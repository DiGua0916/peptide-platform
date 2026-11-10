<?php
/**
 * 手动订单（店主 2026-11-10）：后台“订单 → 手动订单”里选商品、改原价和售价、自定义运费、加几笔自定义优惠，
 * 生成一个链接发给顾客。顾客打开链接：购物车就是这些商品（按这里的价格），运费、优惠按这里的，直接进结账填地址付款。
 *
 * - 价格只对这个链接生效，不改商品本身的价格。
 * - 链接购物车不叠加店铺的满减活动和优惠券、运费优惠（完全按店主配的）。
 * - 链接 30 天有效；顾客用链接下的单会带上 _pc_manual_link，并记到这条手动订单下面。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Manual_Orders {

	public const OPTION   = 'pc_manual_links';
	public const ARG      = 'pc_manual';
	public const META     = '_pc_manual_link';
	private const TTL     = 30 * DAY_IN_SECONDS;
	private const MAX     = 300;

	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_action( 'template_redirect', array( self::class, 'handle' ), 1 );
		add_action( 'woocommerce_before_calculate_totals', array( self::class, 'apply_prices' ), 9999 );
		add_action( 'woocommerce_cart_calculate_fees', array( self::class, 'apply_fees' ), 99 );
		add_filter( 'woocommerce_package_rates', array( self::class, 'apply_shipping' ), 9999, 2 );
		add_filter( 'woocommerce_coupon_is_valid', array( self::class, 'block_coupons' ), 9999, 2 );
		add_action( 'woocommerce_checkout_create_order', array( self::class, 'tag_order' ), 20 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( self::class, 'tag_order' ), 20 );
	}

	// ---------- 数据 ----------

	public static function all(): array {
		$v = get_option( self::OPTION, array() );
		return is_array( $v ) ? $v : array();
	}

	public static function get( string $token ): ?array {
		$all = self::all();
		$c   = $all[ $token ] ?? null;
		return is_array( $c ) && (int) $c['created'] + self::TTL > time() ? $c : null;
	}

	private static function money( float $v ): float {
		return max( 0.0, round( $v, wc_get_price_decimals() ) );
	}

	/** 后台提交的配置 → 清理后保存，返回 token。 */
	public static function create( array $in ) {
		$items = array();
		foreach ( array_slice( (array) ( $in['items'] ?? array() ), 0, 40 ) as $row ) {
			$p   = wc_get_product( absint( $row['id'] ?? 0 ) );
			$qty = max( 1, min( 999, absint( $row['qty'] ?? 1 ) ) );
			if ( ! $p || ! $p->is_purchasable() ) {
				continue;
			}
			$price   = self::money( (float) ( $row['price'] ?? $p->get_price() ) );
			$regular = self::money( (float) ( $row['regular'] ?? $p->get_regular_price() ) );
			$items[] = array( 'id' => $p->get_id(), 'name' => wp_strip_all_tags( $p->get_name() ), 'qty' => $qty, 'price' => $price, 'regular' => max( $regular, $price ) );
		}
		if ( ! $items ) {
			return new \WP_Error( 'pc_manual_empty', '请至少添加一个商品。', array( 'status' => 400 ) );
		}
		$ship = $in['shipping'] ?? null;
		$shipping = is_array( $ship ) && '' !== (string) ( $ship['amount'] ?? '' )
			? array( 'label' => mb_substr( sanitize_text_field( (string) ( $ship['label'] ?? '' ) ), 0, 80 ) ?: 'Shipping', 'amount' => self::money( (float) $ship['amount'] ) )
			: null;
		$discounts = array();
		foreach ( array_slice( (array) ( $in['discounts'] ?? array() ), 0, 10 ) as $d ) {
			$amt = self::money( (float) ( $d['amount'] ?? 0 ) );
			if ( $amt > 0 ) {
				$discounts[] = array( 'label' => mb_substr( sanitize_text_field( (string) ( $d['label'] ?? '' ) ), 0, 80 ) ?: 'Discount', 'amount' => $amt );
			}
		}
		$token = strtolower( wp_generate_password( 16, false, false ) );
		$all   = self::all();
		// 过期的清掉，最多留 MAX 条。
		$all = array_filter( $all, static fn( $c ) => is_array( $c ) && (int) $c['created'] + self::TTL > time() );
		$all[ $token ] = array(
			'token'     => $token,
			'items'     => $items,
			'shipping'  => $shipping,
			'discounts' => $discounts,
			'note'      => mb_substr( sanitize_text_field( (string) ( $in['note'] ?? '' ) ), 0, 200 ),
			'created'   => time(),
			'by'        => get_current_user_id(),
			'orders'    => array(),
		);
		if ( count( $all ) > self::MAX ) {
			$all = array_slice( $all, -self::MAX, null, true );
		}
		update_option( self::OPTION, $all, false );
		return $all[ $token ];
	}

	public static function totals( array $c ): array {
		$sub = 0.0;
		$reg = 0.0;
		foreach ( $c['items'] as $i ) {
			$sub += $i['price'] * $i['qty'];
			$reg += $i['regular'] * $i['qty'];
		}
		$disc = array_sum( array_column( $c['discounts'], 'amount' ) );
		$ship = null !== $c['shipping'] ? (float) $c['shipping']['amount'] : null;
		return array( 'regular' => $reg, 'subtotal' => $sub, 'discount' => $disc, 'shipping' => $ship, 'total' => max( 0, $sub - $disc ) + (float) $ship );
	}

	public static function url( string $token ): string {
		return add_query_arg( self::ARG, $token, home_url( '/' ) );
	}

	// ---------- 顾客打开链接 ----------

	public static function handle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( is_admin() || ! isset( $_GET[ self::ARG ] ) || ! function_exists( 'WC' ) ) {
			return;
		}
		$token = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) wp_unslash( $_GET[ self::ARG ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput
		$c     = '' !== $token ? self::get( $token ) : null;
		if ( null === WC()->cart ) {
			wc_load_cart();
		}
		if ( ! WC()->cart || ! WC()->session ) {
			return;
		}
		nocache_headers();
		if ( ! $c ) {
			wc_add_notice( 'This order link has expired. Please contact us for a new one.', 'error' );
			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}
		WC()->session->set_customer_session_cookie( true );
		WC()->cart->empty_cart();
		WC()->cart->remove_coupons();
		if ( class_exists( Cart_Links::class ) && is_callable( array( Cart_Links::class, 'clear_customer_public' ) ) ) {
			Cart_Links::clear_customer_public();
		}
		foreach ( $c['items'] as $i ) {
			$p = wc_get_product( $i['id'] );
			if ( ! $p ) {
				continue;
			}
			$data = array( 'pc_manual' => $token, 'pc_m_price' => $i['price'], 'pc_m_regular' => $i['regular'] );
			try {
				if ( $p->is_type( 'variation' ) ) {
					WC()->cart->add_to_cart( $p->get_parent_id(), $i['qty'], $p->get_id(), $p->get_variation_attributes(), $data );
				} else {
					WC()->cart->add_to_cart( $p->get_id(), $i['qty'], 0, array(), $data );
				}
			} catch ( \Throwable $e ) { /* 下架或缺货的跳过 */ }
		}
		WC()->cart->calculate_totals();
		wc_clear_notices();
		$keep = array_diff_key( wp_unslash( $_GET ), array( self::ARG => 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput
		$keep = array_filter( array_map( static fn( $v ) => is_scalar( $v ) ? sanitize_text_field( (string) $v ) : '', $keep ), 'strlen' );
		wp_safe_redirect( add_query_arg( $keep, wc_get_checkout_url() ) );
		exit;
	}

	/** 当前购物车是不是手动订单链接来的：返回配置。 */
	public static function active_cart(): ?array {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return null;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! empty( $item['pc_manual'] ) ) {
				return self::get( (string) $item['pc_manual'] );
			}
		}
		return null;
	}

	public static function apply_prices( $cart ): void {
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}
		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item['pc_manual'] ) || ! isset( $item['pc_m_price'] ) || ! $item['data'] instanceof \WC_Product ) {
				continue;
			}
			$price   = (float) $item['pc_m_price'];
			$regular = max( $price, (float) ( $item['pc_m_regular'] ?? $price ) );
			$item['data']->set_regular_price( (string) $regular );
			$item['data']->set_sale_price( $price < $regular ? (string) $price : '' );
			$item['data']->set_price( (string) $price );
		}
	}

	public static function apply_fees( $cart ): void {
		$c = self::active_cart();
		if ( ! $c || ! $cart instanceof \WC_Cart ) {
			return;
		}
		// 不叠加店铺活动：去掉别的优惠费用行，只留店主配的。
		$cart->fees_api()->set_fees( array() );
		foreach ( $c['discounts'] as $n => $d ) {
			$cart->fees_api()->add_fee( array( 'id' => 'pc-manual-' . $n, 'name' => $d['label'], 'amount' => -1 * (float) $d['amount'], 'taxable' => false ) );
		}
	}

	public static function apply_shipping( $rates, $package = array() ) {
		$c = self::active_cart();
		if ( ! $c || null === $c['shipping'] || ! is_array( $rates ) ) {
			return $rates;
		}
		$rate = new \WC_Shipping_Rate( 'pc_manual:' . $c['token'], $c['shipping']['label'], (float) $c['shipping']['amount'], array(), 'pc_manual' );
		return array( $rate->get_id() => $rate );
	}

	public static function block_coupons( $valid, $coupon = null ) {
		return self::active_cart() ? false : $valid;
	}

	public static function tag_order( $order ): void {
		$c = self::active_cart();
		if ( ! $c || ! $order instanceof \WC_Order ) {
			return;
		}
		$order->update_meta_data( self::META, $c['token'] );
		$order->add_order_note( 'Placed from a manual order link (' . $c['token'] . ')' . ( '' !== $c['note'] ? ': ' . $c['note'] : '' ) . '.' );
		$all = self::all();
		if ( isset( $all[ $c['token'] ] ) && $order->get_id() && ! in_array( $order->get_id(), (array) $all[ $c['token'] ]['orders'], true ) ) {
			$all[ $c['token'] ]['orders'][] = $order->get_id();
			update_option( self::OPTION, $all, false );
		}
	}

	// ---------- 后台接口 ----------

	public static function routes(): void {
		$perm = static fn() => current_user_can( 'manage_woocommerce' );
		register_rest_route( 'peptide/v1', '/manual-order/search', array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => array( self::class, 'rest_search' ) ) );
		register_rest_route( 'peptide/v1', '/manual-order/shipping', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => array( self::class, 'rest_shipping' ) ) );
		register_rest_route( 'peptide/v1', '/manual-order', array(
			array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => array( self::class, 'rest_create' ) ),
			array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => array( self::class, 'rest_list' ) ),
		) );
	}

	/** 按店铺运费时，这一单（按改过的售价）大概多少运费：按美国估算，和顾客结账时的算法一样。 */
	public static function rest_shipping( \WP_REST_Request $r ) {
		$contents = array();
		foreach ( array_slice( (array) ( $r->get_json_params()['items'] ?? array() ), 0, 40 ) as $row ) {
			$p   = wc_get_product( absint( $row['id'] ?? 0 ) );
			$qty = max( 1, min( 999, absint( $row['qty'] ?? 1 ) ) );
			if ( $p ) {
				$contents[] = array( 'data' => $p, 'product_id' => $p->get_parent_id() ?: $p->get_id(), 'quantity' => $qty, 'line_subtotal' => self::money( (float) ( $row['price'] ?? $p->get_price() ) ) * $qty );
			}
		}
		$ship = $contents && class_exists( Ship_Preview::class ) ? Ship_Preview::estimate( $contents, 'US' ) : null;
		return rest_ensure_response( array( 'shipping' => $ship ) );
	}

	public static function rest_search( \WP_REST_Request $r ) {
		$q    = sanitize_text_field( (string) $r['q'] );
		$args = array( 'status' => 'publish', 'limit' => 20, 'type' => array( 'simple', 'variable' ), 'orderby' => 'title', 'order' => 'ASC' );
		if ( '' !== $q ) {
			$args['s'] = $q;
		}
		$out = array();
		foreach ( wc_get_products( $args ) as $p ) {
			$img  = (string) wp_get_attachment_image_url( $p->get_image_id(), 'thumbnail' );
			$list = $p->is_type( 'variable' ) ? array_filter( array_map( 'wc_get_product', $p->get_children() ) ) : array( $p );
			foreach ( $list as $v ) {
				if ( ! $v->is_purchasable() ) {
					continue;
				}
				$attrs = $v->is_type( 'variation' ) ? implode( ' / ', array_filter( array_values( $v->get_variation_attributes() ) ) ) : '';
				$out[] = array(
					'id'      => $v->get_id(),
					'name'    => html_entity_decode( $p->get_name(), ENT_QUOTES, 'UTF-8' ) . ( '' !== $attrs ? ' – ' . $attrs : '' ),
					'sku'     => (string) $v->get_sku(),
					'regular' => (float) ( $v->get_regular_price() ?: $v->get_price() ),
					'price'   => (float) $v->get_price(),
					'stock'   => $v->is_in_stock(),
					'image'   => $img,
				);
				if ( count( $out ) >= 60 ) {
					break 2;
				}
			}
		}
		return rest_ensure_response( array( 'items' => $out ) );
	}

	public static function rest_create( \WP_REST_Request $r ) {
		$c = self::create( (array) $r->get_json_params() );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		return rest_ensure_response( array( 'token' => $c['token'], 'url' => self::url( $c['token'] ), 'totals' => self::totals( $c ) ) );
	}

	public static function rest_list() {
		$out = array();
		foreach ( array_reverse( self::all(), true ) as $c ) {
			if ( ! is_array( $c ) || (int) $c['created'] + self::TTL <= time() ) {
				continue;
			}
			$t     = self::totals( $c );
			$out[] = array(
				'url'     => self::url( $c['token'] ),
				'created' => wp_date( 'm-d H:i', (int) $c['created'] ),
				'items'   => implode( ', ', array_map( static fn( $i ) => $i['name'] . ' x' . $i['qty'], $c['items'] ) ),
				'total'   => html_entity_decode( wp_strip_all_tags( wc_price( $t['total'] ) ), ENT_QUOTES, 'UTF-8' ),
				'note'    => $c['note'],
				'orders'  => array_map( static fn( $id ) => array( 'id' => $id, 'url' => admin_url( 'admin.php?page=wc-orders&action=edit&id=' . (int) $id ) ), (array) $c['orders'] ),
			);
			if ( count( $out ) >= 20 ) {
				break;
			}
		}
		return rest_ensure_response( array( 'items' => $out ) );
	}
}
