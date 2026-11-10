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
	private static string $allow_code = '';
	public const ARG      = 'pc_manual';
	public const META     = '_pc_manual_link';
	private const TTL     = 30 * DAY_IN_SECONDS; // 默认有效期；店主可以按单选（DAYS）。
	public const DAYS     = array( 1, 3, 7, 14, 30, 60, 90, 180, 365 );

	/** 这条链接什么时候过期（旧链接没有 expires 就按创建 + 30 天）。 */
	public static function expires_at( array $c ): int {
		return (int) ( $c['expires'] ?? ( (int) $c['created'] + self::TTL ) );
	}
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
		return is_array( $c ) && self::expires_at( $c ) > time() ? $c : null;
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
		$all = array_filter( $all, static fn( $c ) => is_array( $c ) && self::expires_at( $c ) > time() );
		$days = in_array( (int) ( $in['days'] ?? 30 ), self::DAYS, true ) ? (int) $in['days'] : 30;
		$promo_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $in['promo_ids'] ?? array() ) ) ) ) );
		$all[ $token ] = array(
			'token'     => $token,
			'items'     => $items,
			'shipping'  => $shipping,
			'discounts' => $discounts,
			'note'      => mb_substr( sanitize_text_field( (string) ( $in['note'] ?? '' ) ), 0, 200 ),
			// 店主 2026-11-11：店铺满减活动、首单优惠可以选开，开了链接就自动带上，顾客直接付款。
			'promo_ids'    => $promo_ids, // 店主 2026-11-11：店铺可能有多个活动，按单选用哪些。
			'store_promos' => (bool) $promo_ids,
			'first_order'  => ! empty( $in['first_order'] ),
			'created'   => time(),
			'expires'   => time() + $days * DAY_IN_SECONDS,
			'days'      => $days,
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
		if ( ! empty( $c['first_order'] ) && ( $fc = self::first_order_coupon() ) ) {
			self::$allow_code = wc_format_coupon_code( $fc->get_code() );
			WC()->cart->apply_coupon( $fc->get_code() );
			self::$allow_code = '';
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
		// 不叠加店铺活动：去掉别的优惠费用行，只留店主配的；店主在这一单开了“店铺满减活动”就保留活动的优惠。
		$keep = array();
		if ( ! empty( $c['store_promos'] ) ) {
			$want = array_map( 'intval', (array) ( $c['promo_ids'] ?? array() ) );
			foreach ( $cart->fees_api()->get_fees() as $id => $fee ) {
				// 只留店主在这一单选了的活动（旧链接没选具体活动的，全部保留）。
				if ( preg_match( '/^pc-promo-(\d+)$/', (string) $id, $m ) && ( ! $want || in_array( (int) $m[1], $want, true ) ) ) {
					$keep[ $id ] = $fee;
				}
			}
		}
		$cart->fees_api()->set_fees( $keep );
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
		$c = self::active_cart();
		if ( ! $c && '' === self::$allow_code ) {
			return $valid;
		}
		$code = $coupon instanceof \WC_Coupon ? wc_format_coupon_code( $coupon->get_code() ) : '';
		$fc   = self::first_order_coupon();
		$first_ok = $fc && $code === wc_format_coupon_code( $fc->get_code() ) && ( '' !== self::$allow_code || ! empty( $c['first_order'] ) );
		return $first_ok ? $valid : false;
	}

	/** 店铺当前生效的首单优惠券（首单优惠功能开着才有）。 */
	public static function first_order_coupon(): ?\WC_Coupon {
		return class_exists( First_Order_Offer::class ) ? First_Order_Offer::coupon() : null;
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

	// ---------- 草稿（店主 2026-11-11：做到一半要出去看别的信息，先存起来） ----------

	public const DRAFTS = 'pc_manual_drafts';

	public static function drafts(): array {
		$v = get_option( self::DRAFTS, array() );
		return is_array( $v ) ? $v : array();
	}

	/** 草稿原样存页面上的状态（商品行带名称、原价、售价），打开时直接还原。 */
	public static function save_draft( array $in ) {
		$state = is_array( $in['state'] ?? null ) ? $in['state'] : array();
		$lines = array();
		foreach ( array_slice( (array) ( $state['lines'] ?? array() ), 0, 40 ) as $l ) {
			$id = absint( $l['id'] ?? 0 );
			if ( $id ) {
				$lines[] = array( 'id' => $id, 'name' => mb_substr( sanitize_text_field( (string) ( $l['name'] ?? '' ) ), 0, 200 ), 'qty' => max( 1, min( 999, absint( $l['qty'] ?? 1 ) ) ), 'regular' => self::money( (float) ( $l['regular'] ?? 0 ) ), 'price' => self::money( (float) ( $l['price'] ?? 0 ) ) );
			}
		}
		$disc = array();
		foreach ( array_slice( (array) ( $state['discounts'] ?? array() ), 0, 10 ) as $d ) {
			$disc[] = array( 'label' => mb_substr( sanitize_text_field( (string) ( $d['label'] ?? '' ) ), 0, 80 ), 'amount' => self::money( (float) ( $d['amount'] ?? 0 ) ) );
		}
		$clean = array(
			'lines'        => $lines,
			'discounts'    => $disc,
			'ship_mode'    => 'custom' === ( $state['ship_mode'] ?? '' ) ? 'custom' : 'store',
			'ship_label'   => mb_substr( sanitize_text_field( (string) ( $state['ship_label'] ?? 'Shipping' ) ), 0, 80 ),
			'ship_amount'  => self::money( (float) ( $state['ship_amount'] ?? 0 ) ),
			'note'         => mb_substr( sanitize_text_field( (string) ( $state['note'] ?? '' ) ), 0, 200 ),
			'promo_ids'    => array_values( array_filter( array_map( 'absint', (array) ( $state['promo_ids'] ?? array() ) ) ) ),
			'days'         => in_array( (int) ( $state['days'] ?? 30 ), self::DAYS, true ) ? (int) $state['days'] : 30,
			'store_promos' => ! empty( $state['promo_ids'] ),
			'first_order'  => ! empty( $state['first_order'] ),
		);
		if ( ! $lines && '' === $clean['note'] ) {
			return new \WP_Error( 'pc_manual_draft_empty', '还没有内容，不用保存。', array( 'status' => 400 ) );
		}
		$all = self::drafts();
		$id  = ! empty( $in['as_new'] ) ? '' : sanitize_key( (string) ( $in['id'] ?? '' ) );
		$id  = '' !== $id && isset( $all[ $id ] ) ? $id : 'd' . strtolower( wp_generate_password( 10, false, false ) );
		$all[ $id ] = array( 'id' => $id, 'state' => $clean, 'saved' => time(), 'by' => get_current_user_id() );
		if ( count( $all ) > 50 ) {
			uasort( $all, static fn( $a, $b ) => $a['saved'] <=> $b['saved'] );
			$all = array_slice( $all, -50, null, true );
		}
		update_option( self::DRAFTS, $all, false );
		return $all[ $id ];
	}

	public static function draft_summary( array $d ): array {
		$st  = $d['state'];
		$sub = 0.0;
		foreach ( $st['lines'] as $l ) {
			$sub += $l['price'] * $l['qty'];
		}
		return array(
			'id'    => $d['id'],
			'saved' => wp_date( 'm-d H:i', (int) $d['saved'] ),
			'title' => '' !== $st['note'] ? $st['note'] : ( $st['lines'] ? $st['lines'][0]['name'] . ( count( $st['lines'] ) > 1 ? ' 等 ' . count( $st['lines'] ) . ' 件商品' : '' ) : '空草稿' ),
			'items' => implode( ', ', array_map( static fn( $l ) => $l['name'] . ' x' . $l['qty'], $st['lines'] ) ),
			'sub'   => html_entity_decode( wp_strip_all_tags( wc_price( $sub ) ), ENT_QUOTES, 'UTF-8' ),
		);
	}

	// ---------- 按弃单恢复（店主 2026-11-11：弃单里一点，就把原订单的商品和价格带进手动订单） ----------

	private static function line_from( \WC_Product $p, int $qty, float $line_subtotal ): array {
		$price = $qty ? self::money( $line_subtotal / $qty ) : (float) $p->get_price();
		$reg   = (float) ( $p->get_regular_price() ?: $p->get_price() );
		return array( 'id' => $p->get_id(), 'name' => html_entity_decode( wp_strip_all_tags( $p->get_name() ), ENT_QUOTES, 'UTF-8' ), 'qty' => max( 1, $qty ), 'regular' => max( $reg, $price ), 'price' => $price );
	}

	/** 原订单 → 手动订单页面状态：商品、数量、当时的价格；用过首单券就开首单优惠，有满减就开店铺活动，其他优惠码算一笔优惠。 */
	public static function state_from_order( \WC_Order $o ): array {
		$lines = array();
		foreach ( $o->get_items() as $it ) {
			$p = $it->get_product();
			if ( $p instanceof \WC_Product ) {
				$lines[] = self::line_from( $p, (int) $it->get_quantity(), (float) $it->get_subtotal() );
			}
		}
		$fc    = self::first_order_coupon();
		$first = false;
		$disc  = array();
		foreach ( $o->get_items( 'coupon' ) as $ci ) {
			$code = wc_format_coupon_code( $ci->get_code() );
			if ( $fc && wc_format_coupon_code( $fc->get_code() ) === $code ) {
				$first = true;
			} elseif ( (float) $ci->get_discount() > 0 ) {
				$disc[] = array( 'label' => 'Coupon ' . strtoupper( $code ), 'amount' => self::money( (float) $ci->get_discount() ) );
			}
		}
		$promos    = false;
		$promo_ids = array();
		foreach ( $o->get_fees() as $fee ) {
			if ( (float) $fee->get_total() < 0 ) {
				$promos = true;
				$pid    = class_exists( Promotions::class ) ? (int) $fee->get_meta( Promotions::FEE_META ) : 0;
				if ( $pid ) {
					$promo_ids[] = $pid;
				}
			}
		}
		if ( $promos && ! $promo_ids && class_exists( Promotions::class ) ) {
			$promo_ids = array_map( 'intval', Promotions::active_ids() );
		}
		$name = trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() );
		return array( 'lines' => $lines, 'discounts' => $disc, 'ship_mode' => 'store', 'ship_label' => 'Shipping', 'ship_amount' => 0, 'note' => '按弃单 #' . $o->get_order_number() . ( '' !== $name ? ' 恢复 · ' . $name : ' 恢复' ), 'store_promos' => $promos, 'promo_ids' => array_values( array_unique( $promo_ids ) ), 'first_order' => $first );
	}

	/** 没下单的结账（弃单记录里的购物车）→ 手动订单页面状态。 */
	public static function state_from_record( array $r ): array {
		$lines = array();
		foreach ( (array) json_decode( (string) $r['cart'], true ) as $it ) {
			$p = wc_get_product( absint( $it['variation_id'] ?? 0 ) ?: absint( $it['product_id'] ?? 0 ) );
			if ( $p instanceof \WC_Product ) {
				$lines[] = self::line_from( $p, (int) ( $it['quantity'] ?? 1 ), (float) ( $it['line_subtotal'] ?? 0 ) );
			}
		}
		// 当时结账用的优惠码、满减：从顾客的购物会话里读（会话过期了就读不到，只带商品）。
		$first  = false;
		$promos = false;
		$disc   = array();
		global $wpdb;
		$raw = (string) $wpdb->get_var( $wpdb->prepare( "SELECT session_value FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key = %s", (string) $r['session_key'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( '' !== $raw ) {
			$sess = maybe_unserialize( $raw );
			$fc   = self::first_order_coupon();
			foreach ( (array) maybe_unserialize( $sess['applied_coupons'] ?? '' ) as $code ) {
				$code = wc_format_coupon_code( (string) $code );
				if ( $fc && wc_format_coupon_code( $fc->get_code() ) === $code ) {
					$first = true;
				}
			}
			$totals = (array) maybe_unserialize( $sess['cart_totals'] ?? '' );
			$promos = (float) ( $totals['fee_total'] ?? 0 ) < 0;
		}
		return array( 'lines' => $lines, 'discounts' => $disc, 'ship_mode' => 'store', 'ship_label' => 'Shipping', 'ship_amount' => 0, 'note' => '按弃单恢复 · ' . trim( (string) $r['name'] ?: (string) $r['email'] ), 'store_promos' => $promos, 'promo_ids' => $promos && class_exists( Promotions::class ) ? array_map( 'intval', Promotions::active_ids() ) : array(), 'first_order' => $first );
	}

	// ---------- 后台接口 ----------

	public static function routes(): void {
		$perm = static fn() => current_user_can( 'manage_woocommerce' );
		register_rest_route( 'peptide/v1', '/manual-order/search', array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => array( self::class, 'rest_search' ) ) );
		register_rest_route( 'peptide/v1', '/manual-order/drafts', array(
			array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => static fn() => rest_ensure_response( array( 'items' => array_values( array_map( array( self::class, 'draft_summary' ), array_reverse( self::drafts(), true ) ) ) ) ) ),
			array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => static function ( \WP_REST_Request $r ) {
				$d = self::save_draft( (array) $r->get_json_params() );
				return is_wp_error( $d ) ? $d : rest_ensure_response( array( 'id' => $d['id'], 'saved' => wp_date( 'H:i', (int) $d['saved'] ) ) );
			} ),
		) );
		register_rest_route( 'peptide/v1', '/manual-order/drafts/(?P<id>[a-z0-9]+)', array(
			array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => static function ( \WP_REST_Request $r ) {
				$d = self::drafts()[ (string) $r['id'] ] ?? null;
				return $d ? rest_ensure_response( array( 'id' => $d['id'], 'state' => $d['state'] ) ) : new \WP_Error( 'pc_manual_draft', '草稿不存在。', array( 'status' => 404 ) );
			} ),
			array( 'methods' => 'DELETE', 'permission_callback' => $perm, 'callback' => static function ( \WP_REST_Request $r ) {
				$all = self::drafts();
				unset( $all[ (string) $r['id'] ] );
				update_option( self::DRAFTS, $all, false );
				return rest_ensure_response( array( 'deleted' => true ) );
			} ),
		) );
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
		// 店铺满减活动：按这一单（改过的价格）算能减多少；首单优惠：券的比例。
		$promos = array();
		if ( $contents && class_exists( Promotions::class ) && class_exists( '\WC_Cart' ) ) {
			try {
				// 只用来算活动的空购物车：不跑 WC_Cart 的构造（不挂会话、不在请求结束时保存购物车）。
				$cart = new class() extends \WC_Cart {
					public function __construct() {} // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod
					public function get_cart() {
						return array_filter( (array) $this->cart_contents );
					}
				};
				$rows = array();
				foreach ( $contents as $n => $row ) {
					$row['data']        = clone $row['data'];
					$row['data']->set_price( (string) ( $row['line_subtotal'] / max( 1, $row['quantity'] ) ) );
					$row['line_total']  = $row['line_subtotal'];
					$rows[ 'pc' . $n ]  = $row;
				}
				$cart->set_cart_contents( $rows );
				// 活动有“顾客规则”：在后台算的时候当前用户是店主，要按普通顾客（没登录）来算。
				$admin_id = get_current_user_id();
				wp_set_current_user( 0 );
				try {
					foreach ( (array) ( Promotions::discounts( $cart )['applied'] ?? array() ) as $a ) {
						$promos[] = array( 'id' => (int) $a['id'], 'title' => (string) $a['title'], 'discount' => (float) $a['discount'] );
					}
				} finally {
					wp_set_current_user( $admin_id );
				}
			} catch ( \Throwable $e ) { /* 预览算不出来不影响生成链接 */ }
		}
		$active = array();
		if ( class_exists( Promotions::class ) ) {
			foreach ( Promotions::active_ids() as $pid ) {
				$rules    = Promotions::rules_text( Promotions::config( (int) $pid ) );
				$active[] = array( 'id' => (int) $pid, 'title' => Promotions::customer_title( html_entity_decode( get_the_title( $pid ) ) ), 'rules' => implode( '；', array_slice( (array) $rules, 0, 3 ) ) );
			}
		}
		$fc    = self::first_order_coupon();
		$first = $fc ? array( 'code' => strtoupper( $fc->get_code() ), 'percent' => (float) $fc->get_amount() ) : null;
		return rest_ensure_response( array( 'shipping' => $ship, 'promos' => $promos, 'active_promos' => $active, 'first' => $first ) );
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
		return rest_ensure_response( array( 'token' => $c['token'], 'url' => self::url( $c['token'] ), 'totals' => self::totals( $c ), 'expires' => wp_date( 'Y-m-d H:i', self::expires_at( $c ) ) ) );
	}

	public static function rest_list() {
		$out = array();
		foreach ( array_reverse( self::all(), true ) as $c ) {
			if ( ! is_array( $c ) || self::expires_at( $c ) <= time() ) {
				continue;
			}
			$t     = self::totals( $c );
			$out[] = array(
				'url'     => self::url( $c['token'] ),
				'created' => wp_date( 'm-d H:i', (int) $c['created'] ),
				'items'   => implode( ', ', array_map( static fn( $i ) => $i['name'] . ' x' . $i['qty'], $c['items'] ) ),
				'total'   => html_entity_decode( wp_strip_all_tags( wc_price( $t['total'] ) ), ENT_QUOTES, 'UTF-8' ),
				'note'    => $c['note'],
				'expires' => wp_date( 'm-d', self::expires_at( $c ) ),
				'orders'  => array_map( static fn( $id ) => array( 'id' => $id, 'url' => admin_url( 'admin.php?page=wc-orders&action=edit&id=' . (int) $id ) ), (array) $c['orders'] ),
			);
			if ( count( $out ) >= 20 ) {
				break;
			}
		}
		return rest_ensure_response( array( 'items' => $out ) );
	}
}
