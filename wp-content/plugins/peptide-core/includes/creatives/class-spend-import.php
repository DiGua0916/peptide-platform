<?php
/**
 * 花费导入（线 2）：解析 Meta 广告管理工具导出的“按广告、按天”报表 CSV，写进 pc_ad_daily_spend，
 * 再把受影响日期的全店每日花费推给总后台 POST /peptide-hq/v1/creatives/spend。
 *
 * - 列名中英文都认（表头大小写、空格、全角括号不影响）。必需：日期（Day / 日期 / 天，没有就用 Reporting starts /
 *   报告开始日期）、广告编号（Ad ID）、已花费金额（Amount spent (USD)）；可选：广告名称、展示次数、链接点击量。
 * - 处理 UTF-8 BOM、UTF-16（Excel 另存的“Unicode 文本”）、逗号 / 制表符 / 分号分隔、引号、千分位、货币符号。
 * - 同一个文件里 (ad_id, 日期) 重复的行，后面的覆盖前面的；写库按 (ad_id, 日期) 覆盖，重复上传结果不变。
 * - Meta 报表最后的“合计”行（没有广告编号）直接跳过，不算错误。
 * - 推送：每天的值 = 这一天 pc_ad_daily_spend 全部广告的合计（不只是这次文件里的广告）。没推成功的日期记在
 *   PENDING 选项里，每小时重试，也可以在页面上点“重新推送”。没接总后台时只记下、不报错。
 *
 * 纯函数（parse、money_cents、hq_payloads 等）不读数据库，测试直接调用。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Creatives;

defined( 'ABSPATH' ) || exit;

final class Spend_Import {

	public const ROUTE   = '/peptide-hq/v1/creatives/spend';
	public const PENDING = 'peptide_core_creatives_spend_pending';
	public const RETRY   = 'pc_creatives_spend_push';

	/** 一次推送最多几天（总后台限制 1–62 天）。 */
	public const PUSH_DAYS = 62;

	/** 等待推送的日期最多记多少天。 */
	public const PENDING_MAX = 400;

	/** 上传文件大小上限。 */
	public const MAX_BYTES = 8388608;

	/** 错误提示最多列几条。 */
	public const MAX_ERRORS = 20;

	/**
	 * 列：键 => [中文名, 认得的表头（规范化后）, 是否必需]。spend 按前缀认（后面可能带币种）。
	 */
	public const COLUMNS = array(
		'day'         => array( '日期（Day）', array( 'day', 'date', '日期', '天', '按天' ), false ),
		'start'       => array( '报告开始日期（Reporting starts）', array( 'reportingstarts', 'reportingstart', '报告开始日期', '开始日期' ), false ),
		'end'         => array( '报告结束日期（Reporting ends）', array( 'reportingends', 'reportingend', '报告结束日期', '结束日期' ), false ),
		'ad_id'       => array( '广告编号（Ad ID）', array( 'adid', '广告编号', '广告id' ), true ),
		'ad_name'     => array( '广告名称（Ad name）', array( 'adname', '广告名称', '广告名' ), false ),
		'spend'       => array( '已花费金额 (USD)（Amount spent (USD)）', array( 'amountspent', '已花费金额', '花费金额' ), true ),
		'impressions' => array( '展示次数（Impressions）', array( 'impressions', '展示次数' ), false ),
		'link_clicks' => array( '链接点击量（Link clicks）', array( 'linkclicks', '链接点击量', '链接点击次数' ), false ),
	);

	// ---------- 纯函数：解析 ----------

	/** 表头规范化：去 BOM、小写、去空白、全角括号变半角。 */
	public static function norm_header( string $h ): string {
		$h = str_replace( array( "\xEF\xBB\xBF", '（', '）' ), array( '', '(', ')' ), $h );
		return strtolower( (string) preg_replace( '/\s+/u', '', $h ) );
	}

	/**
	 * 表头里每一列是哪个键。
	 *
	 * @param string[] $headers
	 * @return array{map:array<string,int>,currency:string}
	 */
	public static function map_headers( array $headers ): array {
		$map      = array();
		$currency = '';
		foreach ( $headers as $i => $raw ) {
			$h = self::norm_header( (string) $raw );
			foreach ( self::COLUMNS as $key => $col ) {
				if ( isset( $map[ $key ] ) ) {
					continue;
				}
				if ( 'spend' === $key ) {
					foreach ( $col[1] as $alias ) {
						if ( str_starts_with( $h, $alias ) && preg_match( '/^' . preg_quote( $alias, '/' ) . '(\(([a-z]{3})\))?$/u', $h, $m ) ) {
							$map[ $key ] = (int) $i;
							$currency    = strtoupper( $m[2] ?? '' );
							continue 3;
						}
					}
				} elseif ( in_array( $h, $col[1], true ) ) {
					$map[ $key ] = (int) $i;
					continue 2;
				}
			}
		}
		return array( 'map' => $map, 'currency' => $currency );
	}

	/** 日期 → YYYY-MM-DD；认 2026-11-10、2026/11/10、2026.11.10、11/10/2026、2026年11月10日。不对返回空。 */
	public static function parse_day( string $v ): string {
		$v = trim( $v );
		if ( preg_match( '/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/', $v, $m ) || preg_match( '/^(\d{4})年(\d{1,2})月(\d{1,2})日/u', $v, $m ) ) {
			list( , $y, $mo, $d ) = $m;
		} elseif ( preg_match( '/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $v, $m ) ) {
			list( , $mo, $d, $y ) = $m;
		} else {
			return '';
		}
		return checkdate( (int) $mo, (int) $d, (int) $y ) ? sprintf( '%04d-%02d-%02d', $y, $mo, $d ) : '';
	}

	/** 金额 → 美分：去掉货币符号、空格、千分位逗号；空 = 0；看不懂返回 null。 */
	public static function money_cents( string $v ): ?int {
		$v = trim( str_replace( array( 'US$', 'USD', '$', '￥', '¥', ',', ' ', "\xC2\xA0" ), '', $v ) );
		if ( '' === $v || '-' === $v ) {
			return 0;
		}
		if ( ! preg_match( '/^-?\d+(\.\d+)?$/', $v ) ) {
			return null;
		}
		return max( 0, (int) round( (float) $v * 100 ) );
	}

	/** 次数：去千分位；空 = 0；看不懂返回 null。 */
	public static function count_value( string $v ): ?int {
		$v = trim( str_replace( array( ',', ' ', "\xC2\xA0" ), '', $v ) );
		if ( '' === $v || '-' === $v ) {
			return 0;
		}
		return preg_match( '/^\d+(\.0+)?$/', $v ) ? (int) $v : null;
	}

	/** 把文件内容转成 UTF-8（UTF-16 带 BOM 时转码），去掉 UTF-8 BOM。 */
	public static function to_utf8( string $content ): string {
		$bom = substr( $content, 0, 2 );
		if ( "\xFF\xFE" === $bom || "\xFE\xFF" === $bom ) {
			$from = "\xFF\xFE" === $bom ? 'UTF-16LE' : 'UTF-16BE';
			$body = substr( $content, 2 );
			if ( function_exists( 'mb_convert_encoding' ) ) {
				$content = (string) mb_convert_encoding( $body, 'UTF-8', $from );
			} elseif ( function_exists( 'iconv' ) ) {
				$content = (string) iconv( $from, 'UTF-8//IGNORE', $body );
			}
		}
		return str_starts_with( $content, "\xEF\xBB\xBF" ) ? substr( $content, 3 ) : $content;
	}

	/** 按第一行猜分隔符：制表符、分号、逗号里出现最多的。 */
	public static function delimiter( string $first_line ): string {
		$best = ',';
		$n    = substr_count( $first_line, ',' );
		foreach ( array( "\t", ';' ) as $d ) {
			if ( substr_count( $first_line, $d ) > $n ) {
				$best = $d;
				$n    = substr_count( $first_line, $d );
			}
		}
		return $best;
	}

	/**
	 * 解析整份 CSV。
	 *
	 * @return array{
	 *   ok:bool, error:string, missing:string[],
	 *   rows:array<string,array{ad_id:string,day:string,spend_cents:int,impressions:int,link_clicks:int,ad_name:string}>,
	 *   total:int, skipped:int, duplicates:int, errors:string[], warnings:string[]
	 * }
	 */
	public static function parse( string $content ): array {
		$out     = array( 'ok' => false, 'error' => '', 'missing' => array(), 'rows' => array(), 'total' => 0, 'skipped' => 0, 'duplicates' => 0, 'errors' => array(), 'warnings' => array() );
		$content = self::to_utf8( $content );
		if ( '' === trim( $content ) ) {
			$out['error'] = '文件是空的。';
			return $out;
		}
		$first = strtok( $content, "\r\n" );
		$fh    = fopen( 'php://temp', 'r+' );
		fwrite( $fh, $content );
		rewind( $fh );
		$delim   = self::delimiter( (string) $first );
		$headers = fgetcsv( $fh, 0, $delim, '"', '' );
		$cols    = self::map_headers( is_array( $headers ) ? $headers : array() );
		$map     = $cols['map'];
		if ( ! isset( $map['day'] ) && ! isset( $map['start'] ) ) {
			$out['missing'][] = self::COLUMNS['day'][0];
		}
		foreach ( self::COLUMNS as $key => $col ) {
			if ( $col[2] && ! isset( $map[ $key ] ) ) {
				$out['missing'][] = $col[0];
			}
		}
		if ( $out['missing'] ) {
			fclose( $fh );
			$out['error'] = '缺少这些列：' . implode( '、', $out['missing'] ) . '。导出时请按“广告”层级、按“天”细分，并勾选这些列。';
			return $out;
		}
		if ( '' !== $cols['currency'] && 'USD' !== $cols['currency'] ) {
			fclose( $fh );
			$out['error'] = '金额的币种是 ' . $cols['currency'] . '，只支持美元（USD）。请把广告账户的币种设为美元后再导出。';
			return $out;
		}
		if ( ! isset( $map['ad_name'] ) ) {
			$out['warnings'][] = '没有“广告名称”列：新出现的广告无法从广告名识别素材编号，建议导出时勾选。';
		}
		$line = 1;
		$cell = static fn( array $r, string $k ): string => isset( $map[ $k ], $r[ $map[ $k ] ] ) ? trim( (string) $r[ $map[ $k ] ] ) : '';
		$bad  = function ( string $msg ) use ( &$out, &$line ): void {
			if ( count( $out['errors'] ) < self::MAX_ERRORS ) {
				$out['errors'][] = '第 ' . $line . ' 行：' . $msg;
			}
			++$out['skipped'];
		};
		while ( false !== ( $r = fgetcsv( $fh, 0, $delim, '"', '' ) ) ) {
			++$line;
			if ( ! is_array( $r ) || array( null ) === $r || '' === trim( implode( '', array_map( 'strval', $r ) ) ) ) {
				continue; // 空行。
			}
			++$out['total'];
			$ad_id = $cell( $r, 'ad_id' );
			if ( '' === $ad_id ) {
				++$out['skipped']; // 合计行或广告组 / 广告系列的汇总行。
				continue;
			}
			if ( ! Contract::valid_meta_id( $ad_id ) ) {
				$bad( '广告编号“' . self::short( $ad_id ) . '”不是 Meta 的广告编号（应为一串数字）。' );
				continue;
			}
			$day = isset( $map['day'] ) ? self::parse_day( $cell( $r, 'day' ) ) : self::parse_day( $cell( $r, 'start' ) );
			if ( '' === $day ) {
				$bad( '日期“' . self::short( $cell( $r, isset( $map['day'] ) ? 'day' : 'start' ) ) . '”看不懂。' );
				continue;
			}
			if ( ! isset( $map['day'] ) && isset( $map['end'] ) ) {
				$end = self::parse_day( $cell( $r, 'end' ) );
				if ( '' !== $end && $end !== $day ) {
					$bad( '这一行是 ' . $day . ' 到 ' . $end . ' 的合计，不是一天的数据。导出时请按“天”细分。' );
					continue;
				}
			}
			$cents = self::money_cents( $cell( $r, 'spend' ) );
			$imp   = self::count_value( $cell( $r, 'impressions' ) );
			$clk   = self::count_value( $cell( $r, 'link_clicks' ) );
			if ( null === $cents ) {
				$bad( '金额“' . self::short( $cell( $r, 'spend' ) ) . '”看不懂。' );
				continue;
			}
			if ( null === $imp || null === $clk ) {
				$bad( '展示次数或链接点击量不是整数。' );
				continue;
			}
			$key = $ad_id . '|' . $day;
			if ( isset( $out['rows'][ $key ] ) ) {
				++$out['duplicates'];
			}
			$out['rows'][ $key ] = array(
				'ad_id'       => $ad_id,
				'day'         => $day,
				'spend_cents' => $cents,
				'impressions' => $imp,
				'link_clicks' => $clk,
				'ad_name'     => substr( $cell( $r, 'ad_name' ), 0, 255 ),
			);
		}
		fclose( $fh );
		if ( ! $out['rows'] ) {
			$out['error'] = $out['errors'] ? '没有可以导入的行。' : '文件里没有按广告的数据行。导出时请选择“广告”层级。';
			return $out;
		}
		if ( $out['duplicates'] > 0 ) {
			$out['warnings'][] = '有 ' . $out['duplicates'] . ' 行是同一条广告同一天的重复数据，按最后一行算。';
		}
		$out['ok'] = true;
		return $out;
	}

	private static function short( string $v ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $v, 0, 40 ) : substr( $v, 0, 40 );
	}

	/**
	 * 解析结果里涉及的日期（排序、去重）。
	 *
	 * @param array<string,array{day:string}> $rows
	 * @return string[]
	 */
	public static function days( array $rows ): array {
		$days = array_values( array_unique( array_map( static fn( array $r ): string => $r['day'], $rows ) ) );
		sort( $days );
		return $days;
	}

	/**
	 * 从 CSV 里新出现的广告：ad_id => [编号（来自广告名，可能为空）, 广告名]。同一条广告取最后一个非空的广告名。
	 *
	 * @param array<string,array{ad_id:string,ad_name:string}> $rows
	 * @return array<string,array{code:string,ad_name:string}>
	 */
	public static function ads_in( array $rows ): array {
		$out = array();
		foreach ( $rows as $r ) {
			$name = '' !== $r['ad_name'] ? $r['ad_name'] : (string) ( $out[ $r['ad_id'] ]['ad_name'] ?? '' );
			$out[ $r['ad_id'] ] = array( 'code' => Contract::parse_ad_name( $name )['code'], 'ad_name' => $name );
		}
		return $out;
	}

	/**
	 * 推给总后台的请求体（不含 nonce，由发送时加）：每批最多 PUSH_DAYS 天，日期升序。
	 *
	 * @param array<string,int> $totals 日期 => 全店当天花费（美分）
	 * @return array<int,array{days:array<int,array{day:string,spend_cents:int}>}>
	 */
	public static function hq_payloads( array $totals ): array {
		ksort( $totals );
		$days = array();
		foreach ( $totals as $day => $cents ) {
			if ( '' !== self::parse_day( (string) $day ) ) {
				$days[] = array( 'day' => (string) $day, 'spend_cents' => max( 0, (int) $cents ) );
			}
		}
		return array_map( static fn( array $chunk ): array => array( 'days' => $chunk ), array_chunk( $days, self::PUSH_DAYS ) );
	}

	/**
	 * 合并等待推送的日期：去重、排序，只留最近 PENDING_MAX 天。
	 *
	 * @param string[] $pending
	 * @param string[] $add
	 * @return string[]
	 */
	public static function merge_pending( array $pending, array $add ): array {
		$all = array_values( array_unique( array_filter( array_map( 'strval', array_merge( $pending, $add ) ), static fn( string $d ): bool => '' !== self::parse_day( $d ) ) ) );
		sort( $all );
		return array_slice( $all, -self::PENDING_MAX );
	}

	// ---------- 写库 ----------

	/**
	 * 导入解析好的数据：写一条导入记录，按 (ad_id, 日期) 覆盖写花费，补广告表，再推送受影响日期的全店合计。
	 *
	 * @return array{import_id:int,saved:int,ads_added:int,days:string[],push:array{status:string,message:string}}
	 */
	public static function import( array $parsed, string $filename ): array {
		global $wpdb;
		Schema::maybe_install();
		$days = self::days( $parsed['rows'] );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'spend_imports' ),
			array(
				'filename'   => substr( sanitize_file_name( $filename ), 0, 255 ),
				'user_id'    => get_current_user_id(),
				'created_at' => time(),
				'day_from'   => $days[0] ?? null,
				'day_to'     => $days ? $days[ count( $days ) - 1 ] : null,
				'rows_total' => (int) $parsed['total'],
				'rows_saved' => 0,
				'errors'     => (string) wp_json_encode( array_merge( $parsed['errors'], $parsed['warnings'] ), JSON_UNESCAPED_UNICODE ),
			)
		);
		$import_id = (int) $wpdb->insert_id;
		$saved     = 0;
		$table     = Schema::table( 'daily_spend' );
		foreach ( $parsed['rows'] as $r ) {
			$ok = $wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'ad_id'       => $r['ad_id'],
					'day'         => $r['day'],
					'spend_cents' => $r['spend_cents'],
					'impressions' => $r['impressions'],
					'link_clicks' => $r['link_clicks'],
					'ad_name'     => $r['ad_name'],
					'import_id'   => $import_id,
				)
			);
			if ( false !== $ok ) {
				++$saved;
			}
		}
		if ( $import_id > 0 ) {
			$wpdb->update( Schema::table( 'spend_imports' ), array( 'rows_saved' => $saved ), array( 'id' => $import_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$added = self::ensure_ads( self::ads_in( $parsed['rows'] ), $days[0] ?? '' );
		do_action( 'pc_creatives_spend_imported', $days, $import_id );
		return array(
			'import_id' => $import_id,
			'saved'     => $saved,
			'ads_added' => $added,
			'days'      => $days,
			'push'      => self::push( $days ),
		);
	}

	/**
	 * 把 CSV 里出现、广告表里还没有的广告补进去；已有的广告缺广告名或编号时补上。返回新增几条。
	 *
	 * @param array<string,array{code:string,ad_name:string}> $ads
	 */
	public static function ensure_ads( array $ads, string $first_day ): int {
		global $wpdb;
		if ( ! $ads ) {
			return 0;
		}
		$t     = Schema::table( 'ads' );
		$have  = array();
		foreach ( array_chunk( array_keys( $ads ), 200 ) as $chunk ) {
			$in   = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT ad_id, code, ad_name FROM {$t} WHERE ad_id IN ({$in})", $chunk ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			foreach ( $rows as $row ) {
				$have[ (string) $row['ad_id'] ] = $row;
			}
		}
		$seen  = '' !== $first_day ? ( new \DateTimeImmutable( $first_day . ' 00:00:00', wp_timezone() ) )->getTimestamp() * 1000 : time() * 1000;
		$added = 0;
		foreach ( $ads as $ad_id => $ad ) {
			$ad_id = (string) $ad_id;
			if ( ! isset( $have[ $ad_id ] ) ) {
				$ok = $wpdb->insert( $t, array( 'ad_id' => $ad_id, 'code' => $ad['code'], 'ad_name' => $ad['ad_name'], 'first_seen_ms' => $seen, 'last_seen_ms' => 0 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$added += false !== $ok ? 1 : 0;
				continue;
			}
			$fix = array();
			if ( '' === (string) $have[ $ad_id ]['ad_name'] && '' !== $ad['ad_name'] ) {
				$fix['ad_name'] = $ad['ad_name'];
			}
			if ( '' === (string) $have[ $ad_id ]['code'] && '' !== $ad['code'] ) {
				$fix['code'] = $ad['code'];
			}
			if ( $fix ) {
				$wpdb->update( $t, $fix, array( 'ad_id' => $ad_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
		return $added;
	}

	/**
	 * 这些日期的全店花费合计（没有数据的日期 = 0）。
	 *
	 * @param string[] $days
	 * @return array<string,int>
	 */
	public static function totals( array $days ): array {
		global $wpdb;
		$out = array_fill_keys( $days, 0 );
		if ( ! $days ) {
			return $out;
		}
		$in   = implode( ',', array_fill( 0, count( $days ), '%s' ) );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT day, SUM(spend_cents) AS cents FROM ' . Schema::table( 'daily_spend' ) . " WHERE day IN ({$in}) GROUP BY day", $days ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		foreach ( $rows as $r ) {
			$out[ substr( (string) $r['day'], 0, 10 ) ] = (int) $r['cents'];
		}
		return $out;
	}

	/** @return string[] */
	public static function pending(): array {
		$p = get_option( self::PENDING, array() );
		return is_array( $p ) ? self::merge_pending( array(), $p ) : array();
	}

	/**
	 * 推送这些日期（连同以前没推成功的）的全店合计。
	 *
	 * @param string[] $days
	 * @return array{status:string,message:string} status：sent / not_connected / failed / none
	 */
	public static function push( array $days = array() ): array {
		$pending = self::merge_pending( self::pending(), $days );
		update_option( self::PENDING, $pending, false );
		if ( ! $pending ) {
			return array( 'status' => 'none', 'message' => '' );
		}
		if ( ! Creatives::hq_enabled() ) {
			if ( ! wp_next_scheduled( self::RETRY ) ) {
				wp_schedule_single_event( time() + DAY_IN_SECONDS, self::RETRY ); // 每天看一次接上总后台没有。
			}
			return array( 'status' => 'not_connected', 'message' => '这家店还没有接入总后台，每日花费合计先存在本店，接入后会自动补推。' );
		}
		$done  = array();
		$error = '';
		foreach ( self::hq_payloads( self::totals( $pending ) ) as $body ) {
			$res = Creatives::hq_request( 'POST', self::ROUTE, $body );
			if ( is_wp_error( $res ) ) {
				$error = $res->get_error_message();
				continue;
			}
			foreach ( $body['days'] as $d ) {
				$done[] = $d['day'];
			}
		}
		$left = array_values( array_diff( $pending, $done ) );
		update_option( self::PENDING, $left, false );
		if ( $left ) {
			if ( ! wp_next_scheduled( self::RETRY ) ) {
				wp_schedule_single_event( time() + HOUR_IN_SECONDS, self::RETRY );
			}
			return array( 'status' => 'failed', 'message' => sprintf( '有 %d 天的花费合计没推到总后台（%s），一小时后自动重试，也可以点“重新推送”。', count( $left ), $error ) );
		}
		return array( 'status' => 'sent', 'message' => sprintf( '已把 %d 天的全店花费合计推给总后台。', count( $done ) ) );
	}

	/** 定时重试：还有没推成功的日期就再推一次（没接总后台时 push() 只是再约一天后检查）。 */
	public static function retry(): void {
		if ( self::pending() ) {
			self::push();
		}
	}

	/** @return array<int,array<string,mixed>> 最近几次导入 */
	public static function recent( int $limit = 10 ): array {
		global $wpdb;
		if ( ! Schema::installed() ) {
			return array();
		}
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'spend_imports' ) . ' ORDER BY id DESC LIMIT %d', $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}
}
