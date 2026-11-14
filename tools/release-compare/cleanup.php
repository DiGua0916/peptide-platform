<?php
/**
 * 作废 prepare.php 开的登录会话（F5，2026-11-14）。
 *
 *   wp eval-file tools/release-compare/cleanup.php <配置文件>
 *
 * 只作废配置文件里记下的那几个会话，账号原来的登录不受影响。之后请删掉配置文件。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

$f5_args = isset( $args ) && is_array( $args ) ? array_values( $args ) : array();
if ( count( $f5_args ) < 1 ) {
	WP_CLI::error( '用法：wp eval-file cleanup.php <配置文件>' );
}
$f5_config = json_decode( (string) @file_get_contents( $f5_args[0] ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
if ( ! is_array( $f5_config ) || empty( $f5_config['roles'] ) ) {
	WP_CLI::error( '读不到配置文件：' . $f5_args[0] );
}
$f5_done = 0;
foreach ( $f5_config['roles'] as $f5_role => $f5_row ) {
	if ( ! empty( $f5_row['user_id'] ) && ! empty( $f5_row['token'] ) ) {
		WP_Session_Tokens::get_instance( (int) $f5_row['user_id'] )->destroy( (string) $f5_row['token'] );
		++$f5_done;
	}
}
WP_CLI::success( '已作废 ' . $f5_done . ' 个比对用的登录会话。请删掉配置文件 ' . $f5_args[0] . '。' );
