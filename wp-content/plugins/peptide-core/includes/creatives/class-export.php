<?php
/**
 * 素材看板的检查点导出（线 4）：交给“人群与素材分析师”的输入。
 *
 * - 自动：累计付费访客到 100 / 300 / 500 / 1000，以及每个站点版本的付费访客满 500、1000 时各生成一份
 *   （每小时检查一次；同一次检查跨过好几个节点时只生成一份，把这些节点都记为已完成）。
 * - 手动：看板上按日期范围导出。
 * - 每份导出 = 一个 JSON + 一个 CSV，存在 uploads/peptide-creatives-exports/（目录禁止直接访问），
 *   只能通过 admin-post.php?action=pc_creative_export&op=download 下载（要 CAP_VIEW 和 nonce）。
 * - 内容：人群分布、意向分层、按素材、按属性、版本日志；三套数据：全量 / 本版本 / 上一版本（手动导出只有所选范围）。
 *   口径都是“首次来源”，和看板默认一致。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Creatives;

use PeptideCore\Staff_Buyer;

defined( 'ABSPATH' ) || exit;

final class Export {

	public const ALL         = array( 100, 300, 500, 1000 );
	public const PER_VERSION = array( 500, 1000 );

	public const HOOK         = 'pc_creatives_checkpoint_tick';
	public const OPTION_DONE  = 'pc_creatives_checkpoints_done';
	public const OPTION_INDEX = 'pc_creatives_exports';
	public const META_SEEN    = 'pc_creatives_export_seen';
	public const DIR          = 'peptide-creatives-exports';

	/** 导出列表最多留多少份（更早的连文件一起删）。 */
	public const KEEP = 60;

	/** 导出格式版本：字段有变化时加一，分析师按它识别。 */
	public const FORMAT = 1;

	// ---------- 纯函数 ----------

	/**
	 * 现在该生成哪些检查点。
	 *
	 * @param int      $total     累计付费访客
	 * @param string   $version   当前站点版本号（'' = 没设置，不检查版本节点）
	 * @param int      $ver_count 当前版本开始以来的付费访客
	 * @param string[] $done      已经生成过的节点键
	 * @return list<array{key:string,scope:string,threshold:int,version:string}>
	 */
	public static function due( int $total, string $version, int $ver_count, array $done ): array {
		$out = array();
		foreach ( self::ALL as $n ) {
			if ( $total >= $n && ! in_array( 'all:' . $n, $done, true ) ) {
				$out[] = array( 'key' => 'all:' . $n, 'scope' => 'all', 'threshold' => $n, 'version' => '' );
			}
		}
		if ( '' !== $version ) {
			foreach ( self::PER_VERSION as $n ) {
				$key = 'ver:' . $version . ':' . $n;
				if ( $ver_count >= $n && ! in_array( $key, $done, true ) ) {
					$out[] = array( 'key' => $key, 'scope' => 'version', 'threshold' => $n, 'version' => $version );
				}
			}
		}
		return $out;
	}

	/** 一组节点的说明，如“累计 300 访客；版本 v2 满 500 访客”。 */
	public static function label( array $due ): string {
		$all = array_filter( $due, static fn( $d ) => 'all' === $d['scope'] );
		$ver = array_filter( $due, static fn( $d ) => 'version' === $d['scope'] );
		$out = array();
		if ( $all ) {
			$out[] = '累计 ' . max( array_column( $all, 'threshold' ) ) . ' 访客';
		}
		if ( $ver ) {
			$top   = max( array_column( $ver, 'threshold' ) );
			$out[] = '版本 ' . reset( $ver )['version'] . ' 满 ' . $top . ' 访客';
		}
		return implode( '；', $out );
	}

	/**
	 * 版本号对应的时间段 [开始毫秒, 结束毫秒)。版本日志按开始时间排列，下一个版本开始时这个版本结束。
	 *
	 * @param list<array{ver:string,since_ms:int}> $versions
	 * @return array{0:int,1:int}|null
	 */
	public static function version_range( array $versions, string $ver, int $now_ms ): ?array {
		$list = self::sorted( $versions );
		foreach ( $list as $i => $v ) {
			if ( $v['ver'] === $ver ) {
				return array( (int) $v['since_ms'], isset( $list[ $i + 1 ] ) ? (int) $list[ $i + 1 ]['since_ms'] : $now_ms );
			}
		}
		return null;
	}

	/** 上一个版本号（没有 = ''）。 */
	public static function previous_version( array $versions, string $ver ): string {
		$prev = '';
		foreach ( self::sorted( $versions ) as $v ) {
			if ( $v['ver'] === $ver ) {
				return $prev;
			}
			$prev = $v['ver'];
		}
		return '';
	}

	private static function sorted( array $versions ): array {
		$list = array_values( array_filter( $versions, static fn( $v ) => is_array( $v ) && '' !== (string) ( $v['ver'] ?? '' ) ) );
		usort( $list, static fn( $a, $b ) => (int) $a['since_ms'] <=> (int) $b['since_ms'] );
		return $list;
	}

	/**
	 * 一套数据。
	 *
	 * @param array $info        {name, label, from_day, to_day, from_ms, to_ms}
	 * @param array $by_creative Board::aggregate( dim = creative )
	 * @param array $by_attr     Board::aggregate( dim = attr )
	 */
	public static function dataset( array $info, array $by_creative, array $by_attr ): array {
		$strip = static function ( array $row ): array {
			unset( $row['visitor_ids'], $row['meta'] );
			return $row;
		};
		$creatives = array();
		foreach ( (array) ( $by_creative['rows'] ?? array() ) as $row ) {
			$creatives[] = array( 'code' => $row['key'], 'thumb' => (string) ( $row['meta']['thumb'] ?? '' ) ) + $strip( $row );
		}
		$attrs = array();
		foreach ( (array) ( $by_attr['rows'] ?? array() ) as $row ) {
			$pair    = explode( ':', (string) $row['key'], 2 );
			$attrs[] = array( 'attr' => $pair[0], 'value' => $pair[1] ?? '' ) + $strip( $row );
		}
		$total = $strip( (array) ( $by_creative['total'] ?? array() ) );
		// 2026-11-13：每条素材的人群画像（各维度前 Audience::TOP 项）和规则分析，交给“人群与素材分析师”。
		$profiles = array();
		$all      = (array) ( $by_creative['profiles'][ Audience::ALL ] ?? array() );
		foreach ( (array) ( $by_creative['profiles'] ?? array() ) as $code => $p ) {
			$dims = array();
			foreach ( (array) $p['dims'] as $dim => $list ) {
				$dims[ $dim ] = array_slice( (array) $list, 0, Audience::TOP );
			}
			$profiles[ (string) $code ] = array( 'total' => $p['total'], 'dims' => $dims ) + ( Audience::ALL !== (string) $code && $all ? array( 'analysis' => Audience::analyse( $p, $all ) ) : array() );
		}
		return array(
			'name'       => (string) $info['name'],
			'label'      => (string) $info['label'],
			'range'      => array( 'from_day' => (string) $info['from_day'], 'to_day' => (string) $info['to_day'], 'from_ms' => (int) $info['from_ms'], 'to_ms' => (int) $info['to_ms'] ),
			'attribution' => 'first',
			'totals'     => $total,
			'intent'     => (array) ( $total['intent'] ?? array_fill_keys( Contract::INTENT_TIERS, 0 ) ),
			'untagged'   => (int) ( $by_creative['untagged'] ?? 0 ),
			'audience'   => (array) ( $by_creative['audience'] ?? array() ),
			'creatives'  => $creatives,
			'attributes' => $attrs,
			'profiles'   => $profiles,
		);
	}

	/**
	 * 整份导出。
	 *
	 * @param array $meta     {id, kind: checkpoint|manual, label, checkpoints[], generated_at, site, tz, version}
	 * @param array $versions 版本日志
	 * @param list<array> $datasets dataset() 的结果
	 */
	public static function build( array $meta, array $versions, array $datasets ): array {
		return array(
			'format'       => self::FORMAT,
			'id'           => (string) ( $meta['id'] ?? '' ),
			'kind'         => (string) ( $meta['kind'] ?? 'manual' ),
			'label'        => (string) ( $meta['label'] ?? '' ),
			'checkpoints'  => array_values( (array) ( $meta['checkpoints'] ?? array() ) ),
			'generated_at' => (int) ( $meta['generated_at'] ?? 0 ),
			'site'         => (string) ( $meta['site'] ?? '' ),
			'tz'           => (string) ( $meta['tz'] ?? 'UTC' ),
			'version'      => (string) ( $meta['version'] ?? '' ),
			'min_valid'    => Board::MIN_VALID,
			'intent_tiers' => Intent_Tiers::LABELS,
			'version_log'  => self::sorted( $versions ),
			'datasets'     => array_values( $datasets ),
		);
	}

	public const CSV_HEAD = array( 'dataset', 'section', 'key', 'label', 'visitors', 'valid', 'intent_high', 'intent_mid', 'intent_low', 'intent_invalid', 'spend_cents', 'impressions', 'link_clicks', 'arrival_rate', 'valid_rate', 'mid_high_share', 'carted', 'cart_rate', 'checkout', 'checkout_rate', 'cashier', 'orders', 'sales_cents', 'cost_per_valid_cents', 'cost_per_high_cents', 'roas', 'insufficient' );

	/**
	 * CSV 行（第一行是表头）。一行 = 一套数据里的一个汇总、素材、属性取值或人群取值；版本日志单独几行。
	 *
	 * @return list<list<string>>
	 */
	public static function csv_rows( array $export ): array {
		$rows = array( self::CSV_HEAD );
		$num  = static fn( $v, bool $dec = false ): string => null === $v ? '' : ( $dec ? number_format( (float) $v, 4, '.', '' ) : (string) (int) $v );
		$line = static function ( string $ds, string $section, string $key, string $label, array $m ) use ( $num ): array {
			$intent = (array) ( $m['intent'] ?? array() );
			return array(
				self::text( $ds ), self::text( $section ), self::text( $key ), self::text( $label ),
				$num( $m['visitors'] ?? null ), $num( $m['valid'] ?? null ),
				$num( $intent['high'] ?? null ), $num( $intent['mid'] ?? null ), $num( $intent['low'] ?? null ), $num( $intent['invalid'] ?? null ),
				$num( $m['spend_cents'] ?? null ), $num( $m['impressions'] ?? null ), $num( $m['link_clicks'] ?? null ),
				$num( $m['arrival_rate'] ?? null, true ), $num( $m['valid_rate'] ?? null, true ), $num( $m['mid_high_share'] ?? null, true ),
				$num( $m['carted'] ?? null ), $num( $m['cart_rate'] ?? null, true ), $num( $m['checkout'] ?? null ), $num( $m['checkout_rate'] ?? null, true ),
				$num( $m['cashier'] ?? null ), $num( $m['orders'] ?? null ), $num( $m['sales_cents'] ?? null ),
				$num( $m['cost_per_valid'] ?? null, true ), $num( $m['cost_per_high'] ?? null, true ), $num( $m['roas'] ?? null, true ),
				array_key_exists( 'insufficient', $m ) ? ( $m['insufficient'] ? '1' : '0' ) : '',
			);
		};
		foreach ( (array) ( $export['version_log'] ?? array() ) as $v ) {
			$rows[] = $line( '', 'version_log', (string) $v['ver'], gmdate( 'Y-m-d H:i', intdiv( (int) $v['since_ms'], 1000 ) ) . ' UTC', array() );
		}
		foreach ( (array) ( $export['datasets'] ?? array() ) as $ds ) {
			$name   = (string) $ds['name'];
			$rows[] = $line( $name, 'total', 'total', (string) $ds['label'] . ' ' . $ds['range']['from_day'] . '~' . $ds['range']['to_day'], (array) $ds['totals'] );
			$rows[] = $line( $name, 'untagged', 'fb_untagged', '没带参数的 FB/IG 访客', array( 'visitors' => (int) $ds['untagged'] ) );
			foreach ( (array) $ds['creatives'] as $r ) {
				$rows[] = $line( $name, 'creative', (string) $r['code'], (string) $r['label'], $r );
			}
			foreach ( (array) $ds['attributes'] as $r ) {
				$rows[] = $line( $name, 'attribute', $r['attr'] . ':' . $r['value'], (string) $r['label'], $r );
			}
			foreach ( (array) ( $ds['profiles'] ?? array() ) as $code => $p ) {
				foreach ( (array) $p['dims'] as $dim => $list ) {
					foreach ( (array) $list as $r ) {
						$rows[] = $line( $name, 'profile:' . ( Audience::ALL === (string) $code ? 'ALL' : $code ) . ':' . $dim, (string) $r['value'], ( Audience::DIMS[ $dim ] ?? $dim ) . ' · ' . $r['label'], array( 'visitors' => $r['visitors'], 'valid' => $r['valid'], 'carted' => $r['carted'], 'cart_rate' => $r['cart_rate'], 'checkout' => $r['checkout'], 'orders' => $r['orders'], 'sales_cents' => $r['sales_cents'] ) );
					}
				}
			}
			foreach ( (array) $ds['audience'] as $group => $list ) {
				foreach ( (array) $list as $r ) {
					$rows[] = $line( $name, 'audience:' . $group, (string) $r['value'], (string) ( Board::AUDIENCE[ $group ] ?? $group ), array( 'visitors' => $r['visitors'], 'intent' => $r['intent'] ) );
				}
			}
		}
		return $rows;
	}

	/** 文本单元格：以 = + - @ 开头的加单引号，防止表格软件当公式执行。 */
	public static function text( string $v ): string {
		return preg_match( '/^[\s]*[=+@-]/u', $v ) ? "'" . $v : $v;
	}

	/** 导出编号：时间 + 随机数，只含字母数字和横线（也是文件名的一部分）。 */
	public static function valid_id( string $id ): bool {
		return 1 === preg_match( '/^\d{8}-\d{6}-[a-f0-9]{12}$/', $id );
	}

	// ---------- 定时检查 ----------

	public static function register_hooks(): void {
		add_action( self::HOOK, array( self::class, 'tick' ) );
		add_action( 'init', array( self::class, 'schedule' ), 41 );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 15 * MINUTE_IN_SECONDS, 'hourly', self::HOOK );
		}
	}

	/** 每小时：数付费访客，到节点就生成。 */
	public static function tick(): ?array {
		if ( ! Schema::installed() || ! \PeptideCore\Tracking\Schema::installed() ) {
			return null;
		}
		$versions = Board_Page::versions();
		$current  = Board_Page::current_version();
		$range    = '' !== $current ? self::version_range( $versions, $current, self::now_ms() ) : null;
		$done     = array_map( 'strval', (array) get_option( self::OPTION_DONE, array() ) );
		$due      = self::due( self::paid_visitors( 0 ), $current, $range ? self::paid_visitors( $range[0] ) : 0, $done );
		if ( ! $due ) {
			return null;
		}
		$export = self::generate( 'checkpoint', self::label( $due ), array_column( $due, 'key' ), null );
		if ( $export ) {
			update_option( self::OPTION_DONE, array_values( array_unique( array_merge( $done, array_column( $due, 'key' ) ) ) ), false );
		}
		return $export;
	}

	/** 某个时间以来带着付费参数进站的访客数（按访客的首次来源算，和导出口径一致）。 */
	public static function paid_visitors( int $since_ms ): int {
		global $wpdb;
		$s = \PeptideCore\Tracking\Schema::table( 'visits' );
		$v = \PeptideCore\Tracking\Schema::table( 'visitors' );
		// 首次访问已写入（线 1）时用 first_visit_id；没写时退回“最早一次访问”。
		$sql = "SELECT COUNT(*) FROM {$v} v INNER JOIN {$s} s ON s.id = (CASE WHEN v.first_visit_id > 0 THEN v.first_visit_id ELSE (SELECT MIN(x.id) FROM {$s} x WHERE x.visitor_id = v.id) END) WHERE s.source_class = 'paid' AND s.start_ms >= %d";
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $since_ms ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * 生成一份导出并登记到列表。
	 *
	 * @param string[]                $checkpoints
	 * @param array{0:string,1:string}|null $days 手动导出的日期范围；null = 检查点（全量 / 本版本 / 上一版本）
	 */
	public static function generate( string $kind, string $label, array $checkpoints, ?array $days ): ?array {
		$tz       = Board::timezone();
		$now      = self::now_ms();
		$versions = Board_Page::versions();
		$current  = Board_Page::current_version();
		$sets     = array();
		if ( null !== $days ) {
			$ms = Board::range_ms( $days[0], $days[1], $tz );
			if ( null === $ms ) {
				return null;
			}
			$sets[] = self::compute( array( 'name' => 'range', 'label' => '所选日期', 'from_ms' => $ms[0], 'to_ms' => $ms[1], 'from_day' => $days[0], 'to_day' => $days[1] ) );
		} else {
			$sets[] = self::compute( array( 'name' => 'all', 'label' => '全量', 'from_ms' => 0, 'to_ms' => $now, 'from_day' => '2000-01-01', 'to_day' => Board::day( $now, $tz ) ) );
			$prev   = '' !== $current ? self::previous_version( $versions, $current ) : '';
			foreach ( array( 'current_version' => $current, 'previous_version' => $prev ) as $name => $ver ) {
				$r = '' !== $ver ? self::version_range( $versions, $ver, $now ) : null;
				if ( $r ) {
					$sets[] = self::compute( array( 'name' => $name, 'label' => ( 'current_version' === $name ? '本版本 ' : '上一版本 ' ) . $ver, 'from_ms' => $r[0], 'to_ms' => $r[1], 'from_day' => Board::day( $r[0], $tz ), 'to_day' => Board::day( max( $r[0], $r[1] - 1 ), $tz ) ) );
				}
			}
		}
		$id     = gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 6 ) );
		$export = self::build(
			array( 'id' => $id, 'kind' => $kind, 'label' => $label, 'checkpoints' => $checkpoints, 'generated_at' => time(), 'site' => (string) home_url(), 'tz' => $tz, 'version' => $current ),
			$versions,
			$sets
		);
		$dir = self::dir();
		if ( '' === $dir ) {
			return null;
		}
		$json = (string) wp_json_encode( $export, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		$fh   = fopen( 'php://temp', 'w+' );
		fwrite( $fh, "\xEF\xBB\xBF" );
		foreach ( self::csv_rows( $export ) as $row ) {
			fputcsv( $fh, $row, ',', '"', '' );
		}
		rewind( $fh );
		$csv = (string) stream_get_contents( $fh );
		fclose( $fh );
		if ( false === file_put_contents( $dir . '/creatives-' . $id . '.json', $json ) || false === file_put_contents( $dir . '/creatives-' . $id . '.csv', $csv ) ) {
			return null;
		}
		$entry = array( 'id' => $id, 'kind' => $kind, 'label' => $label, 'created' => time(), 'version' => $current, 'datasets' => array_column( $sets, 'name' ), 'visitors' => (int) ( $sets[0]['totals']['visitors'] ?? 0 ) );
		$index = self::index();
		array_unshift( $index, $entry );
		foreach ( array_slice( $index, self::KEEP ) as $old ) {
			foreach ( array( 'json', 'csv' ) as $ext ) {
				$file = $dir . '/creatives-' . $old['id'] . '.' . $ext;
				if ( is_file( $file ) ) {
					unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				}
			}
		}
		update_option( self::OPTION_INDEX, array_slice( $index, 0, self::KEEP ), false );
		return $entry;
	}

	private static function compute( array $info ): array {
		$data = Board::load( array( $info['from_ms'], $info['to_ms'] ), $info['from_day'], $info['to_day'] );
		$opt  = array( 'mode' => 'first', 'from_ms' => $info['from_ms'], 'to_ms' => $info['to_ms'], 'tz' => Board::timezone() );
		return self::dataset( $info, Board::aggregate( $data, $opt + array( 'dim' => 'creative' ) ), Board::aggregate( $data, $opt + array( 'dim' => 'attr' ) ) );
	}

	/** @return list<array> */
	public static function index(): array {
		return array_values( array_filter( (array) get_option( self::OPTION_INDEX, array() ), static fn( $e ) => is_array( $e ) && self::valid_id( (string) ( $e['id'] ?? '' ) ) ) );
	}

	/** 导出目录（不存在就建，并放上禁止直接访问的文件）。失败 = ''。 */
	public static function dir(): string {
		$up = wp_upload_dir( null, false );
		if ( ! empty( $up['error'] ) ) {
			return '';
		}
		$dir = trailingslashit( (string) $up['basedir'] ) . self::DIR;
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		if ( ! is_file( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
		}
		if ( ! is_file( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		return $dir;
	}

	/** 把一份导出发给浏览器（调用方已检查权限和 nonce）。 */
	public static function stream( string $id, string $fmt ): void {
		$fmt = 'csv' === $fmt ? 'csv' : 'json';
		if ( ! self::valid_id( $id ) || ! in_array( $id, array_column( self::index(), 'id' ), true ) ) {
			wp_die( esc_html( '找不到这份导出（可能已经被清理）。' ), 404 );
		}
		$file = self::dir() . '/creatives-' . $id . '.' . $fmt;
		if ( ! is_file( $file ) ) {
			wp_die( esc_html( '导出文件不见了，请重新生成。' ), 404 );
		}
		nocache_headers();
		header( 'Content-Type: ' . ( 'csv' === $fmt ? 'text/csv' : 'application/json' ) . '; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="creatives-' . $id . '.' . $fmt . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/** 后台提示：有新的检查点导出还没看过（投手和店铺管理员都能看到；打开看板页面后不再提示）。 */
	public static function notice(): void {
		if ( ! current_user_can( Staff_Buyer::CAP_VIEW ) ) {
			return;
		}
		$latest = self::latest_checkpoint();
		if ( ! $latest || (string) get_user_meta( get_current_user_id(), self::META_SEEN, true ) >= $latest['id'] ) {
			return;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( Module::PAGE_BOARD === $page ) {
			return; // 看板页面自己显示。
		}
		printf(
			'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
			esc_html( '素材数据到检查点了（' . $latest['label'] . '），已生成分析导出。' ),
			esc_url( admin_url( 'admin.php?page=' . Module::PAGE_BOARD . '#pc-cr-exports' ) ),
			esc_html( '去素材看板下载' )
		);
	}

	public static function latest_checkpoint(): ?array {
		foreach ( self::index() as $e ) {
			if ( 'checkpoint' === $e['kind'] ) {
				return $e;
			}
		}
		return null;
	}

	public static function mark_seen(): void {
		$latest = self::latest_checkpoint();
		if ( $latest ) {
			update_user_meta( get_current_user_id(), self::META_SEEN, $latest['id'] );
		}
	}

	private static function now_ms(): int {
		return (int) floor( microtime( true ) * 1000 );
	}
}
