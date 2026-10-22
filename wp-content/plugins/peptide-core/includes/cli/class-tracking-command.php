<?php
/**
 * wp peptide tracking enable|disable|status|purge（VISITOR-ANALYTICS-TEST-01，测试版）。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Cli;

use PeptideCore\Tracking\Schema;
use PeptideCore\Tracking\Tracker;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

final class Tracking_Command {

	/**
	 * 开启测试追踪（会先建好数据表）。
	 */
	public function enable(): void {
		Tracker::set_enabled( true );
		WP_CLI::success( 'Test tracking is on. Staff accounts are never tracked.' );
	}

	/**
	 * 关闭追踪（数据保留）。
	 */
	public function disable(): void {
		Tracker::set_enabled( false );
		WP_CLI::success( 'Tracking is off. Existing test data is kept.' );
	}

	/**
	 * 显示开关和数据量。
	 */
	public function status(): void {
		global $wpdb;
		WP_CLI::log( 'Enabled: ' . ( Tracker::enabled() ? 'yes (test mode)' : 'no' ) );
		WP_CLI::log( 'Tables: ' . ( Schema::installed() ? 'installed (v' . Schema::DB_VERSION . ')' : 'not installed' ) );
		if ( Schema::installed() ) {
			foreach ( array( 'visitors', 'visits', 'events', 'tabs' ) as $name ) {
				WP_CLI::log( sprintf( '  %-9s %d', $name, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( $name ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			}
		}
	}

	/**
	 * 删除全部追踪测试数据。
	 *
	 * [--yes]
	 * : 不再确认。
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function purge( $args, $assoc_args ): void {
		if ( ! Schema::installed() ) {
			WP_CLI::success( 'Nothing to delete.' );
			return;
		}
		WP_CLI::confirm( 'Delete all tracking test data?', $assoc_args );
		Schema::purge();
		WP_CLI::success( 'Tracking test data deleted.' );
	}
}
