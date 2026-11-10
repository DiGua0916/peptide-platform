<?php
/**
 * 系列同步的店铺端（HQ-05、FE-07、NF-12）。系列定义在总后台，店铺只记自己属于哪个系列和已同步到的版本号。
 *
 * - 店铺选项 peptide_core_series：series_id、slug、name、master、catalog_ver、template_ver、campaign_ver、shipping_ver、synced_at。
 * - 新订单写入订单元 _pc_series_id（下单时店铺所属的系列）。
 * - 总后台调用 POST /peptide/v1/hq/series/sync（签名校验用 Hq_Connector::verify）：每次都带系列基本信息；
 *   catalog（商品）、campaigns（活动）、shipping（运费）三部分各带版本号，版本号不大于本地的直接跳过，
 *   所以重复调用结果不变。dry_run 时只返回差异报告，不改任何东西。
 * - 商品：按 _pc_series_sku → _pc_spu → SKU → 别名找本地商品，找不到就新建；同步后标记 _pc_series_managed = 1，
 *   店铺后台只读。系列里没有的商品改为草稿，不删除。母版店（master）只接收价格，不新建、不下架、不锁定。
 * - 运费：用系列的方案替换“通用”运费方案，按商品单独设置的方案不动。
 * - 活动：内容由营销模块处理，这里只把 campaigns 交给过滤器 pc_series_apply_campaigns。
 * - 每次真正改动前自动存一份快照（商品、运费、系列状态，活动由营销模块经 pc_series_snapshot 放进来），保留最近 3 份；
 *   恢复：POST /peptide/v1/hq/series/restore 或 wp peptide series restore [<版本>]。
 * - GET /peptide/v1/hq/catalog-export：母版店导出商品、运费、活动，给总后台生成系列主商品库。
 *
 * 请求和返回的格式见 docs/CONTRACT.md “M3 系列与商品目录”。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Series_Sync {

	public const OPTION          = 'peptide_core_series';
	public const ORDER_META      = '_pc_series_id';
	public const PRODUCT_SKU     = '_pc_series_sku';
	public const PRODUCT_MANAGED = '_pc_series_managed';
	public const IMAGE_SHA       = '_pc_series_sha256';
	public const SNAPSHOTS       = 'pc_series_snapshots';
	public const SNAPSHOT_PREFIX = 'pc_series_snapshot_';
	public const MEDIA_MAP       = 'pc_series_media';

	/** 随系列同步的商品元和款式元（其余商品元留在店铺本地）。 */
	public const PRODUCT_META   = array( '_pc_spu', '_pc_product_subtitle', '_pc_specs', '_pc_vials_per_sales_unit', '_pc_pricing', '_pc_is_demo' );
	public const VARIATION_META = array( '_pc_vials_per_sales_unit', '_pc_pricing' );

	/** 一次同步最多处理多少个商品、保留几份快照、单张图片最大多少字节、一次请求最多跑多少秒。 */
	public const MAX_PRODUCTS    = 2000;
	public const KEEP_SNAPSHOTS  = 3;
	public const MAX_IMAGE_BYTES = 15728640;
	public const TIME_BUDGET     = 200;

	/** 同步进行中的标记（防止两次同步同时改商品）。 */
	private const LOCK = 'pc_series_sync_lock';

	/** 同步或恢复正在写商品（这时不拦“系列管理的商品只读”）。 */
	private static bool $writing = false;

	/** 本次同步里没成功的图片（原因），随结果报给总后台；图片失败不拦价格和其他内容。 */
	private static array $notes = array();

	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_action( 'woocommerce_before_order_object_save', array( self::class, 'stamp_order' ), 5, 1 );
		add_filter( 'pc_hq_capabilities', array( self::class, 'capabilities' ) );
		add_filter( 'map_meta_cap', array( self::class, 'lock_managed' ), 10, 4 );
		add_action( 'admin_notices', array( self::class, 'managed_notice' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'peptide series status', array( self::class, 'cli_status' ) );
			\WP_CLI::add_command( 'peptide series restore', array( self::class, 'cli_restore' ) );
			\WP_CLI::add_command( 'peptide series export', array( self::class, 'cli_export' ) );
			\WP_CLI::add_command( 'peptide series diff', array( self::class, 'cli_diff' ) );
		}
	}

	// ---------- 本店的系列状态 ----------

	/** @return array{series_id:int,slug:string,name:string,master:bool,catalog_ver:int,template_ver:int,campaign_ver:int,shipping_ver:int,synced_at:int} */
	public static function normalize_state( $s ): array {
		$s = is_array( $s ) ? $s : array();
		return array(
			'series_id'    => max( 0, (int) ( $s['series_id'] ?? 0 ) ),
			'slug'         => (string) ( $s['slug'] ?? '' ),
			'name'         => (string) ( $s['name'] ?? '' ),
			'master'       => ! empty( $s['master'] ),
			'catalog_ver'  => max( 0, (int) ( $s['catalog_ver'] ?? 0 ) ),
			'template_ver' => max( 0, (int) ( $s['template_ver'] ?? 0 ) ),
			'campaign_ver' => max( 0, (int) ( $s['campaign_ver'] ?? 0 ) ),
			'shipping_ver' => max( 0, (int) ( $s['shipping_ver'] ?? 0 ) ),
			'synced_at'    => max( 0, (int) ( $s['synced_at'] ?? 0 ) ),
		);
	}

	public static function state(): array {
		return self::normalize_state( get_option( self::OPTION, array() ) );
	}

	/** 本店所属的系列；还没收到总后台的系列信息时为 0。 */
	public static function series_id(): int {
		return self::state()['series_id'];
	}

	/** 本店是不是所在系列的母版店（商品内容在这里编辑，再由总后台导入）。 */
	public static function is_master(): bool {
		return self::state()['master'];
	}

	/**
	 * 某一部分（catalog / campaign / shipping）是否已经由系列接管：接管后店铺后台不应再提供修改入口。
	 * 母版店不算被接管（内容在母版店编辑）。
	 */
	public static function manages( string $section ): bool {
		$s = self::state();
		return $s['series_id'] > 0 && ! $s['master'] && in_array( $section, array( 'catalog', 'campaign', 'shipping' ), true ) && $s[ $section . '_ver' ] > 0;
	}

	/**
	 * 向总后台声明本店支持的功能（功能键 => 版本号，钩子 pc_hq_capabilities 由发布模块提供）。
	 * 总后台只对声明了 series.sync 的店调用 /hq/series/sync。
	 */
	public static function capabilities( $caps ): array {
		$caps = is_array( $caps ) ? $caps : array();
		return $caps + array(
			'series.sync'     => '1',
			'series.catalog'  => '1',
			'series.shipping' => '1',
		);
	}

	// ---------- 订单带上所属系列 ----------

	/** 新订单第一次保存前写入 _pc_series_id。已有订单不改（历史订单由回填脚本补）。 */
	public static function stamp_order( $order ): void {
		if ( ! $order instanceof \WC_Order || 'shop_order' !== $order->get_type() || $order->get_id() > 0 ) {
			return;
		}
		$id = self::series_id();
		if ( $id > 0 && '' === (string) $order->get_meta( self::ORDER_META ) ) {
			$order->update_meta_data( self::ORDER_META, (string) $id );
		}
	}

	// ---------- 接口 ----------

	public static function routes(): void {
		$auth = array( Hq_Connector::class, 'verify' );
		register_rest_route( Hq_Connector::NS, '/hq/series/sync', array( 'methods' => 'POST', 'callback' => array( self::class, 'sync_route' ), 'permission_callback' => $auth ) );
		register_rest_route( Hq_Connector::NS, '/hq/series/state', array( 'methods' => 'GET', 'callback' => array( self::class, 'state_route' ), 'permission_callback' => $auth ) );
		register_rest_route( Hq_Connector::NS, '/hq/series/restore', array( 'methods' => 'POST', 'callback' => array( self::class, 'restore_route' ), 'permission_callback' => $auth ) );
		register_rest_route( Hq_Connector::NS, '/hq/catalog-export', array( 'methods' => 'GET', 'callback' => array( self::class, 'export_route' ), 'permission_callback' => $auth ) );
	}

	public static function state_route(): array {
		return array( 'series' => self::state(), 'snapshots' => self::snapshots() );
	}

	/**
	 * 校验请求里的系列基本信息（纯函数）。无效时返回 null。
	 *
	 * @return array{series_id:int,slug:string,name:string,template_ver:int,master:bool}|null
	 */
	public static function clean_header( array $p ): ?array {
		$id   = (int) ( $p['series_id'] ?? 0 );
		$slug = (string) ( $p['slug'] ?? '' );
		if ( $id < 1 || ! preg_match( '/^[a-z0-9][a-z0-9-]{0,63}$/D', $slug ) ) {
			return null;
		}
		$name = trim( strip_tags( (string) ( $p['name'] ?? '' ) ) );
		return array(
			'series_id'    => $id,
			'slug'         => $slug,
			'name'         => function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 190 ) : substr( $name, 0, 190 ),
			'template_ver' => max( 0, (int) ( $p['template_ver'] ?? 0 ) ),
			'master'       => ! empty( $p['master'] ),
		);
	}

	/**
	 * 把收到的系列基本信息并进本店状态（纯函数）。换了系列时内容的版本号归零，等新系列重新同步。
	 * 模板版本号只增不减（同一系列内）。
	 */
	public static function merge_header( array $state, array $head ): array {
		$state = self::normalize_state( $state );
		if ( $state['series_id'] !== $head['series_id'] ) {
			$state['catalog_ver']  = 0;
			$state['campaign_ver'] = 0;
			$state['shipping_ver'] = 0;
			$state['template_ver'] = 0;
		}
		$state['series_id']    = $head['series_id'];
		$state['slug']         = $head['slug'];
		$state['name']         = $head['name'];
		$state['master']       = ! empty( $head['master'] );
		$state['template_ver'] = max( $state['template_ver'], $head['template_ver'] );
		return $state;
	}

	/** 某一部分要不要处理：带了这部分、版本号是正整数，而且比本地新。 */
	public static function section_due( $section, int $local_ver ): bool {
		return is_array( $section ) && (int) ( $section['ver'] ?? 0 ) > $local_ver;
	}

	/**
	 * @return array|\WP_Error
	 */
	public static function sync_route( \WP_REST_Request $request ) {
		$p    = $request->get_json_params();
		$p    = is_array( $p ) ? $p : array();
		$head = self::clean_header( $p );
		if ( null === $head ) {
			return new \WP_Error( 'pc_series_bad', 'Invalid series header.', array( 'status' => 400 ) );
		}
		$dry = ! empty( $p['dry_run'] );
		if ( ! $dry ) {
			if ( get_transient( self::LOCK ) ) {
				return new \WP_Error( 'pc_series_busy', 'A series sync is already running on this store.', array( 'status' => 409 ) );
			}
			set_transient( self::LOCK, 1, 10 * MINUTE_IN_SECONDS );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- 首次同步可能要下载图片。
		}
		try {
			return self::sync( $head, $p, $dry );
		} finally {
			if ( ! $dry ) {
				delete_transient( self::LOCK );
			}
		}
	}

	/**
	 * 处理一次同步。$dry 为 true 时什么都不写，只返回差异。
	 *
	 * @return array|\WP_Error
	 */
	public static function sync( array $head, array $p, bool $dry ) {
		$before   = self::state();
		$state    = self::merge_header( $before, $head );
		$applied  = array( 'catalog' => null, 'campaigns' => null, 'shipping' => null );
		$diff     = array();
		$complete = true;
		$deadline = microtime( true ) + self::TIME_BUDGET;

		// 试运行只是比较，带了哪部分就比哪部分，不看版本号。
		$due = array(
			'catalog'   => isset( $p['catalog'] ) && ( $dry ? is_array( $p['catalog'] ) : self::section_due( $p['catalog'], $state['catalog_ver'] ) ),
			'campaigns' => isset( $p['campaigns'] ) && ( $dry ? is_array( $p['campaigns'] ) : self::section_due( $p['campaigns'], $state['campaign_ver'] ) ),
			'shipping'  => isset( $p['shipping'] ) && ( $dry ? is_array( $p['shipping'] ) : self::section_due( $p['shipping'], $state['shipping_ver'] ) ),
		);
		foreach ( $due as $section => $is_due ) {
			if ( isset( $p[ $section ] ) && ! $is_due ) {
				$applied[ $section ] = 'skipped';
			}
		}

		// 真正改动前存一份快照（同一个目标版本只存一次，分批续跑时不重复存）。
		if ( ! $dry && array_filter( $due ) ) {
			$target = array(
				'series_id'    => $state['series_id'],
				'catalog_ver'  => $due['catalog'] ? (int) $p['catalog']['ver'] : $state['catalog_ver'],
				'campaign_ver' => $due['campaigns'] ? (int) $p['campaigns']['ver'] : $state['campaign_ver'],
				'shipping_ver' => $due['shipping'] ? (int) $p['shipping']['ver'] : $state['shipping_ver'],
			);
			if ( ! self::take_snapshot( $before, $target ) ) {
				return new \WP_Error( 'pc_series_snapshot', 'The pre-sync snapshot could not be saved; nothing was changed.', array( 'status' => 500 ) );
			}
		}

		if ( $due['catalog'] ) {
			$result = self::apply_catalog( (array) $p['catalog'], $dry, $state['master'], $deadline );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( $dry ) {
				$diff = $result['diff'];
			} else {
				$applied['catalog'] = $result['counts'];
				$complete           = $result['complete'];
				if ( $complete ) {
					$state['catalog_ver'] = (int) $p['catalog']['ver'];
				}
			}
		}

		if ( $due['shipping'] ) {
			$result = self::apply_shipping( (array) $p['shipping'], $dry );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( $dry ) {
				$diff['shipping'] = $result;
			} else {
				$applied['shipping']   = 'applied';
				$state['shipping_ver'] = (int) $p['shipping']['ver'];
			}
		}

		// 活动：内容由营销模块处理。返回 null 表示没有模块接手，这时不记版本号，下次还会再发。
		if ( $due['campaigns'] ) {
			$items  = array_values( array_filter( (array) ( $p['campaigns']['items'] ?? array() ), 'is_array' ) );
			$ver    = (int) $p['campaigns']['ver'];
			$result = apply_filters( 'pc_series_apply_campaigns', null, $items, $ver, $dry );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( null === $result ) {
				$applied['campaigns'] = 'unhandled';
			} elseif ( $dry ) {
				$diff['campaigns'] = $result;
			} else {
				$applied['campaigns']  = is_array( $result ) ? $result : 'applied';
				$state['campaign_ver'] = $ver;
			}
		}

		if ( $dry ) {
			return array(
				'series'   => $before,
				'applied'  => $applied,
				'complete' => true,
				'diff'     => $diff,
			);
		}
		$state['synced_at'] = time();
		update_option( self::OPTION, $state, false );
		return array(
			'series'   => $state,
			'applied'  => $applied,
			'complete' => $complete,
		);
	}

	// ---------- 商品对象（纯函数） ----------

	/** 款式没有 SKU 时的键：属性的哈希。属性名和取值不分大小写。 */
	public static function attr_key( array $attributes ): string {
		$norm = array();
		foreach ( $attributes as $k => $v ) {
			$norm[ strtolower( trim( (string) $k ) ) ] = strtolower( trim( (string) $v ) );
		}
		ksort( $norm );
		return 'a:' . substr( sha1( (string) json_encode( $norm ) ), 0, 16 );
	}

	/** 美分 → “12.34”。 */
	public static function dollars( int $cents ): string {
		return number_format( $cents / 100, 2, '.', '' );
	}

	/** 价格字符串 → 美分。 */
	public static function to_cents( $amount ): int {
		return (int) round( (float) $amount * 100 );
	}

	/**
	 * 成交价和划线价（美分）→ WooCommerce 的常规价和促销价。划线价不高于成交价时没有促销价。
	 *
	 * @return array{regular:string,sale:string}
	 */
	public static function wc_prices( int $price_cents, int $compare_cents ): array {
		if ( $compare_cents > $price_cents && $price_cents > 0 ) {
			return array( 'regular' => self::dollars( $compare_cents ), 'sale' => self::dollars( $price_cents ) );
		}
		return array( 'regular' => $price_cents > 0 ? self::dollars( $price_cents ) : '', 'sale' => '' );
	}

	/**
	 * WooCommerce 的常规价和促销价 → 成交价和划线价（美分）。
	 *
	 * @return array{0:int,1:int} 成交价、划线价（0 = 没有）
	 */
	public static function series_prices( $regular, $sale ): array {
		$r = self::to_cents( $regular );
		$s = '' === (string) $sale ? 0 : self::to_cents( $sale );
		return $s > 0 && $s < $r ? array( $s, $r ) : array( $r, 0 );
	}

	private static function str( $v, int $max ): string {
		$v = is_scalar( $v ) ? (string) $v : '';
		return function_exists( 'mb_substr' ) ? mb_substr( $v, 0, $max ) : substr( $v, 0, $max );
	}

	/** @return array<string,string> */
	private static function clean_attr_map( $map ): array {
		$out = array();
		foreach ( is_array( $map ) ? $map : array() as $k => $v ) {
			$k = strtolower( trim( (string) $k ) );
			if ( '' !== $k && is_scalar( $v ) ) {
				$out[ $k ] = (string) $v;
			}
		}
		return $out;
	}

	/** @return array{url:string,sha256:string,name:string,alt:string}|null */
	public static function clean_image( $img ): ?array {
		if ( ! is_array( $img ) ) {
			return null;
		}
		$sha = strtolower( (string) ( $img['sha256'] ?? '' ) );
		$url = (string) ( $img['url'] ?? '' );
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $sha ) || ! preg_match( '#^https?://[^\s"<>]+$#D', $url ) ) {
			return null;
		}
		return array(
			'url'    => $url,
			'sha256' => $sha,
			'name'   => self::str( basename( (string) ( $img['name'] ?? '' ) ), 190 ),
			'alt'    => self::str( strip_tags( (string) ( $img['alt'] ?? '' ) ), 300 ),
		);
	}

	/** 只留允许同步的元。 */
	private static function clean_meta( $meta, array $allowed ): array {
		$out = array();
		foreach ( is_array( $meta ) ? $meta : array() as $k => $v ) {
			if ( in_array( $k, $allowed, true ) && ( is_scalar( $v ) || is_array( $v ) ) ) {
				$out[ $k ] = $v;
			}
		}
		ksort( $out );
		return $out;
	}

	/** @return array|null */
	public static function clean_variation( $v ): ?array {
		if ( ! is_array( $v ) ) {
			return null;
		}
		$attrs = self::clean_attr_map( $v['attributes'] ?? array() );
		$sku   = trim( self::str( $v['sku'] ?? '', 100 ) );
		$key   = trim( self::str( $v['key'] ?? '', 100 ) );
		$key   = '' !== $key ? $key : ( '' !== $sku ? $sku : self::attr_key( $attrs ) );
		$price = (int) ( $v['price_cents'] ?? 0 );
		if ( ! $attrs || $price < 0 ) {
			return null;
		}
		return array(
			'key'           => $key,
			'sku'           => $sku,
			'attributes'    => $attrs,
			'price_cents'   => $price,
			'compare_cents' => max( 0, (int) ( $v['compare_cents'] ?? 0 ) ),
			'stock_status'  => in_array( $v['stock_status'] ?? '', array( 'instock', 'outofstock', 'onbackorder' ), true ) ? $v['stock_status'] : 'instock',
			'enabled'       => ! array_key_exists( 'enabled', $v ) || ! empty( $v['enabled'] ),
			'image'         => self::clean_image( $v['image'] ?? null ),
			'meta'          => self::clean_meta( $v['meta'] ?? array(), self::VARIATION_META ),
		);
	}

	/**
	 * 整理一个商品对象（纯函数）。键、名称不对，或者款式有问题时返回 null（整个目录拒收）。
	 * $strict 为 false 用于本店已有的商品：有问题的款式跳过，没有款式的可变商品也保留，这样才能和系列商品配上对。
	 */
	public static function clean_product( $p, bool $strict = true ): ?array {
		if ( ! is_array( $p ) ) {
			return null;
		}
		$sku  = trim( self::str( $p['sku'] ?? '', 100 ) );
		$name = trim( self::str( $p['name'] ?? '', 255 ) );
		$type = 'variable' === ( $p['type'] ?? '' ) ? 'variable' : 'simple';
		if ( '' === $sku || '' === $name ) {
			return null;
		}
		$attrs = array();
		foreach ( array_values( (array) ( $p['attributes'] ?? array() ) ) as $a ) {
			$n = is_array( $a ) ? trim( self::str( $a['name'] ?? '', 190 ) ) : '';
			if ( '' === $n ) {
				continue;
			}
			$attrs[] = array(
				'name'      => $n,
				'options'   => array_values( array_map( 'strval', array_filter( (array) ( $a['options'] ?? array() ), 'is_scalar' ) ) ),
				'visible'   => ! empty( $a['visible'] ),
				'variation' => ! empty( $a['variation'] ),
			);
		}
		$vars = array();
		$seen = array();
		foreach ( array_values( (array) ( $p['variations'] ?? array() ) ) as $raw ) {
			$v = self::clean_variation( $raw );
			if ( null === $v || isset( $seen[ $v['key'] ] ) ) {
				if ( $strict ) {
					return null;
				}
				continue;
			}
			$seen[ $v['key'] ] = true;
			$vars[]            = $v;
		}
		if ( $strict && 'variable' === $type && ! $vars ) {
			return null;
		}
		$cats = array();
		foreach ( (array) ( $p['categories'] ?? array() ) as $c ) {
			$c = trim( self::str( $c, 190 ) );
			if ( '' !== $c && ! in_array( $c, $cats, true ) ) {
				$cats[] = $c;
			}
		}
		return array(
			'sku'                => $sku,
			'wc_sku'             => trim( self::str( $p['wc_sku'] ?? '', 100 ) ),
			'type'               => $type,
			'name'               => $name,
			'slug'               => (string) preg_replace( '/[^a-z0-9%_-]/', '', strtolower( self::str( $p['slug'] ?? '', 200 ) ) ),
			'status'             => 'publish' === ( $p['status'] ?? '' ) ? 'publish' : 'draft',
			'description'        => is_string( $p['description'] ?? null ) ? $p['description'] : '',
			'short_description'  => is_string( $p['short_description'] ?? null ) ? $p['short_description'] : '',
			'categories'         => $cats,
			'menu_order'         => (int) ( $p['menu_order'] ?? 0 ),
			'attributes'         => $attrs,
			'default_attributes' => self::clean_attr_map( $p['default_attributes'] ?? array() ),
			'images'             => array_values( array_filter( array_map( array( self::class, 'clean_image' ), (array) ( $p['images'] ?? array() ) ) ) ),
			'meta'               => self::clean_meta( $p['meta'] ?? array(), self::PRODUCT_META ),
			'price_cents'        => max( 0, (int) ( $p['price_cents'] ?? 0 ) ),
			'compare_cents'      => max( 0, (int) ( $p['compare_cents'] ?? 0 ) ),
			'stock_status'       => in_array( $p['stock_status'] ?? '', array( 'instock', 'outofstock', 'onbackorder' ), true ) ? $p['stock_status'] : 'instock',
			'variations'         => $vars,
		);
	}

	private static function text_key( string $html ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', $html ) );
	}

	private static function text_note( string $html ): string {
		$text = trim( strip_tags( $html ) );
		return ( function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text ) ) . ' 字';
	}

	private static function variation_label( array $v ): string {
		return implode( ' / ', array_values( $v['attributes'] ) );
	}

	/**
	 * 比较本店商品和系列商品（都是商品对象），返回不一样的地方（纯函数）。空数组 = 一样。
	 *
	 * @return array<int,array{field:string,store:string,series:string,variation?:string}>
	 */
	public static function compare_product( array $local, array $in ): array {
		$fields = array();
		$add    = static function ( string $field, $store, $series, string $variation = '' ) use ( &$fields ): void {
			$row = array( 'field' => $field, 'store' => (string) $store, 'series' => (string) $series );
			if ( '' !== $variation ) {
				$row['variation'] = $variation;
			}
			$fields[] = $row;
		};
		foreach ( array( 'name', 'status', 'type' ) as $k ) {
			if ( $local[ $k ] !== $in[ $k ] ) {
				$add( $k, $local[ $k ], $in[ $k ] );
			}
		}
		foreach ( array( 'description', 'short_description' ) as $k ) {
			if ( self::text_key( $local[ $k ] ) !== self::text_key( $in[ $k ] ) ) {
				$add( $k, self::text_note( $local[ $k ] ), self::text_note( $in[ $k ] ) );
			}
		}
		$lc = $local['categories'];
		$ic = $in['categories'];
		sort( $lc );
		sort( $ic );
		if ( $lc !== $ic ) {
			$add( 'categories', implode( '、', $lc ), implode( '、', $ic ) );
		}
		$attr_text = static fn( array $attrs ): string => implode( '；', array_map( static fn( array $a ): string => $a['name'] . '：' . implode( ' | ', $a['options'] ), $attrs ) );
		if ( json_encode( $local['attributes'] ) !== json_encode( $in['attributes'] ) ) {
			$add( 'attributes', $attr_text( $local['attributes'] ), $attr_text( $in['attributes'] ) );
		}
		$ld = $local['default_attributes'];
		$id = $in['default_attributes'];
		ksort( $ld );
		ksort( $id );
		if ( $ld !== $id ) {
			$add( 'default_attributes', implode( ' / ', $ld ), implode( ' / ', $id ) );
		}
		if ( array_column( $local['images'], 'sha256' ) !== array_column( $in['images'], 'sha256' ) ) {
			$add( 'images', count( $local['images'] ) . ' 张', count( $in['images'] ) . ' 张' );
		}
		if ( json_encode( $local['meta'] ) !== json_encode( $in['meta'] ) ) {
			$keys = array();
			foreach ( array_unique( array_merge( array_keys( $local['meta'] ), array_keys( $in['meta'] ) ) ) as $k ) {
				if ( json_encode( $local['meta'][ $k ] ?? null ) !== json_encode( $in['meta'][ $k ] ?? null ) ) {
					$keys[] = $k;
				}
			}
			$add( 'meta', implode( '、', $keys ), implode( '、', $keys ) );
		}
		if ( 'variable' !== $in['type'] || 'variable' !== $local['type'] ) {
			if ( $local['price_cents'] !== $in['price_cents'] ) {
				$add( 'price', self::dollars( $local['price_cents'] ), self::dollars( $in['price_cents'] ) );
			}
			if ( $local['compare_cents'] !== $in['compare_cents'] ) {
				$add( 'compare_price', self::dollars( $local['compare_cents'] ), self::dollars( $in['compare_cents'] ) );
			}
			if ( $local['stock_status'] !== $in['stock_status'] ) {
				$add( 'stock', $local['stock_status'], $in['stock_status'] );
			}
			return $fields;
		}
		$by_key  = array();
		$by_attr = array();
		foreach ( $local['variations'] as $i => $v ) {
			$by_key[ $v['key'] ] = $i;
			$by_attr[ self::attr_key( $v['attributes'] ) ] = $i;
		}
		$used = array();
		foreach ( $in['variations'] as $v ) {
			$label = self::variation_label( $v );
			$i     = $by_key[ $v['key'] ] ?? ( '' !== $v['sku'] && isset( $by_key[ $v['sku'] ] ) ? $by_key[ $v['sku'] ] : ( $by_attr[ self::attr_key( $v['attributes'] ) ] ?? null ) );
			if ( null === $i || isset( $used[ $i ] ) ) {
				$add( 'variation', '（没有）', '新增', $label );
				continue;
			}
			$used[ $i ] = true;
			$l          = $local['variations'][ $i ];
			if ( $l['price_cents'] !== $v['price_cents'] ) {
				$add( 'price', self::dollars( $l['price_cents'] ), self::dollars( $v['price_cents'] ), $label );
			}
			if ( $l['compare_cents'] !== $v['compare_cents'] ) {
				$add( 'compare_price', self::dollars( $l['compare_cents'] ), self::dollars( $v['compare_cents'] ), $label );
			}
			if ( $l['stock_status'] !== $v['stock_status'] ) {
				$add( 'stock', $l['stock_status'], $v['stock_status'], $label );
			}
			if ( $l['enabled'] !== $v['enabled'] ) {
				$add( 'enabled', $l['enabled'] ? '启用' : '停用', $v['enabled'] ? '启用' : '停用', $label );
			}
			$la = $l['attributes'];
			$va = $v['attributes'];
			ksort( $la );
			ksort( $va );
			if ( $la !== $va ) {
				$add( 'variation_attributes', self::variation_label( $l ), $label, $label );
			}
			if ( ( $l['image']['sha256'] ?? '' ) !== ( $v['image']['sha256'] ?? '' ) ) {
				$add( 'image', isset( $l['image'] ) ? '有图' : '无图', isset( $v['image'] ) ? '有图' : '无图', $label );
			}
			if ( json_encode( $l['meta'] ) !== json_encode( $v['meta'] ) ) {
				$add( 'meta', implode( '、', array_keys( $l['meta'] ) ), implode( '、', array_keys( $v['meta'] ) ), $label );
			}
		}
		foreach ( $local['variations'] as $i => $l ) {
			if ( ! isset( $used[ $i ] ) && $l['enabled'] ) {
				$add( 'variation', self::variation_label( $l ), '系列里没有，同步后停用', self::variation_label( $l ) );
			}
		}
		return $fields;
	}

	/** 只留价格相关的差异（母版店只接收价格）。 */
	public static function price_fields( array $fields ): array {
		return array_values( array_filter( $fields, static fn( array $f ): bool => in_array( $f['field'], array( 'price', 'compare_price' ), true ) ) );
	}

	/**
	 * 给系列商品找对应的本地商品（纯函数）：按 _pc_series_sku → _pc_spu → SKU → 别名。一个本地商品只配一次。
	 *
	 * @param array[] $incoming 系列商品
	 * @param array[] $locals   本地商品（export_local 的结果，带 series_sku）
	 * @return array<int,int|null> 系列商品的下标 => 本地商品的下标（没有时为 null）
	 */
	public static function match_products( array $incoming, array $locals ): array {
		$by = array( 'series' => array(), 'spu' => array(), 'sku' => array(), 'slug' => array() );
		foreach ( $locals as $i => $l ) {
			$keys = array(
				'series' => (string) ( $l['series_sku'] ?? '' ),
				'spu'    => is_scalar( $l['meta']['_pc_spu'] ?? null ) ? (string) $l['meta']['_pc_spu'] : '',
				'sku'    => (string) ( $l['wc_sku'] ?? '' ),
				'slug'   => '' !== (string) ( $l['slug'] ?? '' ) ? 'slug:' . $l['slug'] : '',
			);
			foreach ( $keys as $kind => $key ) {
				if ( '' !== $key && ! isset( $by[ $kind ][ $key ] ) ) {
					$by[ $kind ][ $key ] = $i;
				}
			}
		}
		$used = array();
		$out  = array();
		foreach ( $incoming as $n => $p ) {
			$hit = null;
			foreach ( array( 'series', 'spu', 'sku', 'slug' ) as $kind ) {
				$i = $by[ $kind ][ $p['sku'] ] ?? null;
				if ( null !== $i && ! isset( $used[ $i ] ) ) {
					$hit = $i;
					break;
				}
			}
			if ( null !== $hit ) {
				$used[ $hit ] = true;
			}
			$out[ $n ] = $hit;
		}
		return $out;
	}

	/**
	 * 差异报告（纯函数）：系列主商品库和本店现有商品比一遍。
	 *
	 * @param bool $prices_only 母版店：只比价格，不新建、不下架
	 */
	public static function diff_catalog( array $incoming, array $locals, int $ver, bool $prices_only = false ): array {
		$match     = self::match_products( $incoming, $locals );
		$used      = array();
		$create    = array();
		$changed   = array();
		$unpublish = array();
		$same      = 0;
		foreach ( $incoming as $n => $p ) {
			$i = $match[ $n ];
			if ( null === $i ) {
				if ( ! $prices_only ) {
					$create[] = array( 'sku' => $p['sku'], 'name' => $p['name'] );
				}
				continue;
			}
			$used[ $i ] = true;
			$fields     = self::compare_product( $locals[ $i ], $p );
			$fields     = $prices_only ? self::price_fields( $fields ) : $fields;
			if ( $fields ) {
				$changed[] = array( 'sku' => $p['sku'], 'name' => $p['name'], 'id' => (int) ( $locals[ $i ]['id'] ?? 0 ), 'fields' => array_slice( $fields, 0, 40 ) );
			} else {
				++$same;
			}
		}
		if ( ! $prices_only ) {
			foreach ( $locals as $i => $l ) {
				if ( ! isset( $used[ $i ] ) && 'publish' === $l['status'] ) {
					$unpublish[] = array( 'id' => (int) ( $l['id'] ?? 0 ), 'sku' => (string) $l['sku'], 'name' => (string) $l['name'] );
				}
			}
		}
		return array(
			'catalog_ver' => $ver,
			'prices_only' => $prices_only,
			'summary'     => array( 'same' => $same, 'changed' => count( $changed ), 'create' => count( $create ), 'unpublish' => count( $unpublish ) ),
			'create'      => array_slice( $create, 0, 500 ),
			'unpublish'   => array_slice( $unpublish, 0, 500 ),
			'changed'     => array_slice( $changed, 0, 500 ),
		);
	}

	/**
	 * 校验并整理收到的整个目录（纯函数）。有一个商品不对就整体拒收，避免半套商品。
	 *
	 * @return array{ver:int,source_host:string,products:array}|string 出错时返回原因代码
	 */
	public static function clean_catalog( array $catalog ) {
		$ver  = (int) ( $catalog['ver'] ?? 0 );
		$host = strtolower( (string) ( $catalog['source_host'] ?? '' ) );
		$list = array_values( (array) ( $catalog['products'] ?? array() ) );
		if ( $ver < 1 || ! $list || count( $list ) > self::MAX_PRODUCTS ) {
			return 'empty_or_too_large';
		}
		if ( '' !== $host && ! preg_match( '/^[a-z0-9.-]{3,253}$/D', $host ) ) {
			return 'bad_source_host';
		}
		$out = array();
		foreach ( $list as $raw ) {
			$p = self::clean_product( $raw );
			if ( null === $p ) {
				return 'invalid_product';
			}
			if ( isset( $out[ $p['sku'] ] ) ) {
				return 'duplicate_sku';
			}
			$out[ $p['sku'] ] = $p;
		}
		return array( 'ver' => $ver, 'source_host' => $host, 'products' => array_values( $out ) );
	}

	// ---------- 运费（纯函数 + 写入） ----------

	/**
	 * 系列的运费方案 → 本店运费方案的格式（纯函数）。费用从美分换成金额；ID 固定，重复同步结果一样。
	 *
	 * @param int $first_id 新方案从哪个编号开始
	 */
	public static function plans_from_series( array $plans, int $first_id = 1 ): array {
		$out = array();
		foreach ( array_values( $plans ) as $pi => $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$rates = array();
			foreach ( array_values( (array) ( $p['rates'] ?? array() ) ) as $ri => $r ) {
				$max     = $r['max'] ?? null;
				$rates[] = array(
					'id'        => 's' . ( $pi + 1 ) . 'r' . ( $ri + 1 ),
					'enabled'   => true,
					'name'      => (string) ( $r['name'] ?? '' ),
					'icon'      => 0,
					'postcodes' => (string) ( $r['postcodes'] ?? '' ),
					'rule'      => in_array( $r['rule'] ?? '', array( 'subtotal', 'quantity', 'weight' ), true ) ? $r['rule'] : 'subtotal',
					'min'       => max( 0.0, (float) ( $r['min'] ?? 0 ) ),
					'max'       => null === $max || '' === $max ? null : max( 0.0, (float) $max ),
					'fee'       => round( max( 0, (int) ( $r['fee_cents'] ?? 0 ) ) / 100, 2 ),
				);
			}
			$out[] = array(
				'id'       => $first_id + $pi,
				'type'     => 'general',
				'name'     => (string) ( $p['name'] ?? '' ),
				'products' => array(),
				'regions'  => array_values( (array) ( $p['regions'] ?? array() ) ),
				'rates'    => $rates,
			);
		}
		return $out;
	}

	/** 本店的通用运费方案 → 系列运费方案的格式（纯函数），用于导出和比较。只算启用的费用。 */
	public static function plans_to_series( array $plans ): array {
		$out = array();
		foreach ( $plans as $p ) {
			if ( 'general' !== ( $p['type'] ?? '' ) ) {
				continue;
			}
			$rates = array();
			foreach ( (array) ( $p['rates'] ?? array() ) as $r ) {
				if ( empty( $r['enabled'] ) ) {
					continue;
				}
				$row = array(
					'name'      => (string) $r['name'],
					'rule'      => (string) $r['rule'],
					'min'       => (float) $r['min'],
					'max'       => null === $r['max'] ? null : (float) $r['max'],
					'fee_cents' => self::to_cents( $r['fee'] ),
				);
				if ( '' !== (string) ( $r['postcodes'] ?? '' ) ) {
					$row['postcodes'] = (string) $r['postcodes'];
				}
				$rates[] = $row;
			}
			$regions = array();
			foreach ( (array) ( $p['regions'] ?? array() ) as $r ) {
				$regions[] = array( 'country' => (string) $r['country'], 'states' => array_values( (array) ( $r['states'] ?? array() ) ) );
			}
			$out[] = array( 'name' => (string) $p['name'], 'regions' => $regions, 'rates' => $rates );
		}
		return $out;
	}

	/** 用来比较两套运费方案是否等价（忽略名称以外的展示差异）。 */
	private static function shipping_key( array $plans ): string {
		$norm = array();
		foreach ( $plans as $p ) {
			$rates = array();
			foreach ( (array) ( $p['rates'] ?? array() ) as $r ) {
				$rates[] = array( (string) ( $r['name'] ?? '' ), (string) ( $r['rule'] ?? 'subtotal' ), (float) ( $r['min'] ?? 0 ), isset( $r['max'] ) ? (float) $r['max'] : null, (int) ( $r['fee_cents'] ?? 0 ), (string) ( $r['postcodes'] ?? '' ) );
			}
			$norm[] = array( (string) ( $p['name'] ?? '' ), (array) ( $p['regions'] ?? array() ), $rates );
		}
		return (string) json_encode( $norm );
	}

	/**
	 * @return array|\WP_Error dry_run 时返回 { changed }，否则返回写入的方案数
	 */
	private static function apply_shipping( array $shipping, bool $dry ) {
		$plans = array_values( array_filter( (array) ( $shipping['plans'] ?? array() ), 'is_array' ) );
		if ( ! $plans || ! class_exists( Shipping\Plans::class ) ) {
			return new \WP_Error( 'pc_series_shipping', 'The series shipping plans are empty.', array( 'status' => 400 ) );
		}
		$all     = Shipping\Plans::all();
		$changed = self::shipping_key( self::plans_to_series( $all ) ) !== self::shipping_key( $plans );
		if ( $dry ) {
			return array( 'changed' => $changed );
		}
		$keep = array_values( array_filter( $all, static fn( array $p ): bool => 'custom' === $p['type'] ) );
		$next = $keep ? max( array_column( $keep, 'id' ) ) + 1 : 1;
		$new  = array();
		foreach ( self::plans_from_series( $plans, $next ) as $plan ) {
			$plan = Shipping\Plans::normalize( $plan );
			if ( $plan['regions'] && $plan['rates'] ) {
				$new[] = $plan;
			}
		}
		if ( ! $new ) {
			return new \WP_Error( 'pc_series_shipping', 'None of the series shipping plans is valid on this store.', array( 'status' => 400 ) );
		}
		update_option( Shipping\Plans::OPTION, array_merge( $keep, $new ), false );
		$rules = (array) ( $shipping['rules'] ?? array() );
		Shipping\Plans::save_rules( $rules + Shipping\Plans::rules() );
		return array( 'plans' => count( $new ) );
	}

	// ---------- 导出（母版店和快照都用它） ----------

	private static function product_key( \WC_Product $product ): string {
		foreach ( array( (string) $product->get_meta( self::PRODUCT_SKU ), (string) $product->get_meta( '_pc_spu' ), (string) $product->get_sku( 'edit' ) ) as $key ) {
			if ( '' !== trim( $key ) ) {
				return trim( $key );
			}
		}
		return 'slug:' . $product->get_slug( 'edit' );
	}

	private static function export_meta( \WC_Product $product, array $keys ): array {
		$out = array();
		foreach ( $keys as $k ) {
			$v = $product->get_meta( $k );
			if ( '' !== $v && null !== $v && array() !== $v && ( is_scalar( $v ) || is_array( $v ) ) ) {
				$out[ $k ] = $v;
			}
		}
		ksort( $out );
		return $out;
	}

	/** @return array{url:string,sha256:string,name:string,alt:string}|null */
	private static function export_image( int $attachment_id ): ?array {
		$file = get_attached_file( $attachment_id );
		$url  = wp_get_attachment_url( $attachment_id );
		if ( ! $file || ! $url || ! is_readable( $file ) ) {
			return null;
		}
		// 哈希缓存在附件元里（文件修改时间变了才重算）。
		$mtime  = (string) filemtime( $file );
		$cached = explode( '|', (string) get_post_meta( $attachment_id, self::IMAGE_SHA, true ) );
		if ( 2 === count( $cached ) && $cached[1] === $mtime && preg_match( '/^[a-f0-9]{64}$/D', $cached[0] ) ) {
			$sha = $cached[0];
		} else {
			$sha = (string) hash_file( 'sha256', $file );
			update_post_meta( $attachment_id, self::IMAGE_SHA, $sha . '|' . $mtime );
		}
		return array(
			'url'    => class_exists( Design_Sync::class ) ? Design_Sync::public_origin() . (string) wp_parse_url( $url, PHP_URL_PATH ) : $url,
			'sha256' => $sha,
			'name'   => basename( $file ),
			'alt'    => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
		);
	}

	/** @return int[] 款式 ID（含停用的） */
	private static function variation_ids( int $parent_id ): array {
		return array_map(
			'intval',
			(array) wc_get_products(
				array(
					'parent'  => $parent_id,
					'type'    => 'variation',
					'status'  => array( 'publish', 'private' ),
					'limit'   => -1,
					'return'  => 'ids',
					'orderby' => 'menu_order',
					'order'   => 'ASC',
				)
			)
		);
	}

	private static function export_variation( \WC_Product_Variation $v ): array {
		$attrs = self::clean_attr_map( $v->get_attributes( 'edit' ) );
		$sku   = trim( (string) $v->get_sku( 'edit' ) );
		$saved = trim( (string) $v->get_meta( self::PRODUCT_SKU ) );
		$image = (int) $v->get_image_id( 'edit' );
		[ $price, $compare ] = self::series_prices( $v->get_regular_price( 'edit' ), $v->get_sale_price( 'edit' ) );
		return array(
			'key'           => '' !== $saved ? $saved : ( '' !== $sku ? $sku : self::attr_key( $attrs ) ),
			'sku'           => $sku,
			'attributes'    => $attrs,
			'price_cents'   => $price,
			'compare_cents' => $compare,
			'stock_status'  => (string) $v->get_stock_status( 'edit' ),
			'enabled'       => 'publish' === $v->get_status( 'edit' ),
			'image'         => $image ? self::export_image( $image ) : null,
			'meta'          => self::export_meta( $v, self::VARIATION_META ),
		);
	}

	/** 把一个本地商品导出成商品对象。 */
	public static function export_product( \WC_Product $product ): array {
		$variable = $product->is_type( 'variable' );
		$attrs    = array();
		foreach ( (array) $product->get_attributes( 'edit' ) as $a ) {
			if ( ! $a instanceof \WC_Product_Attribute ) {
				continue;
			}
			if ( $a->is_taxonomy() ) {
				$name    = (string) wc_attribute_label( $a->get_name() );
				$options = array_values( array_map( 'strval', (array) wc_get_product_terms( $product->get_id(), $a->get_name(), array( 'fields' => 'names' ) ) ) );
			} else {
				$name    = (string) $a->get_name();
				$options = array_values( array_map( 'strval', (array) $a->get_options() ) );
			}
			$attrs[] = array( 'name' => $name, 'options' => $options, 'visible' => (bool) $a->get_visible(), 'variation' => (bool) $a->get_variation() );
		}
		$images = array();
		foreach ( array_filter( array_merge( array( (int) $product->get_image_id( 'edit' ) ), array_map( 'intval', (array) $product->get_gallery_image_ids( 'edit' ) ) ) ) as $attachment ) {
			$img = self::export_image( (int) $attachment );
			if ( $img ) {
				$images[] = $img;
			}
		}
		$cats = array();
		foreach ( (array) $product->get_category_ids( 'edit' ) as $term_id ) {
			$term = get_term( (int) $term_id, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$cats[] = html_entity_decode( (string) $term->name, ENT_QUOTES );
			}
		}
		$out = array(
			'sku'                => self::product_key( $product ),
			'wc_sku'             => trim( (string) $product->get_sku( 'edit' ) ),
			'type'               => $variable ? 'variable' : 'simple',
			'name'               => (string) $product->get_name( 'edit' ),
			'slug'               => (string) $product->get_slug( 'edit' ),
			'status'             => 'publish' === $product->get_status( 'edit' ) ? 'publish' : 'draft',
			'description'        => (string) $product->get_description( 'edit' ),
			'short_description'  => (string) $product->get_short_description( 'edit' ),
			'categories'         => $cats,
			'menu_order'         => (int) $product->get_menu_order( 'edit' ),
			'attributes'         => $attrs,
			'default_attributes' => self::clean_attr_map( $product->get_default_attributes( 'edit' ) ),
			'images'             => $images,
			'meta'               => self::export_meta( $product, self::PRODUCT_META ),
			'price_cents'        => 0,
			'compare_cents'      => 0,
			'stock_status'       => (string) $product->get_stock_status( 'edit' ),
			'variations'         => array(),
		);
		if ( $variable ) {
			foreach ( self::variation_ids( $product->get_id() ) as $vid ) {
				$v = wc_get_product( $vid );
				if ( $v instanceof \WC_Product_Variation ) {
					$out['variations'][] = self::export_variation( $v );
				}
			}
		} else {
			[ $out['price_cents'], $out['compare_cents'] ] = self::series_prices( $product->get_regular_price( 'edit' ), $product->get_sale_price( 'edit' ) );
		}
		return $out;
	}

	/**
	 * 本店全部商品（已发布和草稿），每个带上本地信息：id、实际状态、series_sku、managed。
	 *
	 * @return array[]
	 */
	public static function export_local(): array {
		$out = array();
		$ids = (array) wc_get_products(
			array(
				'type'    => array( 'simple', 'variable' ),
				'status'  => array( 'publish', 'draft', 'private', 'pending' ),
				'limit'   => -1,
				'return'  => 'ids',
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);
		foreach ( $ids as $id ) {
			$product = wc_get_product( (int) $id );
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			$p = self::clean_product( self::export_product( $product ), false );
			if ( null === $p ) {
				continue; // 连名称都没有的商品不参与比较。
			}
			$p['id']         = (int) $product->get_id();
			$p['series_sku'] = trim( (string) $product->get_meta( self::PRODUCT_SKU ) );
			$p['managed']    = '1' === (string) $product->get_meta( self::PRODUCT_MANAGED );
			$out[]           = $p;
		}
		return $out;
	}

	/** 母版店导出：商品（只要已发布的）、通用运费方案、活动（由营销模块提供）。 */
	public static function export_catalog(): array {
		$products = array();
		foreach ( self::export_local() as $p ) {
			if ( 'publish' === $p['status'] ) {
				unset( $p['id'], $p['series_sku'], $p['managed'] );
				$products[] = $p;
			}
		}
		$origin = class_exists( Design_Sync::class ) ? Design_Sync::public_origin() : home_url( '/' );
		return array(
			'version'     => 1,
			'source_host' => strtolower( (string) wp_parse_url( $origin, PHP_URL_HOST ) ),
			'exported_at' => time(),
			'products'    => $products,
			'shipping'    => array(
				'plans' => class_exists( Shipping\Plans::class ) ? self::plans_to_series( Shipping\Plans::all() ) : array(),
				'rules' => class_exists( Shipping\Plans::class ) ? Shipping\Plans::rules() : array(),
			),
			'campaigns'   => array_values( array_filter( (array) apply_filters( 'pc_series_export_campaigns', array() ), 'is_array' ) ),
		);
	}

	public static function export_route(): array {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		return self::export_catalog();
	}

	// ---------- 写入商品 ----------

	/** 系列商品里指向母版店的绝对链接换成本店地址（描述里的图片、站内链接）。 */
	private static function localize( array $p, string $source_host ): array {
		if ( '' === $source_host || ! class_exists( Design_Sync::class ) ) {
			return $p;
		}
		$target = Design_Sync::public_origin();
		foreach ( array( 'description', 'short_description' ) as $k ) {
			$p[ $k ] = (string) Design_Sync::rewrite_links( $p[ $k ], array( 'https://' . $source_host ), $target );
		}
		return $p;
	}

	/**
	 * @return array{diff:array}|array{counts:array,complete:bool}|\WP_Error
	 */
	private static function apply_catalog( array $catalog, bool $dry, bool $master, float $deadline ) {
		$clean = self::clean_catalog( $catalog );
		if ( ! is_array( $clean ) ) {
			return new \WP_Error( 'pc_series_catalog', 'The series catalog was refused: ' . $clean . '.', array( 'status' => 400 ) );
		}
		$host     = $clean['source_host'];
		$incoming = array_map( static fn( array $p ): array => self::localize( $p, $host ), $clean['products'] );
		$locals   = self::export_local();
		if ( $dry ) {
			return array( 'diff' => self::diff_catalog( $incoming, $locals, $clean['ver'], $master ) );
		}
		$counts   = array( 'created' => 0, 'updated' => 0, 'unpublished' => 0, 'same' => 0, 'errors' => array() );
		$complete = true;
		$match    = self::match_products( $incoming, $locals );
		$used     = array();
		self::$notes   = array();
		self::$writing = true;
		try {
			foreach ( $incoming as $n => $p ) {
				if ( microtime( true ) > $deadline ) {
					$complete = false;
					break;
				}
				$i = $match[ $n ];
				try {
					if ( null === $i ) {
						if ( ! $master ) {
							$complete = self::write_product( 0, $p, $host, $deadline ) && $complete;
							++$counts['created'];
						}
						continue;
					}
					$used[ $i ] = true;
					$local      = $locals[ $i ];
					$fields     = self::compare_product( $local, $p );
					if ( $master ) {
						if ( self::price_fields( $fields ) ) {
							self::write_prices( (int) $local['id'], $p );
							++$counts['updated'];
						} else {
							++$counts['same'];
						}
						continue;
					}
					if ( ! $fields ) {
						if ( ! $local['managed'] || $local['series_sku'] !== $p['sku'] ) {
							update_post_meta( (int) $local['id'], self::PRODUCT_SKU, $p['sku'] );
							update_post_meta( (int) $local['id'], self::PRODUCT_MANAGED, '1' );
						}
						++$counts['same'];
						continue;
					}
					$complete = self::write_product( (int) $local['id'], $p, $host, $deadline ) && $complete;
					++$counts['updated'];
				} catch ( \Throwable $e ) {
					// 一个商品写不进去不拦别的商品：记下原因报给总后台，下一个版本同步时会再试。
					if ( count( $counts['errors'] ) < 10 ) {
						$counts['errors'][] = $p['sku'] . ': ' . $e->getMessage();
					}
				}
			}
			// 全部商品都处理完了，才下架系列里没有的商品（没处理完时不动，避免误下架）。
			if ( $complete && ! $master ) {
				foreach ( $locals as $i => $l ) {
					if ( ! isset( $used[ $i ] ) && 'publish' === $l['status'] ) {
						$product = wc_get_product( (int) $l['id'] );
						if ( $product ) {
							$product->set_status( 'draft' );
							$product->save();
							++$counts['unpublished'];
						}
					}
				}
			}
		} finally {
			self::$writing = false;
		}
		$counts['errors'] = array_slice( array_merge( $counts['errors'], self::$notes ), 0, 10 );
		self::flush_caches();
		return array( 'counts' => $counts, 'complete' => $complete );
	}

	private static function flush_caches(): void {
		if ( class_exists( '\WC_Cache_Helper' ) ) {
			\WC_Cache_Helper::get_transient_version( 'product', true );
		}
		if ( class_exists( Page_Cache::class ) && is_callable( array( Page_Cache::class, 'flush' ) ) ) {
			Page_Cache::flush();
		}
	}

	/** 专辑名称 → 本店的专辑 ID（没有就新建）。 */
	private static function category_ids( array $names ): array {
		$ids = array();
		foreach ( $names as $name ) {
			$term = get_term_by( 'name', $name, 'product_cat' );
			if ( ! $term ) {
				$made = wp_insert_term( $name, 'product_cat' );
				$term = is_wp_error( $made ) ? null : get_term( (int) $made['term_id'], 'product_cat' );
			}
			if ( $term && ! is_wp_error( $term ) ) {
				$ids[] = (int) $term->term_id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * 找到（或下载）一张图片在本店的附件 ID。超过时间预算时返回 0，由下一轮补上。
	 */
	private static function attachment_for( array $img, string $source_host, float $deadline ): int {
		$map = get_option( self::MEDIA_MAP, array() );
		$map = is_array( $map ) ? $map : array();
		$sha = $img['sha256'];
		if ( isset( $map[ $sha ] ) && 'attachment' === get_post_type( (int) $map[ $sha ] ) ) {
			return (int) $map[ $sha ];
		}
		// 复制出来的店铺里通常已经有同名同内容的文件。
		if ( '' !== $img['name'] ) {
			global $wpdb;
			$like = '%' . $wpdb->esc_like( '/' . $img['name'] );
			$ids  = (array) $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND ( meta_value = %s OR meta_value LIKE %s ) LIMIT 20", $img['name'], $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( $ids as $id ) {
				$mine = self::export_image( (int) $id );
				if ( $mine && $mine['sha256'] === $sha ) {
					$map[ $sha ] = (int) $id;
					update_option( self::MEDIA_MAP, $map, false );
					return (int) $id;
				}
			}
		}
		$host = strtolower( (string) wp_parse_url( $img['url'], PHP_URL_HOST ) );
		$bare = static fn( string $h ): string => (string) preg_replace( '/^www\./', '', $h );
		if ( '' === $source_host || 'https' !== wp_parse_url( $img['url'], PHP_URL_SCHEME ) || $bare( $host ) !== $bare( $source_host ) ) {
			throw new \RuntimeException( 'image host not allowed: ' . $host );
		}
		if ( microtime( true ) > $deadline ) {
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$tmp = download_url( $img['url'], 60 );
		if ( is_wp_error( $tmp ) ) {
			throw new \RuntimeException( 'image download failed: ' . $tmp->get_error_message() );
		}
		if ( filesize( $tmp ) > self::MAX_IMAGE_BYTES || ! hash_equals( $sha, (string) hash_file( 'sha256', $tmp ) ) ) {
			wp_delete_file( $tmp );
			throw new \RuntimeException( 'downloaded image does not match the series catalog' );
		}
		$id = media_handle_sideload( array( 'name' => sanitize_file_name( '' !== $img['name'] ? $img['name'] : basename( (string) wp_parse_url( $img['url'], PHP_URL_PATH ) ) ), 'tmp_name' => $tmp ), 0 );
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			throw new \RuntimeException( 'image could not be saved: ' . $id->get_error_message() );
		}
		update_post_meta( (int) $id, '_wp_attachment_image_alt', $img['alt'] );
		$map[ $sha ] = (int) $id;
		update_option( self::MEDIA_MAP, $map, false );
		return (int) $id;
	}

	/**
	 * 图片在本店的附件 ID；0 = 这次拿不到。时间不够时把 $done 置为 false（下一轮接着做）；
	 * 下载失败（来源不对、文件对不上）只记一条原因，不拦商品的其他内容。
	 */
	private static function image_id( array $img, string $source_host, float $deadline, bool &$done, string $sku ): int {
		try {
			$id = self::attachment_for( $img, $source_host, $deadline );
			if ( ! $id ) {
				$done = false;
			}
			return $id;
		} catch ( \Throwable $e ) {
			if ( count( self::$notes ) < 10 ) {
				self::$notes[] = $sku . ': ' . $e->getMessage();
			}
			return 0;
		}
	}

	private static function set_meta( \WC_Product $product, array $meta, array $allowed ): void {
		foreach ( $allowed as $k ) {
			if ( array_key_exists( $k, $meta ) ) {
				$product->update_meta_data( $k, $meta[ $k ] );
			} else {
				$product->delete_meta_data( $k );
			}
		}
	}

	private static function set_prices( \WC_Product $product, int $price_cents, int $compare_cents ): void {
		$prices = self::wc_prices( $price_cents, $compare_cents );
		$product->set_regular_price( $prices['regular'] );
		$product->set_sale_price( $prices['sale'] );
		$product->set_date_on_sale_from( '' );
		$product->set_date_on_sale_to( '' );
	}

	/** SKU 没被别的商品占用时才设置（避免保存时报重复）。 */
	private static function set_sku( \WC_Product $product, string $sku ): void {
		if ( '' === $sku || $sku === (string) $product->get_sku( 'edit' ) ) {
			return;
		}
		$owner = (int) wc_get_product_id_by_sku( $sku );
		if ( 0 === $owner || $owner === (int) $product->get_id() ) {
			$product->set_sku( $sku );
		}
	}

	/** 找系列款式对应的本地款式：按键 → SKU → 属性。 */
	private static function find_variation( array $v, array $locals, array $used ): int {
		foreach ( array( 'key', 'sku', 'attr' ) as $by ) {
			foreach ( $locals as $id => $l ) {
				if ( isset( $used[ $id ] ) ) {
					continue;
				}
				if ( ( 'key' === $by && $l['key'] === $v['key'] ) || ( 'sku' === $by && '' !== $v['sku'] && $l['sku'] === $v['sku'] ) || ( 'attr' === $by && self::attr_key( $l['attributes'] ) === self::attr_key( $v['attributes'] ) ) ) {
					return (int) $id;
				}
			}
		}
		return 0;
	}

	/**
	 * 新建或更新一个商品，并标记为系列管理。返回 false 表示图片没下完（下一轮接着做）。
	 */
	private static function write_product( int $id, array $p, string $source_host, float $deadline ): bool {
		$variable = 'variable' === $p['type'];
		$product  = $id ? wc_get_product( $id ) : null;
		if ( $product && $variable !== $product->is_type( 'variable' ) ) {
			wp_set_object_terms( $id, $variable ? 'variable' : 'simple', 'product_type' );
			$product = $variable ? new \WC_Product_Variable( $id ) : new \WC_Product_Simple( $id );
		}
		if ( ! $product ) {
			$product = $variable ? new \WC_Product_Variable() : new \WC_Product_Simple();
			if ( '' !== $p['slug'] ) {
				$product->set_slug( $p['slug'] );
			}
		}
		$done = true;
		$product->set_name( $p['name'] );
		$product->set_status( $p['status'] );
		$product->set_description( $p['description'] );
		$product->set_short_description( $p['short_description'] );
		$product->set_menu_order( $p['menu_order'] );
		$product->set_category_ids( self::category_ids( $p['categories'] ) );
		self::set_sku( $product, $p['wc_sku'] );

		$attrs = array();
		foreach ( $p['attributes'] as $pos => $a ) {
			$attr = new \WC_Product_Attribute();
			$attr->set_name( $a['name'] );
			$attr->set_options( $a['options'] );
			$attr->set_visible( $a['visible'] );
			$attr->set_variation( $a['variation'] );
			$attr->set_position( (int) $pos );
			$attrs[] = $attr;
		}
		$product->set_attributes( $attrs );
		$product->set_default_attributes( $p['default_attributes'] );

		$image_ids = array();
		$all       = true;
		foreach ( $p['images'] as $img ) {
			$att = self::image_id( $img, $source_host, $deadline, $done, $p['sku'] );
			if ( $att ) {
				$image_ids[] = $att;
			} else {
				$all = false;
			}
		}
		// 有图片这次拿不到时，先保留本店现有的图片，等拿齐了再换。
		if ( $all ) {
			$product->set_image_id( $image_ids[0] ?? 0 );
			$product->set_gallery_image_ids( array_slice( $image_ids, 1 ) );
		}

		self::set_meta( $product, $p['meta'], self::PRODUCT_META );
		$product->update_meta_data( self::PRODUCT_SKU, $p['sku'] );
		$product->update_meta_data( self::PRODUCT_MANAGED, '1' );
		if ( ! $variable ) {
			self::set_prices( $product, $p['price_cents'], $p['compare_cents'] );
			if ( ! $product->get_manage_stock( 'edit' ) ) {
				$product->set_stock_status( $p['stock_status'] );
			}
		}
		$id = (int) $product->save();

		if ( $variable ) {
			$locals = array();
			foreach ( self::variation_ids( $id ) as $vid ) {
				$v = wc_get_product( $vid );
				if ( $v instanceof \WC_Product_Variation ) {
					$locals[ $vid ] = self::export_variation( $v );
				}
			}
			$used = array();
			foreach ( $p['variations'] as $order => $v ) {
				$vid       = self::find_variation( $v, $locals, $used );
				$variation = $vid ? new \WC_Product_Variation( $vid ) : new \WC_Product_Variation();
				if ( $vid ) {
					$used[ $vid ] = true;
				}
				$variation->set_parent_id( $id );
				$variation->set_attributes( $v['attributes'] );
				$variation->set_status( $v['enabled'] ? 'publish' : 'private' );
				$variation->set_menu_order( (int) $order );
				self::set_sku( $variation, $v['sku'] );
				self::set_meta( $variation, $v['meta'], self::VARIATION_META );
				$variation->update_meta_data( self::PRODUCT_SKU, $v['key'] );
				self::set_prices( $variation, $v['price_cents'], $v['compare_cents'] );
				if ( ! $variation->get_manage_stock( 'edit' ) || 'parent' === $variation->get_manage_stock( 'edit' ) ) {
					$variation->set_stock_status( $v['stock_status'] );
				}
				if ( $v['image'] ) {
					$att = self::image_id( $v['image'], $source_host, $deadline, $done, $p['sku'] );
					if ( $att ) {
						$variation->set_image_id( $att );
					}
				} else {
					$variation->set_image_id( 0 );
				}
				$variation->save();
			}
			// 系列里没有的款式停用，不删除。
			foreach ( array_keys( $locals ) as $vid ) {
				if ( ! isset( $used[ $vid ] ) ) {
					$variation = new \WC_Product_Variation( (int) $vid );
					if ( 'private' !== $variation->get_status( 'edit' ) ) {
						$variation->set_status( 'private' );
						$variation->save();
					}
				}
			}
			\WC_Product_Variable::sync( $id );
		}
		wc_delete_product_transients( $id );
		return $done;
	}

	/** 母版店：只写价格（商品和款式），别的都不动。 */
	private static function write_prices( int $id, array $p ): void {
		$product = wc_get_product( $id );
		if ( ! $product ) {
			return;
		}
		if ( 'variable' !== $p['type'] || ! $product->is_type( 'variable' ) ) {
			self::set_prices( $product, $p['price_cents'], $p['compare_cents'] );
			$product->save();
		} else {
			$locals = array();
			foreach ( self::variation_ids( $id ) as $vid ) {
				$v = wc_get_product( $vid );
				if ( $v instanceof \WC_Product_Variation ) {
					$locals[ $vid ] = self::export_variation( $v );
				}
			}
			$used = array();
			foreach ( $p['variations'] as $v ) {
				$vid = self::find_variation( $v, $locals, $used );
				if ( ! $vid ) {
					continue;
				}
				$used[ $vid ] = true;
				if ( $locals[ $vid ]['price_cents'] !== $v['price_cents'] || $locals[ $vid ]['compare_cents'] !== $v['compare_cents'] ) {
					$variation = new \WC_Product_Variation( (int) $vid );
					self::set_prices( $variation, $v['price_cents'], $v['compare_cents'] );
					$variation->save();
				}
			}
			\WC_Product_Variable::sync( $id );
		}
		wc_delete_product_transients( $id );
	}

	// ---------- 系列管理的商品在店铺后台只读 ----------

	/** 系列管理的商品（和它的款式）不允许在店铺后台修改或删除；同步、恢复和命令行脚本不受限制。 */
	public static function lock_managed( $caps, $cap, $user_id, $args ) {
		if ( self::$writing || ( defined( 'WP_CLI' ) && WP_CLI ) || empty( $args[0] ) || ! in_array( $cap, array( 'edit_post', 'delete_post', 'edit_product', 'delete_product' ), true ) ) {
			return $caps;
		}
		$post = get_post( (int) $args[0] );
		if ( ! $post || ! in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
			return $caps;
		}
		$product_id = 'product_variation' === $post->post_type ? (int) $post->post_parent : (int) $post->ID;
		if ( $product_id > 0 && '1' === (string) get_post_meta( $product_id, self::PRODUCT_MANAGED, true ) && self::manages( 'catalog' ) ) {
			$caps[] = 'do_not_allow';
		}
		return $caps;
	}

	public static function managed_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'product', 'edit-product' ), true ) || ! self::manages( 'catalog' ) ) {
			return;
		}
		echo '<div class="notice notice-info"><p>' . esc_html( sprintf( '这家店的商品和价格由系列“%s”统一管理，在这里不能修改。需要调整时请在总后台的“系列管理”里改，会自动同步到同系列的所有店铺。', self::state()['name'] ) ) . '</p></div>';
	}

	// ---------- 快照与恢复 ----------

	/** @return array<int,array{id:int,at:int,from:array,to:array}> 新的在前 */
	public static function snapshots(): array {
		$list = get_option( self::SNAPSHOTS, array() );
		$list = is_array( $list ) ? array_values( array_filter( $list, 'is_array' ) ) : array();
		usort( $list, static fn( array $a, array $b ): int => (int) $b['id'] <=> (int) $a['id'] );
		return $list;
	}

	/** 选哪一份快照（纯函数）：$ver 为 0 取最新；否则先按“同步到的商品版本”找，再按快照编号找。没有时返回 0。 */
	public static function pick_snapshot( array $list, int $ver ): int {
		if ( ! $list ) {
			return 0;
		}
		if ( $ver < 1 ) {
			return (int) max( array_column( $list, 'id' ) );
		}
		foreach ( $list as $s ) {
			if ( (int) ( $s['to']['catalog_ver'] ?? 0 ) === $ver ) {
				return (int) $s['id'];
			}
		}
		foreach ( $list as $s ) {
			if ( (int) $s['id'] === $ver ) {
				return $ver;
			}
		}
		return 0;
	}

	/** 同步前存快照。同一个目标（系列和三个版本号都一样）只存一次；只留最近几份。 */
	private static function take_snapshot( array $before, array $target ): bool {
		$list = self::snapshots();
		foreach ( $list as $s ) {
			if ( ( $s['to'] ?? null ) == $target ) { // phpcs:ignore Universal.Operators.StrictComparisons -- 数组内容相同即可。
				return true;
			}
		}
		$id       = $list ? (int) max( array_column( $list, 'id' ) ) + 1 : 1;
		$snapshot = array(
			'id'       => $id,
			'at'       => time(),
			'state'    => $before,
			'catalog'  => self::export_local(),
			'shipping' => class_exists( Shipping\Plans::class ) ? array( 'plans' => get_option( Shipping\Plans::OPTION, array() ), 'rules' => get_option( Shipping\Plans::RULES, array() ) ) : null,
		);
		$snapshot = (array) apply_filters( 'pc_series_snapshot', $snapshot );
		$json     = wp_json_encode( $snapshot );
		if ( ! is_string( $json ) || '' === $json ) {
			return false;
		}
		$stored = function_exists( 'gzcompress' ) ? 'gz:' . base64_encode( (string) gzcompress( $json, 6 ) ) : 'js:' . $json;
		update_option( self::SNAPSHOT_PREFIX . $id, $stored, false );
		if ( null === self::load_snapshot( $id ) ) {
			delete_option( self::SNAPSHOT_PREFIX . $id );
			return false; // 没存进去（例如数据库拒绝了太大的内容）：不继续同步。
		}
		array_unshift( $list, array( 'id' => $id, 'at' => $snapshot['at'], 'from' => array_intersect_key( $before, $target ), 'to' => $target ) );
		foreach ( array_slice( $list, self::KEEP_SNAPSHOTS ) as $old ) {
			delete_option( self::SNAPSHOT_PREFIX . (int) $old['id'] );
		}
		update_option( self::SNAPSHOTS, array_slice( $list, 0, self::KEEP_SNAPSHOTS ), false );
		return true;
	}

	private static function load_snapshot( int $id ): ?array {
		$stored = (string) get_option( self::SNAPSHOT_PREFIX . $id, '' );
		if ( str_starts_with( $stored, 'gz:' ) && function_exists( 'gzuncompress' ) ) {
			$json = (string) gzuncompress( (string) base64_decode( substr( $stored, 3 ), true ) );
		} elseif ( str_starts_with( $stored, 'js:' ) ) {
			$json = substr( $stored, 3 );
		} else {
			return null;
		}
		$snapshot = json_decode( $json, true );
		return is_array( $snapshot ) && is_array( $snapshot['catalog'] ?? null ) ? $snapshot : null;
	}

	/**
	 * 恢复到某次同步前的样子：商品（内容、价格、状态、是否由系列管理）、运费、系列状态，活动交给营销模块。
	 * 那次同步之后才新建的商品改为草稿。
	 *
	 * @return array|\WP_Error
	 */
	public static function restore( int $ver = 0 ) {
		return self::restore_snapshot( self::pick_snapshot( self::snapshots(), $ver ) );
	}

	/**
	 * 按快照编号恢复。
	 *
	 * @return array|\WP_Error
	 */
	public static function restore_snapshot( int $id ) {
		$snapshot = $id > 0 ? self::load_snapshot( $id ) : null;
		if ( ! $snapshot ) {
			return new \WP_Error( 'pc_series_snapshot', 'No snapshot to restore.', array( 'status' => 404 ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$counts   = array( 'restored' => 0, 'unpublished' => 0, 'missing' => 0, 'failed' => 0 );
		$deadline = microtime( true ) + self::TIME_BUDGET;
		$own_host = strtolower( (string) wp_parse_url( class_exists( Design_Sync::class ) ? Design_Sync::public_origin() : home_url( '/' ), PHP_URL_HOST ) );
		$known    = array();
		self::$writing = true;
		try {
			foreach ( $snapshot['catalog'] as $p ) {
				$pid           = (int) ( $p['id'] ?? 0 );
				$known[ $pid ] = true;
				if ( ! $pid || ! wc_get_product( $pid ) ) {
					++$counts['missing'];
					continue;
				}
				try {
					self::write_product( $pid, $p, $own_host, $deadline );
				} catch ( \Throwable $e ) {
					++$counts['failed'];
					continue;
				}
				if ( empty( $p['managed'] ) ) {
					delete_post_meta( $pid, self::PRODUCT_MANAGED );
				}
				if ( '' === (string) ( $p['series_sku'] ?? '' ) ) {
					delete_post_meta( $pid, self::PRODUCT_SKU );
				} else {
					update_post_meta( $pid, self::PRODUCT_SKU, (string) $p['series_sku'] );
				}
				++$counts['restored'];
			}
			foreach ( self::export_local() as $l ) {
				if ( ! isset( $known[ $l['id'] ] ) && 'publish' === $l['status'] ) {
					$product = wc_get_product( $l['id'] );
					if ( $product ) {
						$product->set_status( 'draft' );
						$product->save();
						++$counts['unpublished'];
					}
				}
			}
		} finally {
			self::$writing = false;
		}
		if ( is_array( $snapshot['shipping'] ?? null ) && class_exists( Shipping\Plans::class ) ) {
			update_option( Shipping\Plans::OPTION, $snapshot['shipping']['plans'], false );
			Shipping\Plans::save_rules( (array) $snapshot['shipping']['rules'] );
		}
		do_action( 'pc_series_restore', $snapshot );
		$state              = self::normalize_state( $snapshot['state'] ?? array() );
		$state['synced_at'] = time();
		update_option( self::OPTION, $state, false );
		self::flush_caches();
		return array( 'snapshot' => $id, 'series' => $state, 'counts' => $counts );
	}

	/**
	 * @return array|\WP_Error
	 */
	public static function restore_route( \WP_REST_Request $request ) {
		$p = (array) $request->get_json_params();
		return self::restore( max( 0, (int) ( $p['ver'] ?? 0 ) ) );
	}

	// ---------- 命令行 ----------

	/**
	 * 显示本店的系列状态和可恢复的快照。
	 */
	public static function cli_status(): void {
		$s = self::state();
		\WP_CLI::log( sprintf( '系列：%s（编号 %d，代号 %s）%s', '' !== $s['name'] ? $s['name'] : '还没收到', $s['series_id'], $s['slug'], $s['master'] ? '，本店是母版店' : '' ) );
		\WP_CLI::log( sprintf( '已同步版本：商品 %d，模板 %d，活动 %d，运费 %d；最近同步 %s', $s['catalog_ver'], $s['template_ver'], $s['campaign_ver'], $s['shipping_ver'], $s['synced_at'] ? wp_date( 'Y-m-d H:i', $s['synced_at'] ) : '—' ) );
		foreach ( self::snapshots() as $snap ) {
			\WP_CLI::log( sprintf( '快照 #%1$d（%2$s）：同步到商品第 %3$d 版之前的样子', $snap['id'], wp_date( 'Y-m-d H:i', (int) $snap['at'] ), (int) ( $snap['to']['catalog_ver'] ?? 0 ) ) );
		}
	}

	/**
	 * 恢复到某次系列同步之前的样子（商品、运费、系列状态）。
	 *
	 * ## OPTIONS
	 *
	 * [<版本>]
	 * : 同步到的商品版本号（恢复到它之前），或快照编号。不填用最近一份快照。
	 *
	 * [--yes]
	 * : 不再确认。
	 */
	public static function cli_restore( $args, $assoc ): void {
		$ver = (int) ( $args[0] ?? 0 );
		$id  = self::pick_snapshot( self::snapshots(), $ver );
		if ( ! $id ) {
			\WP_CLI::error( '没有可以恢复的快照。用 wp peptide series status 看有哪些。' );
		}
		\WP_CLI::confirm( sprintf( '确定把这家店的商品、价格和运费恢复到快照 #%d？恢复后请在总后台关闭这家店的系列同步，否则下一次推送会再次覆盖。', $id ), $assoc );
		$res = self::restore_snapshot( $id );
		if ( is_wp_error( $res ) ) {
			\WP_CLI::error( $res->get_error_message() );
		}
		\WP_CLI::success( sprintf( '已恢复快照 #%1$d：恢复 %2$d 个商品，同步后新建的 %3$d 个改为草稿，%4$d 个已不存在。', $res['snapshot'], $res['counts']['restored'], $res['counts']['unpublished'], $res['counts']['missing'] ) );
	}

	/**
	 * 把本店的商品、运费、活动导出成系列目录文件（和总后台“从母版店导入”拿到的内容一样）。
	 *
	 * ## OPTIONS
	 *
	 * --file=<path>
	 * : 写到哪个文件。
	 */
	public static function cli_export( $args, $assoc ): void {
		$file = (string) ( $assoc['file'] ?? '' );
		$data = self::export_catalog();
		if ( '' === $file || false === file_put_contents( $file, (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			\WP_CLI::error( '写不了文件，请用 --file=<路径> 指定一个可写的位置。' );
		}
		\WP_CLI::success( sprintf( '已导出 %d 个商品到 %s。', count( $data['products'] ), $file ) );
	}

	/**
	 * 把一份系列目录文件和本店现有的商品比一遍，只出差异报告，不改任何东西。
	 *
	 * ## OPTIONS
	 *
	 * --file=<path>
	 * : 母版店导出的目录文件（wp peptide series export 的结果）。
	 *
	 * [--out=<path>]
	 * : 把完整的差异报告（JSON）写到这个文件，可以交给 tools/catalog/series_diff_report.py 生成给人看的报告。
	 */
	public static function cli_diff( $args, $assoc ): void {
		$raw = json_decode( (string) @file_get_contents( (string) ( $assoc['file'] ?? '' ) ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		if ( ! is_array( $raw ) || ! is_array( $raw['products'] ?? null ) ) {
			\WP_CLI::error( '读不了目录文件。' );
		}
		$clean = self::clean_catalog( array( 'ver' => 1, 'source_host' => (string) ( $raw['source_host'] ?? '' ), 'products' => $raw['products'] ) );
		if ( ! is_array( $clean ) ) {
			\WP_CLI::error( '目录文件无效：' . $clean );
		}
		$host     = $clean['source_host'];
		$incoming = array_map( static fn( array $p ): array => self::localize( $p, $host ), $clean['products'] );
		$diff     = self::diff_catalog( $incoming, self::export_local(), 0 );
		$diff['store'] = array( 'name' => get_bloginfo( 'name' ), 'host' => (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		if ( ! empty( $assoc['out'] ) ) {
			file_put_contents( (string) $assoc['out'], (string) wp_json_encode( $diff, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$s = $diff['summary'];
		\WP_CLI::log( sprintf( '一样：%1$d；有差异：%2$d；本店没有、同步后会新建：%3$d；系列里没有、同步后会下架：%4$d', $s['same'], $s['changed'], $s['create'], $s['unpublish'] ) );
		foreach ( array_slice( $diff['changed'], 0, 30 ) as $c ) {
			\WP_CLI::log( sprintf( '  %s（%s）：%s', $c['name'], $c['sku'], implode( '，', array_unique( array_column( $c['fields'], 'field' ) ) ) ) );
		}
		\WP_CLI::success( '只做了比较，没有改任何东西。' );
	}
}
