<?php
/**
 * 应用：离开意图增收（APP-EXIT-01），对照 Shopname 应用中心的同名应用。
 *
 * 顾客要离开网站时弹出窗口挽回：
 * - 弹窗类型：优惠券（领取后自动用到购物车）/ 抽奖转盘（最多 8 个奖项，按权重在服务端抽奖）/ 自定义内容（标题、文字、按钮链接）。
 * - 触发：电脑端鼠标移出页面顶部（离开意图）；手机端快速上滑或一段时间没有操作；页面至少停留几秒后才会触发。
 * - 范围：哪些页面弹（首页 / 商品列表 / 商品页 / 购物车 / 结账 / 其他），哪些访客弹（所有人 / 购物车有商品 / 没下过单）；
 *   每位访客多少天最多弹一次（浏览器本地记录）。员工不会看到弹窗，后台用“预览”查看。
 * - 抽奖结果由服务端决定（权重就是真实概率），每个购物会话只能抽一次；抽中带优惠码的奖项时，
 *   用和分享链接一样的方式（Coupons::remember）用到购物车，购物车还空时先记下、加入商品后自动用上。
 * - 数据：每天的弹出、关闭、领取、抽奖、中奖次数，以及通过弹窗领到的优惠码下单的订单数和金额（表 {prefix}pc_app_stats）。
 *   订单 meta `_pc_exit_intent` 记下弹窗给的优惠码。
 *
 * 前台弹窗由 assets/exit-intent.js 生成（样式在 assets/exit-intent.css，类名 pc-exit-*，主题可以覆盖）。
 * 设置存在选项 peptide_core_exit_intent。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Exit_Intent {

	public const OPTION      = 'peptide_core_exit_intent';
	public const APP         = 'exit_intent';
	public const ORDER_META  = '_pc_exit_intent';
	public const SESSION_KEY = 'pc_exit_intent';
	public const MAX_PRIZES  = 8;
	public const TYPES       = array( 'coupon', 'wheel', 'message' );
	public const PAGES       = array( 'home', 'shop', 'product', 'cart', 'checkout', 'other' );
	public const EVENTS      = array( 'show', 'close', 'claim', 'spin', 'win', 'order' );

	private const DB_OPTION  = 'peptide_core_app_stats_db';
	private const DB_VERSION = '1';

	public static function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'wc_ajax_pc_exit_event', array( self::class, 'ajax_event' ) );
		add_action( 'wc_ajax_pc_exit_claim', array( self::class, 'ajax_claim' ) );
		add_action( 'wc_ajax_pc_exit_spin', array( self::class, 'ajax_spin' ) );
		add_action( 'woocommerce_checkout_order_processed', array( self::class, 'attribute_order' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'attribute_order' ), 20, 1 );
	}

	// ---------- 设置 ----------

	public static function defaults(): array {
		return array(
			'enabled'        => false,
			'type'           => 'coupon',
			'title'          => 'Wait! Before you go',
			'text'           => 'Here is a little something for your order.',
			'button'         => 'Get my discount',
			'decline'        => 'No thanks',
			'image'          => 0,
			'color'          => '#1d4ed8',
			'coupon'         => '',
			'link'           => '',
			'prizes'         => array(),   // [{label, coupon, weight}]
			'spin_button'    => 'Spin now',
			'win_title'      => 'You won {prize}!',
			'win_text'       => 'Your code: {code}',
			'lose_text'      => 'Not this time. Thanks for playing!',
			'desktop_exit'   => true,
			'mobile'         => 'scroll',  // scroll / idle / off
			'idle_seconds'   => 20,
			'min_seconds'    => 5,
			'pages'          => self::PAGES,
			'audience'       => 'all',     // all / cart / new
			'frequency_days' => 1,         // 0 = 每次打开浏览器最多一次
		);
	}

	public static function settings(): array {
		$s = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $s ) ? $s : array() );
	}

	/** 这个优惠码现在能不能发给顾客（存在、已启用、没过期、没用完）。 */
	public static function coupon_ok( string $code ): bool {
		if ( '' === $code ) {
			return false;
		}
		$id = wc_get_coupon_id_by_code( $code );
		return $id && '' === Coupons::unusable_reason( new \WC_Coupon( $id ) );
	}

	/**
	 * 清理后台提交的设置。返回 [settings, errors]。
	 *
	 * @return array{0:array,1:string[]}
	 */
	public static function clean( array $raw ): array {
		$errors = array();
		$d      = self::defaults();
		$text   = static function ( $v, int $max ): string {
			$v = is_scalar( $v ) ? trim( sanitize_text_field( (string) $v ) ) : '';
			return function_exists( 'mb_substr' ) ? mb_substr( $v, 0, $max ) : substr( $v, 0, $max );
		};
		$s            = array();
		$s['enabled'] = ! empty( $raw['enabled'] );
		$s['type']    = in_array( $raw['type'] ?? '', self::TYPES, true ) ? (string) $raw['type'] : 'coupon';
		foreach ( array( 'title' => 80, 'button' => 40, 'decline' => 40, 'spin_button' => 40, 'win_title' => 80 ) as $k => $max ) {
			$s[ $k ] = $text( $raw[ $k ] ?? $d[ $k ], $max );
		}
		foreach ( array( 'text', 'win_text', 'lose_text' ) as $k ) {
			$v       = is_scalar( $raw[ $k ] ?? null ) ? trim( sanitize_textarea_field( (string) $raw[ $k ] ) ) : '';
			$s[ $k ] = function_exists( 'mb_substr' ) ? mb_substr( $v, 0, 300 ) : substr( $v, 0, 300 );
		}
		$s['image'] = absint( $raw['image'] ?? 0 );
		if ( $s['image'] && ! wp_attachment_is_image( $s['image'] ) ) {
			$s['image'] = 0;
		}
		$color      = sanitize_hex_color( (string) ( $raw['color'] ?? '' ) );
		$s['color'] = $color ? $color : $d['color'];
		$s['coupon'] = wc_format_coupon_code( $text( $raw['coupon'] ?? '', 50 ) );
		$s['link']   = esc_url_raw( (string) ( $raw['link'] ?? '' ), array( 'http', 'https' ) );
		$prizes      = array();
		foreach ( is_array( $raw['prizes'] ?? null ) ? $raw['prizes'] : array() as $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$label  = $text( $p['label'] ?? '', 24 );
			$coupon = wc_format_coupon_code( $text( $p['coupon'] ?? '', 50 ) );
			$weight = max( 0, min( 1000, (int) ( $p['weight'] ?? 0 ) ) );
			if ( '' === $label && '' === $coupon ) {
				continue; // 空行
			}
			if ( '' === $label ) {
				$errors[] = __( 'Every prize needs a name.', 'peptide-core' );
				continue;
			}
			$prizes[] = array( 'label' => $label, 'coupon' => $coupon, 'weight' => $weight );
		}
		$s['prizes'] = array_slice( $prizes, 0, self::MAX_PRIZES );
		$s['desktop_exit']   = ! empty( $raw['desktop_exit'] );
		$s['mobile']         = in_array( $raw['mobile'] ?? '', array( 'scroll', 'idle', 'off' ), true ) ? (string) $raw['mobile'] : 'scroll';
		$s['idle_seconds']   = max( 5, min( 600, (int) ( $raw['idle_seconds'] ?? 20 ) ) );
		$s['min_seconds']    = max( 0, min( 300, (int) ( $raw['min_seconds'] ?? 5 ) ) );
		$s['pages']          = array_values( array_intersect( self::PAGES, array_map( 'strval', (array) ( $raw['pages'] ?? array() ) ) ) );
		$s['audience']       = in_array( $raw['audience'] ?? '', array( 'cart', 'new' ), true ) ? (string) $raw['audience'] : 'all';
		$s['frequency_days'] = max( 0, min( 365, (int) ( $raw['frequency_days'] ?? 1 ) ) );

		if ( '' === $s['title'] ) {
			$errors[] = __( 'Enter a popup title.', 'peptide-core' );
		}
		if ( ! $s['pages'] ) {
			$errors[] = __( 'Choose at least one page type.', 'peptide-core' );
		}
		if ( ! $s['desktop_exit'] && 'off' === $s['mobile'] ) {
			$errors[] = __( 'Turn on at least one trigger.', 'peptide-core' );
		}
		if ( 'coupon' === $s['type'] && ! self::coupon_ok( $s['coupon'] ) ) {
			$errors[] = __( 'Choose a coupon code that is enabled, not expired and not used up.', 'peptide-core' );
		}
		if ( 'message' === $s['type'] && '' !== $s['button'] && '' === $s['link'] ) {
			$errors[] = __( 'Enter the button link, or leave the button text empty.', 'peptide-core' );
		}
		if ( 'wheel' === $s['type'] ) {
			if ( count( $s['prizes'] ) < 2 ) {
				$errors[] = __( 'The wheel needs at least 2 prizes.', 'peptide-core' );
			}
			if ( ! array_sum( array_column( $s['prizes'], 'weight' ) ) ) {
				$errors[] = __( 'At least one prize needs a chance greater than zero.', 'peptide-core' );
			}
			foreach ( $s['prizes'] as $p ) {
				if ( '' !== $p['coupon'] && ! self::coupon_ok( $p['coupon'] ) ) {
					/* translators: %s: coupon code */
					$errors[] = sprintf( __( 'The coupon code %s of a prize is not enabled, has expired or is used up.', 'peptide-core' ), strtoupper( $p['coupon'] ) );
				}
			}
		}
		return array( $s, array_values( array_unique( $errors ) ) );
	}

	/** 保存设置。有错误时不能开启（其他内容照样保存，方便继续编辑）。 */
	public static function save( array $raw ): array {
		[ $s, $errors ] = self::clean( $raw );
		if ( $errors ) {
			$s['enabled'] = false;
		}
		update_option( self::OPTION, $s, false );
		return $errors;
	}

	// ---------- 前台 ----------

	/** 当前页面属于哪一类。 */
	public static function page_kind(): string {
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return 'checkout';
		}
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return 'cart';
		}
		if ( function_exists( 'is_product' ) && is_product() ) {
			return 'product';
		}
		if ( ( function_exists( 'is_shop' ) && is_shop() ) || ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() ) || is_search() ) {
			return 'shop';
		}
		if ( is_front_page() ) {
			return 'home';
		}
		return 'other';
	}

	/** 这位访客是否在范围内。 */
	public static function audience_ok( array $s ): bool {
		if ( 'cart' === $s['audience'] ) {
			return function_exists( 'WC' ) && WC()->cart && ! WC()->cart->is_empty();
		}
		if ( 'new' === $s['audience'] ) {
			$user = get_current_user_id();
			return ! $user || ! wc_get_orders( array( 'customer_id' => $user, 'status' => wc_get_is_paid_statuses(), 'limit' => 1, 'return' => 'ids' ) );
		}
		return true;
	}

	private static function preview(): bool {
		return isset( $_GET['pc_exit_preview'] ) && current_user_can( 'manage_woocommerce' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/** 给浏览器的配置：不含权重和奖项对应的优惠码（抽奖在服务端进行）。 */
	public static function public_config( array $s, bool $preview = false ): array {
		$image = $s['image'] ? (string) wp_get_attachment_image_url( $s['image'], 'medium_large' ) : '';
		$out   = array(
			'type'      => $s['type'],
			'title'     => $s['title'],
			'text'      => $s['text'],
			'button'    => $s['button'],
			'decline'   => $s['decline'],
			'image'     => $image,
			'color'     => $s['color'],
			'link'      => 'message' === $s['type'] ? $s['link'] : '',
			'code'      => 'coupon' === $s['type'] ? strtoupper( $s['coupon'] ) : '',
			'prizes'    => 'wheel' === $s['type'] ? array_column( $s['prizes'], 'label' ) : array(),
			'spin'      => $s['spin_button'],
			'winTitle'  => $s['win_title'],
			'winText'   => $s['win_text'],
			'loseText'  => $s['lose_text'],
			'triggers'  => array(
				'desktop' => (bool) $s['desktop_exit'],
				'mobile'  => $s['mobile'],
				'idle'    => (int) $s['idle_seconds'],
				'min'     => (int) $s['min_seconds'],
			),
			'frequency' => (int) $s['frequency_days'],
			'preview'   => $preview,
			'ajax'      => array(
				'event' => \WC_AJAX::get_endpoint( 'pc_exit_event' ),
				'claim' => \WC_AJAX::get_endpoint( 'pc_exit_claim' ),
				'spin'  => \WC_AJAX::get_endpoint( 'pc_exit_spin' ),
			),
			'strings'   => array(
				'close'   => __( 'Close', 'peptide-core' ),
				'copy'    => __( 'Copy code', 'peptide-core' ),
				'copied'  => __( 'Copied', 'peptide-core' ),
				'error'   => __( 'Something went wrong. Please try again.', 'peptide-core' ),
				'shop'    => __( 'Continue shopping', 'peptide-core' ),
				'spinning' => __( 'Spinning…', 'peptide-core' ),
			),
		);
		return $out;
	}

	public static function enqueue(): void {
		if ( is_admin() ) {
			return;
		}
		$s       = self::settings();
		$preview = self::preview();
		if ( ! $preview ) {
			if ( ! $s['enabled'] || Access::is_staff( get_current_user_id() ) || current_user_can( 'manage_woocommerce' ) ) {
				return;
			}
			if ( ! in_array( self::page_kind(), $s['pages'], true ) || ! self::audience_ok( $s ) ) {
				return;
			}
		}
		self::enqueue_assets();
		wp_localize_script( 'peptide-core-exit-intent', 'pcExitIntent', self::public_config( $s, $preview ) );
	}

	/** 前台和后台预览共用的脚本和样式。 */
	public static function enqueue_assets(): void {
		$ver = static fn( string $f ): string => PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( PEPTIDE_CORE_DIR . '/assets/' . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_enqueue_style( 'peptide-core-exit-intent', plugins_url( 'assets/exit-intent.css', PEPTIDE_CORE_FILE ), array(), $ver( 'exit-intent.css' ) );
		wp_enqueue_script( 'peptide-core-exit-intent', plugins_url( 'assets/exit-intent.js', PEPTIDE_CORE_FILE ), array(), $ver( 'exit-intent.js' ), true );
	}

	// ---------- 弹窗的请求（wc-ajax，带购物会话） ----------
	// 和 WooCommerce 的加购请求一样是公开请求，不用 nonce：游客的 nonce 跟着购物会话变，页面打开后才建会话时会失效。
	// 这些请求只能领取当前设置里的优惠码、每个会话只能抽一次，被别的网站代为触发也没有危害。

	/** 员工（含预览）不记数据、不发优惠码。 */
	private static function is_staff_request(): bool {
		return Access::is_staff( get_current_user_id() ) || current_user_can( 'manage_woocommerce' );
	}

	public static function ajax_event(): void {
		$event = sanitize_key( wp_unslash( $_POST['event'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- 公开请求，见上面的说明。
		if ( in_array( $event, array( 'show', 'close' ), true ) && ! self::is_staff_request() ) {
			self::record( $event );
		}
		wp_send_json_success();
	}

	/** 用到购物车（或先记下），返回给顾客看的结果。 */
	private static function give( string $code ): array {
		if ( ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}
		$state = Coupons::remember( $code );
		wc_clear_notices(); // 结果直接显示在弹窗里，不再出现在下一页的提示里。
		WC()->session->set( self::SESSION_KEY . '_code', $code );
		return array(
			'state'   => $state,
			'message' => 'applied' === $state
				? __( 'The code is applied to your cart.', 'peptide-core' )
				: ( 'pending' === $state ? __( 'The code is saved and will be applied once your cart meets its conditions.', 'peptide-core' ) : __( 'This code can no longer be used.', 'peptide-core' ) ),
		);
	}

	public static function ajax_claim(): void {
		$s = self::settings();
		if ( 'coupon' !== $s['type'] || ! self::coupon_ok( $s['coupon'] ) ) {
			wp_send_json_error( array( 'message' => __( 'This offer has ended.', 'peptide-core' ) ) );
		}
		if ( self::is_staff_request() ) {
			wp_send_json_success( array( 'state' => 'preview', 'code' => strtoupper( $s['coupon'] ), 'message' => __( 'Preview: customers get the code applied to their cart here.', 'peptide-core' ) ) );
		}
		$r = self::give( $s['coupon'] );
		self::record( 'claim' );
		wp_send_json_success( $r + array( 'code' => strtoupper( $s['coupon'] ) ) );
	}

	/**
	 * 按权重抽一个奖项（权重就是真实概率）。
	 *
	 * @param array<int,array{label:string,coupon:string,weight:int}> $prizes
	 */
	public static function draw( array $prizes, ?int $roll = null ): int {
		$total = array_sum( array_column( $prizes, 'weight' ) );
		if ( $total <= 0 ) {
			return 0;
		}
		$roll = $roll ?? random_int( 1, $total );
		foreach ( $prizes as $i => $p ) {
			$roll -= (int) $p['weight'];
			if ( $roll <= 0 ) {
				return (int) $i;
			}
		}
		return count( $prizes ) - 1;
	}

	public static function ajax_spin(): void {
		$s = self::settings();
		if ( 'wheel' !== $s['type'] || count( $s['prizes'] ) < 2 ) {
			wp_send_json_error( array( 'message' => __( 'This offer has ended.', 'peptide-core' ) ) );
		}
		$staff = self::is_staff_request();
		// 每个购物会话只能抽一次：再次请求时返回第一次的结果。
		$done = $staff ? null : WC()->session->get( self::SESSION_KEY . '_spin' );
		if ( is_array( $done ) && isset( $s['prizes'][ $done['index'] ] ) ) {
			wp_send_json_success( $done + array( 'repeat' => true ) );
		}
		$index = self::draw( $s['prizes'] );
		$prize = $s['prizes'][ $index ];
		$won   = '' !== $prize['coupon'] && self::coupon_ok( $prize['coupon'] );
		$out   = array(
			'index' => $index,
			'label' => $prize['label'],
			'won'   => $won,
			'code'  => $won ? strtoupper( $prize['coupon'] ) : '',
		);
		if ( $staff ) {
			wp_send_json_success( $out + array( 'state' => 'preview', 'message' => __( 'Preview: the draw is real, but no code is applied and nothing is counted.', 'peptide-core' ) ) );
		}
		if ( ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}
		self::record( 'spin' );
		if ( $won ) {
			$out = $out + self::give( $prize['coupon'] );
			self::record( 'win' );
		}
		WC()->session->set( self::SESSION_KEY . '_spin', $out );
		wp_send_json_success( $out );
	}

	/** 下单时：用了弹窗给的优惠码就记到订单上，并计入数据。 */
	public static function attribute_order( $order ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order );
		if ( ! $order instanceof \WC_Order || ! function_exists( 'WC' ) || ! WC()->session || $order->get_meta( self::ORDER_META ) ) {
			return;
		}
		$code = (string) WC()->session->get( self::SESSION_KEY . '_code', '' );
		if ( '' === $code || ! in_array( wc_format_coupon_code( $code ), array_map( 'wc_format_coupon_code', $order->get_coupon_codes() ), true ) ) {
			return;
		}
		$order->update_meta_data( self::ORDER_META, strtoupper( $code ) );
		$order->save();
		self::record( 'order', (float) $order->get_total() );
		WC()->session->set( self::SESSION_KEY . '_code', null );
	}

	// ---------- 数据 ----------

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pc_app_stats';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta(
			"CREATE TABLE {$t} (
  day date NOT NULL,
  app varchar(32) NOT NULL,
  event varchar(16) NOT NULL,
  count int(10) unsigned NOT NULL DEFAULT 0,
  amount decimal(19,4) NOT NULL DEFAULT 0,
  PRIMARY KEY  (day,app,event)
) {$wpdb->get_charset_collate()};"
		);
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	public static function installed(): bool {
		return self::DB_VERSION === get_option( self::DB_OPTION );
	}

	/** 当天（店铺时区）的某个事件加一次。 */
	public static function record( string $event, float $amount = 0.0, ?string $day = null ): void {
		global $wpdb;
		if ( ! in_array( $event, self::EVENTS, true ) ) {
			return;
		}
		if ( ! self::installed() ) {
			self::install();
		}
		$t = self::table();
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$t} (day, app, event, count, amount) VALUES (%s, %s, %s, 1, %f) ON DUPLICATE KEY UPDATE count = count + 1, amount = amount + VALUES(amount)", $day ?? wp_date( 'Y-m-d' ), self::APP, $event, $amount ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/** @return array<string,array{count:int,amount:float}> 最近 N 天（含今天）每个事件的合计。 */
	public static function totals( int $days = 30 ): array {
		global $wpdb;
		$out = array_fill_keys( self::EVENTS, array( 'count' => 0, 'amount' => 0.0 ) );
		if ( ! self::installed() ) {
			return $out;
		}
		$t    = self::table();
		$from = wp_date( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT event, SUM(count) AS c, SUM(amount) AS a FROM {$t} WHERE app = %s AND day >= %s GROUP BY event", self::APP, $from ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $rows as $r ) {
			if ( isset( $out[ $r['event'] ] ) ) {
				$out[ $r['event'] ] = array( 'count' => (int) $r['c'], 'amount' => (float) $r['a'] );
			}
		}
		return $out;
	}
}
