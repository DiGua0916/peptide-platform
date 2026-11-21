<?php
/**
 * wp peptide mail-domain status|sync：换公开域名后同步店铺的发件邮箱、发件人名称和客服邮箱（Domain_Mail_Sync）。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Cli;

use PeptideCore\Domain_Mail_Sync;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

final class Mail_Domain_Command {

	/**
	 * 显示现在的公开域名、上次同步记下的域名和三处邮箱的当前值。
	 */
	public function status(): void {
		$state = get_option( Domain_Mail_Sync::STATE, array() );
		$state = is_array( $state ) ? $state : array();
		$cur   = Domain_Mail_Sync::current();
		WP_CLI::log( 'Public domain:      ' . Domain_Mail_Sync::current_bare() );
		WP_CLI::log( 'Last synced domain: ' . (string) ( $state['host'] ?? '(none)' ) . ( ! empty( $state['at'] ) ? ' at ' . gmdate( 'Y-m-d H:i', (int) $state['at'] ) . ' UTC' : '' ) );
		WP_CLI::log( 'Sender email:       ' . $cur['from_address'] );
		WP_CLI::log( 'Sender name:        ' . $cur['from_name'] );
		WP_CLI::log( 'Support email:      ' . ( '' !== $cur['support_email'] ? $cur['support_email'] : '(empty: uses orders@<public domain>)' ) );
	}

	/**
	 * 按旧域名 → 现在的公开域名同步三处邮箱。可以重复运行。
	 *
	 * ## OPTIONS
	 *
	 * [--from=<old>]
	 * : 旧域名（不填用上次同步记下的域名）。
	 *
	 * [--dry-run]
	 * : 只显示要改什么。
	 *
	 * @param array $args  未使用。
	 * @param array $assoc 选项。
	 */
	public function sync( array $args, array $assoc ): void {
		$dry = ! empty( $assoc['dry-run'] );
		$r   = Domain_Mail_Sync::sync( (string) ( $assoc['from'] ?? '' ), '', $dry );
		if ( '' === $r['new'] ) {
			WP_CLI::error( 'The public domain (home) is not a valid domain.' );
		}
		WP_CLI::log( sprintf( 'Domain: %s -> %s', '' !== $r['old'] ? $r['old'] : '(unknown)', $r['new'] ) );
		foreach ( $r['changes'] as $key => $pair ) {
			WP_CLI::log( sprintf( '  %s: "%s" -> "%s"', $key, $pair[0], $pair[1] ) );
		}
		foreach ( $r['kept'] as $key => $why ) {
			WP_CLI::log( sprintf( '  %s: kept (%s)', $key, $why ) );
		}
		if ( '' !== $r['brand_hint'] ) {
			WP_CLI::warning( $r['brand_hint'] );
		}
		WP_CLI::success( sprintf( '%s: %d change(s).', $dry ? 'dry-run' : 'synced', count( $r['changes'] ) ) );
	}
}
