<?php
/**
 * M4 对照测试的运行环境（不连 WordPress、不连数据库）。
 *
 * - WordPress / WooCommerce 的函数和数据对象是内存里的替身；
 * - 购物车金额算法用 m4-wc-math.php（WooCommerce 源码的精简副本），或者设置 PC_WC_SRC 后用真的 WooCommerce 源文件；
 * - 旧逻辑是真的：Promotions、Coupon_Rules、Loyalty、First_Order_Offer、Product_Offers、Shipping\Discounts
 *   按线上相同的钩子和优先级挂上去，由替身购物车的 calculate_totals() 触发；
 * - 新引擎也是真的：Cart_Reader::read() 读这份购物车，Engine::price() 计算。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Activity {
	/** 加购推荐的配置（真的类要读很多站点设置，这里只给引擎需要的那一项）。 */
	final class Store {
		public static function effective(): array {
			return (array) ( $GLOBALS['m4']['activity'] ?? array() );
		}
	}
}

namespace {
	if ( 'cli' !== PHP_SAPI ) {
		exit( 2 );
	}
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'WC_ROUNDING_PRECISION', 6 );
	define( 'WC_DISCOUNT_ROUNDING_MODE', 2 );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'ARRAY_A', 'ARRAY_A' );
	define( 'HOUR_IN_SECONDS', 3600 );

	function m4_reset(): void {
		$GLOBALS['m4'] = array(
			'options'  => array(),
			'posts'    => array(),
			'products' => array(),
			'coupons'  => array(),
			'hooks'    => array(),
			'did'      => array(),
			'user'     => 0,
			'users'    => array(),
			'orders'   => array(),
			'ledger'   => array(),
			'rates'    => array(),
			'chosen'   => null,
			'packages' => array(),
			'session'  => array(),
			'billing'  => '',
			'activity' => array(),
			'uuid'     => 0,
			'transients' => array(),
			'dbdelta'  => array(),
			'throw'    => '',
		);
		if ( isset( $GLOBALS['wpdb'] ) ) {
			$GLOBALS['wpdb']->writes    = array();
			$GLOBALS['wpdb']->insert_id = 0;
		}
		\PeptideCore\Pricing\Runtime::reset();
		\PeptideCore\Pricing\Shadow::reset();
		// 线上的钩子和优先级（见各类的 register_hooks）。
		add_filter( 'woocommerce_add_cart_item_data', array( \PeptideCore\Product_Offers::class, 'cart_data' ), 1000, 4 );
		add_action( 'woocommerce_before_calculate_totals', array( \PeptideCore\Product_Offers::class, 'sync_cart' ), 100 );
		add_filter( 'woocommerce_coupon_is_valid', array( \PeptideCore\Coupon_Rules::class, 'validate' ), 10, 3 );
		add_filter( 'woocommerce_coupon_is_valid', array( \PeptideCore\Loyalty::class, 'validate_coupon' ), 20, 3 );
		add_filter( 'woocommerce_coupon_is_valid', array( \PeptideCore\Product_Offers::class, 'coupon_valid' ), 30, 3 );
		add_filter( 'woocommerce_coupon_is_valid', array( \PeptideCore\First_Order_Offer::class, 'validate_stack' ), 30, 3 );
		add_filter( 'woocommerce_coupon_is_valid_for_product', array( \PeptideCore\Product_Offers::class, 'coupon_product_valid' ), 30, 4 );
		add_action( 'woocommerce_cart_calculate_fees', array( \PeptideCore\Promotions::class, 'apply_to_cart' ), 20 );
		add_filter( 'woocommerce_package_rates', array( \PeptideCore\Shipping\Discounts::class, 'apply' ), 120, 2 );
		add_filter( 'woocommerce_cart_shipping_packages', array( \PeptideCore\Shipping\Discounts::class, 'tag_packages' ), 20 );
	}

	// ---------- 钩子 ----------
	function add_filter( $tag, $fn, $priority = 10, $args = 1 ) {
		$GLOBALS['m4']['hooks'][ $tag ][ $priority ][] = array( $fn, $args );
		return true;
	}
	function add_action( $tag, $fn, $priority = 10, $args = 1 ) {
		return add_filter( $tag, $fn, $priority, $args );
	}
	function has_filter( $tag, $fn = false ) {
		return ! empty( $GLOBALS['m4']['hooks'][ $tag ] );
	}
	function has_action( $tag, $fn = false ) {
		return has_filter( $tag );
	}
	function did_action( $tag ) {
		return (int) ( $GLOBALS['m4']['did'][ $tag ] ?? 0 );
	}
	function m4_run( string $tag, array $args, bool $filter ) {
		$hooks = $GLOBALS['m4']['hooks'][ $tag ] ?? array();
		ksort( $hooks );
		foreach ( $hooks as $list ) {
			foreach ( $list as [ $fn, $n ] ) {
				$r = $fn( ...array_slice( $args, 0, $n ) );
				if ( $filter ) {
					$args[0] = $r;
				}
			}
		}
		return $args[0] ?? null;
	}
	function apply_filters( $tag, $value, ...$args ) {
		return m4_run( $tag, array_merge( array( $value ), $args ), true );
	}
	function do_action( $tag, ...$args ) {
		$GLOBALS['m4']['did'][ $tag ] = did_action( $tag ) + 1;
		m4_run( $tag, $args, false );
	}

	// ---------- WordPress ----------
	final class WP_Error {
		public function __construct( public string $code = '', public string $message = '', public $data = null ) {}
		public function get_error_message() { return $this->message; }
		public function get_error_code() { return $this->code; }
	}
	function is_wp_error( $v ) { return $v instanceof WP_Error; }
	function __( $t, $d = '' ) { return $t; }
	function _n( $s, $p, $n, $d = '' ) { return 1 === (int) $n ? $s : $p; }
	function esc_html( $t ) { return (string) $t; }
	function esc_html__( $t, $d = '' ) { return (string) $t; }
	function esc_attr( $t ) { return (string) $t; }
	function absint( $v ) { return abs( (int) $v ); }
	function sanitize_email( $v ) { return trim( (string) $v ); }
	function sanitize_text_field( $v ) { return trim( (string) $v ); }
	function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $v ) ); }
	function is_email( $v ) { return false !== strpos( (string) $v, '@' ) ? $v : false; }
	function wp_strip_all_tags( $v ) { return strip_tags( (string) $v ); }
	function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
	function wp_salt( $s = 'auth' ) { return 'm4-test-salt-' . $s; }
	function wp_generate_uuid4() { return sprintf( '00000000-0000-4000-8000-%012d', ++$GLOBALS['m4']['uuid'] ); }
	function wp_date( $format, $ts = null ) { return gmdate( $format, $ts ?? time() ); }
	function wp_list_pluck( $list, $field ) {
		$out = array();
		foreach ( $list as $k => $v ) { $out[ $k ] = is_object( $v ) ? $v->$field : $v[ $field ]; }
		return $out;
	}
	function get_option( $k, $default = false ) { return array_key_exists( $k, $GLOBALS['m4']['options'] ) ? $GLOBALS['m4']['options'][ $k ] : $default; }
	function update_option( $k, $v, $autoload = null ) { $GLOBALS['m4']['options'][ $k ] = $v; return true; }
	function get_post_type( $id ) { return $GLOBALS['m4']['posts'][ (int) $id ]['type'] ?? false; }
	function get_post_status( $id ) { return $GLOBALS['m4']['posts'][ (int) $id ]['status'] ?? false; }
	function get_the_title( $id ) { return $GLOBALS['m4']['posts'][ (int) $id ]['title'] ?? ''; }
	function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['m4']['posts'][ (int) $id ]['meta'][ $key ] ?? ''; }
	function wp_get_post_parent_id( $id ) { return (int) ( $GLOBALS['m4']['products'][ (int) $id ]['parent'] ?? 0 ); }
	function get_posts( $args ) {
		$status = (array) ( $args['post_status'] ?? 'publish' );
		$rows   = array();
		foreach ( $GLOBALS['m4']['posts'] as $id => $p ) {
			if ( $p['type'] === $args['post_type'] && in_array( $p['status'], $status, true ) && ! in_array( $id, (array) ( $args['exclude'] ?? array() ), true ) ) {
				$rows[ $id ] = $p;
			}
		}
		// 两种用到的排序：创建时间最近的在前（再按 ID）；ID 大的在前。
		uksort( $rows, static fn( $a, $b ) => 'ID' === ( $args['orderby'] ?? '' ) ? $b <=> $a : array( $rows[ $b ]['date'], $b ) <=> array( $rows[ $a ]['date'], $a ) );
		return 'ids' === ( $args['fields'] ?? '' ) ? array_keys( $rows ) : array_map( static fn( $id ) => (object) array( 'ID' => $id ), array_keys( $rows ) );
	}
	function get_current_user_id() { return (int) $GLOBALS['m4']['user']; }
	function is_user_logged_in() { return get_current_user_id() > 0; }
	function get_userdata( $id ) { return isset( $GLOBALS['m4']['users'][ (int) $id ] ) ? (object) ( $GLOBALS['m4']['users'][ (int) $id ] + array( 'ID' => (int) $id, 'roles' => array( 'customer' ) ) ) : false; }
	function wp_get_current_user() { $u = get_userdata( get_current_user_id() ); return $u ? $u : (object) array( 'ID' => 0, 'user_email' => '', 'roles' => array() ); }
	function get_user_meta( $id, $key, $single = false ) {
		if ( 'user_meta' === $GLOBALS['m4']['throw'] ) { throw new RuntimeException( '测试：模拟引擎读取顾客资料时出错' ); }
		return $GLOBALS['m4']['users'][ (int) $id ]['meta'][ $key ] ?? '';
	}
	function dbDelta( $sql ) { $GLOBALS['m4']['dbdelta'][] = (string) $sql; return array(); } // phpcs:ignore WordPress.NamingConventions.ValidFunctionName
	function get_transient( $k ) { return $GLOBALS['m4']['transients'][ $k ] ?? false; }
	function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['m4']['transients'][ $k ] = $v; return true; }
	function user_can( $user, $cap ) { return false; }
	final class M4_Wpdb {
		public string $prefix = 'wp_';
		public function prepare( $query, ...$args ) { return array( $query, $args ); }
		public function get_row( $prepared, $output = null ) { return $GLOBALS['m4']['ledger'][ (string) ( $prepared[1][0] ?? '' ) ] ?? null; }
		public function get_var( $prepared ) { return null; }
		// 核对日志（Shadow）写进来的内容记在内存里，测试直接查看。
		public array $writes  = array();
		public int $insert_id = 0;
		public function query( $q ) { $this->writes[] = array( 'query', $q ); return 1; }
		public function insert( $table, $data ) { $this->writes[] = array( 'insert', $table, $data ); ++$this->insert_id; return 1; }
		public function get_results( $q, $output = null ) { return array(); }
		public function get_charset_collate() { return ''; }
	}
	$GLOBALS['wpdb'] = new M4_Wpdb();

	// ---------- WooCommerce：函数 ----------
	function wc_get_price_decimals() { return (int) get_option( 'woocommerce_price_num_decimals', 2 ); }
	function wc_get_rounding_precision() { return max( wc_get_price_decimals() + 2, absint( WC_ROUNDING_PRECISION ) ); }
	function wc_add_number_precision( ?float $value, bool $round = true ) {
		if ( ! $value ) { return 0.0; }
		$result = $value * pow( 10, wc_get_price_decimals() );
		return round( round( $result, WC_ROUNDING_PRECISION ), $round ? wc_get_rounding_precision() - wc_get_price_decimals() : wc_get_rounding_precision() );
	}
	function wc_remove_number_precision( $value ) { return $value ? $value / pow( 10, wc_get_price_decimals() ) : 0.0; }
	function wc_add_number_precision_deep( $value, $round = true ) {
		if ( ! is_array( $value ) ) { return wc_add_number_precision( (float) $value, $round ); }
		foreach ( $value as $k => $v ) { $value[ $k ] = wc_add_number_precision_deep( $v, $round ); }
		return $value;
	}
	function wc_remove_number_precision_deep( $value ) {
		if ( ! is_array( $value ) ) { return wc_remove_number_precision( $value ); }
		foreach ( $value as $k => $v ) { $value[ $k ] = wc_remove_number_precision_deep( $v ); }
		return $value;
	}
	function wc_round_discount( $value, $precision ) { return round( is_float( $value ) ? round( $value, WC_ROUNDING_PRECISION ) : $value, $precision, WC_DISCOUNT_ROUNDING_MODE ); }
	function wc_format_decimal( $number, $dp = false, $trim_zeros = false ) {
		if ( is_float( $number ) ) {
			$number = sprintf( '%.' . wc_get_rounding_precision() . 'f', $number );
			$number = false !== strpos( $number, '.' ) ? rtrim( rtrim( $number, '0' ), '.' ) : $number;
		}
		return false !== $dp ? number_format( (float) $number, (int) $dp, '.', '' ) : (string) $number;
	}
	function wc_string_to_bool( $v ) { return is_bool( $v ) ? $v : in_array( strtolower( (string) $v ), array( 'yes', 'true', '1' ), true ); }
	function wc_price( $n ) { return '$' . number_format( (float) $n, wc_get_price_decimals(), '.', ',' ); }
	function wc_format_localized_decimal( $n ) { return (string) $n; }
	function wc_format_coupon_code( $c ) { return strtolower( trim( (string) $c ) ); }
	function wc_strtolower( $s ) { return strtolower( (string) $s ); }
	function wc_coupons_enabled() { return true; }
	function wc_tax_enabled() { return false; }
	function wc_prices_include_tax() { return false; }
	function get_woocommerce_currency() { return 'USD'; }
	function wc_get_product_coupon_types() { return array( 'fixed_product', 'percent' ); }
	function wc_get_cart_coupon_types() { return array( 'fixed_cart' ); }
	function wc_get_is_paid_statuses() { return array( 'processing', 'completed' ); }
	function wc_get_orders( $args ) {
		foreach ( $GLOBALS['m4']['orders'] as $o ) {
			if ( ( isset( $args['customer_id'] ) && $o['user'] === $args['customer_id'] ) || ( isset( $args['billing_email'] ) && $o['email'] === $args['billing_email'] ) ) { return array( 1 ); }
		}
		return array();
	}
	function wc_get_product( $id ) { return isset( $GLOBALS['m4']['products'][ (int) $id ] ) ? new WC_Product( (int) $id ) : false; }
	function wc_get_product_cat_ids( $id ) { return (array) ( $GLOBALS['m4']['products'][ (int) $id ]['cats'] ?? array() ); }
	function wc_has_notice( $m, $t = 'success' ) { return false; }
	function wc_add_notice( $m, $t = 'success' ) {}

	// ---------- WooCommerce：数据对象 ----------
	final class WC_Product {
		private array $d;
		public function __construct( public int $id ) { $this->d = $GLOBALS['m4']['products'][ $id ]; }
		public function get_id() { return $this->id; }
		public function get_parent_id() { return (int) $this->d['parent']; }
		public function get_price( $c = 'view' ) { return $this->d['price']; }
		public function set_price( $p ) { $this->d['price'] = $p; }
		public function get_regular_price( $c = 'view' ) { return $this->d['regular']; }
		public function is_on_sale() { return (float) $this->d['regular'] > (float) $GLOBALS['m4']['products'][ $this->id ]['price']; }
		public function get_type() { return $this->d['parent'] ? 'variation' : 'simple'; }
		public function is_type( $t ) { return in_array( $this->get_type(), (array) $t, true ); }
		public function get_status() { return $this->d['status']; }
		public function is_purchasable() { return 'publish' === $this->d['status']; }
		public function is_in_stock() { return $this->d['stock'] > 0; }
		public function is_on_backorder( $q = 1 ) { return false; }
		public function is_sold_individually() { return false; }
		public function managing_stock() { return true; }
		public function get_stock_managed_by_id() { return $this->id; }
		public function has_enough_stock( $q ) { return $this->d['stock'] >= $q; }
		public function get_name() { return 'Product ' . $this->id; }
		public function get_variation_attributes() { return array(); }
		public function get_tax_class() { return ''; }
		public function get_tax_status() { return 'taxable'; }
		public function is_taxable() { return true; }
	}
	final class M4_Coupon_Store {
		public function get_usage_by_user_id( $coupon, $user ) { return (int) ( $coupon->raw( 'used_by' )[ $user ] ?? 0 ); }
		public function get_tentative_usage_count( $id ) { return 0; }
		public function get_tentative_usages_for_user( $id, $users ) { return 0; }
	}
	class WC_Coupon {
		public const E_WC_COUPON_USAGE_LIMIT_REACHED            = 106;
		public const E_WC_COUPON_USAGE_LIMIT_COUPON_STUCK       = 115;
		public const E_WC_COUPON_USAGE_LIMIT_COUPON_STUCK_GUEST = 116;
		public const E_WC_COUPON_NOT_YOURS_REMOVED              = 102;
		public $sort = 0;
		private array $d;
		public function __construct( $ref = '' ) {
			$found = null;
			foreach ( $GLOBALS['m4']['coupons'] as $c ) {
				if ( ( is_int( $ref ) && $c['id'] === $ref ) || ( ! is_int( $ref ) && $c['code'] === wc_format_coupon_code( $ref ) ) ) { $found = $c; }
			}
			$this->d = $found ?? array_merge( m4_coupon_defaults(), array( 'id' => 0, 'code' => is_int( $ref ) ? '' : wc_format_coupon_code( $ref ) ) );
		}
		public function raw( $k ) { return $this->d[ $k ]; }
		public function get_id() { return $this->d['id']; }
		public function get_code() { return $this->d['code']; }
		public function get_virtual() { return false; }
		public function get_status() { return (string) get_post_status( $this->d['id'] ); }
		public function get_discount_type() { return $this->d['type']; }
		public function is_type( $t ) { return in_array( $this->d['type'], (array) $t, true ); }
		public function get_amount() { return wc_format_decimal( $this->d['amount'] ); }
		public function get_individual_use() { return $this->d['individual_use']; }
		public function get_free_shipping() { return $this->d['free_shipping']; }
		public function get_product_ids() { return $this->d['product_ids']; }
		public function get_excluded_product_ids() { return $this->d['excluded_ids']; }
		public function get_product_categories() { return $this->d['cats']; }
		public function get_excluded_product_categories() { return $this->d['excluded_cats']; }
		public function get_exclude_sale_items() { return $this->d['exclude_sale']; }
		public function get_minimum_amount() { return wc_format_decimal( $this->d['min'] ); }
		public function get_maximum_amount() { return wc_format_decimal( $this->d['max'] ); }
		public function get_limit_usage_to_x_items() { return $this->d['limit_x']; }
		public function get_usage_limit() { return $this->d['usage_limit']; }
		public function get_usage_count() { return $this->d['usage_count']; }
		public function get_usage_limit_per_user() { return $this->d['limit_per_user']; }
		public function get_email_restrictions() { return $this->d['emails']; }
		public function get_date_expires() {
			$ts = $this->d['expires'];
			return $ts ? new class( $ts ) { public function __construct( private int $ts ) {} public function getTimestamp() { return $this->ts; } } : null;
		}
		public function get_meta( $k ) { return get_post_meta( $this->d['id'], $k, true ); }
		public function get_data_store() { return new M4_Coupon_Store(); }
		public function get_coupon_error( $code ) { return 'coupon error ' . $code; }
		public function get_context_based_coupon_errors( $code ) { return array(); }
		public function is_valid_for_cart() { return apply_filters( 'woocommerce_coupon_is_valid_for_cart', $this->is_type( wc_get_cart_coupon_types() ), $this ); }
		public function is_valid_for_product( $product, $values = array() ) {
			if ( ! $this->is_type( wc_get_product_coupon_types() ) || ! is_a( $product, WC_Product::class ) ) {
				return apply_filters( 'woocommerce_coupon_is_valid_for_product', false, $product, $this, $values );
			}
			$valid = false;
			$cats  = wc_get_product_cat_ids( $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id() );
			$ids   = array( $product->get_id(), $product->get_parent_id() );
			if ( count( $this->get_product_ids() ) && count( array_intersect( $ids, $this->get_product_ids() ) ) ) { $valid = true; }
			if ( count( $this->get_product_categories() ) && count( array_intersect( $cats, $this->get_product_categories() ) ) ) { $valid = true; }
			if ( ! count( $this->get_product_ids() ) && ! count( $this->get_product_categories() ) ) { $valid = true; }
			if ( count( $this->get_excluded_product_ids() ) && count( array_intersect( $ids, $this->get_excluded_product_ids() ) ) ) { $valid = false; }
			if ( count( $this->get_excluded_product_categories() ) && count( array_intersect( $cats, $this->get_excluded_product_categories() ) ) ) { $valid = false; }
			if ( $this->get_exclude_sale_items() && $product->is_on_sale() ) { $valid = false; }
			return apply_filters( 'woocommerce_coupon_is_valid_for_product', $valid, $product, $this, $values );
		}
	}
	final class WC_Customer {
		public function get_billing_email() { return (string) $GLOBALS['m4']['billing']; }
		public function get_is_vat_exempt() { return false; }
	}
	final class WC_Shipping_Rate {
		public $taxes  = array();
		private $meta  = array();
		public function __construct( public string $id, public $cost ) {}
		public function get_id() { return $this->id; }
		public function get_cost() { return $this->cost; }
		public function set_cost( $c ) { $this->cost = $c; }
		public function get_taxes() { return $this->taxes; }
		public function set_taxes( $t ) { $this->taxes = $t; }
		public function add_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
		public function get_meta_data() { return $this->meta; }
	}
	final class M4_Session {
		public function get( $k, $default = null ) { return $GLOBALS['m4']['session'][ $k ] ?? $default; }
		public function set( $k, $v ) { $GLOBALS['m4']['session'][ $k ] = $v; }
		public function has_session() { return true; }
	}
	final class M4_Shipping {
		public function get_packages() { return $GLOBALS['m4']['packages']; }
	}
	final class M4_WC {
		public $cart;
		public $customer;
		public $session;
		public function __construct() { $this->customer = new WC_Customer(); $this->session = new M4_Session(); }
		public function shipping() { return new M4_Shipping(); }
	}
	function WC() { static $wc; return $wc ??= new M4_WC(); } // phpcs:ignore

	class WC_Cart {
		public $cart_contents         = array();
		public $removed_cart_contents = array();
		public $applied_coupons       = array();
		protected $totals             = array();
		protected $fees_api;
		public function __construct() { $this->fees_api = new WC_Cart_Fees(); }
		public function get_cart() { return $this->cart_contents; }
		public function is_empty() { return ! $this->cart_contents; }
		public function get_customer() { return WC()->customer; }
		public function get_applied_coupons() { return $this->applied_coupons; }
		public function has_discount( $code ) { return in_array( wc_format_coupon_code( $code ), $this->applied_coupons, true ); }
		public function get_coupons() {
			$out = array();
			foreach ( $this->applied_coupons as $code ) { $out[ $code ] = new WC_Coupon( $code ); }
			return $out;
		}
		public function fees_api() { return $this->fees_api; }
		public function get_fees() { return $this->fees_api->get_fees(); }
		public function calculate_fees() { do_action( 'woocommerce_cart_calculate_fees', $this ); }
		public function needs_shipping() { return (bool) $GLOBALS['m4']['rates']; }
		public function show_shipping() { return true; }
		public function display_prices_including_tax() { return false; }
		public function get_displayed_subtotal() { return $this->get_subtotal(); }
		public function calculate_shipping() {
			$GLOBALS['m4']['packages'] = array();
			if ( ! $this->needs_shipping() ) { return array(); }
			$packages = apply_filters( 'woocommerce_cart_shipping_packages', array( array( 'contents' => $this->cart_contents ) ) );
			$rates    = array();
			foreach ( $GLOBALS['m4']['rates'] as $r ) { $rates[ $r['id'] ] = new WC_Shipping_Rate( $r['id'], $r['cost'] ); }
			$rates                = apply_filters( 'woocommerce_package_rates', $rates, $packages[0] );
			$packages[0]['rates'] = $rates;
			$chosen               = $GLOBALS['m4']['chosen'] ?? array_key_first( $rates );
			$GLOBALS['m4']['packages']                           = $packages;
			$GLOBALS['m4']['session']['chosen_shipping_methods'] = array( $chosen );
			return isset( $rates[ $chosen ] ) ? array( $rates[ $chosen ] ) : array();
		}
		public function __call( $name, $args ) {
			if ( 0 === strpos( $name, 'set_' ) ) { $this->totals[ substr( $name, 4 ) ] = $args[0]; return null; }
			if ( 0 === strpos( $name, 'get_' ) ) { return $this->totals[ substr( $name, 4 ) ] ?? 0; }
			throw new BadMethodCallException( $name );
		}
		public function get_coupon_discount_amount( $code ) { return $this->totals['coupon_discount_totals'][ $code ] ?? 0; }
		public function calculate_totals() {
			$this->totals = array();
			$this->fees_api->remove_all_fees();
			if ( $this->is_empty() ) { return; }
			do_action( 'woocommerce_before_calculate_totals', $this );
			new WC_Cart_Totals( $this );
			do_action( 'woocommerce_after_calculate_totals', $this );
		}
		public function add_to_cart( $product_id = 0, $quantity = 1, $variation_id = 0, $variation = array(), $data = array() ) {
			$data = (array) apply_filters( 'woocommerce_add_cart_item_data', $data, $product_id, $variation_id, $quantity );
			$key  = md5( $product_id . '|' . $variation_id . '|' . json_encode( $data ) );
			if ( isset( $this->cart_contents[ $key ] ) ) {
				$this->cart_contents[ $key ]['quantity'] += $quantity;
				return $key;
			}
			$this->cart_contents[ $key ] = array_merge( $data, array( 'key' => $key, 'product_id' => $product_id, 'variation_id' => $variation_id, 'quantity' => $quantity, 'data' => wc_get_product( $variation_id ? $variation_id : $product_id ) ) );
			return $key;
		}
		public function remove_cart_item( $key ) { unset( $this->cart_contents[ $key ] ); return true; }
		public function set_quantity( $key, $quantity = 1, $refresh = true ) { $this->cart_contents[ $key ]['quantity'] = $quantity; return true; }
	}

	if ( getenv( 'PC_WC_SRC' ) ) {
		require __DIR__ . '/m4-wc-real.php';
	} else {
		require __DIR__ . '/m4-wc-math.php';
	}
	require dirname( __DIR__, 2 ) . '/includes/autoload.php';

	// ---------- 搭场景 ----------
	function m4_product( int $id, $price, array $o = array() ): void {
		$GLOBALS['m4']['products'][ $id ] = array( 'price' => (string) $price, 'regular' => (string) ( $o['regular'] ?? $price ), 'parent' => (int) ( $o['parent'] ?? 0 ), 'cats' => (array) ( $o['cats'] ?? array() ), 'status' => $o['status'] ?? 'publish', 'stock' => $o['stock'] ?? 1000 );
		$GLOBALS['m4']['posts'][ $id ]    = array( 'type' => $o['parent'] ?? 0 ? 'product_variation' : 'product', 'status' => $o['status'] ?? 'publish', 'title' => 'Product ' . $id, 'meta' => array(), 'date' => $id );
	}
	function m4_promotion( int $id, string $title, array $config, string $status = 'publish' ): void {
		$GLOBALS['m4']['posts'][ $id ] = array( 'type' => \PeptideCore\Promotions::CPT, 'status' => $status, 'title' => $title, 'meta' => array( \PeptideCore\Promotions::META => $config ), 'date' => $config['created'] ?? $id );
	}
	function m4_coupon_defaults(): array {
		return array( 'id' => 0, 'code' => '', 'type' => 'fixed_cart', 'amount' => '0', 'individual_use' => false, 'free_shipping' => false, 'product_ids' => array(), 'excluded_ids' => array(), 'cats' => array(), 'excluded_cats' => array(), 'exclude_sale' => false, 'min' => '', 'max' => '', 'limit_x' => null, 'usage_limit' => 0, 'usage_count' => 0, 'limit_per_user' => 0, 'used_by' => array(), 'emails' => array(), 'expires' => 0 );
	}
	function m4_coupon( int $id, string $code, array $props, array $meta = array(), string $status = 'publish' ): void {
		$GLOBALS['m4']['coupons'][ $id ] = array_merge( m4_coupon_defaults(), $props, array( 'id' => $id, 'code' => wc_format_coupon_code( $code ) ) );
		$GLOBALS['m4']['posts'][ $id ]   = array( 'type' => 'shop_coupon', 'status' => $status, 'title' => $code, 'meta' => $meta, 'date' => $id );
	}
	function m4_offer( int $id, string $title, array $config ): void {
		$config                        = array_merge( \PeptideCore\Product_Offers::defaults(), array( 'enabled' => true ), $config );
		$GLOBALS['m4']['posts'][ $id ] = array( 'type' => \PeptideCore\Product_Offers::CPT, 'status' => $config['enabled'] ? 'publish' : 'draft', 'title' => $title, 'meta' => array( \PeptideCore\Product_Offers::META => $config ), 'date' => $id );
	}
	function m4_shipping_discount( array $d ): void {
		$all   = (array) get_option( \PeptideCore\Shipping\Discounts::OPTION, array() );
		$all[] = array_merge( \PeptideCore\Shipping\Discounts::defaults(), $d );
		update_option( \PeptideCore\Shipping\Discounts::OPTION, $all );
	}
	function m4_user( int $id, string $email, array $meta = array() ): void {
		$GLOBALS['m4']['users'][ $id ] = array( 'user_email' => $email, 'meta' => $meta );
		$GLOBALS['m4']['user']         = $id;
	}
	/** @param array<int,array{0:int,1:int}> $lines [[商品 ID, 数量]]；$coupons：已挂在购物车上的优惠码。 */
	function m4_cart( array $lines, array $coupons = array() ): WC_Cart {
		$cart       = new WC_Cart();
		WC()->cart  = $cart;
		foreach ( $lines as [ $product, $qty ] ) {
			$parent = (int) $GLOBALS['m4']['products'][ $product ]['parent'];
			$cart->add_to_cart( $parent ? $parent : $product, $qty, $parent ? $product : 0 );
		}
		$cart->applied_coupons = array_map( 'wc_format_coupon_code', $coupons );
		return $cart;
	}

	/** 旧逻辑（线上现在的结果）：让购物车照常计算，读出各项金额（分）。 */
	function m4_legacy( WC_Cart $cart ): array {
		$cart->calculate_totals();
		$s    = pow( 10, wc_get_price_decimals() );
		$fees = array();
		foreach ( $cart->get_fees() as $fee ) { $fees[ $fee->id ] = round( $fee->total * $s, 4 ); }
		$units = array();
		foreach ( $cart->get_cart() as $key => $item ) { $units[ $key ] = round( (float) $item['data']->get_price(), 8 ); }
		ksort( $fees );
		ksort( $units );
		return array(
			'total'    => (int) round( $cart->get_total( 'edit' ) * $s ),
			'subtotal' => round( $cart->get_subtotal() * $s, 4 ),
			'discount' => round( $cart->get_discount_total() * $s, 4 ),
			'coupons'  => array_map( static fn( $v ) => (int) round( $v * $s ), array_filter( (array) $cart->get_coupon_discount_totals() ) ),
			'fees'     => $fees,
			'shipping' => round( $cart->get_shipping_total() * $s, 4 ),
			'units'    => $units,
		);
	}
	/** 新引擎：读同一份购物车，算出同一组数字。 */
	function m4_engine( WC_Cart $cart ): array {
		$r    = \PeptideCore\Pricing\Engine::price( \PeptideCore\Pricing\Cart_Reader::read( $cart ) );
		$fees = array();
		foreach ( $r['promotions']['fees'] as $id => $fee ) { $fees[ $id ] = round( $fee['total_cents'], 4 ); }
		$units = array();
		foreach ( $r['lines'] as $key => $line ) { $units[ $key ] = round( $line['unit'], 8 ); }
		ksort( $fees );
		ksort( $units );
		return array(
			'total'    => $r['totals']['total_cents'],
			'subtotal' => round( $r['totals']['items_subtotal_cents'], 4 ),
			'discount' => round( $r['totals']['coupon_discount_cents'], 4 ),
			'coupons'  => array_filter( $r['coupons'] ),
			'fees'     => $fees,
			'shipping' => round( $r['totals']['shipping_cents'], 4 ),
			'units'    => $units,
			'result'   => $r,
		);
	}
}
