<?php
/**
 * 访客当前购物车（VISITOR-CART-01）。后台“实时访客”的“购物车”列和弹窗用它查看客户车里现在有什么。
 *
 * 唯一来源是 WooCommerce 会话（woocommerce_sessions 表）：
 * - 绑定：前台请求里 Woo 已经校验过的会话编号（WC()->session->get_customer_id()）记到访客行的 cart_key。
 *   购物车从会话载入、改动、清空时都会更新绑定（woocommerce_cart_loaded_from_session / cart_updated / cart_emptied，
 *   请求结束前统一写一次）；游客登录后 Woo 把会话迁到账号（woocommerce_guest_session_to_user_id），绑定跟着改。
 *   绑定只来自服务器端的 Woo 会话，不读取浏览器传来的会话 cookie。
 * - 读取：后台查看时按绑定（访客关联了账号时也试账号的会话）直接读 Woo 会话里的购物车，
 *   所以同一个会话被两个访客编号共用时，看到的都是同一份当前购物车，不会各留一份旧副本。
 * - 会话已过期或被删除：退回最后一次在请求结束时保存的快照，状态为 expired（只能当作历史参考）。
 * - 状态：active（有商品）/ empty（空车）/ unknown（还没有绑定过 Woo 会话）/ expired。
 * - 金额口径：小计是 WooCommerce 的 get_subtotal()，税前、优惠码前、不含运费，不是最终应付金额。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Tracking;

defined( 'ABSPATH' ) || exit;

final class Carts {

	private static bool $dirty = false;

	/** @var array<string,array|null> 本次请求里读过的会话（键 => 解析后的会话，null 表示不存在或已过期）。 */
	private static array $sessions = array();

	public static function register_hooks(): void {
		add_action( 'woocommerce_cart_loaded_from_session', array( self::class, 'mark' ) );
		add_action( 'woocommerce_cart_updated', array( self::class, 'mark' ) );
		add_action( 'woocommerce_cart_emptied', array( self::class, 'mark' ) );
		add_action( 'woocommerce_guest_session_to_user_id', array( self::class, 'migrate' ), 10, 2 );
		add_action( 'shutdown', array( self::class, 'flush' ), 5 );
	}

	/** 同一请求里购物车可能变化多次，只在请求结束前记一次最终结果。 */
	public static function mark(): void {
		self::$dirty = true;
	}

	/** 清空本次请求里缓存的会话读取结果（测试、或同一请求里会话刚被改动时用）。 */
	public static function forget(): void {
		self::$sessions = array();
	}

	/** 游客登录：Woo 把游客会话迁到账号会话，原来绑定游客会话的访客改绑账号会话。 */
	public static function migrate( $guest_id, $user_id ): void {
		global $wpdb;
		$guest = substr( (string) $guest_id, 0, 64 );
		$user  = substr( (string) $user_id, 0, 64 );
		if ( '' === $guest || '' === $user ) {
			return;
		}
		$wpdb->update( Schema::table( 'visitors' ), array( 'cart_key' => $user ), array( 'cart_key' => $guest ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
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
		// 每次页面载入都会触发“从会话载入购物车”：没有访客行、车也是空的时不建行（避免爬虫等每次请求都写库）。
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'visitors' ) . ' WHERE vid = %s', $vid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( ! $id ) {
			if ( WC()->cart->is_empty() ) {
				return;
			}
			$id = Tracker::visitor( $vid, Tracker::now_ms() )['id'];
		}
		self::save( $id, WC()->cart, (string) WC()->session->get_customer_id() );
	}

	private static function money( float $amount ): string {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ) );
	}

	/**
	 * 一行商品的展示字段。$item 是 Woo 购物车行（WC_Cart 的行或会话里保存的行），规格取客户实际选择的值。
	 */
	private static function line( string $key, array $item ): ?array {
		$product_id   = (int) ( $item['product_id'] ?? 0 );
		$variation_id = (int) ( $item['variation_id'] ?? 0 );
		$product      = $item['data'] ?? null;
		if ( ! $product instanceof \WC_Product ) {
			$product = wc_get_product( $variation_id ? $variation_id : $product_id );
		}
		if ( ! $product instanceof \WC_Product ) {
			return null;
		}
		$attrs = array();
		foreach ( (array) ( $item['variation'] ?? array() ) as $name => $value ) {
			$taxonomy = str_replace( 'attribute_', '', (string) $name );
			$term     = taxonomy_exists( $taxonomy ) ? get_term_by( 'slug', (string) $value, $taxonomy ) : false;
			$attrs[]  = wc_attribute_label( $taxonomy, $product ) . ': ' . ( $term ? $term->name : (string) $value );
		}
		$qty   = (float) ( $item['quantity'] ?? 0 );
		$total = (float) ( $item['line_subtotal'] ?? 0 );
		$image = wp_get_attachment_image_src( (int) $product->get_image_id(), 'thumbnail' );
		return array(
			'key'             => substr( $key, 0, 64 ),
			'product_id'      => $product_id,
			'variation_id'    => $variation_id,
			'name'            => html_entity_decode( $product->get_name() ),
			'variation'       => implode( ', ', $attrs ),
			'sku'             => (string) $product->get_sku(),
			'quantity'        => $qty,
			'unit_price'      => $qty > 0 ? round( $total / $qty, 2 ) : 0,
			'unit_price_text' => self::money( $qty > 0 ? $total / $qty : 0 ),
			'line_total'      => round( $total, 2 ),
			'line_total_text' => self::money( $total ),
			'image'           => $image ? (string) $image[0] : '',
		);
	}

	private static function summary( array $items, float $subtotal, float $discount, array $coupons ): array {
		$count = 0;
		foreach ( $items as $i ) {
			$count += (int) $i['quantity'];
		}
		return array(
			'items'         => $items,
			'count'         => $count,
			'subtotal'      => round( $subtotal, 2 ),
			'subtotal_text' => self::money( $subtotal ),
			'discount_text' => $discount > 0 ? self::money( $discount ) : '',
			'coupons'       => array_values( array_map( 'strtoupper', $coupons ) ),
			'currency'      => get_woocommerce_currency(),
		);
	}

	/** 把一个 WC_Cart 整理成快照。 */
	public static function snapshot( \WC_Cart $cart ): array {
		$items = array();
		foreach ( $cart->get_cart() as $key => $item ) {
			$line = self::line( (string) $key, (array) $item );
			if ( $line ) {
				$items[] = $line;
			}
		}
		return self::summary( $items, (float) $cart->get_subtotal(), (float) $cart->get_discount_total(), $cart->get_applied_coupons() );
	}

	/** 请求结束时：记下这位访客绑定的 Woo 会话，并保存一份快照（会话过期后作为历史参考）。内容没变时不写库。 */
	public static function save( int $visitor_id, \WC_Cart $cart, string $session_key ): void {
		global $wpdb;
		if ( $visitor_id <= 0 ) {
			return;
		}
		$table = Schema::table( 'visitors' );
		$json  = wp_json_encode( self::snapshot( $cart ) );
		$key   = substr( $session_key, 0, 64 );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT cart, cart_key FROM {$table} WHERE id = %d", $visitor_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( $row && $row['cart'] === $json && $row['cart_key'] === $key ) {
			return;
		}
		$wpdb->update( $table, array( 'cart' => $json, 'cart_ms' => Tracker::now_ms(), 'cart_key' => $key ), array( 'id' => $visitor_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** 读一个仍然有效的 Woo 会话里的购物车；不存在或已过期时返回 null。 */
	private static function session_cart( string $key ): ?array {
		global $wpdb;
		if ( '' === $key ) {
			return null;
		}
		if ( array_key_exists( $key, self::$sessions ) ) {
			return self::$sessions[ $key ];
		}
		$row  = $wpdb->get_row( $wpdb->prepare( "SELECT session_value, session_expiry FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key = %s", $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$cart = null;
		if ( $row && (int) $row['session_expiry'] > time() ) {
			$data   = maybe_unserialize( $row['session_value'] );
			$data   = is_array( $data ) ? $data : array();
			$lines  = maybe_unserialize( $data['cart'] ?? '' );
			$totals = maybe_unserialize( $data['cart_totals'] ?? '' );
			$codes  = maybe_unserialize( $data['applied_coupons'] ?? '' );
			$items  = array();
			foreach ( is_array( $lines ) ? $lines : array() as $k => $item ) {
				$line = is_array( $item ) ? self::line( (string) $k, $item ) : null;
				if ( $line ) {
					$items[] = $line;
				}
			}
			$subtotal = is_array( $totals ) && isset( $totals['subtotal'] ) ? (float) $totals['subtotal'] : array_sum( array_column( $items, 'line_total' ) );
			$discount = is_array( $totals ) ? (float) ( $totals['discount_total'] ?? 0 ) : 0.0;
			$cart     = self::summary( $items, $subtotal, $discount, is_array( $codes ) ? $codes : array() );
		}
		self::$sessions[ $key ] = $cart;
		return $cart;
	}

	/**
	 * 访客当前对应的有效 Woo 会话键：先用这个浏览器最后绑定的会话；它已失效且访客关联了账号时，再试账号的会话
	 * （登录顾客的 Woo 会话键就是账号 ID）。
	 */
	private static function live_key( array $row ): string {
		foreach ( array( (string) ( $row['cart_key'] ?? '' ), (int) ( $row['user_id'] ?? 0 ) > 0 ? (string) $row['user_id'] : '' ) as $key ) {
			if ( '' !== $key && null !== self::session_cart( $key ) ) {
				return $key;
			}
		}
		return '';
	}

	/** 同一个会话最后一次观察到变化的时间（多个访客编号共用会话时取最新的）。 */
	private static function updated_ms( string $key, int $fallback ): int {
		global $wpdb;
		$ms = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(cart_ms) FROM ' . Schema::table( 'visitors' ) . ' WHERE cart_key = %s', $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		return max( $ms, $fallback );
	}

	/**
	 * 一位访客的购物车：状态 + 内容。$row 是访客行（含 user_id / cart / cart_ms / cart_key）。
	 */
	public static function view( array $row, bool $with_items ): array {
		$key = self::live_key( $row );
		if ( '' !== $key ) {
			$cart   = self::session_cart( $key );
			$status = $cart['count'] > 0 ? 'active' : 'empty';
			$ms     = self::updated_ms( $key, 0 );
		} else {
			$cart = isset( $row['cart'] ) && '' !== (string) $row['cart'] ? json_decode( (string) $row['cart'], true ) : null;
			$ms   = (int) ( $row['cart_ms'] ?? 0 );
			if ( ! is_array( $cart ) ) {
				return array( 'status' => 'unknown', 'count' => 0, 'subtotal_text' => '', 'updated_ms' => 0 ) + ( $with_items ? array( 'items' => array() ) : array() );
			}
			// 绑定的会话已经不在了：快照只能当作历史参考。
			$status = (int) $cart['count'] > 0 ? 'expired' : 'empty';
		}
		$out = array(
			'status'        => $status,
			'count'         => (int) $cart['count'],
			'subtotal_text' => (string) $cart['subtotal_text'],
			'updated_ms'    => $ms,
		);
		if ( $with_items ) {
			$out += array(
				'items'         => $cart['items'],
				'subtotal'      => $cart['subtotal'],
				'discount_text' => (string) ( $cart['discount_text'] ?? '' ),
				'coupons'       => (array) ( $cart['coupons'] ?? array() ),
				'currency'      => (string) ( $cart['currency'] ?? '' ),
			);
		}
		return $out;
	}

	/** 后台弹窗：一位访客的购物车明细；访客不存在时返回 null。 */
	public static function detail( int $visitor_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, user_id, cart, cart_ms, cart_key FROM ' . Schema::table( 'visitors' ) . ' WHERE id = %d', $visitor_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		return $row ? array( 'visitor_id' => $visitor_id ) + self::view( $row, true ) : null;
	}
}
