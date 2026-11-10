<?php
/**
 * 系列同步的店铺端（HQ-05、NF-12）。系列定义在总后台，店铺只记自己属于哪个系列和已同步到的版本号。
 *
 * - 店铺选项 peptide_core_series：series_id、slug、name、catalog_ver、template_ver、campaign_ver、shipping_ver、synced_at。
 * - 新订单写入订单元 _pc_series_id（下单时店铺所属的系列）。
 * - 总后台调用 POST /peptide/v1/hq/series/sync（签名校验用 Hq_Connector::verify）：
 *   每次都带系列基本信息；catalog（商品）、campaigns（活动）、shipping（运费）三部分各带版本号，
 *   版本号不大于本地的直接跳过，所以重复调用结果不变。dry_run 时只返回差异，不改任何东西。
 * - 活动的内容由营销模块处理：本类只把 campaigns 交给过滤器 pc_series_apply_campaigns，没人处理就不记版本。
 * - GET /peptide/v1/hq/series/state：返回本店的系列状态。
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

	/** 同步进行中的标记（防止两次同步同时改商品）。 */
	private const LOCK = 'pc_series_sync_lock';

	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_action( 'woocommerce_before_order_object_save', array( self::class, 'stamp_order' ), 5, 1 );
		add_filter( 'pc_hq_capabilities', array( self::class, 'capabilities' ) );
	}

	// ---------- 本店的系列状态 ----------

	/** @return array{series_id:int,slug:string,name:string,catalog_ver:int,template_ver:int,campaign_ver:int,shipping_ver:int,synced_at:int} */
	public static function normalize_state( $s ): array {
		$s = is_array( $s ) ? $s : array();
		return array(
			'series_id'    => max( 0, (int) ( $s['series_id'] ?? 0 ) ),
			'slug'         => (string) ( $s['slug'] ?? '' ),
			'name'         => (string) ( $s['name'] ?? '' ),
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

	/** 某一部分（catalog / campaign / shipping）是否已经由系列接管：接管后店铺后台不应再提供修改入口。 */
	public static function manages( string $section ): bool {
		$s = self::state();
		return $s['series_id'] > 0 && in_array( $section, array( 'catalog', 'campaign', 'shipping' ), true ) && $s[ $section . '_ver' ] > 0;
	}

	/** 向总后台声明本店支持的功能（功能声明钩子由发布模块提供）。 */
	public static function capabilities( $caps ): array {
		$caps = is_array( $caps ) ? $caps : array();
		foreach ( array( 'series_sync', 'series_catalog', 'series_shipping' ) as $cap ) {
			if ( ! in_array( $cap, $caps, true ) ) {
				$caps[] = $cap;
			}
		}
		return $caps;
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
	}

	public static function state_route(): array {
		return array( 'series' => self::state() );
	}

	/**
	 * 校验请求里的系列基本信息（纯函数）。无效时返回 null。
	 *
	 * @return array{series_id:int,slug:string,name:string,template_ver:int}|null
	 */
	public static function clean_header( array $p ): ?array {
		$id   = (int) ( $p['series_id'] ?? 0 );
		$slug = (string) ( $p['slug'] ?? '' );
		if ( $id < 1 || ! preg_match( '/^[a-z0-9][a-z0-9-]{0,63}$/D', $slug ) ) {
			return null;
		}
		return array(
			'series_id'    => $id,
			'slug'         => $slug,
			'name'         => mb_substr( trim( wp_strip_all_tags( (string) ( $p['name'] ?? '' ) ) ), 0, 190 ),
			'template_ver' => max( 0, (int) ( $p['template_ver'] ?? 0 ) ),
		);
	}

	/**
	 * 把收到的系列基本信息并进本店状态（纯函数）。换了系列时三部分内容的版本号归零，等新系列重新同步。
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
		$state    = self::merge_header( self::state(), $head );
		$applied  = array( 'catalog' => null, 'campaigns' => null, 'shipping' => null );
		$diff     = array();
		$complete = true;

		// 活动：内容由营销模块处理。返回 null 表示没有模块接手，这时不记版本号，下次还会再发。
		if ( isset( $p['campaigns'] ) ) {
			if ( ! self::section_due( $p['campaigns'], $state['campaign_ver'] ) ) {
				$applied['campaigns'] = 'skipped';
			} else {
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
		}

		if ( $dry ) {
			return array(
				'series'   => self::state(),
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
}
