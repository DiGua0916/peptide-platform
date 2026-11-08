<?php
/**
 * 店铺复制（2026-11-08）：把一家店的内容复制到另一家店。
 *
 * - 总后台只往 <队列>/clone/requests/ 写一个有界的请求（两家店的编号和 uuid、要复制的内容），不带路径或命令。
 * - root 开店任务（每 20 秒一次）读取请求，通过已有的受控 SSH 通道让店铺服务器执行复制，回执写到 clone/results/。
 * - 一键完整复制 = 全部内容；也可以勾选要复制的内容。订单、顾客、访客记录和付款记录永远不复制；
 *   目标店铺自己的域名、后台连接、支付绑定、邮箱都保留。
 *
 * @package PeptideHq
 */

namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Store_Clone {

	public const OPTION = 'pchq_clone_jobs';

	public const PARTS = array(
		'products'  => '商品（分类、规格、价格、库存）',
		'media'     => '图片与媒体库',
		'pages'     => '页面与菜单',
		'design'    => '店铺装修（模板、首页、样式）',
		'reports'   => 'COA 报告',
		'marketing' => '营销（优惠码、活动、应用设置）',
		'settings'  => '店铺设置（运费、结账、邮件模板等）',
		'tracking'  => '像素与追踪设置',
	);

	public static function root(): string {
		return defined( 'PEPTIDE_HQ_PROVISION_QUEUE' ) ? rtrim( (string) PEPTIDE_HQ_PROVISION_QUEUE, '/\\' ) . '/clone' : '';
	}

	public static function ready(): bool {
		$root = self::root();
		if ( '' === $root || is_link( $root ) ) {
			return false;
		}
		$path = $root . '/requests';
		return ! is_link( $path ) && is_dir( $path ) && is_writable( $path );
	}

	/** 可作为来源或目标的店铺：在线、有 uuid、没有下线。 */
	public static function stores(): array {
		$out = array();
		foreach ( Sites::all() as $s ) {
			if ( 'retired' === ( $s['status'] ?? '' ) || '' === (string) $s['uuid'] || ! Site_Lifecycle::active( (int) $s['id'] ) ) {
				continue;
			}
			$out[ (int) $s['id'] ] = $s;
		}
		return $out;
	}

	/** 主店（最早接入的店铺）：默认来源，永远不能当目标。 */
	public static function main_id(): int {
		$ids = array_keys( self::stores() );
		return $ids ? (int) min( $ids ) : 0;
	}

	/** 店铺编号（sNNNN），取自平台分配的管理地址。 */
	public static function slug( array $site ): string {
		$host = (string) wp_parse_url( Site_Urls::management( $site ), PHP_URL_HOST );
		return preg_match( '/^(s[0-9]{4})\./', $host, $m ) ? $m[1] : '';
	}

	/** @param string[]|'all' $parts @return true|\WP_Error */
	public static function request( int $source_id, int $target_id, $parts ) {
		if ( ! self::ready() ) {
			return new \WP_Error( 'pchq_clone_queue', '复制服务还没准备好，请一分钟后再试。' );
		}
		$stores = self::stores();
		$source = $stores[ $source_id ] ?? null;
		$target = $stores[ $target_id ] ?? null;
		if ( ! $source || ! $target ) {
			return new \WP_Error( 'pchq_clone_site', '请选择在线的来源店铺和目标店铺。' );
		}
		if ( $source_id === $target_id ) {
			return new \WP_Error( 'pchq_clone_site', '来源和目标不能是同一家店。' );
		}
		if ( $target_id === self::main_id() ) {
			return new \WP_Error( 'pchq_clone_site', '不能把内容复制到主店。' );
		}
		if ( '' === self::slug( $source ) || '' === self::slug( $target ) ) {
			return new \WP_Error( 'pchq_clone_site', '店铺的管理地址不是平台分配的固定地址，不能复制。' );
		}
		if ( 'all' !== $parts ) {
			$parts = array_values( array_unique( array_filter( (array) $parts, static fn( $p ): bool => isset( self::PARTS[ $p ] ) ) ) );
			if ( ! $parts ) {
				return new \WP_Error( 'pchq_clone_parts', '请至少选择一项要复制的内容。' );
			}
		}
		foreach ( self::jobs() as $j ) {
			if ( (int) $j['target_site_id'] === $target_id && in_array( $j['state'], array( 'queued', 'running' ), true ) && (int) $j['at'] > time() - 1800 ) {
				return new \WP_Error( 'pchq_clone_busy', '这家店铺正在复制中，请等它完成。' );
			}
		}
		$op   = wp_generate_uuid4();
		$body = wp_json_encode( array(
			'version'        => 1,
			'operation_id'   => $op,
			'actor_id'       => max( 1, get_current_user_id() ),
			'source_site_id' => $source_id,
			'source_uuid'    => strtolower( (string) $source['uuid'] ),
			'source_slug'    => self::slug( $source ),
			'target_site_id' => $target_id,
			'target_uuid'    => strtolower( (string) $target['uuid'] ),
			'target_slug'    => self::slug( $target ),
			'parts'          => $parts,
		), JSON_UNESCAPED_SLASHES );
		$path   = self::root() . '/requests/' . $op . '.json';
		$handle = @fopen( $path, 'x' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		if ( ! $handle ) {
			return new \WP_Error( 'pchq_clone_queue', '任务没能提交，请重试。' );
		}
		chmod( $path, 0600 );
		$written = fwrite( $handle, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( strlen( $body ) !== $written ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new \WP_Error( 'pchq_clone_queue', '任务没能完整提交，请重试。' );
		}
		$jobs = get_option( self::OPTION, array() );
		$jobs = is_array( $jobs ) ? $jobs : array();
		$jobs[ $op ] = array( 'source_site_id' => $source_id, 'target_site_id' => $target_id, 'parts' => $parts, 'at' => time() );
		update_option( self::OPTION, array_slice( $jobs, -50, null, true ), false );
		return true;
	}

	/** 最近的复制任务（新的在前），合并总后台记录和 root 回执。 */
	public static function jobs(): array {
		$jobs = get_option( self::OPTION, array() );
		$jobs = is_array( $jobs ) ? $jobs : array();
		$out  = array();
		foreach ( $jobs as $op => $j ) {
			if ( ! is_string( $op ) || ! preg_match( '/^[0-9a-f-]{36}$/D', $op ) || ! is_array( $j ) ) {
				continue;
			}
			$row = array( 'operation_id' => $op, 'source_site_id' => (int) ( $j['source_site_id'] ?? 0 ), 'target_site_id' => (int) ( $j['target_site_id'] ?? 0 ),
				'parts' => $j['parts'] ?? 'all', 'state' => 'queued', 'message' => '', 'error_code' => '', 'counts' => array(), 'at' => (int) ( $j['at'] ?? 0 ), 'finished_at' => 0 );
			$r   = self::receipt( self::root() . '/results/' . $op . '.json' );
			if ( $r ) {
				$row = array_merge( $row, array_intersect_key( $r, array_flip( array( 'state', 'message', 'error_code', 'counts', 'finished_at' ) ) ) );
			} elseif ( $row['at'] < time() - 3 * HOUR_IN_SECONDS ) {
				$row['state']   = 'failed';
				$row['message'] = '任务没有被处理，请重新提交。';
			}
			$out[] = $row;
		}
		usort( $out, static fn( $a, $b ) => $b['at'] <=> $a['at'] );
		return $out;
	}

	private static function receipt( string $path ): ?array {
		if ( '' === self::root() || is_link( $path ) || ! is_file( $path ) ) {
			return null;
		}
		$st = lstat( $path );
		if ( ! $st || 0 !== $st['uid'] || 1 !== $st['nlink'] || $st['size'] > 16384 || ( $st['mode'] & 0022 ) ) {
			return null;
		}
		$p = json_decode( (string) file_get_contents( $path, false, null, 0, 16385 ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! is_array( $p ) || ! in_array( $p['state'] ?? '', array( 'running', 'done', 'failed' ), true ) ) {
			return null;
		}
		return $p;
	}
}
