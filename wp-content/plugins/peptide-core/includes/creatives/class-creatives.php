<?php
/**
 * 素材库的数据（线 2）：素材的登记、编号、修改、归档；素材卡片的统计；未登记广告和补登记归位；
 * 以及和总后台通信的签名请求（花费推送、词表同步共用）。
 *
 * - 编号：本店独立计数。下一个号 = 素材表和广告表里出现过的最大编号 + 1（广告名里先用了的号不会再分给别的素材）。
 *   并发时靠 code 的唯一索引：插入撞号就重新取号再试。
 * - 状态：最近 LIVE_DAYS 天（含今天，店铺时区）在 pc_ad_daily_spend 有花费 = 投放中，否则已停；归档的素材单独算。
 * - 未登记广告：pc_ad_ads 里编号为空、或编号不在素材表里的广告。补登记后改写这条广告的编号，
 *   并把这条广告带来的访问（pc_track_visits.utm_content = ad_id）的 creative_code 一起改掉，然后触发
 *   do_action( 'pc_creatives_relinked', $ad_id, $code )，看板（线 4）可以据此重报受影响的日期。
 *
 * 纯函数（不读数据库）放在前面，测试直接调用。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Creatives;

use PeptideCore\Hq_Connector;
use PeptideCore\Order_Report;
use PeptideCore\Tracking;

defined( 'ABSPATH' ) || exit;

final class Creatives {

	/** 最近几天有花费算投放中（含今天）。 */
	public const LIVE_DAYS = 3;

	/** 未登记广告最多列多少条。 */
	public const UNREG_LIMIT = 200;

	public const STATUS_ACTIVE   = 'active';
	public const STATUS_ARCHIVED = 'archived';

	// ---------- 纯函数 ----------

	/**
	 * 下一个编号的序号：已用编号里最大的数字 + 1。不合规的编号忽略。
	 *
	 * @param string[] $codes
	 */
	public static function next_number( array $codes ): int {
		$max = 0;
		foreach ( $codes as $code ) {
			$code = (string) $code;
			if ( Contract::valid_code( $code ) ) {
				$max = max( $max, (int) substr( $code, 1 ) );
			}
		}
		return $max + 1;
	}

	/**
	 * 表单提交的属性：只收词表里的属性名，取值小写去空格；空的不收。
	 *
	 * @param array<string,mixed> $input
	 * @param array<int,array{attr:string,value:string}> $vocab
	 * @return array{attrs:array<string,string>,problems:string[]}
	 */
	public static function clean_attrs( array $input, array $vocab ): array {
		$names = array_merge( Contract::REQUIRED_ATTRS, array( 'lang' ) );
		$attrs = array();
		foreach ( $names as $key ) {
			$v = isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? strtolower( trim( (string) $input[ $key ] ) ) : '';
			if ( '' !== $v ) {
				$attrs[ $key ] = $v;
			}
		}
		return array( 'attrs' => $attrs, 'problems' => Contract::attr_problems( $attrs, $vocab ) );
	}

	/** 属性名的中文。 */
	public static function attr_name( string $attr ): string {
		$names = array( 'hook' => '钩子', 'fmt' => '形式', 'angle' => '卖点', 'lang' => '语言' );
		return $names[ $attr ] ?? $attr;
	}

	/**
	 * 属性取值 → 中文名（词表里没有的取值原样显示）。
	 *
	 * @param array<int,array{attr:string,value:string,label:string}> $vocab
	 * @return array<string,array<string,string>> attr => value => label
	 */
	public static function label_map( array $vocab ): array {
		$out = array();
		foreach ( $vocab as $item ) {
			$out[ $item['attr'] ][ $item['value'] ] = '' !== (string) ( $item['label'] ?? '' ) ? (string) $item['label'] : $item['value'];
		}
		return $out;
	}

	/** 状态：有花费的最后一天在最近 LIVE_DAYS 天内 = live，否则 stopped。$today、$last_day 都是 YYYY-MM-DD。 */
	public static function status_for( string $last_day, string $today ): string {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $last_day ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $today ) ) {
			return 'stopped';
		}
		$from = gmdate( 'Y-m-d', (int) strtotime( $today . ' 00:00:00 UTC' ) - ( self::LIVE_DAYS - 1 ) * 86400 );
		return ( $last_day >= $from && $last_day <= $today ) ? 'live' : 'stopped';
	}

	/** 状态的中文标签。 */
	public const STATE_LABELS = array( 'live' => '投放中', 'stopped' => '已停', 'pending' => '有访客 · 待导入花费', 'new' => '未投放', 'archived' => '已归档' );

	/**
	 * 卡片 / 链接列表显示的状态（2026-11-12）：还没导入过花费时不算“已停”——有访客 = pending，没有 = new。
	 *
	 * @param array{last_day?:string,visitors?:int} $st stats() 的一行
	 */
	public static function display_state( array $st, string $today ): string {
		if ( '' === (string) ( $st['last_day'] ?? '' ) ) {
			return (int) ( $st['visitors'] ?? 0 ) > 0 ? 'pending' : 'new';
		}
		return self::status_for( (string) $st['last_day'], $today );
	}

	/**
	 * 未登记的广告：编号为空，或编号不在已登记的编号里。
	 *
	 * @param array<int,array<string,mixed>> $ads   pc_ad_ads 的行
	 * @param string[]                       $codes 素材表里的编号（含已归档）
	 * @return array<int,array<string,mixed>>
	 */
	public static function unregistered( array $ads, array $codes ): array {
		$known = array_fill_keys( $codes, true );
		return array_values(
			array_filter(
				$ads,
				static fn( array $ad ): bool => '' === (string) ( $ad['code'] ?? '' ) || ! isset( $known[ (string) $ad['code'] ] )
			)
		);
	}

	/**
	 * 补登记一条广告时用哪个编号：广告名里写了合规编号、而且还没被素材占用，就沿用它（Meta 里的广告名不用改）；
	 * 否则返回空，由 create() 分配下一个号。
	 *
	 * @param string[] $taken 素材表里已有的编号
	 */
	public static function code_from_ad( string $ad_code, string $ad_name, array $taken ): string {
		foreach ( array( $ad_code, Contract::parse_ad_name( $ad_name )['code'] ) as $code ) {
			if ( Contract::valid_code( $code ) && ! in_array( $code, $taken, true ) ) {
				return $code;
			}
		}
		return '';
	}

	/** 美分 → $1,234.56 */
	public static function money( int $cents ): string {
		return ( $cents < 0 ? '-$' : '$' ) . number_format( abs( $cents ) / 100, 2 );
	}

	// ---------- 素材 ----------

	private static function decode( array $row ): array {
		$attrs  = json_decode( (string) ( $row['attrs'] ?? '' ), true );
		$custom = json_decode( (string) ( $row['custom'] ?? '' ), true );
		return array(
			'id'            => (int) $row['id'],
			'code'          => (string) $row['code'],
			'attachment_id' => (int) $row['attachment_id'],
			'video_id'      => (int) ( $row['video_id'] ?? 0 ),
			'attrs'         => is_array( $attrs ) ? array_map( 'strval', $attrs ) : array(),
			'custom'        => is_array( $custom ) ? array_map( 'strval', $custom ) : array(),
			'note'          => (string) $row['note'],
			'status'        => (string) $row['status'],
			'created_at'    => (int) $row['created_at'],
			'updated_at'    => (int) $row['updated_at'],
		);
	}

	/** @return array<string,array<string,mixed>> code => 素材，按编号倒序 */
	public static function all(): array {
		global $wpdb;
		if ( ! Schema::installed() ) {
			return array();
		}
		$t    = Schema::table( 'creatives' );
		$rows = (array) $wpdb->get_results( "SELECT * FROM {$t} ORDER BY id DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$out  = array();
		foreach ( $rows as $row ) {
			$c               = self::decode( $row );
			$out[ $c['code'] ] = $c;
		}
		return $out;
	}

	public static function get( string $code ): ?array {
		global $wpdb;
		if ( ! Contract::valid_code( $code ) || ! Schema::installed() ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'creatives' ) . ' WHERE code = %s', $code ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		return is_array( $row ) ? self::decode( $row ) : null;
	}

	/** @return string[] 素材表里所有编号 */
	public static function codes(): array {
		global $wpdb;
		return Schema::installed() ? array_map( 'strval', (array) $wpdb->get_col( 'SELECT code FROM ' . Schema::table( 'creatives' ) ) ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/** @return string[] 广告表里用过的编号 */
	private static function ad_codes(): array {
		global $wpdb;
		return array_map( 'strval', (array) $wpdb->get_col( 'SELECT DISTINCT code FROM ' . Schema::table( 'ads' ) . " WHERE code <> ''" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * 登记一个素材，返回分到的编号。$want 是想用的编号（补登记沿用广告名里的号），被占用时改分下一个号。
	 *
	 * @param array<string,string> $attrs 已校验
	 * @return string|\WP_Error
	 */
	public static function create( array $attrs, int $attachment_id, string $note, string $want = '', int $video_id = 0 ) {
		global $wpdb;
		Schema::maybe_install();
		$t = Schema::table( 'creatives' );
		$now = time();
		$suppress = $wpdb->suppress_errors( true );
		for ( $try = 0; $try < 8; $try++ ) {
			$code = ( '' !== $want && Contract::valid_code( $want ) ) ? $want : Contract::code_for( self::next_number( array_merge( self::codes(), self::ad_codes(), self::retired_codes() ) ) );
			$ok   = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$t,
				array(
					'code'          => $code,
					'attachment_id' => max( 0, $attachment_id ),
					'video_id'      => max( 0, $video_id ),
					'attrs'         => (string) wp_json_encode( $attrs ),
					'custom'        => '{}',
					'lang'          => (string) ( $attrs['lang'] ?? '' ),
					'note'          => self::cut( $note, 500 ),
					'status'        => self::STATUS_ACTIVE,
					'created_by'    => get_current_user_id(),
					'created_at'    => $now,
					'updated_at'    => $now,
				)
			);
			if ( false !== $ok ) {
				$wpdb->suppress_errors( $suppress );
				return $code;
			}
			$want = ''; // 撞号（别人刚用了这个号）：重新取下一个号。
		}
		$wpdb->suppress_errors( $suppress );
		return new \WP_Error( 'pc_creative_code', '分配编号失败，请再保存一次。' );
	}

	/** 修改属性、备注、图片 / 视频（$attachment_id、$video_id 为 null = 不改）。 */
	public static function update( string $code, array $attrs, string $note, ?int $attachment_id = null, ?int $video_id = null ): bool {
		global $wpdb;
		$data = array(
			'attrs'      => (string) wp_json_encode( $attrs ),
			'lang'       => (string) ( $attrs['lang'] ?? '' ),
			'note'       => self::cut( $note, 500 ),
			'updated_at' => time(),
		);
		if ( null !== $attachment_id ) {
			$data['attachment_id'] = max( 0, $attachment_id );
		}
		if ( null !== $video_id ) {
			$data['video_id'] = max( 0, $video_id );
		}
		return false !== $wpdb->update( Schema::table( 'creatives' ), $data, array( 'code' => $code ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** 删除过的编号（2026-11-13）：不再分给新素材，免得 Meta 里还挂着的旧链接算到新素材上。 */
	public const RETIRED = 'pc_cr_retired_codes';

	/** @return string[] */
	public static function retired_codes(): array {
		return array_values( array_filter( array_map( 'strval', (array) get_option( self::RETIRED, array() ) ), array( Contract::class, 'valid_code' ) ) );
	}

	/**
	 * 能不能删：没有进站访客、没有花费、没有广告用过这个编号才能删（只删“传错了、还没用过”的素材）；
	 * 已经有数据的只能归档，数据不能丢。返回不能删的原因，能删 = ''。
	 */
	public static function delete_blocker( string $code, ?array $st = null ): string {
		global $wpdb;
		$st = $st ?? ( self::stats()[ $code ] ?? array() );
		if ( (int) ( $st['visitors'] ?? 0 ) > 0 || (int) ( $st['spend_cents'] ?? 0 ) > 0 ) {
			return '已经有访客或花费数据';
		}
		$ads = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'ads' ) . ' WHERE code = %s', $code ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		return $ads > 0 ? '已经有广告用了这个素材' : '';
	}

	/**
	 * 删除素材（2026-11-13）：只删素材记录和它的落地页、AI 分析记录；图片 / 视频留在媒体库。
	 *
	 * @return true|\WP_Error
	 */
	public static function delete( string $code ) {
		global $wpdb;
		if ( ! self::get( $code ) ) {
			return new \WP_Error( 'pc_creative_missing', '找不到这个素材。' );
		}
		$why = self::delete_blocker( $code );
		if ( '' !== $why ) {
			return new \WP_Error( 'pc_creative_in_use', $code . ' ' . $why . '，不能删除，可以归档（数据保留，随时恢复）。' );
		}
		if ( false === $wpdb->delete( Schema::table( 'creatives' ), array( 'code' => $code ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new \WP_Error( 'pc_creative_delete', '删除失败，请再试一次。' );
		}
		update_option( self::RETIRED, array_values( array_unique( array_merge( self::retired_codes(), array( $code ) ) ) ), false );
		foreach ( array( Ads_Panel::LANDINGS, Audience_Ai::OPTION ) as $opt ) {
			$v = get_option( $opt, array() );
			if ( is_array( $v ) && isset( $v[ $code ] ) ) {
				unset( $v[ $code ] );
				update_option( $opt, $v, false );
			}
		}
		return true;
	}

	public static function set_status( string $code, string $status ): bool {
		global $wpdb;
		$status = self::STATUS_ARCHIVED === $status ? self::STATUS_ARCHIVED : self::STATUS_ACTIVE;
		return false !== $wpdb->update( Schema::table( 'creatives' ), array( 'status' => $status, 'updated_at' => time() ), array( 'code' => $code ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private static function cut( string $text, int $len ): string {
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) ?? '' );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $len ) : substr( $text, 0, $len );
	}

	// ---------- 统计 ----------

	/**
	 * 每个编号的累计花费、最后有花费的一天、进站访客数。
	 *
	 * @return array<string,array{spend_cents:int,last_day:string,visitors:int}>
	 */
	public static function stats(): array {
		global $wpdb;
		$out = array();
		if ( ! Schema::installed() ) {
			return $out;
		}
		$sp   = Schema::table( 'daily_spend' );
		$ad   = Schema::table( 'ads' );
		$rows = (array) $wpdb->get_results( "SELECT a.code AS code, SUM(s.spend_cents) AS cents, MAX(CASE WHEN s.spend_cents > 0 THEN s.day END) AS last_day FROM {$sp} s INNER JOIN {$ad} a ON a.ad_id = s.ad_id WHERE a.code <> '' GROUP BY a.code", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		foreach ( $rows as $r ) {
			$out[ (string) $r['code'] ] = array( 'spend_cents' => (int) $r['cents'], 'last_day' => (string) $r['last_day'], 'visitors' => 0 );
		}
		if ( class_exists( Tracking\Schema::class ) && Tracking\Schema::installed() ) {
			$vi       = Tracking\Schema::table( 'visits' );
			$suppress = $wpdb->suppress_errors( true );
			$rows     = (array) $wpdb->get_results( "SELECT creative_code AS code, COUNT(DISTINCT visitor_id) AS n FROM {$vi} WHERE creative_code <> '' GROUP BY creative_code", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$wpdb->suppress_errors( $suppress );
			foreach ( $rows as $r ) {
				$code                     = (string) $r['code'];
				$out[ $code ]             = ( $out[ $code ] ?? array( 'spend_cents' => 0, 'last_day' => '' ) ) + array( 'visitors' => 0 );
				$out[ $code ]['visitors'] = (int) $r['n'];
			}
		}
		return $out;
	}

	// ---------- 未登记广告 ----------

	/** @return array<int,array{ad_id:string,code:string,ad_name:string,first_seen_ms:int,spend_cents:int}> */
	public static function unregistered_ads(): array {
		global $wpdb;
		if ( ! Schema::installed() ) {
			return array();
		}
		$ad   = Schema::table( 'ads' );
		$cr   = Schema::table( 'creatives' );
		$sp   = Schema::table( 'daily_spend' );
		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT a.ad_id, a.code, a.ad_name, a.first_seen_ms, (SELECT COALESCE(SUM(s.spend_cents), 0) FROM {$sp} s WHERE s.ad_id = a.ad_id) AS spend_cents FROM {$ad} a WHERE a.code = '' OR a.code NOT IN (SELECT code FROM {$cr}) ORDER BY a.first_seen_ms DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::UNREG_LIMIT
			),
			ARRAY_A
		);
		return array_map(
			static fn( array $r ): array => array(
				'ad_id'         => (string) $r['ad_id'],
				'code'          => (string) $r['code'],
				'ad_name'       => (string) $r['ad_name'],
				'first_seen_ms' => (int) $r['first_seen_ms'],
				'spend_cents'   => (int) $r['spend_cents'],
			),
			$rows
		);
	}

	public static function ad( string $ad_id ): ?array {
		global $wpdb;
		if ( ! Contract::valid_meta_id( $ad_id ) || ! Schema::installed() ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT ad_id, code, ad_name, first_seen_ms FROM ' . Schema::table( 'ads' ) . ' WHERE ad_id = %s', $ad_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		return is_array( $row ) ? $row : null;
	}

	/**
	 * 把一条广告归到某个素材：改广告表的编号，再把这条广告带来的访问一起改过去。
	 */
	public static function link_ad( string $ad_id, string $code ): bool {
		global $wpdb;
		if ( ! Contract::valid_meta_id( $ad_id ) || ! Contract::valid_code( $code ) ) {
			return false;
		}
		$ok = false !== $wpdb->update( Schema::table( 'ads' ), array( 'code' => $code ), array( 'ad_id' => $ad_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $ok && class_exists( Tracking\Schema::class ) && Tracking\Schema::installed() ) {
			$suppress = $wpdb->suppress_errors( true );
			$wpdb->update( Tracking\Schema::table( 'visits' ), array( 'creative_code' => $code ), array( 'utm_content' => $ad_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->suppress_errors( $suppress );
		}
		if ( $ok ) {
			do_action( 'pc_creatives_relinked', $ad_id, $code );
		}
		return $ok;
	}

	// ---------- 总后台 ----------

	/** 店铺是否已经接入总后台（和订单上报同一个判断）。 */
	public static function hq_enabled(): bool {
		return Order_Report::enabled();
	}

	/**
	 * 给总后台发一个签名请求（签名同订单上报）。POST 的请求体自动带 nonce。
	 *
	 * @param array<string,mixed>|null $body
	 * @return array|\WP_Error 总后台回应的 JSON
	 */
	public static function hq_request( string $method, string $route, ?array $body = null, int $timeout = 20 ) {
		if ( ! self::hq_enabled() ) {
			return new \WP_Error( 'pc_hq_not_connected', '还没有接入总后台。' );
		}
		$method = strtoupper( $method );
		$raw    = '';
		if ( 'POST' === $method ) {
			$raw = (string) wp_json_encode( array( 'nonce' => wp_generate_uuid4() ) + (array) $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}
		$s    = Hq_Connector::settings();
		$time = time();
		$args = array(
			'method'  => $method,
			'timeout' => $timeout,
			'headers' => array(
				'Content-Type' => 'application/json',
				'X-PC-HQ-Site' => $s['uuid'],
				'X-PC-HQ-Time' => (string) $time,
				'X-PC-HQ-Sign' => Hq_Connector::sign( $s['key'], $method, $route, '', $time, $raw ),
			),
		);
		if ( 'POST' === $method ) {
			$args['body'] = $raw;
		}
		$res = wp_remote_request( add_query_arg( 'rest_route', rawurlencode( $route ), trailingslashit( (string) PEPTIDE_HQ_URL ) ), $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code || ! is_array( $json ) ) {
			return new \WP_Error( 'pc_hq_http', 'HTTP ' . $code . ( is_array( $json ) && is_string( $json['message'] ?? null ) ? ' ' . $json['message'] : '' ) );
		}
		return $json;
	}
}
