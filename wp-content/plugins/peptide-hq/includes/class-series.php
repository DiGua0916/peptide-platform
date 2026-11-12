<?php
/**
 * 系列（HQ-05、NF-12）：一个系列 = 一套商品和价格 + 一套模板 + 一套营销活动 + 一套运费。
 *
 * - 系列定义存在总后台；店铺只记自己属于哪个系列和已同步到的版本号（店铺选项 peptide_core_series）。
 * - “所属系列”“投手”“开店向导进度”“是否接受系列同步”记在 pchq_sites 的新增列上（见 Series_Db）。
 * - 每个系列四个版本号：catalog_ver（商品和价格）、template_ver（模板）、campaign_ver（活动）、shipping_ver（运费）。
 *   内容一改就加一，推送任务（Series_Push）把落后的店补齐。
 * - 新店注册时自动归入系列：开店时预留了系列就用预留的，否则归入默认系列（最早的系列，即“系列 1”）。
 *
 * @package PeptideHq
 */

namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Series {

	public const DEFAULT_SLUG = 'series-1';
	public const DEFAULT_NAME = '系列 1';

	/** 开店时预留的系列：管理地址的主机名 => 系列、投手。 */
	public const RESERVED = 'pchq_series_reserved';

	/** 有版本号的四部分内容。 */
	public const SECTIONS = array( 'catalog', 'template', 'campaign', 'shipping' );

	/** @var array<int,array>|null 本次请求内的店铺归属缓存。 */
	private static ?array $site_map = null;

	public static function register_hooks(): void {
		Series_Db::maybe_upgrade();
		add_action( 'pchq_site_registered', array( self::class, 'on_site_registered' ), 5, 1 );
		Series_Push::register_hooks();
		if ( is_admin() && class_exists( Admin\Series_Page::class ) ) {
			Admin\Series_Page::hooks();
		}
	}

	public static function table(): string {
		return Db::table( 'series' );
	}

	/** 表结构是否已经升级好。没升级好时所有读取都返回空，不报错。 */
	public static function ready(): bool {
		return Series_Db::VERSION === get_option( Series_Db::OPTION );
	}

	// ---------- 系列 ----------

	private static function row( ?array $r ): ?array {
		if ( ! $r ) {
			return null;
		}
		$shipping = json_decode( (string) ( $r['shipping'] ?? '' ), true );
		return array(
			'id'             => (int) $r['id'],
			'slug'           => (string) $r['slug'],
			'name'           => (string) $r['name'],
			'status'         => (string) $r['status'],
			'master_site_id' => (int) $r['master_site_id'],
			'catalog_ver'    => (int) $r['catalog_ver'],
			'template_ver'   => (int) $r['template_ver'],
			'campaign_ver'   => (int) $r['campaign_ver'],
			'shipping_ver'   => (int) $r['shipping_ver'],
			'shipping'       => is_array( $shipping ) ? $shipping : array(),
			'created_at'     => (int) $r['created_at'],
			'updated_at'     => (int) $r['updated_at'],
		);
	}

	/** @return array<int,array> 系列 ID => 系列，按 ID 排列。 */
	public static function all( bool $with_archived = false ): array {
		global $wpdb;
		if ( ! self::ready() ) {
			return array();
		}
		$t   = self::table();
		$out = array();
		foreach ( (array) $wpdb->get_results( "SELECT * FROM {$t} ORDER BY id ASC", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$row = self::row( $r );
			if ( $row && ( $with_archived || 'active' === $row['status'] ) ) {
				$out[ $row['id'] ] = $row;
			}
		}
		return $out;
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		if ( $id < 1 || ! self::ready() ) {
			return null;
		}
		$t = self::table();
		return self::row( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/** 开店表单的下拉选项：系列 ID => 名称（只列在用的系列）。 */
	public static function choices(): array {
		return array_map( static fn( array $s ): string => $s['name'], self::all() );
	}

	/** 默认系列：最早建的那个（“系列 1”）。 */
	public static function default_id(): int {
		global $wpdb;
		if ( ! self::ready() ) {
			return 0;
		}
		$t = self::table();
		return (int) $wpdb->get_var( "SELECT id FROM {$t} ORDER BY id ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * 由名称生成不重复的英文代号（纯函数）。中文名称没有可用字符时用 series、series-2…
	 *
	 * @param string[] $taken 已经用掉的代号
	 */
	public static function slugify( string $name, array $taken = array() ): string {
		$base = strtolower( trim( (string) preg_replace( '/[^a-z0-9]+/i', '-', $name ), '-' ) );
		if ( '' === $base ) {
			$base = 'series';
		} elseif ( ! preg_match( '/^[a-z]/', $base ) ) {
			$base = 'series-' . $base;
		}
		$base = rtrim( substr( $base, 0, 48 ), '-' );
		$slug = $base;
		$n    = 2;
		while ( in_array( $slug, $taken, true ) ) {
			$slug = $base . '-' . $n;
			++$n;
		}
		return $slug;
	}

	/**
	 * 升级用：没有系列时建“系列 1”，把还没有系列的店铺全部归入。可重复执行。
	 * 这一步不看 ready()（升级还没写版本号时就要用）。
	 *
	 * @return int 默认系列的 ID，失败时为 0
	 */
	public static function ensure_default(): int {
		global $wpdb;
		$t  = self::table();
		$id = (int) $wpdb->get_var( "SELECT id FROM {$t} ORDER BY id ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( ! $id ) {
			$now = time();
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$t} (slug, name, status, created_at, updated_at) VALUES (%s, %s, %s, %d, %d)", self::DEFAULT_SLUG, self::DEFAULT_NAME, 'active', $now, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$id = (int) $wpdb->get_var( "SELECT id FROM {$t} ORDER BY id ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		}
		if ( $id ) {
			$sites = Db::table( 'sites' );
			$wpdb->query( $wpdb->prepare( "UPDATE {$sites} SET series_id = %d WHERE series_id = 0", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			self::$site_map = null;
		}
		return $id;
	}

	/** @return int|\WP_Error 新系列的 ID */
	public static function create( string $name, string $slug = '' ) {
		global $wpdb;
		if ( ! self::ready() ) {
			return new \WP_Error( 'pchq_series_db', '系列的数据表还没准备好，请刷新后再试。' );
		}
		$name = mb_substr( trim( sanitize_text_field( $name ) ), 0, 190 );
		if ( '' === $name ) {
			return new \WP_Error( 'pchq_series_name', '请填写系列名称。' );
		}
		$all = self::all( true );
		foreach ( $all as $s ) {
			if ( $s['name'] === $name ) {
				return new \WP_Error( 'pchq_series_name', '已经有同名的系列。' );
			}
		}
		$now = time();
		$ok  = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'slug'       => self::slugify( '' !== $slug ? $slug : $name, array_column( $all, 'slug' ) ),
				'name'       => $name,
				'status'     => 'active',
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		return $ok ? (int) $wpdb->insert_id : new \WP_Error( 'pchq_series_save', '系列没能保存，请重试。' );
	}

	/** 只允许改这几项：name、status（active / archived）、master_site_id、shipping（数组）。 */
	public static function update( int $id, array $fields ): bool {
		global $wpdb;
		$series = self::get( $id );
		if ( ! $series ) {
			return false;
		}
		$set = array();
		if ( isset( $fields['name'] ) ) {
			$name = mb_substr( trim( sanitize_text_field( (string) $fields['name'] ) ), 0, 190 );
			if ( '' !== $name ) {
				$set['name'] = $name;
			}
		}
		if ( isset( $fields['status'] ) && in_array( $fields['status'], array( 'active', 'archived' ), true ) ) {
			// 默认系列和还有店铺的系列不能归档。
			if ( 'active' === $fields['status'] || ( $id !== self::default_id() && ! self::sites_of( $id ) ) ) {
				$set['status'] = $fields['status'];
			}
		}
		if ( isset( $fields['master_site_id'] ) ) {
			$master = (int) $fields['master_site_id'];
			if ( 0 === $master || isset( self::sites_of( $id )[ $master ] ) ) {
				$set['master_site_id'] = $master;
			}
		}
		if ( isset( $fields['shipping'] ) && is_array( $fields['shipping'] ) ) {
			$set['shipping'] = (string) wp_json_encode( $fields['shipping'] );
		}
		if ( ! $set ) {
			return false;
		}
		$set['updated_at'] = time();
		return false !== $wpdb->update( self::table(), $set, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * 某一部分内容改了：版本号加一。$section 取 catalog / template / campaign / shipping。
	 *
	 * @return int 新的版本号；系列不存在或参数不对时为 0
	 */
	public static function bump( int $id, string $section ): int {
		global $wpdb;
		if ( ! in_array( $section, self::SECTIONS, true ) || ! self::get( $id ) ) {
			return 0;
		}
		$t   = self::table();
		$col = $section . '_ver';
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET {$col} = {$col} + 1, updated_at = %d WHERE id = %d", time(), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT {$col} FROM {$t} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	// ---------- 运费（FE-07：$15 / $45 两档，由系列统一配置） ----------

	/**
	 * 默认的两档运费：订单金额不满门槛收高档，满门槛收低档。金额用美分。
	 * 门槛和两档金额都可以在系列页改；这里只是初始值。
	 */
	public static function default_shipping( int $threshold = 1000, int $below_cents = 4500, int $above_cents = 1500 ): array {
		return array(
			'plans' => array(
				array(
					'name'    => 'Standard shipping',
					'regions' => array( array( 'country' => 'US', 'states' => array() ) ),
					'rates'   => array(
						array( 'name' => 'Standard Shipping', 'rule' => 'subtotal', 'min' => 0, 'max' => $threshold, 'fee_cents' => $below_cents ),
						array( 'name' => 'Standard Shipping', 'rule' => 'subtotal', 'min' => $threshold, 'max' => null, 'fee_cents' => $above_cents ),
					),
				),
			),
			'rules' => array( 'combine' => 'sum', 'sort' => 'price' ),
		);
	}

	/**
	 * 整理运费配置（纯函数）：只留认识的字段，金额取非负整数美分。没有任何有效方案时返回 null。
	 *
	 * @return array{plans:array,rules:array}|null
	 */
	public static function clean_shipping( array $in ): ?array {
		$plans = array();
		foreach ( array_slice( array_values( (array) ( $in['plans'] ?? array() ) ), 0, 20 ) as $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$regions = array();
			foreach ( array_slice( array_values( (array) ( $p['regions'] ?? array() ) ), 0, 260 ) as $r ) {
				$cc = strtoupper( (string) ( $r['country'] ?? '' ) );
				if ( ! preg_match( '/^[A-Z]{2}$/D', $cc ) ) {
					continue;
				}
				$states = array();
				foreach ( array_slice( array_values( (array) ( $r['states'] ?? array() ) ), 0, 100 ) as $s ) {
					$s = strtoupper( (string) $s );
					if ( preg_match( '/^[A-Z0-9-]{1,10}$/D', $s ) ) {
						$states[] = $s;
					}
				}
				$regions[] = array( 'country' => $cc, 'states' => array_values( array_unique( $states ) ) );
			}
			$rates = array();
			foreach ( array_slice( array_values( (array) ( $p['rates'] ?? array() ) ), 0, 20 ) as $r ) {
				$name = trim( wp_strip_all_tags( (string) ( $r['name'] ?? '' ) ) );
				if ( '' === $name ) {
					continue;
				}
				$max  = $r['max'] ?? null;
				$rate = array(
					'name'      => mb_substr( $name, 0, 80 ),
					'rule'      => in_array( $r['rule'] ?? '', array( 'subtotal', 'quantity', 'weight' ), true ) ? $r['rule'] : 'subtotal',
					'min'       => max( 0.0, round( (float) ( $r['min'] ?? 0 ), 2 ) ),
					'max'       => null === $max || '' === $max ? null : max( 0.0, round( (float) $max, 2 ) ),
					'fee_cents' => max( 0, (int) ( $r['fee_cents'] ?? 0 ) ),
				);
				// 邮编范围（可选）：只在这些邮编内收这档运费。
				$postcodes = mb_substr( trim( wp_strip_all_tags( (string) ( $r['postcodes'] ?? '' ) ) ), 0, 2000 );
				if ( '' !== $postcodes ) {
					$rate['postcodes'] = $postcodes;
				}
				$rates[] = $rate;
			}
			$name = trim( wp_strip_all_tags( (string) ( $p['name'] ?? '' ) ) );
			if ( '' === $name || ! $regions || ! $rates ) {
				continue;
			}
			$plans[] = array( 'name' => mb_substr( $name, 0, 80 ), 'regions' => $regions, 'rates' => $rates );
		}
		if ( ! $plans ) {
			return null;
		}
		$rules = (array) ( $in['rules'] ?? array() );
		return array(
			'plans' => $plans,
			'rules' => array(
				'combine' => 'max' === ( $rules['combine'] ?? '' ) ? 'max' : 'sum',
				'sort'    => 'manual' === ( $rules['sort'] ?? '' ) ? 'manual' : 'price',
			),
		);
	}

	/**
	 * 保存系列运费并把版本号加一。内容没变时不加版本。
	 *
	 * @return int|\WP_Error 运费版本号
	 */
	public static function save_shipping( int $id, array $config ) {
		$series = self::get( $id );
		$clean  = self::clean_shipping( $config );
		if ( ! $series || null === $clean ) {
			return new \WP_Error( 'pchq_series_shipping', '运费配置无效：至少要有一个带地区和费用的方案。' );
		}
		// 用 JSON 文本比较：存进数据库再读出来，1000.0 会变成 1000，直接比数组会误判成“改过”。
		if ( $series['shipping_ver'] > 0 && wp_json_encode( $clean ) === wp_json_encode( $series['shipping'] ) ) {
			return $series['shipping_ver'];
		}
		if ( ! self::update( $id, array( 'shipping' => $clean ) ) ) {
			return new \WP_Error( 'pchq_series_save', '运费没能保存，请重试。' );
		}
		return self::bump( $id, 'shipping' );
	}

	// ---------- 店铺归属 ----------

	/**
	 * 所有店铺的归属：店铺 ID => series_id、buyer_name、onboarding_state、series_sync。
	 *
	 * @return array<int,array{series_id:int,buyer_name:string,onboarding_state:string,series_sync:bool}>
	 */
	public static function site_map(): array {
		global $wpdb;
		if ( null !== self::$site_map ) {
			return self::$site_map;
		}
		if ( ! self::ready() ) {
			return array();
		}
		$t   = Db::table( 'sites' );
		$out = array();
		foreach ( (array) $wpdb->get_results( "SELECT id, series_id, buyer_name, onboarding_state, series_sync FROM {$t}", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$out[ (int) $r['id'] ] = array(
				'series_id'        => (int) $r['series_id'],
				'buyer_name'       => (string) $r['buyer_name'],
				'onboarding_state' => (string) $r['onboarding_state'],
				'series_sync'      => 1 === (int) $r['series_sync'],
			);
		}
		self::$site_map = $out;
		return $out;
	}

	/** 一家店的归属；没有记录时各项为空。 */
	public static function site( int $site_id ): array {
		return self::site_map()[ $site_id ] ?? array( 'series_id' => 0, 'buyer_name' => '', 'onboarding_state' => '', 'series_sync' => false );
	}

	/**
	 * 某个系列下在用的店铺（已下线的不算）：店铺 ID => 店铺资料 + 归属字段。
	 *
	 * @return array<int,array>
	 */
	public static function sites_of( int $series_id ): array {
		$map = self::site_map();
		$out = array();
		foreach ( Sites::all() as $id => $site ) {
			if ( $series_id === ( $map[ $id ]['series_id'] ?? 0 ) ) {
				$out[ (int) $id ] = array_merge( $site, $map[ $id ] );
			}
		}
		return $out;
	}

	private static function update_site( int $site_id, array $fields ): void {
		Sites::update( $site_id, $fields );
		self::$site_map = null;
	}

	/**
	 * 把店铺归到某个系列。换系列时关掉“接受系列同步”，要重新做差异确认后才会同步商品。
	 *
	 * @return true|\WP_Error
	 */
	public static function assign( int $site_id, int $series_id ) {
		$series = self::get( $series_id );
		if ( ! $series || 'active' !== $series['status'] || ! Sites::get( $site_id ) ) {
			return new \WP_Error( 'pchq_series_assign', '店铺或系列不存在。' );
		}
		$now = self::site( $site_id );
		if ( $now['series_id'] === $series_id ) {
			return true;
		}
		self::update_site( $site_id, array( 'series_id' => $series_id, 'series_sync' => 0 ) );
		// 原系列的母版店被移走时，清掉母版设置。
		if ( $now['series_id'] > 0 ) {
			$old = self::get( $now['series_id'] );
			if ( $old && $old['master_site_id'] === $site_id ) {
				self::update( $now['series_id'], array( 'master_site_id' => 0 ) );
			}
		}
		if ( class_exists( Series_Catalog::class ) ) {
			Series_Catalog::forget_site( $site_id );
		}
		Series_Push::forget( $site_id );
		Series_Push::queue_site( $site_id );
		return true;
	}

	/** 投手姓名（报表按它分组）。 */
	public static function set_buyer( int $site_id, string $name ): void {
		self::update_site( $site_id, array( 'buyer_name' => mb_substr( trim( sanitize_text_field( $name ) ), 0, 190 ) ) );
	}

	/** 这家店是否接受系列同步（商品、活动、运费）。 */
	public static function set_sync( int $site_id, bool $on ): void {
		self::update_site( $site_id, array( 'series_sync' => $on ? 1 : 0 ) );
		if ( $on ) {
			Series_Push::queue_site( $site_id );
		}
	}

	/** 开店向导进度（由投手后台模块写入，例如 pending / step2 / done）。 */
	public static function set_onboarding( int $site_id, string $state ): void {
		self::update_site( $site_id, array( 'onboarding_state' => substr( sanitize_key( $state ), 0, 32 ) ) );
	}

	// ---------- 新店 ----------

	private static function host( string $url_or_host ): string {
		$host = str_contains( $url_or_host, '/' ) ? (string) wp_parse_url( $url_or_host, PHP_URL_HOST ) : $url_or_host;
		$host = strtolower( trim( $host ) );
		return preg_match( '/^[a-z0-9.-]{3,253}$/D', $host ) ? $host : '';
	}

	/**
	 * 开店时预留：这个管理地址（或域名）的店铺注册进来时归入指定系列，并记下投手。
	 * 由开店页面在提交开店任务时调用。预留 30 天有效。
	 */
	public static function reserve( string $host, int $series_id, string $buyer_name = '' ): bool {
		$host   = self::host( $host );
		$series = self::get( $series_id );
		if ( '' === $host || ! $series || 'active' !== $series['status'] ) {
			return false;
		}
		$all = get_option( self::RESERVED, array() );
		$all = is_array( $all ) ? $all : array();
		$all = array_filter( $all, static fn( $r ): bool => is_array( $r ) && (int) ( $r['at'] ?? 0 ) > time() - 30 * DAY_IN_SECONDS );
		$all[ $host ] = array(
			'series_id'  => $series_id,
			'buyer_name' => mb_substr( trim( sanitize_text_field( $buyer_name ) ), 0, 190 ),
			'at'         => time(),
		);
		update_option( self::RESERVED, array_slice( $all, -200, null, true ), false );
		return true;
	}

	/** 新店注册：归入预留的系列（没有预留就归入默认系列），默认接受系列同步，稍后把系列信息发给店铺。 */
	public static function on_site_registered( $site_id ): void {
		$site_id = (int) $site_id;
		$site    = Sites::get( $site_id );
		if ( ! $site || ! self::ready() ) {
			return;
		}
		$all = get_option( self::RESERVED, array() );
		$all = is_array( $all ) ? $all : array();
		$hit = null;
		foreach ( array( $site['management_url'], $site['public_url'], $site['url'] ) as $url ) {
			$host = self::host( (string) $url );
			if ( '' !== $host && isset( $all[ $host ] ) && is_array( $all[ $host ] ) ) {
				$hit = $all[ $host ];
				unset( $all[ $host ] );
				update_option( self::RESERVED, $all, false );
				break;
			}
		}
		$series = $hit ? self::get( (int) ( $hit['series_id'] ?? 0 ) ) : null;
		$sid    = $series && 'active' === $series['status'] ? $series['id'] : self::default_id();
		if ( $sid < 1 ) {
			return;
		}
		self::update_site(
			$site_id,
			array(
				'series_id'   => $sid,
				'buyer_name'  => $hit ? (string) ( $hit['buyer_name'] ?? '' ) : '',
				'series_sync' => 1,
			)
		);
		Series_Push::queue_site( $site_id, 2 * MINUTE_IN_SECONDS );
	}
}
