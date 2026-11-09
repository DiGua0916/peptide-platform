<?php
/**
 * 测试单规则（2026-11-09，店主要求）：收件人姓、名、地址、城市全部填“1”的就是店主自己在测支付通道。
 *
 * - 邮编、邮箱也填“1”时放行（格式校验只对测试单跳过）；
 * - 这样的订单不发任何邮件（顾客邮件、后台新订单邮件、注册邀请都不发），也不进弃单追单；
 * - 订单上打标记 `_pc_test_order` 并留一条备注，后台一眼能认出来。
 * 只要收件信息不是全“1”，一切照旧。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Test_Orders {

	public const META = '_pc_test_order';
	private const MARK   = '1';
	private const FIELDS = array( 'first_name', 'last_name', 'address_1', 'city' );

	public static function register_hooks(): void {
		add_filter( 'woocommerce_validate_postcode', array( self::class, 'allow_postcode' ), 10, 3 );
		add_filter( 'is_email', array( self::class, 'allow_email' ), 10, 2 );
		add_filter( 'sanitize_email', array( self::class, 'keep_email' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( self::class, 'mark' ), 5 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'mark' ), 5 );
		add_action( 'woocommerce_checkout_order_processed', array( self::class, 'mark' ), 5 );
		add_action( 'init', array( self::class, 'hook_emails' ), 30 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'assets' ), 160 );
	}

	/** 一组地址字段是不是“全 1”。 */
	public static function is_test_address( array $address, string $prefix = '' ): bool {
		foreach ( self::FIELDS as $field ) {
			if ( self::MARK !== trim( (string) ( $address[ $prefix . $field ] ?? '' ) ) ) {
				return false;
			}
		}
		return true;
	}

	/** 订单是不是测试单（收货地址或账单地址全 1）。 */
	public static function is_test_order( $order ): bool {
		if ( ! $order instanceof \WC_Order ) {
			return false;
		}
		if ( '' !== (string) $order->get_meta( self::META ) ) {
			return '1' === (string) $order->get_meta( self::META );
		}
		return self::is_test_address( $order->get_address( 'shipping' ) ) || self::is_test_address( $order->get_address( 'billing' ) );
	}

	/** 弃单扫描用：WooCommerce 会话里的 customer 数组是不是测试数据。 */
	public static function is_test_customer( array $customer ): bool {
		return self::is_test_address( $customer ) || self::is_test_address( $customer, 'shipping_' );
	}

	/** 当前结账请求（Store API JSON、经典结账表单或已写入的顾客资料）里有没有全 1 的地址。 */
	private static function request_is_test(): bool {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}
		$cached = false;
		$raw    = (string) file_get_contents( 'php://input' );
		if ( '' !== $raw && '{' === $raw[0] ) {
			$json = json_decode( $raw, true );
			foreach ( array( 'shipping_address', 'billing_address' ) as $key ) {
				if ( is_array( $json[ $key ] ?? null ) && self::is_test_address( $json[ $key ] ) ) {
					$cached = true;
					return true;
				}
			}
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['shipping_first_name'] ) && self::is_test_address( wp_unslash( $_POST ), 'shipping_' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$cached = true;
			return true;
		}
		if ( isset( $_POST['billing_first_name'] ) && self::is_test_address( wp_unslash( $_POST ), 'billing_' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$cached = true;
			return true;
		}
		// phpcs:enable
		if ( function_exists( 'WC' ) && WC()->customer instanceof \WC_Customer ) {
			$c = WC()->customer;
			foreach ( array( 'shipping', 'billing' ) as $type ) {
				$a = array();
				foreach ( self::FIELDS as $f ) {
					$getter = "get_{$type}_{$f}";
					$a[ $f ] = method_exists( $c, $getter ) ? $c->$getter() : '';
				}
				if ( self::is_test_address( $a ) ) {
					$cached = true;
					return true;
				}
			}
		}
		return false;
	}

	/** 邮编“1”只对测试单放行。 */
	public static function allow_postcode( $valid, $postcode, $country ) {
		if ( $valid || self::MARK !== trim( (string) $postcode ) ) {
			return $valid;
		}
		return self::request_is_test() ? true : $valid;
	}

	/** 测试单的邮箱：填“1”时换成本店的占位地址（支付方要求邮箱格式合法；反正测试单不发邮件）。 */
	public static function placeholder_email(): string {
		$host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		return 'test-order@' . ( preg_replace( '/^www\./', '', $host ) ?: 'example.com' );
	}

	public static function allow_email( $result, $email ) {
		if ( false !== $result || self::MARK !== trim( (string) $email ) ) {
			return $result;
		}
		return self::request_is_test() ? self::placeholder_email() : $result;
	}

	/** sanitize_email 会把“1”清成空串，导致结账时提示邮箱必填；测试单换成占位地址。 */
	public static function keep_email( $sanitized, $email ) {
		if ( '' !== (string) $sanitized || self::MARK !== trim( (string) $email ) ) {
			return $sanitized;
		}
		return self::request_is_test() ? self::placeholder_email() : $sanitized;
	}

	/** 下单时打标记、留备注。 */
	public static function mark( $order ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order );
		if ( ! $order instanceof \WC_Order || '' !== (string) $order->get_meta( self::META ) ) {
			return;
		}
		$test = self::is_test_address( $order->get_address( 'shipping' ) ) || self::is_test_address( $order->get_address( 'billing' ) );
		$order->update_meta_data( self::META, $test ? '1' : '0' );
		if ( $test ) {
			$order->add_order_note( '测试单（收件信息全为 1）：不发送任何邮件，不进弃单追单。' );
		}
		if ( doing_action( 'woocommerce_checkout_create_order' ) ) {
			return; // WooCommerce 随后会自己保存。
		}
		$order->save();
	}

	/** 所有 WooCommerce 邮件（含本插件基于 WC_Email 的邮件）：对象是测试单就不发。 */
	public static function hook_emails(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->mailer() ) {
			return;
		}
		foreach ( WC()->mailer()->get_emails() as $email ) {
			if ( $email instanceof \WC_Email && '' !== (string) $email->id ) {
				add_filter( 'woocommerce_email_enabled_' . $email->id, array( self::class, 'email_enabled' ), 5, 2 );
			}
		}
	}

	public static function email_enabled( $enabled, $object = null ) {
		if ( $object instanceof \WC_Order && self::is_test_order( $object ) ) {
			return false;
		}
		return $enabled;
	}

	/** 结账页：收件信息全 1 时，把前端对邮编“1”的格式报错清掉，让单子能提交。 */
	public static function assets(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
			return;
		}
		$file = PEPTIDE_CORE_DIR . '/assets/test-orders.js';
		if ( ! is_readable( $file ) ) {
			return;
		}
		wp_enqueue_script( 'pc-test-orders', plugins_url( 'assets/test-orders.js', PEPTIDE_CORE_FILE ), array( 'wp-data' ), (string) filemtime( $file ), true );
	}
}
