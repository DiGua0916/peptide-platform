<?php
/**
 * 测试用：改用真的 WooCommerce 源文件做购物车金额计算（WC_Cart_Fees、WC_Discounts、WC_Cart_Totals、NumberUtil）。
 * 设置环境变量 PC_WC_SRC=<woocommerce 插件目录> 后由 m4-harness.php 加载；不设置时用 m4-wc-math.php（精简副本）。
 * 这里只补上这几个文件用到、而测试环境没有的类和函数（不含税）。
 *
 * @package PeptideCore
 */

namespace Automattic\WooCommerce\Enums {
	final class OrderInternalStatus {
		public const FAILED  = 'wc-failed';
		public const PENDING = 'wc-pending';
	}
	final class ProductTaxStatus {
		public const TAXABLE = 'taxable';
	}
}

namespace Automattic\WooCommerce\Utilities {
	final class DiscountsUtil {
		public static function is_coupon_emails_allowed( $check_emails, $restrictions ) {
			foreach ( $check_emails as $check_email ) {
				if ( in_array( $check_email, $restrictions, true ) ) {
					return true;
				}
				foreach ( $restrictions as $restriction ) {
					$regex = '/^' . str_replace( '*', '(.+)?', $restriction ) . '$/';
					preg_match( $regex, $check_email, $match );
					if ( ! empty( $match ) ) {
						return true;
					}
				}
			}
			return false;
		}
	}
}

namespace {
	final class WC_Tax {
		public static function get_tax_classes() { return array(); }
		public static function get_tax_class_slugs() { return array(); }
		public static function get_rates( ...$a ) { return array(); }
		public static function get_base_tax_rates( ...$a ) { return array(); }
		public static function calc_tax( ...$a ) { return array(); }
	}
	function wp_parse_args( $args, $defaults = array() ) { return array_merge( (array) $defaults, (array) $args ); }
	function wp_kses( $v, $allowed = array() ) { return (string) $v; }
	function sanitize_title( $v ) { return strtolower( preg_replace( '/[^a-zA-Z0-9]+/', '-', (string) $v ) ); }
	function wc_doing_it_wrong( ...$a ) {}
	function get_term( $id, $taxonomy = '' ) { return (object) array( 'term_id' => (int) $id, 'name' => 'Term ' . $id ); }
	function wc_uasort_comparison( $a, $b ) { return $a <=> $b; }
	function wc_array_merge_recursive_numeric( ...$arrays ) { return array(); }
	function wc_get_price_thousand_separator() { return ','; }
	function wc_get_price_decimal_separator() { return '.'; }
	function wc_round_tax_total( $v, $precision = null ) { return round( (float) $v, $precision ?? wc_get_price_decimals() ); }

	$m4_src = rtrim( (string) getenv( 'PC_WC_SRC' ), '/' );
	foreach ( array( 'src/Utilities/NumberUtil.php', 'includes/traits/trait-wc-item-totals.php', 'includes/class-wc-cart-fees.php', 'includes/class-wc-discounts.php', 'includes/class-wc-cart-totals.php' ) as $m4_file ) {
		$m4_path = is_readable( $m4_src . '/' . $m4_file ) ? $m4_src . '/' . $m4_file : $m4_src . '/' . basename( $m4_file );
		if ( ! is_readable( $m4_path ) ) {
			fwrite( STDERR, "PC_WC_SRC 里找不到 {$m4_file}\n" );
			exit( 2 );
		}
		require $m4_path;
	}
	echo "（金额计算使用真实的 WooCommerce 源文件：{$m4_src}）\n";
}
