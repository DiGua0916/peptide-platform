<?php
/**
 * 上线体检：列出正式上线前必须处理或需要确认的事项。只读，不修改任何设置。
 *
 * 级别：fail = 上线前必须处理；warn = 需要确认；ok = 已满足；info = 仅供参考。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Launch_Check {

	/**
	 * @return array<int,array{check:string,level:string,detail:string}>
	 */
	public static function run(): array {
		$out = array();
		$add = static function ( string $check, string $level, string $detail ) use ( &$out ): void {
			$out[] = array(
				'check'  => $check,
				'level'  => $level,
				'detail' => $detail,
			);
		};

		// 支付。
		if ( Workbench::test_payment_enabled() ) {
			$add( 'Test payment', 'fail', 'Test payment (no money charged) is enabled. Run `wp peptide demo commerce --disable`.' );
		} else {
			$add( 'Test payment', 'ok', 'Disabled.' );
		}
		$real = array();
		foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
			if ( 'yes' === $gateway->enabled && ! ( 'cod' === $gateway->id && Workbench::test_payment_enabled() ) ) {
				$real[] = $gateway->get_title();
			}
		}
		$add( 'Real payment method', $real ? 'ok' : 'fail', $real ? implode( ', ', $real ) : 'No real payment method is enabled.' );

		// 配送。
		$zones = \WC_Shipping_Zones::get_zones();
		$test  = array_filter( $zones, static fn( $z ) => str_contains( (string) $z['zone_name'], '(test shipping)' ) );
		if ( $test ) {
			$add( 'Shipping', 'fail', 'Only the $0 test shipping zone is configured. Replace it with real rates.' );
		} elseif ( ! $zones ) {
			$add( 'Shipping', 'fail', 'No shipping zone is configured.' );
		} else {
			$add( 'Shipping', 'ok', count( $zones ) . ' shipping zone(s).' );
		}

		// 演示数据。
		$settings = Settings::all();
		$add( 'Demo mode', $settings['demo_mode'] ? 'fail' : 'ok', $settings['demo_mode'] ? 'Demo mode is on; the storefront shows the demo notice.' : 'Off.' );
		$demo_seed = Workbench::demo_record_count();
		$add( 'Demo seed records', $demo_seed ? 'fail' : 'ok', $demo_seed ? $demo_seed . ' record(s). Run `wp peptide demo purge`.' : 'None.' );
		$demo_products = count(
			get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => 'any',
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'no_found_rows'  => true,
					'meta_key'       => Meta::PRODUCT_IS_DEMO, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			)
		);
		$add( 'Products marked as demo', $demo_products ? 'fail' : 'ok', $demo_products ? $demo_products . ' product(s) are still marked as demo. Only products cleared for sale should go live.' : 'None.' );

		// 内容与政策。
		$add( 'Use statement', null === Settings::public_config()['use_statement_html'] ? 'warn' : 'ok', null === Settings::public_config()['use_statement_html'] ? 'Empty (hidden on the storefront). Content to be provided by the owner.' : 'Set.' );
		$add( 'Checkout confirmation', null === Settings::checkout_ack_label() ? 'warn' : 'ok', null === Settings::checkout_ack_label() ? 'Not enabled.' : 'Enabled.' );
		foreach ( array(
			'Terms page'   => (int) get_option( 'woocommerce_terms_page_id' ),
			'Privacy page' => (int) get_option( 'wp_page_for_privacy_policy' ),
		) as $label => $page_id ) {
			$ok = $page_id && 'publish' === get_post_status( $page_id );
			$add( $label, $ok ? 'ok' : 'fail', $ok ? get_the_title( $page_id ) : 'Not set or not published. Content to be provided by the owner.' );
		}

		// 技术配置。
		$add( 'HTTPS', str_starts_with( home_url(), 'https://' ) ? 'ok' : 'fail', home_url() );
		$debug_display = defined( 'WP_DEBUG' ) && WP_DEBUG && ( ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY );
		$add( 'Debug output', $debug_display ? 'fail' : ( defined( 'WP_DEBUG' ) && WP_DEBUG ? 'warn' : 'ok' ), $debug_display ? 'Errors are displayed to visitors.' : ( defined( 'WP_DEBUG' ) && WP_DEBUG ? 'WP_DEBUG is on (log only).' : 'Off.' ) );

		$dir     = Private_Files::dir();
		$outside = defined( 'PEPTIDE_CORE_PRIVATE_DIR' ) && ! str_starts_with( wp_normalize_path( $dir ), wp_normalize_path( ABSPATH ) );
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			$add( 'Private report folder', 'fail', 'Missing or not writable: ' . $dir );
		} else {
			$add( 'Private report folder', $outside ? 'ok' : 'warn', $outside ? 'Outside the web root.' : 'Inside the web root; only safe on Apache with .htaccess. Define PEPTIDE_CORE_PRIVATE_DIR outside the web root.' );
		}

		$from = (string) get_option( 'woocommerce_email_from_address' );
		$bad  = '' === $from || str_ends_with( $from, '.local' ) || str_contains( $from, 'wpengine.local' );
		$add( 'Store email sender', $bad ? 'fail' : 'ok', '' === $from ? 'Not set.' : $from );
		$add( 'Admin email', str_ends_with( (string) get_option( 'admin_email' ), '.local' ) ? 'fail' : 'ok', (string) get_option( 'admin_email' ) );

		$coming_soon = 'yes' === get_option( 'woocommerce_coming_soon' );
		$add( 'Store visibility', 'info', $coming_soon ? 'Coming soon mode (store hidden from the public).' : 'Store is public.' );

		$countries = 'specific' === get_option( 'woocommerce_allowed_countries' ) ? implode( ', ', (array) get_option( 'woocommerce_specific_allowed_countries' ) ) : get_option( 'woocommerce_allowed_countries' );
		$add( 'Selling countries', 'info', (string) $countries );

		return $out;
	}

	/**
	 * @param array<int,array{check:string,level:string,detail:string}> $results
	 * @return array<string,int>
	 */
	public static function summary( array $results ): array {
		$sum = array(
			'fail' => 0,
			'warn' => 0,
			'ok'   => 0,
			'info' => 0,
		);
		foreach ( $results as $r ) {
			++$sum[ $r['level'] ];
		}
		return $sum;
	}
}
