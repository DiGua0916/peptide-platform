<?php
/**
 * 右下角物流追踪球（店主 2026-11-11）：货车图标，点开看自己订单的物流。
 *
 * - 已登录：自己最近 10 张订单；
 * - 没登录但在这台设备上付过款：付款成功弹窗记住了订单号和订单密钥（localStorage），直接显示；
 * - 都没有：输入订单号 + 下单邮箱查询（按 IP 限频），或者登录。
 * 包裹信息来自后台订单里添加的发货记录（WooCommerce 订单履约：承运商 + 单号）；FedEx 的包裹再用官方接口取扫描记录。
 * 页面上只输出静态按钮，数据点开后才请求，首页静态缓存不受影响。不返回顾客地址。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Track_Widget {

	public const OPTION = 'pc_track_ball';

	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'assets' ), 160 );
		add_action( 'wp_footer', array( self::class, 'render' ), 46 );
	}

	public static function enabled(): bool {
		return '0' !== (string) get_option( self::OPTION, '1' );
	}

	private static function visible(): bool {
		return self::enabled() && ! is_admin() && ! is_customize_preview() && ! ( function_exists( 'is_checkout' ) && is_checkout() );
	}

	public static function assets(): void {
		if ( ! self::visible() ) {
			return;
		}
		$v = static fn( string $f ): string => PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( PEPTIDE_CORE_DIR . '/assets/' . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_enqueue_style( 'pc-track-widget', plugins_url( 'assets/track-widget.css', PEPTIDE_CORE_FILE ), array(), $v( 'track-widget.css' ) );
		wp_enqueue_script( 'pc-track-widget', plugins_url( 'assets/track-widget.js', PEPTIDE_CORE_FILE ), array(), $v( 'track-widget.js' ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_add_inline_script(
			'pc-track-widget',
			'window.pcTrack=' . wp_json_encode(
				array(
					'mine'    => rest_url( 'peptide/v1/track/mine' ),
					'lookup'  => rest_url( 'peptide/v1/track/lookup' ),
					// 已登录的页面不进静态缓存，这里的 nonce 不会被别人拿到。
					'nonce'   => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
					'account' => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : '',
					'login'   => rest_url( 'peptide/v1/account/login' ),
					'lost'    => function_exists( 'wc_lostpassword_url' ) ? wc_lostpassword_url() : wp_lostpassword_url(),
					'shop'    => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' ),
					'signup'  => class_exists( Accounts::class ) && Accounts::settings()['signup'] && ! is_user_logged_in(),
				)
			) . ';',
			'before'
		);
	}

	public static function render(): void {
		if ( ! self::visible() ) {
			return;
		}
		?>
<button type="button" class="pc-trk-ball" data-pc-trk-ball aria-label="Track your order" title="Track your order">
	<img src="<?php echo esc_url( plugins_url( 'assets/track-ball-van.png', PEPTIDE_CORE_FILE ) . '?v=' . (int) @filemtime( PEPTIDE_CORE_DIR . '/assets/track-ball-van.png' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors ?>" width="44" height="44" alt="" decoding="async" loading="lazy">
</button>
<dialog class="pc-trk" data-pc-trk aria-labelledby="pc-trk-title">
	<div class="pc-trk__box">
		<div class="pc-trk__head">
			<h2 id="pc-trk-title">Track your order</h2>
			<button type="button" class="pc-trk__x" data-pc-trk-close aria-label="Close">&times;</button>
		</div>
		<div class="pc-trk__body" data-pc-trk-body aria-live="polite"></div>
	</div>
</dialog>
		<?php
	}

	/* ---------------------------------------------------------------- 接口 */

	public static function routes(): void {
		register_rest_route(
			'peptide/v1',
			'/track/mine',
			array(
				'methods'             => 'GET',
				'permission_callback' => static fn() => is_user_logged_in(),
				'callback'            => array( self::class, 'rest_mine' ),
			)
		);
		register_rest_route(
			'peptide/v1',
			'/track/lookup',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true', // 用订单密钥或下单邮箱校验，按 IP 限频。
				'callback'            => array( self::class, 'rest_lookup' ),
			)
		);
	}

	public static function rest_mine() {
		$orders = wc_get_orders( array( 'customer_id' => get_current_user_id(), 'limit' => 10, 'orderby' => 'date', 'order' => 'DESC', 'type' => 'shop_order', 'return' => 'objects' ) );
		return rest_ensure_response( array( 'orders' => array_values( array_filter( array_map( array( self::class, 'order_view' ), $orders ) ) ) ) );
	}

	public static function rest_lookup( \WP_REST_Request $r ) {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$rl  = 'pc_trk_rl_' . md5( $ip );
		$n   = (int) get_transient( $rl );
		$out = array();
		// 设备上记住的订单（订单号 + 密钥）：最多 5 张。
		$pairs = $r['orders'];
		if ( is_array( $pairs ) && $pairs ) {
			if ( $n >= 40 ) { // 审查 2026-11-11：记住的订单这条路也限频。
				return new \WP_Error( 'pc_trk_rate', 'Too many attempts. Please try again in a few minutes.', array( 'status' => 429 ) );
			}
			set_transient( $rl, $n + 1, 10 * MINUTE_IN_SECONDS );
			foreach ( array_slice( $pairs, 0, 5 ) as $p ) {
				$o = wc_get_order( absint( $p['o'] ?? 0 ) );
				if ( $o instanceof \WC_Order && 'shop_order' === $o->get_type() && hash_equals( $o->get_order_key(), (string) ( $p['k'] ?? '' ) ) ) {
					$v = self::order_view( $o );
					if ( $v ) {
						$out[] = $v;
					}
				}
			}
			return rest_ensure_response( array( 'orders' => $out ) );
		}
		// 订单号 + 邮箱。
		if ( $n >= 20 ) {
			return new \WP_Error( 'pc_trk_rate', 'Too many attempts. Please try again in a few minutes.', array( 'status' => 429 ) );
		}
		set_transient( $rl, $n + 1, 10 * MINUTE_IN_SECONDS );
		$email = strtolower( trim( (string) $r['email'] ) );
		$o     = Order_Number::find( (string) $r['order'] );
		if ( ! $o instanceof \WC_Order || 'shop_order' !== $o->get_type() || '' === $email || ! hash_equals( strtolower( (string) $o->get_billing_email() ), $email ) ) {
			return new \WP_Error( 'pc_trk_nf', 'We could not find an order with that number and email.', array( 'status' => 404 ) );
		}
		$v = self::order_view( $o );
		return rest_ensure_response( array( 'orders' => $v ? array( $v ) : array() ) );
	}

	/** 给顾客看的一张订单：不含地址。 */
	public static function order_view( $order ): ?array {
		if ( ! $order instanceof \WC_Order ) {
			return null;
		}
		$items = array();
		foreach ( $order->get_items( 'line_item' ) as $it ) {
			$items[] = $it->get_name() . ' x' . (int) $it->get_quantity();
		}
		$view      = class_exists( Shipping_Tracking::class ) ? Shipping_Tracking::customer_view( $order ) : null;
		$shipments = array();
		foreach ( (array) ( $view['shipments'] ?? array() ) as $sh ) {
			$num = (string) ( $sh['tracking_number'] ?? '' );
			$url = (string) ( $sh['tracking_url'] ?? '' );
			$row = array(
				'carrier' => (string) ( $sh['provider_name'] ?? '' ),
				'number'  => $num,
				'url'     => $url,
				'shipped' => $sh['shipped_at'] ?? null,
				'live'    => null,
			);
			if ( '' !== $num && Fedex_Track::is_fedex( $sh['provider'] ?? null, $num ) ) {
				if ( '' === $row['carrier'] ) {
					$row['carrier'] = 'FedEx';
				}
				if ( '' === $url ) {
					$row['url'] = 'https://www.fedex.com/wtrk/track/?trknbr=' . rawurlencode( $num ); // FedEx 2026 年的新查询地址（旧的 /fedextrack/ 会跳转）。
				}
				if ( Fedex_Track::configured() ) {
					$t = Fedex_Track::track( $num );
					if ( ! is_wp_error( $t ) ) {
						$row['live'] = $t;
					}
				}
			}
			$shipments[] = $row;
		}
		if ( $order->has_status( array( 'cancelled', 'refunded', 'failed' ) ) ) {
			$stage = 'closed';
		} elseif ( ! $order->is_paid() ) {
			$stage = 'unpaid';
		} elseif ( array_filter( array_column( array_column( $shipments, 'live' ), 'delivered' ) ) ) {
			$stage = 'delivered';
		} elseif ( $shipments ) {
			$stage = 'shipped';
		} else {
			$stage = 'preparing';
		}
		$created = $order->get_date_created();
		$paid    = $order->get_date_paid();
		return array(
			'number'    => (string) $order->get_order_number(),
			'date'      => $created ? $created->date( 'c' ) : null,
			'paid'      => $paid ? $paid->date( 'c' ) : null,
			'status'    => wc_get_order_status_name( $order->get_status() ),
			'stage'     => $stage,
			'items'     => $items,
			'total'     => html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ), ENT_QUOTES, 'UTF-8' ),
			'shipments' => $shipments,
		);
	}
}
