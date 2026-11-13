<?php
/**
 * 流量斗篷“已购用户信任 IP”（店主 2026-11-14）。
 *
 * 斗篷开着时（只放美国手机、拦电脑、拦云服务器网段等），付过款的真实客户换到电脑或公司网络就进不来，
 * 连登录页都打不开，查不了自己的订单。这里不改斗篷插件本身，只做三件事：
 *
 * 1. 名单：订单真正付款（处理中 / 已完成，排除测试单）时，把下单用的 IP 记进选项 pc_cloak_buyer_ips；
 *    订单之后退款 / 取消 / 失败，这个 IP 没有别的已付款订单就移出。已有的已付款订单补录一次（backfill）。
 * 2. 信任设备：付款成功页、已付款客户登录时，给这台设备发一个签名 Cookie（一年）。手机网络 IP 常变，
 *    带着这个 Cookie 回来时，当前 IP 也当作信任 IP，并补进名单。
 * 3. 让斗篷认这份名单：前台读 acloak_options 时，把名单（和带信任 Cookie 的当前 IP）临时并进它自己的
 *    “IP 白名单”（只在内存里，不写回）。斗篷的服务端跳转和防缓存的 AJAX 复查都走这里。
 *    后台设置页读的是原始值，保存时不会把自动名单混进手填的白名单。
 *
 * 设置页上加一块“已购用户信任 IP”卡片：显示名单、来自哪张订单、可单个移出。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Cloak_Trust {

	public const OPTION = 'pc_cloak_buyer_ips';
	public const COOKIE = 'pc_buyer_trust';
	public const MAX    = 5000;
	private const BACKFILLED = 'pc_cloak_buyer_ips_backfilled';

	public static function register_hooks(): void {
		add_filter( 'option_acloak_options', array( self::class, 'merge' ) );
		add_action( 'woocommerce_order_status_changed', array( self::class, 'status_changed' ), 20, 4 );
		add_action( 'woocommerce_payment_complete', array( self::class, 'paid' ), 20 );
		add_action( 'woocommerce_thankyou', array( self::class, 'thankyou' ), 5 );
		add_action( 'wp_login', array( self::class, 'login' ), 20, 2 );
		add_action( 'admin_footer', array( self::class, 'admin_card' ) );
		add_action( 'admin_post_pc_cloak_trust_remove', array( self::class, 'remove_handler' ) );
		add_action( 'admin_init', array( self::class, 'maybe_backfill' ) );
	}

	// ---------- 名单 ----------

	/** @return array<string,array{order:int,number:string,at:int,via:string}> */
	public static function all(): array {
		$v = get_option( self::OPTION, array() );
		return is_array( $v ) ? $v : array();
	}

	/** 统一成标准写法（IPv6 压缩），不是公网 IP 返回 ''。 */
	public static function normalize( $ip ): string {
		$ip = trim( (string) $ip );
		if ( '' === $ip || false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return '';
		}
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return false === $packed ? '' : (string) inet_ntop( $packed );
	}

	private static function save( array $list ): void {
		if ( count( $list ) > self::MAX ) {
			uasort( $list, static fn( $a, $b ) => (int) ( $b['at'] ?? 0 ) <=> (int) ( $a['at'] ?? 0 ) );
			$list = array_slice( $list, 0, self::MAX, true );
		}
		update_option( self::OPTION, $list, true );
	}

	public static function add( string $ip, \WC_Order $order, string $via ): void {
		$ip = self::normalize( $ip );
		if ( '' === $ip ) {
			return;
		}
		$list = self::all();
		if ( isset( $list[ $ip ] ) && (int) $list[ $ip ]['order'] === $order->get_id() ) {
			return;
		}
		$list[ $ip ] = array( 'order' => $order->get_id(), 'number' => (string) $order->get_order_number(), 'at' => time(), 'via' => $via );
		self::save( $list );
	}

	/** 订单是不是“真实付款”的：已付款状态、不是测试单。 */
	public static function qualifies( $order ): bool {
		return $order instanceof \WC_Order && 'shop_order' === $order->get_type() && $order->has_status( array( 'processing', 'completed' ) )
			&& ! ( class_exists( Test_Orders::class ) && Test_Orders::is_test_order( $order ) );
	}

	public static function paid( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( self::qualifies( $order ) ) {
			self::add( (string) $order->get_customer_ip_address(), $order, 'order' );
		}
	}

	public static function status_changed( $order_id, $from, $to, $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		if ( in_array( $to, array( 'processing', 'completed' ), true ) ) {
			self::paid( $order->get_id() );
			return;
		}
		if ( in_array( $to, array( 'refunded', 'cancelled', 'failed' ), true ) ) {
			self::forget_order( $order );
		}
	}

	/** 订单退款 / 取消后：它带进名单的 IP，没有别的已付款订单就移出。 */
	private static function forget_order( \WC_Order $order ): void {
		$list    = self::all();
		$changed = false;
		foreach ( $list as $ip => $row ) {
			if ( (int) $row['order'] !== $order->get_id() ) {
				continue;
			}
			$other = self::other_paid_order( (string) $ip, $order->get_id() );
			if ( $other ) {
				$list[ $ip ] = array( 'order' => $other->get_id(), 'number' => (string) $other->get_order_number(), 'at' => (int) $row['at'], 'via' => 'order' );
			} else {
				unset( $list[ $ip ] );
			}
			$changed = true;
		}
		if ( $changed ) {
			self::save( $list );
		}
	}

	private static function other_paid_order( string $ip, int $except ): ?\WC_Order {
		// 最近 500 张已付款订单里找同一个 IP 的（不同存储方式下按 IP 查询的参数不统一，直接比对）。
		foreach ( wc_get_orders( array( 'status' => array( 'processing', 'completed' ), 'limit' => 500, 'type' => 'shop_order', 'exclude' => array( $except ) ) ) as $o ) {
			if ( self::normalize( $o->get_customer_ip_address() ) === $ip && self::qualifies( $o ) ) {
				return $o;
			}
		}
		return null;
	}

	/** 已有的已付款订单补录一次（上线后第一次打开后台时跑）。 */
	public static function maybe_backfill(): void {
		if ( get_option( self::BACKFILLED ) || ! current_user_can( 'manage_woocommerce' ) || ! function_exists( 'wc_get_orders' ) ) {
			return;
		}
		update_option( self::BACKFILLED, time(), false );
		self::backfill();
	}

	public static function backfill(): int {
		$n = 0;
		foreach ( wc_get_orders( array( 'status' => array( 'processing', 'completed' ), 'limit' => -1, 'type' => 'shop_order', 'orderby' => 'date', 'order' => 'ASC' ) ) as $order ) {
			if ( self::qualifies( $order ) && '' !== self::normalize( $order->get_customer_ip_address() ) ) {
				self::add( (string) $order->get_customer_ip_address(), $order, 'order' );
				++$n;
			}
		}
		return $n;
	}

	// ---------- 信任设备 ----------

	private static function sign( int $order_id, int $expires ): string {
		return hash_hmac( 'sha256', 'pc-buyer|' . $order_id . '|' . $expires, wp_salt( 'auth' ) );
	}

	private static function give_cookie( \WC_Order $order ): void {
		if ( headers_sent() ) {
			return;
		}
		$expires = time() + YEAR_IN_SECONDS;
		$value   = $order->get_id() . '.' . $expires . '.' . self::sign( $order->get_id(), $expires );
		setcookie( self::COOKIE, $value, array( 'expires' => $expires, 'path' => COOKIEPATH ?: '/', 'domain' => COOKIE_DOMAIN ?: '', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
		$_COOKIE[ self::COOKIE ] = $value;
	}

	/** 带着有效信任 Cookie 的已付款订单；没有 = null。 */
	private static function cookie_order(): ?\WC_Order {
		$raw = isset( $_COOKIE[ self::COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 下面按格式和签名校验。
		if ( 1 !== preg_match( '/\A(\d{1,12})\.(\d{10})\.([a-f0-9]{64})\z/', $raw, $m ) || (int) $m[2] < time() || ! hash_equals( self::sign( (int) $m[1], (int) $m[2] ), $m[3] ) ) {
			return null;
		}
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $m[1] ) : null;
		return self::qualifies( $order ) ? $order : null;
	}

	/** 付款成功页：给这台设备发信任 Cookie，并补录当前 IP。 */
	public static function thankyou( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! self::qualifies( $order ) ) {
			return;
		}
		self::give_cookie( $order );
		self::add( self::visitor_ip(), $order, 'device' );
	}

	/** 付过款的客户登录：同样发 Cookie、补录当前 IP。 */
	public static function login( $login, $user ): void {
		if ( ! $user instanceof \WP_User || ! function_exists( 'wc_get_orders' ) ) {
			return;
		}
		foreach ( wc_get_orders( array( 'customer_id' => $user->ID, 'status' => array( 'processing', 'completed' ), 'limit' => 3 ) ) as $order ) {
			if ( self::qualifies( $order ) ) {
				self::give_cookie( $order );
				self::add( self::visitor_ip(), $order, 'login' );
				return;
			}
		}
	}

	/** 和斗篷一样取访客 IP（Cloudflare 的 CF-Connecting-IP 优先）。 */
	public static function visitor_ip(): string {
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$v = explode( ',', (string) wp_unslash( $_SERVER[ $key ] ) )[0]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalize() 校验。
				return trim( $v );
			}
		}
		return '';
	}

	// ---------- 让斗篷认这份名单 ----------

	/** 前台 / 斗篷的 AJAX 复查读 acloak_options 时，把信任 IP 临时并进 ip_whitelist。后台设置页不改。 */
	public static function merge( $options ) {
		if ( ! is_array( $options ) || ( is_admin() && ! wp_doing_ajax() ) ) {
			return $options;
		}
		static $extra = null;
		if ( null === $extra ) {
			$extra = array_keys( self::all() );
			$here  = self::visitor_ip();
			if ( '' !== $here && isset( $_COOKIE[ self::COOKIE ] ) ) {
				$order = self::cookie_order();
				if ( $order ) {
					$extra[] = $here;
					$norm    = self::normalize( $here );
					if ( '' !== $norm && ! isset( self::all()[ $norm ] ) ) {
						self::add( $here, $order, 'device' );
					}
				}
			}
			// 斗篷用原样的头部值精确比对，名单里存的是标准写法，两种都放进去。
			$here_norm = self::normalize( $here );
			if ( '' !== $here_norm && $here_norm !== $here && in_array( $here_norm, $extra, true ) ) {
				$extra[] = $here;
			}
		}
		if ( $extra ) {
			$options['ip_whitelist'] = trim( (string) ( $options['ip_whitelist'] ?? '' ) . "\n" . implode( "\n", $extra ) );
		}
		return $options;
	}

	// ---------- 斗篷设置页上的卡片 ----------

	public static function admin_card(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || 'advanced_cloak' !== sanitize_key( wp_unslash( $_GET['page'] ) ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$list = self::all();
		uasort( $list, static fn( $a, $b ) => (int) ( $b['at'] ?? 0 ) <=> (int) ( $a['at'] ?? 0 ) );
		$via  = array( 'order' => '下单时', 'device' => '付款设备', 'login' => '客户登录' );
		$rows = '';
		foreach ( array_slice( $list, 0, 300, true ) as $ip => $r ) {
			$order = admin_url( 'admin.php?page=pc-order&id=' . (int) $r['order'] );
			$del   = wp_nonce_url( admin_url( 'admin-post.php?action=pc_cloak_trust_remove&ip=' . rawurlencode( (string) $ip ) ), 'pc_cloak_trust_remove' );
			$rows .= '<tr><td class="cbs-mono">' . esc_html( (string) $ip ) . '</td><td><a href="' . esc_url( $order ) . '" target="_blank" rel="noopener">#' . esc_html( (string) $r['number'] ) . '</a></td><td>' . esc_html( $via[ $r['via'] ] ?? '' ) . '</td><td>' . esc_html( wp_date( 'Y-m-d H:i', (int) $r['at'] ) ) . '</td><td><a class="button button-small" href="' . esc_url( $del ) . '">移出</a></td></tr>';
		}
		$body = $list
			? '<div style="max-height:280px;overflow:auto"><table class="widefat striped" style="font-size:12px;white-space:nowrap"><thead><tr><th>IP</th><th>订单</th><th>来源</th><th>加入时间</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
			: '<p class="cbs-help">还没有。客户真实付款后会自动加进来。</p>';
		$card = '<section class="cbs-card cbs-span-2" id="pc-cloak-trust"><div class="cbs-card-head"><span class="cbs-card-head-icon dashicons dashicons-yes-alt"></span><div><h2>已购用户信任 IP（' . count( $list ) . '）</h2><p>付过款的客户自动放行，斗篷开着也能进来查订单</p></div></div><div class="cbs-card-body">'
			. '<p class="cbs-help">只收真实付款的订单（处理中 / 已完成，不含测试单）。下单用的 IP 自动加入；客户付款成功或登录后，那台设备一年内换了 IP 也会放行并补进来。订单退款 / 取消后自动移出。不影响上面手填的 IP 白名单。</p>'
			. $body . '</div></section>';
		echo '<template id="pc-cloak-trust-tpl">' . $card . '</template><script>(function(){var t=document.getElementById("pc-cloak-trust-tpl"),w=document.getElementById("ip_whitelist");if(!t)return;var c=w&&w.closest(".cbs-card"),g=document.querySelector(".cbs-grid");var n=t.content.cloneNode(true);if(c){c.parentNode.insertBefore(n,c.nextSibling);}else if(g){g.appendChild(n);}})();</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 上面逐项转义。
	}

	public static function remove_handler(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( '没有权限。' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'pc_cloak_trust_remove' );
		$ip   = isset( $_GET['ip'] ) ? self::normalize( sanitize_text_field( wp_unslash( $_GET['ip'] ) ) ) : '';
		$list = self::all();
		if ( '' !== $ip && isset( $list[ $ip ] ) ) {
			unset( $list[ $ip ] );
			self::save( $list );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=advanced_cloak#pc-cloak-trust' ) );
		exit;
	}
}
