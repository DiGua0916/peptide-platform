<?php
/**
 * wp peptide tracking enable|disable|status|purge（TRACKING-LIVE-01 起是正式追踪；purge 只删测试版数据，--all 删全部）。
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
	 * 开启访客追踪（会先建好数据表）。
	 */
	public function enable(): void {
		Tracker::set_enabled( true );
		WP_CLI::success( 'Visitor tracking is on. Staff accounts and browsers that opt out are never tracked.' );
	}

	/**
	 * 关闭追踪（数据保留）。
	 */
	public function disable(): void {
		Tracker::set_enabled( false );
		WP_CLI::success( 'Tracking is off. Existing data is kept.' );
	}

	/**
	 * 显示开关和数据量。
	 */
	public function status(): void {
		global $wpdb;
		$s = Tracker::settings();
		WP_CLI::log( 'Enabled: ' . ( Tracker::enabled() ? 'yes' : 'no' ) . ' · keep ' . $s['retention_days'] . ' days · GPC ' . ( $s['gpc'] ? 'respected' : 'ignored' ) );
		WP_CLI::log( 'Test-version visitors left: ' . Schema::test_visitors() );
		WP_CLI::log( 'Tables: ' . ( Schema::installed() ? 'installed (v' . Schema::DB_VERSION . ')' : 'not installed' ) );
		if ( Schema::installed() ) {
			foreach ( array( 'visitors', 'visits', 'events', 'tabs' ) as $name ) {
				WP_CLI::log( sprintf( '  %-9s %d', $name, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( $name ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			}
		}
	}

	/**
	 * 删除测试版留下的追踪数据；加 --all 删除全部追踪数据。
	 *
	 * [--all]
	 * : 删除全部（包括正式数据）。
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
		if ( ! empty( $assoc_args['all'] ) ) {
			WP_CLI::confirm( 'Delete ALL tracking data, including real visits?', $assoc_args );
			Schema::purge();
			WP_CLI::success( 'All tracking data deleted.' );
			return;
		}
		WP_CLI::confirm( 'Delete the tracking data from the test version?', $assoc_args );
		Schema::purge_test();
		WP_CLI::success( 'Test-version tracking data deleted.' );
	}
}
