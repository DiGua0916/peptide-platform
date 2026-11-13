<?php
/**
 * 物流填单同步到腾讯文档（店主 2026-11-12）。
 *
 * - 店主在腾讯文档开放平台建应用（审核通过后拿到 Client ID / Secret），填到总后台，点“授权腾讯文档”用自己的账号登录授权一次。
 * - 填两个工作表的链接（发货登记、报关填单，链接里带 ?tab=）。总后台按第 1 行表头的名字找列，不要求列的顺序完全一样。
 * - 新的付款订单接在最后一个有“客户单号”的行下面，序号接着往下编；表里已经有的单号（店主手填的）不动。
 * - 我们写过的行，物流单号 / 妥投变了会更新那一行。腾讯文档接口不能设背景色：新行的绿色要靠表格本身的列格式。
 * - 接口：OAuth2 https://docs.qq.com/oauth/v2/…，表格 v3 /openapi/spreadsheet/v3/files/{fileId}/…（每次 batchUpdate 最多 5 个操作）。
 *
 * @package PeptideHq
 */

namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Tencent_Docs {

	public const OPTION     = 'pchq_tdocs';
	public const CRON       = 'pchq_tdocs_sync';
	public const CRON_SOON  = 'pchq_tdocs_sync_soon';
	private const BASE      = 'https://docs.qq.com';
	private const DB_OPTION = 'pchq_tdocs_db';
	private const LOOKBACK  = 60; // 同步最近 60 天付款的订单。

	public const KINDS = array(
		'ship'    => '发货登记',
		'customs' => '报关填单',
	);

	public static function register_hooks(): void {
		add_action( self::CRON, array( self::class, 'sync_all' ) );
		add_action( self::CRON_SOON, array( self::class, 'sync_all' ) );
		add_action( 'admin_init', array( self::class, 'maybe_install' ) );
		add_action(
			'init',
			static function (): void {
				if ( ! wp_next_scheduled( self::CRON ) ) {
					wp_schedule_event( time() + 900, 'hourly', self::CRON );
				}
			}
		);
	}

	/** 有新订单报上来：2 分钟后同步一次（合并同一时间的多单）。 */
	public static function soon(): void {
		if ( self::ready() && ! wp_next_scheduled( self::CRON_SOON ) ) {
			wp_schedule_single_event( time() + 120, self::CRON_SOON );
		}
	}

	// ---------- 设置 ----------

	public static function settings(): array {
		$s = get_option( self::OPTION, array() );
		return array_merge(
			array( 'client_id' => '', 'secret_box' => '', 'token_box' => '', 'ship_url' => '', 'customs_url' => '', 'files' => array(), 'last_sync' => 0, 'last_error' => '', 'last_result' => '' ),
			is_array( $s ) ? $s : array()
		);
	}

	private static function put( array $changes ): void {
		update_option( self::OPTION, array_merge( self::settings(), $changes ), false );
	}

	private static function secret(): string {
		$b = (string) self::settings()['secret_box'];
		try {
			return '' !== $b ? (string) Crypto::decrypt( $b ) : '';
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	private static function token(): array {
		$b = (string) self::settings()['token_box'];
		try {
			$t = '' !== $b ? json_decode( (string) Crypto::decrypt( $b ), true ) : null;
		} catch ( \Throwable $e ) {
			$t = null;
		}
		return is_array( $t ) ? $t : array();
	}

	private static function store_token( array $t ): void {
		self::put( array( 'token_box' => Crypto::encrypt( (string) wp_json_encode( $t ) ) ) );
	}

	public static function has_app(): bool {
		$s = self::settings();
		return '' !== $s['client_id'] && '' !== self::secret();
	}

	public static function authorized(): bool {
		$t = self::token();
		return ! empty( $t['refresh_token'] ) && ! empty( $t['open_id'] );
	}

	public static function ready(): bool {
		$s = self::settings();
		return self::has_app() && self::authorized() && ( '' !== $s['ship_url'] || '' !== $s['customs_url'] );
	}

	public static function save( array $in ): void {
		if ( ! empty( $in['tdocs_clear'] ) ) {
			delete_option( self::OPTION );
			return;
		}
		$s   = self::settings();
		$new = array();
		$id  = trim( sanitize_text_field( (string) ( $in['tdocs_client_id'] ?? '' ) ) );
		if ( '' !== $id && $id !== $s['client_id'] ) {
			$new['client_id'] = $id;
			$new['token_box'] = ''; // 换了应用要重新授权。
		}
		$sec = trim( sanitize_text_field( (string) ( $in['tdocs_secret'] ?? '' ) ) );
		if ( '' !== $sec ) {
			$new['secret_box'] = Crypto::encrypt( $sec );
		}
		foreach ( array( 'ship', 'customs' ) as $k ) {
			if ( isset( $in[ 'tdocs_' . $k . '_url' ] ) ) {
				$url = esc_url_raw( trim( (string) $in[ 'tdocs_' . $k . '_url' ] ) );
				if ( $url !== $s[ $k . '_url' ] ) {
					$new[ $k . '_url' ] = $url;
					$files              = (array) $s['files'];
					unset( $files[ $k ] );
					$new['files'] = $files;
				}
			}
		}
		if ( $new ) {
			self::put( $new );
		}
	}

	// ---------- OAuth ----------

	public static function redirect_uri(): string {
		return admin_url( 'admin-post.php?action=pchq_tdocs_callback' );
	}

	public static function authorize_url(): string {
		$state = wp_create_nonce( 'pchq_tdocs_state' );
		return self::BASE . '/oauth/v2/authorize?' . http_build_query(
			array(
				'client_id'     => self::settings()['client_id'],
				'redirect_uri'  => self::redirect_uri(),
				'new_login'     => 1,
				'response_type' => 'code',
				'scope'         => 'all',
				'state'         => $state,
			)
		);
	}

	/** @return true|\WP_Error */
	public static function exchange( string $code ) {
		$j = self::oauth(
			array(
				'client_id'     => self::settings()['client_id'],
				'client_secret' => self::secret(),
				'redirect_uri'  => self::redirect_uri(),
				'grant_type'    => 'authorization_code',
				'code'          => $code,
			)
		);
		if ( is_wp_error( $j ) ) {
			return $j;
		}
		self::store_token(
			array(
				'access_token'  => (string) $j['access_token'],
				'refresh_token' => (string) ( $j['refresh_token'] ?? '' ),
				'open_id'       => (string) ( $j['user_id'] ?? '' ),
				'expires'       => time() + (int) ( $j['expires_in'] ?? 86400 ),
				'authorized'    => time(),
			)
		);
		return true;
	}

	/** @return array|\WP_Error */
	private static function oauth( array $q ) {
		$res = wp_remote_get( self::BASE . '/oauth/v2/token?' . http_build_query( $q ), array( 'timeout' => 15 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$j = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) || empty( $j['access_token'] ) ) {
			$msg = is_array( $j ) ? (string) ( $j['error_description'] ?? ( $j['message'] ?? ( $j['error'] ?? '' ) ) ) : '';
			return new \WP_Error( 'pchq_tdocs_oauth', '腾讯文档授权失败（HTTP ' . wp_remote_retrieve_response_code( $res ) . '）' . ( '' !== $msg ? '：' . $msg : '' ) );
		}
		return $j;
	}

	/** @return string|\WP_Error 可用的 Access Token（快过期时自动续期）。 */
	private static function access() {
		$t = self::token();
		if ( empty( $t['refresh_token'] ) ) {
			return new \WP_Error( 'pchq_tdocs_auth', '还没有授权腾讯文档。' );
		}
		if ( ! empty( $t['access_token'] ) && (int) ( $t['expires'] ?? 0 ) > time() + DAY_IN_SECONDS ) {
			return (string) $t['access_token'];
		}
		$j = self::oauth(
			array(
				'client_id'     => self::settings()['client_id'],
				'client_secret' => self::secret(),
				'grant_type'    => 'refresh_token',
				'refresh_token' => (string) $t['refresh_token'],
			)
		);
		if ( is_wp_error( $j ) ) {
			return $j;
		}
		$t['access_token'] = (string) $j['access_token'];
		$t['expires']      = time() + (int) ( $j['expires_in'] ?? 86400 );
		if ( ! empty( $j['refresh_token'] ) ) {
			$t['refresh_token'] = (string) $j['refresh_token'];
		}
		if ( ! empty( $j['user_id'] ) ) {
			$t['open_id'] = (string) $j['user_id'];
		}
		self::store_token( $t );
		return $t['access_token'];
	}

	/** @return array|\WP_Error */
	private static function api( string $method, string $path, ?array $body = null ) {
		$tok = self::access();
		if ( is_wp_error( $tok ) ) {
			return $tok;
		}
		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'Access-Token' => $tok,
				'Client-Id'    => self::settings()['client_id'],
				'Open-Id'      => (string) ( self::token()['open_id'] ?? '' ),
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = (string) wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		$res = wp_remote_request( self::BASE . $path, $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$j    = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code || ( is_array( $j ) && isset( $j['ret'] ) && 0 !== (int) $j['ret'] ) ) {
			$msg = is_array( $j ) ? (string) ( $j['message'] ?? ( $j['msg'] ?? '' ) ) : '';
			$map = array( 401 => '授权已失效，请重新点“授权腾讯文档”', 403 => '这个账号没有编辑这个表格的权限', 404 => '找不到表格或工作表，请检查链接', 429 => '请求太频繁，稍后会自动重试' );
			return new \WP_Error( 'pchq_tdocs_api', ( $map[ $code ] ?? '腾讯文档接口出错（HTTP ' . $code . '）' ) . ( '' !== $msg ? '：' . $msg : '' ) );
		}
		return is_array( $j ) ? $j : array();
	}

	// ---------- 表格定位 ----------

	/** 从链接取 encodedID（/sheet/ 后面那段）和工作表 ID（?tab=）。 */
	public static function parse_url( string $url ): ?array {
		if ( ! preg_match( '#docs\.qq\.com/sheet/([A-Za-z0-9_-]+)#', $url, $m ) ) {
			return null;
		}
		$q = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		$tab = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $q['tab'] ?? '' ) );
		return '' !== $tab ? array( 'encoded' => $m[1], 'sheet' => $tab ) : null;
	}

	/** @return array{file:string,sheet:string}|\WP_Error */
	private static function target( string $kind ) {
		$s     = self::settings();
		$files = (array) $s['files'];
		if ( ! empty( $files[ $kind ]['file'] ) ) {
			return $files[ $kind ];
		}
		$p = self::parse_url( (string) $s[ $kind . '_url' ] );
		if ( ! $p ) {
			return new \WP_Error( 'pchq_tdocs_url', self::KINDS[ $kind ] . '的链接不对：要先点开那个工作表，再复制浏览器地址（带 ?tab=）。' );
		}
		$j = self::api( 'GET', '/openapi/drive/v2/util/converter?type=2&value=' . rawurlencode( $p['encoded'] ) );
		if ( is_wp_error( $j ) ) {
			return $j;
		}
		$file = (string) ( $j['data']['fileID'] ?? '' );
		if ( '' === $file ) {
			return new \WP_Error( 'pchq_tdocs_url', '腾讯文档没有返回表格编号，请检查链接。' );
		}
		$files[ $kind ] = array( 'file' => $file, 'sheet' => $p['sheet'] );
		self::put( array( 'files' => $files ) );
		return $files[ $kind ];
	}

	private static function fpath( string $file ): string {
		return '/openapi/spreadsheet/v3/files/' . rawurlencode( $file );
	}

	private static function col( int $i ): string {
		$s = '';
		for ( $i++; $i > 0; $i = intdiv( $i - 1, 26 ) ) {
			$s = chr( 65 + ( $i - 1 ) % 26 ) . $s;
		}
		return $s;
	}

	private static function value( array $cell ): string {
		$v = (array) ( $cell['cellValue'] ?? array() );
		if ( isset( $v['text'] ) ) {
			return trim( (string) $v['text'] );
		}
		if ( isset( $v['number'] ) ) {
			$n = (float) $v['number'];
			return floor( $n ) === $n ? (string) (int) $n : (string) $n;
		}
		if ( isset( $v['link']['text'] ) ) {
			return trim( (string) $v['link']['text'] );
		}
		return '';
	}

	/**
	 * 读一块区域（每次最多 1000 行），返回二维文字数组（行号从 $start 起，0 开始）。
	 *
	 * @return array<int,array<int,string>>|\WP_Error
	 */
	private static function read( array $t, int $start, int $end, int $cols ) {
		$out = array();
		for ( $r = $start; $r < $end; $r += 1000 ) {
			$to    = min( $end, $r + 1000 );
			$range = 'A' . ( $r + 1 ) . ':' . self::col( $cols - 1 ) . $to;
			$j     = self::api( 'GET', self::fpath( $t['file'] ) . '/' . rawurlencode( $t['sheet'] ) . '/' . $range );
			if ( is_wp_error( $j ) ) {
				return $j;
			}
			$g  = (array) ( $j['gridData'] ?? array() );
			$r0 = (int) ( $g['startRow'] ?? $r );
			foreach ( (array) ( $g['rows'] ?? array() ) as $i => $row ) {
				$line = array();
				foreach ( (array) ( $row['values'] ?? array() ) as $ci => $cell ) {
					$line[ $ci ] = self::value( (array) $cell );
				}
				$out[ $r0 + $i ] = $line;
			}
		}
		return $out;
	}

	private static function norm( string $h ): string {
		return str_replace( array( ' ', '　', '，', ',', '/', '／' ), '', strtolower( trim( $h ) ) );
	}

	// ---------- 同步 ----------

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pchq_tdocs_rows';
	}

	public static function maybe_install(): void {
		if ( '1' === get_option( self::DB_OPTION ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta(
			"CREATE TABLE {$t} (
 kind varchar(10) NOT NULL,
 file_sheet varchar(120) NOT NULL,
 site_id bigint(20) unsigned NOT NULL,
 order_id bigint(20) unsigned NOT NULL,
 row_index int unsigned NOT NULL,
 seq varchar(20) NOT NULL DEFAULT '',
 hash char(32) NOT NULL DEFAULT '',
 updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY  (kind,file_sheet,site_id,order_id)
) " . $wpdb->get_charset_collate() . ';'
		);
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) {
			update_option( self::DB_OPTION, '1', false );
		}
	}

	/** 定时任务 / 按钮：两个表都同步。 */
	public static function sync_all(): string {
		if ( ! self::ready() ) {
			return '';
		}
		self::maybe_install();
		$msgs = array();
		$err  = '';
		foreach ( array_keys( self::KINDS ) as $kind ) {
			if ( '' === (string) self::settings()[ $kind . '_url' ] ) {
				continue;
			}
			$r = self::sync( $kind );
			if ( is_wp_error( $r ) ) {
				$err   .= self::KINDS[ $kind ] . '：' . $r->get_error_message() . '　';
				$msgs[] = self::KINDS[ $kind ] . '同步失败';
			} else {
				$msgs[] = self::KINDS[ $kind ] . sprintf( '：新增 %d 行，更新 %d 行，已在表里 %d 单', $r['added'], $r['updated'], $r['present'] );
			}
		}
		$result = implode( '；', $msgs );
		self::put( array( 'last_sync' => time(), 'last_error' => trim( $err ), 'last_result' => $result ) );
		return '' !== $err ? trim( $err ) : $result;
	}

	/** @return array{added:int,updated:int,present:int}|\WP_Error */
	public static function sync( string $kind ) {
		$t = self::target( $kind );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		$meta = self::api( 'GET', self::fpath( $t['file'] ) );
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}
		$used = 0;
		$cols = 26;
		foreach ( (array) ( $meta['properties'] ?? array() ) as $p ) {
			if ( (string) ( $p['sheetId'] ?? '' ) === $t['sheet'] ) {
				$used = (int) ( $p['rowCount'] ?? 0 );
				$cols = max( 26, min( 200, (int) ( $p['columnCount'] ?? 26 ) ) );
			}
		}
		if ( $used < 1 ) {
			return new \WP_Error( 'pchq_tdocs_head', self::KINDS[ $kind ] . '的工作表是空的：第 1 行要有表头（序号、日期、客户单号……）。' );
		}

		// 第 1 行表头 → 我们的列放在表格的第几列。
		$all  = Logistics::rows( time() - self::LOOKBACK * DAY_IN_SECONDS, time() + DAY_IN_SECONDS );
		$data = 'customs' === $kind ? Logistics::sheet_customs( $all ) : Logistics::sheet_ship( $all );
		$ours = array_shift( $data );
		$grid = self::read( $t, 0, $used, $cols );
		if ( is_wp_error( $grid ) ) {
			return $grid;
		}
		$head = array();
		foreach ( (array) ( $grid[0] ?? array() ) as $ci => $h ) {
			if ( '' !== $h ) {
				$head[ self::norm( $h ) ] = $ci;
			}
		}
		$map = array(); // 我们的第几列 → 表格第几列
		foreach ( $ours as $oi => $h ) {
			$k = self::norm( $h );
			if ( isset( $head[ $k ] ) ) {
				$map[ $oi ] = $head[ $k ];
				continue;
			}
			foreach ( $head as $hk => $ci ) { // “州/省”“州,省”等写法差一点也认。
				if ( '' !== $hk && ( str_starts_with( $hk, $k ) || str_starts_with( $k, $hk ) ) && mb_strlen( $hk ) >= 2 ) {
					$map[ $oi ] = $ci;
					break;
				}
			}
		}
		if ( ! isset( $map[2] ) ) {
			return new \WP_Error( 'pchq_tdocs_head', self::KINDS[ $kind ] . '的第 1 行找不到“客户单号”这一列。' );
		}
		$c_no  = $map[2];
		$c_seq = $map[0] ?? null;
		$width = max( $map ) + 1;

		// 表里已有的单号、最后一个有单号的行、最后的序号。
		$present = array();
		$last    = 0;
		$seq     = 0;
		foreach ( $grid as $ri => $line ) {
			if ( 0 === $ri ) {
				continue;
			}
			$no = (string) ( $line[ $c_no ] ?? '' );
			if ( '' !== $no ) {
				$present[ $no ] = $ri;
				$last           = max( $last, $ri );
				if ( null !== $c_seq && is_numeric( $line[ $c_seq ] ?? '' ) ) {
					$seq = (int) $line[ $c_seq ];
				}
			}
		}
		if ( null !== $c_seq && 0 === $seq ) {
			$seq = $last; // 没有数字序号时按行数接着编。
		}

		global $wpdb;
		$tb   = self::table();
		$fs   = $t['file'] . '|' . $t['sheet'];
		$mine = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tb} WHERE kind = %s AND file_sheet = %s", $kind, $fs ), ARRAY_A ) as $m ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$mine[ (int) $m['site_id'] . ':' . (int) $m['order_id'] ] = $m;
		}

		$appends = array();
		$updates = array();
		$n_pres  = 0;
		foreach ( $all as $i => $r ) {
			$key  = (int) $r['site_id'] . ':' . (int) $r['order_id'];
			$line = $data[ $i ];
			$no   = (string) $line[2];
			$m    = $mine[ $key ] ?? null;
			if ( $m && ( $present[ $no ] ?? -1 ) === (int) $m['row_index'] ) {
				// 我们写过的行：内容变了（物流单号、妥投）就更新，序号保留原来的。
				$line[0] = is_numeric( $m['seq'] ) ? (int) $m['seq'] : $m['seq'];
				$h       = md5( (string) wp_json_encode( $line ) );
				if ( $h !== $m['hash'] ) {
					$updates[] = array( 'row' => (int) $m['row_index'], 'line' => $line, 'key' => $key, 'hash' => $h, 'seq' => (string) $m['seq'] );
				}
				continue;
			}
			if ( isset( $present[ $no ] ) ) {
				++$n_pres; // 店主手填过，或者别处已经有：不动。
				continue;
			}
			$line[0]   = ++$seq;
			$appends[] = array( 'row' => ++$last, 'line' => $line, 'key' => $key, 'hash' => md5( (string) wp_json_encode( $line ) ), 'seq' => (string) $seq );
		}

		$jobs = array_merge( $updates, $appends );
		foreach ( array_chunk( $jobs, 5 ) as $chunk ) {
			$reqs = array();
			foreach ( $chunk as $job ) {
				$cells = array_fill( 0, $width, array( 'cellValue' => array( 'text' => '' ) ) );
				foreach ( $map as $oi => $ci ) {
					$v            = $job['line'][ $oi ];
					$cells[ $ci ] = array( 'cellValue' => ( is_int( $v ) || is_float( $v ) ) ? array( 'number' => $v ) : array( 'text' => (string) $v ) );
				}
				$reqs[] = array( 'updateRangeRequest' => array( 'sheetId' => $t['sheet'], 'gridData' => array( 'startRow' => $job['row'], 'startColumn' => 0, 'rows' => array( array( 'values' => $cells ) ) ) ) );
			}
			$res = self::api( 'POST', self::fpath( $t['file'] ) . '/batchUpdate', array( 'requests' => $reqs ) );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			foreach ( $chunk as $job ) {
				list( $site, $order ) = array_map( 'intval', explode( ':', $job['key'] ) );
				$wpdb->replace( $tb, array( 'kind' => $kind, 'file_sheet' => $fs, 'site_id' => $site, 'order_id' => $order, 'row_index' => $job['row'], 'seq' => $job['seq'], 'hash' => $job['hash'], 'updated_at' => time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			usleep( 300000 ); // 每个表每分钟最多写 50 次。
		}
		return array( 'added' => count( $appends ), 'updated' => count( $updates ), 'present' => $n_pres );
	}
}
