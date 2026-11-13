<?php
/**
 * 店铺后台（总后台主机上的 store-admin / tenant-admin）专用：数据库连接复用（2026-11-14 提速）。
 *
 * 这些后台通过 SSH 通道（127.0.0.1:13361）连 web 主机上的店铺数据库，每个请求新开一次连接要 42–83ms。
 * 让每个 PHP 进程复用自己的连接（mysqli 持久连接，主机名前加 "p:"），每个请求约省 40ms。
 * 复用时 mysqlnd 会重置连接状态（回滚未提交事务、清临时表、释放锁），不会把上一个请求的状态带过来。
 * 只对走 TCP 的远程地址生效；数据库在本机（localhost / socket）时什么都不改。放在 wp-content/db.php。
 */
defined( 'ABSPATH' ) || exit;

final class PC_Reused_Connection_WPDB extends wpdb {
	public function parse_db_host( $host ) {
		$parts = parent::parse_db_host( $host );
		if ( is_array( $parts ) && empty( $parts[3] ) && '' !== (string) $parts[0] && ! empty( $parts[1] ) && 'localhost' !== $parts[0] && ! str_starts_with( (string) $parts[0], 'p:' ) ) {
			$parts[0] = 'p:' . $parts[0];
		}
		return $parts;
	}
}

$wpdb = new PC_Reused_Connection_WPDB( defined( 'DB_USER' ) ? DB_USER : '', defined( 'DB_PASSWORD' ) ? DB_PASSWORD : '', defined( 'DB_NAME' ) ? DB_NAME : '', defined( 'DB_HOST' ) ? DB_HOST : '' );
