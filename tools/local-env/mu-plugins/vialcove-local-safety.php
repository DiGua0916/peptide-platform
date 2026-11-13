<?php
/**
 * 本地测试站的保险：不发真邮件、不连外网、不接受没脱敏的数据。
 * 只在 wp-config.php 里定义了 VIALCOVE_LOCAL_ENV 的站点上生效；放到别处是空文件。
 */
defined( 'ABSPATH' ) || exit;
if ( ! defined( 'VIALCOVE_LOCAL_ENV' ) || ! VIALCOVE_LOCAL_ENV ) {
	return;
}

/** 1. 邮件一律不发，写进 logs/mail/ 供测试检查。 */
add_filter(
	'pre_wp_mail',
	static function ( $short, $atts ) {
		$dir = ( defined( 'VIALCOVE_LOCAL_LOGS' ) ? VIALCOVE_LOCAL_LOGS : sys_get_temp_dir() ) . '/mail';
		if ( ! is_dir( $dir ) ) {
			@mkdir( $dir, 0700, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$record = array(
			'time'    => gmdate( 'c' ),
			'to'      => $atts['to'] ?? '',
			'subject' => $atts['subject'] ?? '',
			'headers' => $atts['headers'] ?? '',
			'message' => $atts['message'] ?? '',
		);
		file_put_contents( $dir . '/' . gmdate( 'Ymd-His' ) . '-' . substr( md5( wp_json_encode( $record ) . microtime() ), 0, 8 ) . '.json', wp_json_encode( $record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
		return true;
	},
	1,
	2
);

/** 2. 对外 HTTP 一律拦下（支付方、总后台、像素、AI、Cloudflare）。要放行某个主机：define( 'VIALCOVE_LOCAL_HTTP_ALLOW', 'host1,host2' ); */
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		$host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$allow = array_filter( array_map( 'trim', explode( ',', 'localhost,127.0.0.1,' . ( defined( 'VIALCOVE_LOCAL_HTTP_ALLOW' ) ? VIALCOVE_LOCAL_HTTP_ALLOW : '' ) ) ) );
		if ( in_array( $host, $allow, true ) ) {
			return $pre;
		}
		if ( defined( 'VIALCOVE_LOCAL_LOGS' ) ) {
			error_log( gmdate( 'c' ) . ' blocked ' . ( $args['method'] ?? 'GET' ) . ' ' . preg_replace( '/\?.*/', '', $url ) . "\n", 3, VIALCOVE_LOCAL_LOGS . '/http-blocked.log' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		return new WP_Error( 'vialcove_local_http_blocked', '本地测试站不连外网：' . $host );
	},
	1,
	3
);

/** 3. 库里出现不是 @example.test 的账号邮箱，说明导入的不是脱敏快照：停下来。 */
add_action(
	'init',
	static function () {
		global $wpdb;
		$key = 'vialcove_local_pii_check';
		$bad = get_transient( $key );
		if ( false === $bad ) {
			$bad = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_email <> '' AND user_email NOT LIKE '%@example.test'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			set_transient( $key, $bad, 5 * MINUTE_IN_SECONDS );
		}
		if ( $bad > 0 ) {
			wp_die( '这个数据库里有 ' . (int) $bad . ' 个真实邮箱的账号，不是脱敏快照。本地测试站拒绝运行：请删掉这个库，用 tools/local-env/snapshot/snapshot.py 重新导出。', '本地测试站', array( 'response' => 503 ) );
		}
	},
	0
);

add_action(
	'admin_notices',
	static function () {
		echo '<div class="notice notice-warning"><p><strong>本地测试站</strong>：邮件写入日志不外发，对外请求全部拦截，数据为演示或脱敏数据。</p></div>';
	}
);
