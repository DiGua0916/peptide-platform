<?php
/**
 * 访客当前购物车快照（VISITOR-CART-01）。后台“实时访客”的“购物车”列和弹窗用它查看客户车里现在有什么。
 *
 * - 来源只有 WooCommerce：购物车写回会话时（woocommerce_cart_updated，经典购物车和 Store API 都会触发）、
 *   清空时（woocommerce_cart_emptied，包括下单后），在请求结束前把当前购物车整理成快照，存到访客行
 *   （visitors.cart / cart_ms / cart_key）。前端不上报价格或商品清单。
 * - 只给有访客 cookie 的非员工请求记录；追踪关闭时不记录。
 * - 状态：active（有商品）/ empty（空车）/ unknown（还没有同步过）/ expired（Woo 会话已过期或已被删除，
 *   快照可能已经不是客户现在的购物车）。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Tracking;

defined( 'ABSPATH' ) || exit;

final class Carts {

	private static bool $dirty = false;

	public static function register_hooks(): void {
		add_action( 'woocommerce_cart_updated', array( self::class, 'mark' ) );
		add_action( 'woocommerce_cart_emptied', array( self::class, 'mark' ) );
		add_action( 'shutdown', array( self::class, 'flush' ), 5 );
	}

	/** 同一请求里购物车可能变化多次，只在请求结束前记一次最终结果。 */
	public static function mark(): void {
		self::$dirty = true;
	}

	public static function flush(): void {
		if ( ! self::$dirty ) {
			return;
		}
		self::$dirty = false;
		if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->session ) {
			return;
		}
		$vid = Tracker::current_vid();
		if ( '' === $vid || Tracker::is_staff( Tracker::cookie_user_id() ) ) {
			return;
		}
		self::save( Tracker::visitor( $vid, Tracker::now_ms() )['id'], WC()->cart, (string) WC()->session->get_customer_id() );
	}

	private static function money( float $amount ): string {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ) );
	}

	/** 把购物车整理成快照（只读展示用的字段）。 */
	public static function snapshot( \WC_Cart $cart ): array {
		$items = array();
		$count = 0;
		foreach ( $cart->get_cart() as $key => $item ) {
			$product = $item['data'] ?? null;
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			$qty   = (float) ( $item['quantity'] ?? 0 );
			$line  = (float) ( $item['line_subtotal'] ?? 0 );
			$image = wp_get_attachment_image_src( (int) $product->get_image_id(), 'thumbnail' );
			$attrs = array();
			if ( $product instanceof \WC_Product_Variation ) {
				foreach ( $product->get_variation_attributes() as $name => $value ) {
					$taxonomy = str_replace( 'attribute_', '', $name );
					$term     = taxonomy_exists( $taxonomy ) ? get_term_by( 'slug', (string) $value, $taxonomy ) : false;
					$attrs[]  = wc_attribute_label( $taxonomy, $product ) . ': ' . ( $term ? $term->name : (string) $value );
				}
			}
			$count  += (int) $qty;
			$items[] = array(
				'key'             => substr( (string) $key, 0, 64 ),
				'product_id'      => (int) ( $item['product_id'] ?? 0 ),
				'variation_id'    => (int) ( $item['variation_id'] ?? 0 ),
				'name'            => html_entity_decode( $product->get_name() ),
				'variation'       => implode( ', ', $attrs ),
				'sku'             => (string) $product->get_sku(),
				'quantity'        => $qty,
				'unit_price'      => $qty > 0 ? round( $line / $qty, 2 ) : 0,
				'unit_price_text' => self::money( $qty > 0 ? $line / $qty : 0 ),
				'line_total'      => round( $line, 2 ),
				'line_total_text' => self::money( $line ),
				'image'           => $image ? (string) $image[0] : '',
			);
		}
		$subtotal = (float) $cart->get_subtotal();
		$discount = (float) $cart->get_discount_total();
		return array(
			'items'         => $items,
			'count'         => $count,
			'subtotal'      => round( $subtotal, 2 ),
			'subtotal_text' => self::money( $subtotal ),
			'discount_text' => $discount > 0 ? self::money( $discount ) : '',
			'coupons'       => array_values( array_map( 'strtoupper', $cart->get_applied_coupons() ) ),
			'currency'      => get_woocommerce_currency(),
		);
	}

	public static function save( int $visitor_id, \WC_Cart $cart, string $session_key ): void {
		global $wpdb;
		if ( $visitor_id <= 0 ) {
			return;
		}
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'visitors' ),
			array(
				'cart'     => wp_json_encode( self::snapshot( $cart ) ),
				'cart_ms'  => Tracker::now_ms(),
				'cart_key' => substr( $session_key, 0, 64 ),
			),
			array( 'id' => $visitor_id )
		);
	}

	/** Woo 会话还在不在（过期或被删除时，快照可能已经不是客户现在的购物车）。 */
	private static function session_alive( string $key ): bool {
		global $wpdb;
		if ( '' === $key ) {
			return false;
		}
		$expiry = $wpdb->get_var( $wpdb->prepare( "SELECT session_expiry FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key = %s", $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return null !== $expiry && (int) $expiry > time();
	}

	/**
	 * 一位访客的购物车：状态 + 快照。$row 是访客行（含 cart / cart_ms / cart_key）。
	 */
	public static function view( array $row, bool $with_items ): array {
		$snap = isset( $row['cart'] ) && '' !== (string) $row['cart'] ? json_decode( (string) $row['cart'], true ) : null;
		$ms   = (int) ( $row['cart_ms'] ?? 0 );
		if ( ! is_array( $snap ) || ! $ms ) {
			return array( 'status' => 'unknown', 'count' => 0, 'subtotal_text' => '', 'updated_ms' => 0 );
		}
		$status = $snap['count'] > 0 ? ( self::session_alive( (string) ( $row['cart_key'] ?? '' ) ) ? 'active' : 'expired' ) : 'empty';
		$out    = array(
			'status'        => $status,
			'count'         => (int) $snap['count'],
			'subtotal_text' => (string) $snap['subtotal_text'],
			'updated_ms'    => $ms,
		);
		if ( $with_items ) {
			$out += array(
				'items'         => $snap['items'],
				'subtotal'      => $snap['subtotal'],
				'discount_text' => (string) ( $snap['discount_text'] ?? '' ),
				'coupons'       => (array) ( $snap['coupons'] ?? array() ),
				'currency'      => (string) ( $snap['currency'] ?? '' ),
			);
		}
		return $out;
	}

	/** 后台弹窗：一位访客的购物车明细；访客不存在时返回 null。 */
	public static function detail( int $visitor_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, cart, cart_ms, cart_key FROM ' . Schema::table( 'visitors' ) . ' WHERE id = %d', $visitor_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		return $row ? array( 'visitor_id' => $visitor_id ) + self::view( $row, true ) : null;
	}
}
