<?php
/**
 * 付款链接（2026-11-10 店主：我在自己的浏览器里选好商品，把链接发给客户，客户打开就是我选好的商品，直接付款）。
 *
 * - 链接把购物车写在网址里：/?pc_cart=商品或规格ID x 数量,…&pc_coupon=优惠码。谁打开都会得到同样的购物车，
 *   不依赖原来那台电脑的会话。打开时先清空对方原来的购物车，再按链接加入，然后直接到结账页（或购物车页，可设置）。
 * - 价格、库存、能不能买都以店里当时为准；链接里只有 ID 和数量。
 * - 店主 2026-11-10：不要按钮。购物车页的地址栏自动变成付款链接（assets/cart-share.js），直接复制地址栏发给客户。
 *   （旧的“分享模式”开关仍兼容，但前台不再显示任何按钮。）
 * - 带参数的网址不会进页面缓存（Page_Cache 只缓存不带参数的首页）。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Cart_Links {

	public const OPTION = 'pc_cart_links';
	public const COOKIE = 'pc_share_mode';
	private const MAX_ITEMS = 40;

	public static function register_hooks(): void {
		add_action( 'template_redirect', array( self::class, 'handle' ), 1 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ), 40 );
	}

	public static function settings(): array {
		$s = get_option( self::OPTION, array() );
		$s = is_array( $s ) ? $s : array();
		return array(
			'enabled' => true, // 店主 2026-11-10：去掉后台设置页，一直开启。
			'target'  => 'cart' === ( $s['target'] ?? '' ) ? 'cart' : 'checkout',
		);
	}

	public static function save( array $in ): void {
		update_option( self::OPTION, array( 'enabled' => ! empty( $in['enabled'] ), 'target' => 'cart' === ( $in['target'] ?? '' ) ? 'cart' : 'checkout' ), false );
	}

	/** 后台“在这个浏览器开启分享按钮”的签名网址（30 天内有效）。 */
	public static function share_mode_url( bool $on = true ): string {
		$base = Management_Host::public_url() . '/';
		if ( ! $on ) {
			return add_query_arg( self::COOKIE, 'off', $base );
		}
		$exp = time() + 30 * DAY_IN_SECONDS;
		return add_query_arg( self::COOKIE, $exp . '.' . hash_hmac( 'sha256', 'cartshare|' . $exp, wp_salt( 'auth' ) ), $base );
	}

	/** 把购物车行（商品 / 规格 ID + 数量）变成付款链接。 */
	public static function build( array $items, array $coupons = array() ): string {
		$parts = array();
		foreach ( $items as $i ) {
			$id  = (int) ( $i['id'] ?? 0 );
			$qty = max( 1, min( 999, (int) ( $i['qty'] ?? 1 ) ) );
			if ( $id ) {
				$parts[] = $id . 'x' . $qty;
			}
		}
		$args = array( 'pc_cart' => implode( ',', $parts ) );
		if ( $coupons ) {
			$args['pc_coupon'] = implode( ',', array_map( 'wc_format_coupon_code', $coupons ) );
		}
		return add_query_arg( $args, Management_Host::public_url() . '/' );
	}

	public static function handle(): void {
		if ( is_admin() || wp_doing_ajax() || ! function_exists( 'WC' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET[ self::COOKIE ] ) ) {
			self::share_mode( sanitize_text_field( wp_unslash( $_GET[ self::COOKIE ] ) ) );
			return;
		}
		if ( ! isset( $_GET['pc_cart'] ) || ! self::settings()['enabled'] ) {
			return;
		}
		$raw     = sanitize_text_field( wp_unslash( $_GET['pc_cart'] ) );
		$coupons = isset( $_GET['pc_coupon'] ) ? array_filter( array_map( 'wc_format_coupon_code', explode( ',', sanitize_text_field( wp_unslash( $_GET['pc_coupon'] ) ) ) ) ) : array();
		// phpcs:enable
		if ( null === WC()->cart ) {
			wc_load_cart();
		}
		if ( ! WC()->cart || ! WC()->session ) {
			return;
		}
		WC()->session->set_customer_session_cookie( true );
		WC()->cart->empty_cart();
		self::clear_customer();
		$added = 0;
		foreach ( array_slice( explode( ',', $raw ), 0, self::MAX_ITEMS ) as $part ) {
			if ( ! preg_match( '/^(\d{1,10})(?:x(\d{1,3}))?$/', trim( $part ), $m ) ) {
				continue;
			}
			$product = wc_get_product( (int) $m[1] );
			$qty     = max( 1, (int) ( $m[2] ?? 1 ) );
			if ( ! $product || ! $product->is_purchasable() || 'publish' !== get_post_status( $product->get_parent_id() ?: $product->get_id() ) ) {
				continue;
			}
			try {
				$ok = $product->is_type( 'variation' )
					? WC()->cart->add_to_cart( $product->get_parent_id(), $qty, $product->get_id(), $product->get_variation_attributes() )
					: WC()->cart->add_to_cart( $product->get_id(), $qty );
			} catch ( \Throwable $e ) {
				$ok = false;
			}
			$added += $ok ? 1 : 0;
		}
		foreach ( array_slice( $coupons, 0, 3 ) as $code ) {
			if ( ! WC()->cart->has_discount( $code ) ) {
				WC()->cart->apply_coupon( $code );
			}
		}
		WC()->cart->calculate_totals();
		// 店主 2026-11-10：不要任何提示。加购、优惠码产生的提示全部清掉，客户打开就是选好的商品。
		wc_clear_notices();
		// 其他参数（广告标记、fbclid 等）原样带过去，付款链接自己的参数去掉。
		$keep = array_diff_key( wp_unslash( $_GET ), array_flip( array( 'pc_cart', 'pc_coupon' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput
		$keep = array_map( static fn( $v ) => is_scalar( $v ) ? sanitize_text_field( (string) $v ) : '', $keep );
		$to   = $added ? ( 'cart' === self::settings()['target'] ? wc_get_cart_url() : wc_get_checkout_url() ) : wc_get_cart_url();
		nocache_headers();
		wp_safe_redirect( add_query_arg( array_filter( $keep, 'strlen' ), $to ) );
		exit;
	}

	/**
	 * 店主 2026-11-10：链接只带商品和优惠码。打开链接时清掉这个浏览器里之前填过的邮箱、姓名、地址、电话，
	 * 让结账页从空白开始（登录的会员不动，用他自己账户里的资料）。
	 */
	/** 手动订单链接也用：清掉这个浏览器之前填过的顾客资料。 */
	public static function clear_customer_public(): void {
		if ( null === WC()->customer ) {
			return;
		}
		self::clear_customer();
	}

	private static function clear_customer(): void {
		if ( is_user_logged_in() || ! WC()->customer ) {
			return;
		}
		$c = WC()->customer;
		foreach ( array( 'billing', 'shipping' ) as $t ) {
			foreach ( array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'phone' ) as $f ) {
				$m = 'set_' . $t . '_' . $f;
				if ( is_callable( array( $c, $m ) ) ) {
					$c->$m( '' );
				}
			}
		}
		$c->set_billing_email( '' );
		$c->set_email( '' );
		$c->set_calculated_shipping( false );
		$c->save();
		WC()->session->set( 'chosen_shipping_methods', null );
	}

	/** 开 / 关这个浏览器里的“复制付款链接”按钮。 */
	private static function share_mode( string $token ): void {
		$secure = is_ssl();
		if ( 'off' === $token ) {
			setcookie( self::COOKIE, '', array( 'expires' => time() - 3600, 'path' => '/', 'secure' => $secure, 'samesite' => 'Lax' ) );
		} else {
			[ $exp, $sig ] = array_pad( explode( '.', $token, 2 ), 2, '' );
			if ( (int) $exp < time() || ! hash_equals( hash_hmac( 'sha256', 'cartshare|' . (int) $exp, wp_salt( 'auth' ) ), $sig ) ) {
				wp_die( '这个开启链接已失效，请回到店铺后台“付款链接”重新点“在这个浏览器开启”。', '', array( 'response' => 403 ) );
			}
			setcookie( self::COOKIE, '1', array( 'expires' => (int) $exp, 'path' => '/', 'secure' => $secure, 'samesite' => 'Lax' ) );
		}
		nocache_headers();
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	public static function enqueue(): void {
		// 购物车页和结账页（店主 2026-11-10：从购物车抽屉直接去结账时不经过购物车页）：地址栏就是付款链接，页面上不显示任何东西。
		$on = function_exists( 'is_cart' ) && ( is_cart() || ( is_checkout() && ! is_order_received_page() && ! is_checkout_pay_page() ) );
		if ( is_admin() || ! self::settings()['enabled'] || ! $on ) {
			return;
		}
		$file = PEPTIDE_CORE_DIR . '/assets/cart-share.js';
		if ( ! is_readable( $file ) ) {
			return;
		}
		wp_enqueue_script( 'pc-cart-share', plugins_url( 'assets/cart-share.js', PEPTIDE_CORE_FILE ), array(), (string) filemtime( $file ), true );
		wp_add_inline_script( 'pc-cart-share', 'window.pcCartShare=' . wp_json_encode( array( 'cart' => home_url( '/wp-json/wc/store/v1/cart' ), 'base' => home_url( '/' ), 'cookie' => self::COOKIE, 'manual' => class_exists( Manual_Orders::class ) && ( $m = Manual_Orders::active_cart() ) ? $m['token'] : '' ) ) . ';', 'before' );
	}
}
