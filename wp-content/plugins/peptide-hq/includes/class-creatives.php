<?php
/**
 * 素材归因（CR）总后台端：接收各店每天的“按素材”汇总，维护素材属性词表并下发给店铺。
 *
 * - 表（模块自己的升级，版本选项 pchq_creatives_db，可重复执行）：
 *   pchq_creative_daily（店铺 + 日期 + 素材编号一行）、pchq_attribute_vocab（词表主版本）。
 * - 店铺 → 总后台：POST /peptide-hq/v1/creatives/report（签名同订单上报，Mail::verify）。格式见 clean_report()，
 *   店铺端按 PeptideCore\Creatives\Contract::report_payload() 生成。约定全文见 docs/CONTRACT.md 的“CR 素材归因”。
 * - 店铺 → 总后台：POST /peptide-hq/v1/creatives/spend，每日全店花费（导入 Meta 报表后推送），见 clean_spend()。
 * - 店铺 ← 总后台：GET /peptide-hq/v1/creatives/vocab，返回 {ver, items[]}。
 * - 每日全店花费仍写 Orders 的 pchq_ad_spend（source = csv），跨店报表的 ROAS 不用改。
 *
 * 打地基阶段：建表、校验、词表接口已完成。线 3（2026-11-11）：
 * - store_rows()：同一家店同一天整天覆盖（事务内先写新行、再删掉这一天不再出现的编号；写入失败整批回滚，旧数据不丢）。
 * - 指标和分组（纯函数）：metrics()、fold()、compare()，有效访客少于 MIN_SAMPLE 标“样本不足”。
 * - 词表维护：vocab_changes()（纯函数，只新增 / 改名 / 停用启用 / 调顺序，不删除）+ vocab_save()（有改动时版本号加一）。
 * - 检查点导出：全部店铺合计有效访客每跨过 CHECKPOINT_STEP 的整数倍，生成一份 CSV + JSON（checkpoint_due()、maybe_checkpoint()）。
 * - 页面：Admin\Creatives_Page（“素材对比”“素材词表”），只给总后台管理员。
 *
 * @package PeptideHq
 */

namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Creatives {

	public const VERSION   = '1';
	public const OPTION    = 'pchq_creatives_db';
	/** 词表版本号：每次改词表加一，店铺按这个判断要不要更新。 */
	public const VOCAB_VER = 'pchq_attribute_vocab_ver';
	public const MAX_ROWS  = 500;

	/** 有效访客少于这个数的行置灰，标“样本不足”。 */
	public const MIN_SAMPLE = 30;
	/** 全部店铺合计有效访客每跨过这个数的整数倍，生成一份检查点导出。 */
	public const CHECKPOINT_STEP = 1000;
	/** 已生成到哪个整数倍（例如 3000）。 */
	public const CHECKPOINT_OPTION = 'pchq_creatives_checkpoint';
	/** 导出文件登记（最近 EXPORT_KEEP 份）。 */
	public const EXPORTS_OPTION = 'pchq_creatives_exports';
	public const EXPORT_KEEP    = 50;
	/** 导出文件放在上传目录下的这个子目录（文件名带随机串，只能经后台下载）。 */
	public const EXPORT_DIR = 'pchq-creative-exports';

	/** pchq_creative_daily 里可以累加的数字列。 */
	public const SUM_COLUMNS = array( 'visitors', 'valid_visitors', 'intent_high', 'intent_mid', 'intent_low', 'intent_invalid', 'add_to_cart', 'checkout', 'cashier', 'orders', 'sales_cents', 'spend_cents', 'impressions', 'link_clicks' );

	/** 和店铺端 Contract 一致。 */
	public const COUNTS       = array( 'visitors', 'valid_visitors', 'add_to_cart', 'checkout', 'cashier', 'orders', 'sales_cents', 'spend_cents', 'impressions', 'link_clicks' );
	public const INTENT_TIERS = array( 'high', 'mid', 'low', 'invalid' );
	public const UNREGISTERED = 'UNREG';
	public const REQUIRED     = array( 'hook', 'fmt', 'angle' );

	/** 词表初版（和店铺端 Contract::DEFAULT_VOCAB 一致）。 */
	public const DEFAULT_VOCAB = array(
		array( 'hook', 'lab', '检测报告' ), array( 'hook', 'price', '价格' ), array( 'hook', 'ship', '发货速度' ), array( 'hook', 'q', '提问式' ),
		array( 'fmt', 'vid', '视频' ), array( 'fmt', 'img', '单图' ), array( 'fmt', 'car', '轮播' ), array( 'fmt', 'ugc', '用户视角' ),
		array( 'angle', 'purity', '纯度' ), array( 'angle', 'price', '价格' ), array( 'angle', 'trust', '信任' ), array( 'angle', 'range', '品类齐全' ),
		array( 'lang', 'en', '英文' ), array( 'lang', 'es', '西班牙文' ),
	);

	public static function register_hooks(): void {
		self::maybe_upgrade();
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		if ( is_admin() && class_exists( Admin\Creatives_Page::class ) ) {
			Admin\Creatives_Page::hooks();
		}
	}

	public static function daily_table(): string {
		return Db::table( 'creative_daily' );
	}

	public static function vocab_table(): string {
		return Db::table( 'attribute_vocab' );
	}

	public static function ready(): bool {
		return self::VERSION === get_option( self::OPTION );
	}

	// ---------- 表结构 ----------

	public static function maybe_upgrade(): void {
		if ( ! self::ready() ) {
			self::install();
		}
	}

	/** 建表、写入初版词表。没成功就不写版本号，下次再试。 */
	public static function install(): bool {
		global $wpdb;
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		$c     = $wpdb->get_charset_collate();
		$daily = self::daily_table();
		$vocab = self::vocab_table();
		dbDelta(
			"CREATE TABLE {$daily} (
  site_id bigint(20) unsigned NOT NULL,
  day date NOT NULL,
  code varchar(16) NOT NULL,
  attrs text NULL,
  custom text NULL,
  thumb varchar(500) NOT NULL DEFAULT '',
  visitors int(10) unsigned NOT NULL DEFAULT 0,
  valid_visitors int(10) unsigned NOT NULL DEFAULT 0,
  intent_high int(10) unsigned NOT NULL DEFAULT 0,
  intent_mid int(10) unsigned NOT NULL DEFAULT 0,
  intent_low int(10) unsigned NOT NULL DEFAULT 0,
  intent_invalid int(10) unsigned NOT NULL DEFAULT 0,
  add_to_cart int(10) unsigned NOT NULL DEFAULT 0,
  checkout int(10) unsigned NOT NULL DEFAULT 0,
  cashier int(10) unsigned NOT NULL DEFAULT 0,
  orders int(10) unsigned NOT NULL DEFAULT 0,
  sales_cents bigint(20) NOT NULL DEFAULT 0,
  spend_cents bigint(20) NOT NULL DEFAULT 0,
  impressions bigint(20) unsigned NOT NULL DEFAULT 0,
  link_clicks bigint(20) unsigned NOT NULL DEFAULT 0,
  reported_at int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (site_id,day,code),
  KEY day (day)
) {$c};
CREATE TABLE {$vocab} (
  attr varchar(16) NOT NULL,
  value varchar(24) NOT NULL,
  label varchar(60) NOT NULL DEFAULT '',
  required tinyint(1) unsigned NOT NULL DEFAULT 0,
  active tinyint(1) unsigned NOT NULL DEFAULT 1,
  position int(10) unsigned NOT NULL DEFAULT 0,
  updated_at int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (attr,value)
) {$c};"
		);
		if ( ! Series_Db::has_table( $daily ) || ! Series_Db::has_table( $vocab ) ) {
			return false;
		}
		if ( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$vocab}" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			foreach ( self::DEFAULT_VOCAB as $i => $item ) {
				$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$vocab,
					array( 'attr' => $item[0], 'value' => $item[1], 'label' => $item[2], 'required' => in_array( $item[0], self::REQUIRED, true ) ? 1 : 0, 'active' => 1, 'position' => $i, 'updated_at' => time() )
				);
			}
			update_option( self::VOCAB_VER, 1, false );
		}
		update_option( self::OPTION, self::VERSION, true );
		return true;
	}

	// ---------- 接口 ----------

	public static function routes(): void {
		register_rest_route(
			Register::NS,
			'/creatives/report',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_report' ),
				'permission_callback' => array( Mail::class, 'verify' ),
			)
		);
		register_rest_route(
			Register::NS,
			'/creatives/spend',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_spend' ),
				'permission_callback' => array( Mail::class, 'verify' ),
			)
		);
		register_rest_route(
			Register::NS,
			'/creatives/vocab',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'vocab_route' ),
				'permission_callback' => array( Mail::class, 'verify' ),
			)
		);
	}

	/**
	 * 整理一行（纯函数，规则和店铺端 Contract::report_row() 相同）。编号不合规时返回 null。
	 *
	 * @param mixed $row
	 * @return array<string,mixed>|null
	 */
	public static function clean_row( $row ): ?array {
		if ( ! is_array( $row ) ) {
			return null;
		}
		$code = (string) ( $row['code'] ?? '' );
		if ( self::UNREGISTERED !== $code && ! preg_match( '/^C\d{4,9}$/', $code ) ) {
			return null;
		}
		$text = static function ( $map ): array {
			$out = array();
			foreach ( is_array( $map ) ? $map : array() as $k => $v ) {
				if ( is_string( $k ) && is_scalar( $v ) && preg_match( '/^[a-z][a-z0-9]{0,15}$/', $k ) && preg_match( '/^[a-z0-9]{1,24}$/', (string) $v ) ) {
					$out[ $k ] = (string) $v;
				}
			}
			ksort( $out );
			return $out;
		};
		$int = static fn( $v ): int => is_int( $v ) && $v > 0 ? $v : 0;
		$out = array(
			'code'   => $code,
			'attrs'  => $text( $row['attrs'] ?? array() ),
			'custom' => $text( $row['custom'] ?? array() ),
			'thumb'  => is_string( $row['thumb'] ?? null ) && preg_match( '#^https://#', $row['thumb'] ) ? substr( $row['thumb'], 0, 500 ) : '',
			'intent' => array(),
		);
		foreach ( self::INTENT_TIERS as $tier ) {
			$out['intent'][ $tier ] = $int( $row['intent'][ $tier ] ?? 0 );
		}
		foreach ( self::COUNTS as $key ) {
			$out[ $key ] = $int( $row[ $key ] ?? 0 );
		}
		return $out;
	}

	/**
	 * 整理一次上报（纯函数）。
	 *
	 * @param mixed $p 请求体
	 * @return array{day:string,tz:string,rows:array,untagged:array{visitors:int},rejected:int}|null 格式不对时为 null
	 */
	public static function clean_report( $p ): ?array {
		if ( ! is_array( $p ) || ! is_string( $p['day'] ?? null ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $p['day'] ) || ! is_array( $p['rows'] ?? null ) || count( $p['rows'] ) > self::MAX_ROWS ) {
			return null;
		}
		$rows     = array();
		$rejected = 0;
		foreach ( $p['rows'] as $raw ) {
			$r = self::clean_row( $raw );
			if ( null === $r ) {
				++$rejected;
				continue;
			}
			$rows[ $r['code'] ] = $r;
		}
		ksort( $rows );
		$untagged = $p['untagged']['visitors'] ?? 0;
		return array(
			'day'      => $p['day'],
			'tz'       => is_string( $p['tz'] ?? null ) ? substr( $p['tz'], 0, 64 ) : '',
			'rows'     => array_values( $rows ),
			'untagged' => array( 'visitors' => is_int( $untagged ) && $untagged > 0 ? $untagged : 0 ),
			'rejected' => $rejected,
		);
	}

	/**
	 * @return array|\WP_Error { day, stored: 行数, rejected: 行数 }
	 */
	public static function handle_report( \WP_REST_Request $request ) {
		$site_id = (int) $request->get_param( '_pchq_site' );
		$clean   = self::clean_report( $request->get_json_params() );
		if ( $site_id < 1 || null === $clean ) {
			return new \WP_Error( 'pchq_creatives_bad', 'Send one day (YYYY-MM-DD) with at most 500 rows.', array( 'status' => 400 ) );
		}
		if ( ! self::ready() ) {
			return new \WP_Error( 'pchq_creatives_db', 'The creative tables are not ready yet.', array( 'status' => 503 ) );
		}
		$stored = self::store_rows( $site_id, $clean );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		// 检查点导出失败不影响这次上报（下次上报再试）。
		try {
			self::maybe_checkpoint();
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		return array( 'day' => $clean['day'], 'stored' => $stored, 'rejected' => $clean['rejected'] );
	}

	// ---------- 写入日报 ----------

	/**
	 * clean_row() 的一行变成 pchq_creative_daily 的一行（纯函数）：attrs / custom 存 JSON，intent 拆成四列。
	 *
	 * @param array<string,mixed> $row clean_row() 的结果
	 * @return array<string,int|string>
	 */
	public static function daily_record( int $site_id, string $day, array $row, int $now ): array {
		$json = static fn( $map ): string => (string) json_encode( is_array( $map ) && $map ? $map : new \stdClass(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$rec  = array(
			'site_id' => $site_id,
			'day'     => $day,
			'code'    => (string) $row['code'],
			'attrs'   => $json( $row['attrs'] ?? array() ),
			'custom'  => $json( $row['custom'] ?? array() ),
			'thumb'   => (string) ( $row['thumb'] ?? '' ),
		);
		foreach ( self::INTENT_TIERS as $tier ) {
			$rec[ 'intent_' . $tier ] = (int) ( $row['intent'][ $tier ] ?? 0 );
		}
		foreach ( self::COUNTS as $key ) {
			$rec[ $key ] = (int) ( $row[ $key ] ?? 0 );
		}
		$rec['reported_at'] = $now;
		return $rec;
	}

	/**
	 * 整天覆盖要删掉的编号（纯函数）：这一天原来有、这次上报里没有的。
	 *
	 * @param string[] $existing 库里这一天已有的编号
	 * @param string[] $incoming 这次上报的编号
	 * @return string[]
	 */
	public static function stale_codes( array $existing, array $incoming ): array {
		return array_values( array_diff( array_map( 'strval', $existing ), array_map( 'strval', $incoming ) ) );
	}

	/**
	 * 写入一家店一天的素材汇总，整天覆盖（补传时以最后一次为准）。
	 *
	 * 顺序：开事务 → 逐行 REPLACE 新数据 → 删掉这一天不再出现的编号 → 提交。任何一步失败就回滚；
	 * 即使数据库不支持事务，也是先写后删，不会出现“删了没写上”。
	 *
	 * @param array{day:string,rows:array} $clean clean_report() 的结果
	 * @return int|\WP_Error 写入的行数
	 */
	public static function store_rows( int $site_id, array $clean ) {
		global $wpdb;
		$t       = self::daily_table();
		$day     = (string) $clean['day'];
		$now     = time();
		$records = array();
		foreach ( (array) $clean['rows'] as $row ) {
			$records[] = self::daily_record( $site_id, $day, $row, $now );
		}
		$fail = static function () use ( $wpdb ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new \WP_Error( 'pchq_creatives_store', 'Could not store the creative report; nothing was changed. Try again.', array( 'status' => 500 ) );
		};
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $records as $rec ) {
			if ( false === $wpdb->replace( $t, $rec ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return $fail();
			}
		}
		$existing = $wpdb->get_col( $wpdb->prepare( "SELECT code FROM {$t} WHERE site_id = %d AND day = %s", $site_id, $day ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( ! is_array( $existing ) ) {
			return $fail();
		}
		foreach ( self::stale_codes( $existing, array_column( $records, 'code' ) ) as $code ) {
			if ( false === $wpdb->delete( $t, array( 'site_id' => $site_id, 'day' => $day, 'code' => $code ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return $fail();
			}
		}
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return count( $records );
	}

	// ---------- 读取和对比 ----------

	/**
	 * 一段日期内的日报行（按日期升序），可只看部分店铺。attrs / custom 解成数组。
	 *
	 * @param int[] $site_ids 空数组 = 全部店铺
	 * @return array<int,array<string,mixed>>
	 */
	public static function rows_between( string $from, string $to, array $site_ids = array() ): array {
		global $wpdb;
		if ( ! self::ready() ) {
			return array();
		}
		$t    = self::daily_table();
		$sql  = "SELECT * FROM {$t} WHERE day >= %s AND day <= %s";
		$args = array( $from, $to );
		$ids  = array_values( array_filter( array_map( 'intval', $site_ids ), static fn( int $id ): bool => $id > 0 ) );
		if ( $ids ) {
			$sql .= ' AND site_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			$args = array_merge( $args, $ids );
		}
		$sql .= ' ORDER BY day, site_id, code';
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		return array_map( array( self::class, 'decode_row' ), $rows );
	}

	/** 数据库的一行转成统一格式（纯函数）。 */
	public static function decode_row( array $r ): array {
		$map = static function ( $json ): array {
			$v = is_string( $json ) ? json_decode( $json, true ) : null;
			return is_array( $v ) ? $v : array();
		};
		$out = array(
			'site_id' => (int) ( $r['site_id'] ?? 0 ),
			'day'     => (string) ( $r['day'] ?? '' ),
			'code'    => (string) ( $r['code'] ?? '' ),
			'attrs'   => $map( $r['attrs'] ?? '' ),
			'custom'  => $map( $r['custom'] ?? '' ),
			'thumb'   => (string) ( $r['thumb'] ?? '' ),
		);
		foreach ( self::SUM_COLUMNS as $c ) {
			$out[ $c ] = (int) ( $r[ $c ] ?? 0 );
		}
		return $out;
	}

	/** 有数据的日期范围和店铺（导出、筛选用）。 */
	public static function data_span(): array {
		global $wpdb;
		if ( ! self::ready() ) {
			return array( 'from' => '', 'to' => '', 'site_ids' => array() );
		}
		$t   = self::daily_table();
		$row = (array) $wpdb->get_row( "SELECT MIN(day) AS f, MAX(day) AS l FROM {$t}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$ids = array_map( 'intval', (array) $wpdb->get_col( "SELECT DISTINCT site_id FROM {$t}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return array( 'from' => (string) ( $row['f'] ?? '' ), 'to' => (string) ( $row['l'] ?? '' ), 'site_ids' => $ids );
	}

	/** 全部店铺、全部日期合计的有效访客（检查点用）。 */
	public static function total_valid_visitors(): int {
		global $wpdb;
		if ( ! self::ready() ) {
			return 0;
		}
		$t = self::daily_table();
		return (int) $wpdb->get_var( "SELECT COALESCE(SUM(valid_visitors),0) FROM {$t}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/** 一组数字全为 0 的合计。 */
	public static function zero(): array {
		return array_fill_keys( self::SUM_COLUMNS, 0 );
	}

	/** 把一行的数字加进合计（纯函数）。 */
	public static function add( array $sum, array $row ): array {
		foreach ( self::SUM_COLUMNS as $c ) {
			$sum[ $c ] = (int) ( $sum[ $c ] ?? 0 ) + (int) ( $row[ $c ] ?? 0 );
		}
		return $sum;
	}

	/**
	 * 合计加上比率（纯函数）。分母为 0 时比率为 null（显示“—”）。
	 *
	 * - mid_high_share：（高意向 + 中意向）÷ 有效访客
	 * - cart_rate：加购访客 ÷ 有效访客；checkout_rate：进结账访客 ÷ 有效访客
	 * - cost_valid_cents：花费 ÷ 有效访客；cost_high_cents：花费 ÷ 高意向访客
	 * - roas：销售额 ÷ 花费
	 * - thin：有效访客 < MIN_SAMPLE
	 */
	public static function metrics( array $s ): array {
		$s     = array_merge( self::zero(), array_intersect_key( $s, self::zero() ) );
		$valid = (int) $s['valid_visitors'];
		$high  = (int) $s['intent_high'];
		$spend = (int) $s['spend_cents'];
		$ratio = static fn( int $a, int $b ): ?float => $b > 0 ? $a / $b : null;
		return $s + array(
			'mid_high_share'   => $ratio( $high + (int) $s['intent_mid'], $valid ),
			'cart_rate'        => $ratio( (int) $s['add_to_cart'], $valid ),
			'checkout_rate'    => $ratio( (int) $s['checkout'], $valid ),
			'cost_valid_cents' => $valid > 0 ? $spend / $valid : null,
			'cost_high_cents'  => $high > 0 ? $spend / $high : null,
			'roas'             => $ratio( (int) $s['sales_cents'], $spend ),
			'thin'             => self::thin( $valid ),
		);
	}

	public static function thin( int $valid_visitors ): bool {
		return $valid_visitors < self::MIN_SAMPLE;
	}

	/**
	 * 日报行按（店铺，编号）合成一个素材（纯函数）：数字相加；属性、缩略图取最近一天的（素材重新登记后以新为准）。
	 * 输入要按日期升序（rows_between() 已排好）。
	 *
	 * @return array<string,array> 键为 "店铺ID:编号"
	 */
	public static function fold( array $rows ): array {
		$out = array();
		foreach ( $rows as $r ) {
			$key = (int) $r['site_id'] . ':' . (string) $r['code'];
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = array( 'site_id' => (int) $r['site_id'], 'code' => (string) $r['code'], 'attrs' => array(), 'custom' => array(), 'thumb' => '', 'first_day' => (string) $r['day'], 'last_day' => '', 'sum' => self::zero() );
			}
			$c = &$out[ $key ];
			$c['sum']      = self::add( $c['sum'], $r );
			$c['last_day'] = (string) $r['day'];
			if ( $r['attrs'] ) {
				$c['attrs'] = (array) $r['attrs'];
			}
			if ( $r['custom'] ) {
				$c['custom'] = (array) $r['custom'];
			}
			if ( '' !== (string) $r['thumb'] ) {
				$c['thumb'] = (string) $r['thumb'];
			}
			unset( $c );
		}
		return $out;
	}

	/** 只保留带某个属性取值的素材（纯函数）。$attr 为空时不筛。 */
	public static function filter_attr( array $creatives, string $attr, string $value ): array {
		if ( '' === $attr || '' === $value ) {
			return $creatives;
		}
		return array_filter( $creatives, static fn( array $c ): bool => (string) ( $c['attrs'][ $attr ] ?? '' ) === $value );
	}

	/**
	 * 分组对比（纯函数）。
	 *
	 * @param array  $creatives fold() 的结果
	 * @param string $mode      attr（按某个属性的取值）/ creative（按单个素材）/ site（按店铺）/ buyer（按投手）
	 * @param string $attr      mode = attr 时用哪个属性
	 * @param array  $buyers    店铺 ID => 投手名
	 * @return array{total:array,groups:array<int,array{key:string,label_key:string,creatives:array,sites:int[],metrics:array}>}
	 */
	public static function compare( array $creatives, string $mode, string $attr = 'hook', array $buyers = array() ): array {
		$groups = array();
		$total  = self::zero();
		foreach ( $creatives as $id => $c ) {
			switch ( $mode ) {
				case 'creative':
					$key = (string) $id;
					break;
				case 'site':
					$key = 'site:' . (int) $c['site_id'];
					break;
				case 'buyer':
					$key = 'buyer:' . trim( (string) ( $buyers[ (int) $c['site_id'] ] ?? '' ) );
					break;
				default:
					$key = self::UNREGISTERED === $c['code'] ? '#unreg' : 'attr:' . (string) ( $c['attrs'][ $attr ] ?? '' );
			}
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array( 'key' => $key, 'creatives' => array(), 'sites' => array(), 'sum' => self::zero() );
			}
			$groups[ $key ]['creatives'][]                   = $id;
			$groups[ $key ]['sites'][ (int) $c['site_id'] ] = (int) $c['site_id'];
			$groups[ $key ]['sum']                           = self::add( $groups[ $key ]['sum'], $c['sum'] );
			$total = self::add( $total, $c['sum'] );
		}
		$out = array();
		foreach ( $groups as $g ) {
			$out[] = array( 'key' => $g['key'], 'creatives' => $g['creatives'], 'sites' => array_values( $g['sites'] ), 'metrics' => self::metrics( $g['sum'] ) );
		}
		// 样本足的在前；同一类里按有效访客、花费从多到少。
		usort(
			$out,
			static fn( array $a, array $b ): int => array( (int) $a['metrics']['thin'], -$a['metrics']['valid_visitors'], -$a['metrics']['spend_cents'], $a['key'] )
				<=> array( (int) $b['metrics']['thin'], -$b['metrics']['valid_visitors'], -$b['metrics']['spend_cents'], $b['key'] )
		);
		return array( 'total' => self::metrics( $total ), 'groups' => $out );
	}

	// ---------- 词表维护 ----------

	/**
	 * 全部词表（含停用的），键为 "属性:取值"。
	 *
	 * @return array<string,array{attr:string,value:string,label:string,required:bool,active:bool,position:int}>
	 */
	public static function vocab_all(): array {
		global $wpdb;
		$out = array();
		if ( ! self::ready() ) {
			return $out;
		}
		$t = self::vocab_table();
		foreach ( (array) $wpdb->get_results( "SELECT attr, value, label, required, active, position FROM {$t} ORDER BY position, attr, value", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$out[ $r['attr'] . ':' . $r['value'] ] = array( 'attr' => (string) $r['attr'], 'value' => (string) $r['value'], 'label' => (string) $r['label'], 'required' => '1' === (string) $r['required'], 'active' => '1' === (string) $r['active'], 'position' => (int) $r['position'] );
		}
		return $out;
	}

	private static function label_len( string $s ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : strlen( $s );
	}

	/**
	 * 算出这次保存要改哪些词条（纯函数）。只能新增、改中文名、停用 / 启用、调顺序；表单里没有的词条保持不变，不删除。
	 *
	 * @param array $current vocab_all() 的结果
	 * @param array $posted  "属性:取值" => {label, active, position}
	 * @param array $new     要新增的一条 {attr, value, label, position}；全空表示不新增
	 * @return array{rows:array<int,array>,errors:string[]} rows 为要写入的完整词条
	 */
	public static function vocab_changes( array $current, array $posted, array $new = array() ): array {
		$errors = array();
		$next   = $current;
		foreach ( $posted as $key => $p ) {
			if ( ! isset( $current[ $key ] ) || ! is_array( $p ) ) {
				continue;
			}
			$label = trim( (string) ( $p['label'] ?? '' ) );
			if ( '' === $label || self::label_len( $label ) > 60 ) {
				$errors[] = $key . '：中文名不能为空，最多 60 个字。';
				continue;
			}
			$pos = $p['position'] ?? $current[ $key ]['position'];
			$next[ $key ]['label']    = $label;
			$next[ $key ]['active']   = ! empty( $p['active'] );
			$next[ $key ]['position'] = is_numeric( $pos ) ? max( 0, min( 9999, (int) $pos ) ) : $current[ $key ]['position'];
		}
		$attr  = strtolower( trim( (string) ( $new['attr'] ?? '' ) ) );
		$value = strtolower( trim( (string) ( $new['value'] ?? '' ) ) );
		$label = trim( (string) ( $new['label'] ?? '' ) );
		if ( '' !== $attr || '' !== $value || '' !== $label ) {
			if ( ! preg_match( '/^[a-z][a-z0-9]{0,15}$/D', $attr ) ) {
				$errors[] = '新属性名只能用小写字母和数字，以字母开头，最多 16 位。';
			} elseif ( ! preg_match( '/^[a-z0-9]{1,24}$/D', $value ) ) {
				$errors[] = '新取值只能用小写字母和数字，最多 24 位（会出现在广告名里，不能有横线和空格）。';
			} elseif ( isset( $current[ $attr . ':' . $value ] ) ) {
				$errors[] = $attr . '-' . $value . ' 已经存在；停用的可以在上面重新启用。';
			} elseif ( '' === $label || self::label_len( $label ) > 60 ) {
				$errors[] = '新取值要填中文名，最多 60 个字。';
			} else {
				$pos = $new['position'] ?? '';
				if ( ! is_numeric( $pos ) ) {
					$pos = $current ? max( array_column( $current, 'position' ) ) + 1 : 0;
				}
				$next[ $attr . ':' . $value ] = array( 'attr' => $attr, 'value' => $value, 'label' => $label, 'required' => in_array( $attr, self::REQUIRED, true ), 'active' => true, 'position' => max( 0, min( 9999, (int) $pos ) ) );
			}
		}
		// 必填属性至少留一个启用的取值，否则店铺登记素材时选不了。
		foreach ( self::REQUIRED as $req ) {
			$had = array_filter( $current, static fn( array $i ): bool => $i['attr'] === $req && $i['active'] );
			$has = array_filter( $next, static fn( array $i ): bool => $i['attr'] === $req && $i['active'] );
			if ( $had && ! $has ) {
				$errors[] = '必填属性 ' . $req . ' 至少要保留一个启用的取值。';
			}
		}
		if ( $errors ) {
			return array( 'rows' => array(), 'errors' => $errors );
		}
		$rows = array();
		foreach ( $next as $key => $item ) {
			if ( ! isset( $current[ $key ] ) || $current[ $key ] != $item ) { // phpcs:ignore Universal.Operators.StrictComparisons -- 数组逐项比较。
				$rows[] = $item;
			}
		}
		return array( 'rows' => $rows, 'errors' => array() );
	}

	/**
	 * 写入词表改动；有改动时版本号加一（店铺发现 ver 变大就整体覆盖本地词表）。
	 *
	 * @return int|\WP_Error 保存后的版本号
	 */
	public static function vocab_save( array $rows ) {
		global $wpdb;
		$ver = (int) get_option( self::VOCAB_VER, 0 );
		if ( ! $rows ) {
			return $ver;
		}
		$t = self::vocab_table();
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $rows as $item ) {
			$ok = $wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$t,
				array( 'attr' => $item['attr'], 'value' => $item['value'], 'label' => $item['label'], 'required' => $item['required'] ? 1 : 0, 'active' => $item['active'] ? 1 : 0, 'position' => (int) $item['position'], 'updated_at' => time() )
			);
			if ( false === $ok ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return new \WP_Error( 'pchq_vocab_store', '词表没能保存，没有任何改动，请再试一次。' );
			}
		}
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		update_option( self::VOCAB_VER, $ver + 1, false );
		return $ver + 1;
	}

	// ---------- 检查点导出 ----------

	/**
	 * 该不该生成新的检查点（纯函数）：合计有效访客达到的最高整数倍比上次记录的大时，返回这个整数倍，否则 null。
	 * 一次跨过好几个整数倍（例如补传）只生成一份，记最高的那个。
	 */
	public static function checkpoint_due( int $total_valid, int $last_mark, int $step = self::CHECKPOINT_STEP ): ?int {
		if ( $step < 1 ) {
			return null;
		}
		$mark = intdiv( max( 0, $total_valid ), $step ) * $step;
		return $mark >= $step && $mark > $last_mark ? $mark : null;
	}

	/** 上报写入后检查：跨过新的整数倍就生成一份全量导出。用一个短锁避免两家店同时上报时生成两份。 */
	public static function maybe_checkpoint(): ?array {
		$mark = self::checkpoint_due( self::total_valid_visitors(), (int) get_option( self::CHECKPOINT_OPTION, 0 ) );
		if ( null === $mark ) {
			return null;
		}
		$lock = 'pchq_creatives_checkpoint_lock';
		$held = (int) get_option( $lock, 0 );
		if ( $held && $held > time() - 600 ) {
			return null;
		}
		if ( $held ) {
			delete_option( $lock );
		}
		if ( ! add_option( $lock, time(), '', false ) ) {
			return null;
		}
		try {
			$span  = self::data_span();
			$entry = self::write_export( 'checkpoint', $span['from'], $span['to'], array(), $mark );
			if ( is_array( $entry ) ) {
				update_option( self::CHECKPOINT_OPTION, $mark, false );
				return $entry;
			}
			return null;
		} finally {
			delete_option( $lock );
		}
	}

	/** 导出的一行（纯函数）：日报行加上店铺名、投手、各属性一列。 */
	public static function export_rows( array $rows, array $site_names, array $buyers, array $attr_keys ): array {
		$out = array();
		foreach ( $rows as $r ) {
			$line = array(
				'day'        => $r['day'],
				'site_id'    => (int) $r['site_id'],
				'site'       => (string) ( $site_names[ (int) $r['site_id'] ] ?? '店铺 #' . (int) $r['site_id'] ),
				'buyer'      => (string) ( $buyers[ (int) $r['site_id'] ] ?? '' ),
				'code'       => $r['code'],
			);
			foreach ( $attr_keys as $a ) {
				$line[ 'attr_' . $a ] = (string) ( $r['attrs'][ $a ] ?? '' );
			}
			$line['custom'] = $r['custom'] ? (string) json_encode( $r['custom'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : '';
			$line['thumb']  = $r['thumb'];
			foreach ( self::SUM_COLUMNS as $c ) {
				$line[ $c ] = (int) $r[ $c ];
			}
			$out[] = $line;
		}
		return $out;
	}

	/**
	 * 一份导出的完整内容（纯函数）：明细行 + 按每个属性的对比汇总 + 合计。
	 *
	 * @param array $meta kind、mark、from、to、generated_at
	 */
	public static function export_payload( array $rows, array $site_names, array $buyers, array $meta ): array {
		$attr_keys = array();
		foreach ( $rows as $r ) {
			foreach ( array_keys( (array) $r['attrs'] ) as $a ) {
				$attr_keys[ $a ] = true;
			}
		}
		$attr_keys = array_keys( $attr_keys );
		sort( $attr_keys );
		$creatives = self::fold( $rows );
		$by_attr   = array();
		foreach ( $attr_keys as $a ) {
			$cmp = self::compare( $creatives, 'attr', $a );
			foreach ( $cmp['groups'] as $g ) {
				$by_attr[ $a ][] = array( 'value' => '#unreg' === $g['key'] ? self::UNREGISTERED : substr( $g['key'], 5 ), 'creatives' => count( $g['creatives'] ), 'sites' => count( $g['sites'] ) ) + $g['metrics'];
			}
		}
		$total = self::compare( $creatives, 'site' )['total'];
		return array(
			'kind'           => (string) ( $meta['kind'] ?? 'manual' ),
			'checkpoint'     => (int) ( $meta['mark'] ?? 0 ),
			'generated_at'   => (string) ( $meta['generated_at'] ?? '' ),
			'from'           => (string) ( $meta['from'] ?? '' ),
			'to'             => (string) ( $meta['to'] ?? '' ),
			'min_sample'     => self::MIN_SAMPLE,
			'valid_visitors' => (int) $total['valid_visitors'],
			'total'          => $total,
			'by_attr'        => $by_attr,
			'rows'           => self::export_rows( $rows, $site_names, $buyers, $attr_keys ),
		);
	}

	/** 导出明细转 CSV（纯函数，带 BOM 方便 Excel 打开中文）。 */
	public static function export_csv( array $payload ): string {
		$fh = fopen( 'php://temp', 'w+' );
		fwrite( $fh, "\xEF\xBB\xBF" );
		$rows = (array) ( $payload['rows'] ?? array() );
		if ( $rows ) {
			fputcsv( $fh, array_keys( $rows[0] ), ',', '"', '' );
			foreach ( $rows as $r ) {
				// 以 = + - @ 开头的文字前面加单引号，防止表格软件当公式执行。
				$r = array_map( static fn( $v ) => is_string( $v ) && preg_match( '/^[=+\-@\t\r]/', $v ) ? "'" . $v : $v, $r );
				fputcsv( $fh, $r, ',', '"', '' );
			}
		}
		rewind( $fh );
		$csv = (string) stream_get_contents( $fh );
		fclose( $fh );
		return $csv;
	}

	/** 店铺名和投手（导出、页面共用）。已下线但有数据的店铺也要有名字。 */
	public static function site_labels( array $site_ids ): array {
		$names  = array();
		$buyers = array();
		$map    = class_exists( Series::class ) ? Series::site_map() : array();
		foreach ( array_unique( array_map( 'intval', $site_ids ) ) as $id ) {
			$s            = Sites::get( $id );
			$names[ $id ] = $s && '' !== (string) $s['name'] ? (string) $s['name'] : ( $s ? (string) wp_parse_url( (string) $s['url'], PHP_URL_HOST ) : '店铺 #' . $id );
			$buyers[ $id ] = (string) ( $map[ $id ]['buyer_name'] ?? '' );
		}
		return array( $names, $buyers );
	}

	/** 生成一份导出内容（不写文件）。 */
	public static function build_export( string $kind, string $from, string $to, array $site_ids, int $mark = 0 ): array {
		$rows = '' === $from ? array() : self::rows_between( $from, $to, $site_ids );
		[ $names, $buyers ] = self::site_labels( array_column( $rows, 'site_id' ) );
		return self::export_payload( $rows, $names, $buyers, array( 'kind' => $kind, 'mark' => $mark, 'from' => $from, 'to' => $to, 'generated_at' => gmdate( 'c' ) ) );
	}

	private static function export_dir(): string {
		$up = wp_upload_dir( null, false );
		return rtrim( (string) $up['basedir'], '/\\' ) . '/' . self::EXPORT_DIR;
	}

	/**
	 * 生成并保存一份导出（CSV + JSON），登记到 EXPORTS_OPTION。
	 *
	 * @return array|\WP_Error 登记的条目
	 */
	public static function write_export( string $kind, string $from, string $to, array $site_ids = array(), int $mark = 0 ) {
		$dir = self::export_dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'pchq_creatives_export_dir', '导出目录无法创建。' );
		}
		// 目录不给直接访问（Apache）；文件名再带随机串（Nginx 等不认 .htaccess 时也猜不到）。
		if ( ! is_file( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( ! is_file( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$payload = self::build_export( $kind, $from, $to, $site_ids, $mark );
		$id      = gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 8 ) );
		$base    = 'creatives-' . ( 'checkpoint' === $kind ? 'checkpoint-' . $mark : 'manual' ) . '-' . $id;
		$json    = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		if ( false === file_put_contents( $dir . '/' . $base . '.csv', self::export_csv( $payload ) ) || false === file_put_contents( $dir . '/' . $base . '.json', (string) $json ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new \WP_Error( 'pchq_creatives_export_write', '导出文件没能写入。' );
		}
		$entry = array( 'id' => $id, 'kind' => $kind, 'mark' => $mark, 'from' => $from, 'to' => $to, 'rows' => count( $payload['rows'] ), 'valid_visitors' => $payload['valid_visitors'], 'created' => time(), 'base' => $base );
		$list  = (array) get_option( self::EXPORTS_OPTION, array() );
		array_unshift( $list, $entry );
		foreach ( array_slice( $list, self::EXPORT_KEEP ) as $old ) {
			foreach ( array( 'csv', 'json' ) as $ext ) {
				$f = $dir . '/' . (string) ( $old['base'] ?? '' ) . '.' . $ext;
				if ( '' !== (string) ( $old['base'] ?? '' ) && is_file( $f ) ) {
					wp_delete_file( $f );
				}
			}
		}
		update_option( self::EXPORTS_OPTION, array_slice( $list, 0, self::EXPORT_KEEP ), false );
		return $entry;
	}

	/** @return array<int,array> 已保存的导出，新的在前 */
	public static function exports(): array {
		return array_values( array_filter( (array) get_option( self::EXPORTS_OPTION, array() ), 'is_array' ) );
	}

	/** 某份导出文件的完整路径；不存在时为空字符串。 */
	public static function export_file( string $id, string $ext ): string {
		if ( ! in_array( $ext, array( 'csv', 'json' ), true ) ) {
			return '';
		}
		foreach ( self::exports() as $e ) {
			if ( hash_equals( (string) $e['id'], $id ) ) {
				$f = self::export_dir() . '/' . (string) $e['base'] . '.' . $ext;
				return is_file( $f ) ? $f : '';
			}
		}
		return '';
	}

	/**
	 * 整理每日全店花费（纯函数）：{"days":[{"day":"2026-11-11","spend_cents":12345}]}，最多 62 天。
	 * 同一天出现多次时取最后一个；金额为 0 表示这一天没花费（总后台删掉这一行）。
	 *
	 * @param mixed $p
	 * @return array<string,int>|null 日期 => 美分；格式不对时为 null
	 */
	public static function clean_spend( $p ): ?array {
		if ( ! is_array( $p ) || ! is_array( $p['days'] ?? null ) || ! $p['days'] || count( $p['days'] ) > 62 ) {
			return null;
		}
		$out = array();
		foreach ( $p['days'] as $d ) {
			if ( ! is_array( $d ) || ! is_string( $d['day'] ?? null ) || ! Orders::valid_day( $d['day'] ) || ! is_int( $d['spend_cents'] ?? null ) || $d['spend_cents'] < 0 ) {
				return null;
			}
			$out[ $d['day'] ] = $d['spend_cents'];
		}
		ksort( $out );
		return $out;
	}

	/** 店铺导入 Meta 报表后推送每日全店花费，写进 Orders 的 pchq_ad_spend（source = csv）。 */
	public static function handle_spend( \WP_REST_Request $request ) {
		$site_id = (int) $request->get_param( '_pchq_site' );
		$days    = self::clean_spend( $request->get_json_params() );
		if ( $site_id < 1 || null === $days ) {
			return new \WP_Error( 'pchq_creatives_spend_bad', 'Send 1 to 62 days of {day, spend_cents}.', array( 'status' => 400 ) );
		}
		if ( ! Orders::ready() ) {
			return new \WP_Error( 'pchq_orders_db', 'The spend table is not ready yet.', array( 'status' => 503 ) );
		}
		$saved = array();
		foreach ( $days as $day => $cents ) {
			if ( Orders::set_spend( $site_id, $day, $cents, 0, 'csv' ) ) {
				$saved[] = $day;
			}
		}
		return array( 'saved' => $saved );
	}

	/**
	 * 启用中的词表和版本号。
	 *
	 * @return array{ver:int,items:array<int,array{attr:string,value:string,label:string,required:bool,position:int}>}
	 */
	public static function vocab(): array {
		global $wpdb;
		$items = array();
		if ( self::ready() ) {
			$t    = self::vocab_table();
			$rows = (array) $wpdb->get_results( "SELECT attr, value, label, required, position FROM {$t} WHERE active = 1 ORDER BY position, attr, value", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			foreach ( $rows as $r ) {
				$items[] = array( 'attr' => (string) $r['attr'], 'value' => (string) $r['value'], 'label' => (string) $r['label'], 'required' => '1' === (string) $r['required'], 'position' => (int) $r['position'] );
			}
		}
		return array( 'ver' => (int) get_option( self::VOCAB_VER, 0 ), 'items' => $items );
	}

	public static function vocab_route(): array {
		return self::vocab();
	}
}
