<?php
/**
 * WP-CLI：物流单号追踪（wp peptide shipping），开关 WooCommerce 原生的订单履约功能。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Cli;

use PeptideCore\Settings;
use PeptideCore\Shipping_Tracking;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

final class Shipping_Command {

	/**
	 * 开启 WooCommerce 订单履约（发货、单号、承运商、追踪链接、顾客发货邮件）。
	 *
	 * 数据表会在下一次页面加载时由 WooCommerce 自动创建。已有订单和数据不受影响。
	 *
	 * ## EXAMPLES
	 *
	 *     wp peptide shipping enable
	 */
	public function enable(): void {
		update_option( Shipping_Tracking::FEATURE_OPTION, 'yes' );
		WP_CLI::success( 'Order fulfillments enabled. Run `wp peptide shipping status` to confirm the tables were created.' );
	}

	/**
	 * 关闭订单履约。已经保存的发货记录保留在数据库里，重新开启后恢复显示。
	 *
	 * ## EXAMPLES
	 *
	 *     wp peptide shipping disable
	 */
	public function disable(): void {
		update_option( Shipping_Tracking::FEATURE_OPTION, 'no' );
		WP_CLI::success( 'Order fulfillments disabled. Existing shipment records are kept.' );
	}

	/**
	 * 显示订单履约的状态。
	 *
	 * ## EXAMPLES
	 *
	 *     wp peptide shipping status
	 */
	public function status(): void {
		global $wpdb;
		$table  = $wpdb->prefix . 'wc_order_fulfillments';
		$exists = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$count  = $exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE date_deleted IS NULL" ) : 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		WP_CLI::log( 'Order fulfillments:        ' . ( Shipping_Tracking::native_enabled() ? 'on' : 'off' ) );
		WP_CLI::log( 'Fulfillment tables:        ' . ( $exists ? 'present' : 'not created yet' ) );
		WP_CLI::log( 'Shipment records:          ' . $count );
		WP_CLI::log( 'Batch check before ship:   ' . ( empty( Settings::all()['require_batch_to_ship'] ) ? 'off' : 'on' ) );
	}
}
