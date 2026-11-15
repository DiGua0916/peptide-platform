<?php
/**
 * 按系列接收营销活动（HQ-07 店铺端）。接 M3 系列同步（Series_Sync）的四个钩子：
 *
 * - pc_series_apply_campaigns  用总后台下发的活动整体替换本店的系列活动（可试运行，只出差异不写入）；
 * - pc_series_export_campaigns 在母版店导出当前的活动；
 * - pc_series_snapshot         同步前存快照；
 * - pc_series_restore          恢复快照。
 *
 * 每条活动的外形由 M3 定：{ kind, key, active, config }。config 的内容由 M4 定（见 docs/CONTRACT.md 的 M4 一节）：
 *
 *   promotion     满减活动   → 满减活动（pc_promotion）
 *   coupon        优惠码     → WooCommerce 优惠码 + 本插件的规则（叠加策略、开始时间、全局首单、满金额 / 满件数、公开）
 *   ship_discount 运费折扣   → 运费折扣
 *   first_order   首单优惠   → 首单优惠绑定哪张券（一家店只有一个）
 *   exit_intent   离开挽回   → 离开挽回弹窗（发优惠码 / 自定义内容）
 *   wheel         抽奖转盘   → 离开挽回弹窗的转盘类型（和 exit_intent 共用一个弹窗设置，同时只能开一个）
 *   upsell、progress_bar（加购推荐、进度条）这一版不接：它们的设置在 Activity\Store，只能由登录的管理员保存，结果里标 unsupported。
 *
 * 规则：
 * - 商品用系列里的商品键（sku）引用，专辑用名称或别名引用，到店铺换成本店的 ID；找不到的记在结果里。
 * - 写入的仍是各功能现有的存储，每条带系列标记（文章 / 优惠码 meta _pc_series_campaign = "kind/key"，
 *   运费折扣、首单、离开挽回是数组里的 series_key）。带标记的活动在店铺后台对所有人只读。
 * - 全量对齐：带标记但这次没下发的活动改为停用，不删除。本店自己建的活动不动；和本店自建的满减、同码优惠券冲突时
 *   不覆盖本店的，结果里标 conflict。首单优惠和离开挽回一家店只有一份设置：系列下发时接管，停用时还原成本店原来的设置。
 * - 先整体检查：有任何一条的内容不合规（error）就什么都不写，返回错误；skipped / conflict / unsupported 不拦其他活动。
 * - 幂等：内容没变的活动不重复写。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Pricing;

use PeptideCore\Coupon_Rules;
use PeptideCore\Coupons;
use PeptideCore\Exit_Intent;
use PeptideCore\First_Order_Offer;
use PeptideCore\Promotions;
use PeptideCore\Shipping\Discounts;

defined( 'ABSPATH' ) || exit;

final class Series_Campaigns {

	/** 系列标记：文章 / 优惠码 meta，值 = "kind/key"。 */
	public const META      = '_pc_series_campaign';
	/** 上一次写入时内容的指纹（没变就不重复写）。 */
	public const HASH_META = '_pc_series_campaign_hash';
	/** 本店现在接管着的系列活动：{ ver, items, store: { first_order, exit_intent } }。 */
	public const OPTION    = 'pc_series_campaigns';

	public const KINDS       = array( 'promotion', 'coupon', 'ship_discount', 'first_order', 'exit_intent', 'wheel' );
	public const UNSUPPORTED = array( 'upsell', 'progress_bar' );

	/**
	 * 按店开关：值是 on 才接收系列下发的营销活动，默认关（wp-config.php 的常量 PC_SERIES_CAMPAIGNS_MODE 优先）。
	 * 关着时四个钩子都原样放行：系列同步里的活动部分和 M4 上线之前一样是“没有人处理”（unhandled），
	 * 活动版本号不记，店铺后台的营销页面不会因此变成只读，也不向总后台声明 series.campaigns。
	 */
	public const MODE_OPTION = 'pc_series_campaigns_mode';

	/** 首单优惠 / 离开挽回这类“一家店一份设置”的选项，是不是系列下发的（开关关着时一律不算）。 */
	public static function setting_locked( $option ): bool {
		return self::enabled() && is_array( $option ) && '' !== (string) ( $option['series_key'] ?? '' );
	}

	public static function enabled(): bool {
		$mode = defined( 'PC_SERIES_CAMPAIGNS_MODE' ) ? (string) PC_SERIES_CAMPAIGNS_MODE : (string) get_option( self::MODE_OPTION, 'off' );
		return 'on' === $mode;
	}

	public static function register_hooks(): void {
		add_filter( 'pc_series_apply_campaigns', array( self::class, 'apply' ), 10, 4 );
		add_filter( 'pc_series_export_campaigns', array( self::class, 'export' ) );
		add_filter( 'pc_series_snapshot', array( self::class, 'snapshot' ) );
		add_action( 'pc_series_restore', array( self::class, 'restore' ) );
	}

	// ---------- 状态 ----------

	public static function state(): array {
		$s = get_option( self::OPTION, array() );
		$s = is_array( $s ) ? $s : array();
		return array( 'ver' => (int) ( $s['ver'] ?? 0 ), 'items' => array_values( array_filter( (array) ( $s['items'] ?? array() ), 'is_array' ) ), 'store' => (array) ( $s['store'] ?? array() ) );
	}

	/** 系列是否已经接管本店的营销活动（M3 的 Series_Sync 还没合进来时为否）。 */
	public static function managed(): bool {
		return self::enabled() && class_exists( \PeptideCore\Series_Sync::class ) && \PeptideCore\Series_Sync::manages( 'campaign' );
	}

	/** 这条活动是不是系列下发的（店铺后台对所有人只读）。$kind：promotion / coupon / ship_discount。 */
	public static function locked( string $kind, int $id ): bool {
		if ( ! self::enabled() ) {
			return false; // 开关关着：系列标记不起作用，和 M4 上线之前一样谁能改就还能改。
		}
		if ( 'ship_discount' === $kind ) {
			$d = Discounts::get( $id );
			return $d && '' !== (string) ( $d['series_key'] ?? '' );
		}
		return $id > 0 && '' !== (string) get_post_meta( $id, self::META, true );
	}

	// ---------- 引用换算 ----------

	/** 系列商品键 → 本店商品 ID（_pc_series_sku → _pc_spu → SKU → slug:别名）。 */
	private static function product_id( string $sku ): int {
		if ( '' === $sku ) {
			return 0;
		}
		foreach ( array( '_pc_series_sku', '_pc_spu' ) as $meta ) {
			$ids = get_posts( array( 'post_type' => array( 'product', 'product_variation' ), 'post_status' => array( 'publish', 'draft', 'private' ), 'meta_key' => $meta, 'meta_value' => $sku, 'fields' => 'ids', 'posts_per_page' => 1, 'no_found_rows' => true ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
			if ( $ids ) {
				return (int) $ids[0];
			}
		}
		$id = (int) wc_get_product_id_by_sku( $sku );
		if ( $id ) {
			return $id;
		}
		if ( 0 === strpos( $sku, 'slug:' ) ) {
			$post = get_page_by_path( substr( $sku, 5 ), OBJECT, 'product' );
			return $post ? (int) $post->ID : 0;
		}
		return 0;
	}

	/** @return array{0:int[],1:string[]} [本店商品 ID, 找不到的商品键] */
	private static function product_ids( $skus ): array {
		$ids     = array();
		$missing = array();
		foreach ( array_slice( array_values( array_unique( array_map( 'strval', (array) $skus ) ) ), 0, 500 ) as $sku ) {
			$id = self::product_id( trim( $sku ) );
			if ( $id ) {
				$ids[] = $id;
			} else {
				$missing[] = $sku;
			}
		}
		return array( array_values( array_unique( $ids ) ), $missing );
	}

	/** @return array{0:int[],1:string[]} [本店专辑 ID, 找不到的专辑] */
	private static function category_ids( $names ): array {
		$ids     = array();
		$missing = array();
		foreach ( array_slice( array_values( array_unique( array_map( 'strval', (array) $names ) ) ), 0, 200 ) as $name ) {
			$term = get_term_by( 'slug', sanitize_title( $name ), 'product_cat' );
			$term = $term ? $term : get_term_by( 'name', $name, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$ids[] = (int) $term->term_id;
			} else {
				$missing[] = $name;
			}
		}
		return array( array_values( array_unique( $ids ) ), $missing );
	}

	private static function product_key( int $id ): string {
		foreach ( array( '_pc_series_sku', '_pc_spu' ) as $meta ) {
			$v = (string) get_post_meta( $id, $meta, true );
			if ( '' !== $v ) {
				return $v;
			}
		}
		$p   = wc_get_product( $id );
		$sku = $p ? (string) $p->get_sku() : '';
		return '' !== $sku ? $sku : 'slug:' . (string) get_post_field( 'post_name', $id );
	}

	private static function category_names( array $ids ): array {
		$out = array();
		foreach ( $ids as $id ) {
			$term = get_term( (int) $id, 'product_cat' );
			if ( $term instanceof \WP_Term ) {
				$out[] = html_entity_decode( $term->name );
			}
		}
		return $out;
	}

	// ---------- 本店现有的系列活动 ----------

	/** @return array<string,array{kind:string,key:string,id:int,active:bool,hash:string}> "kind/key" => … */
	private static function existing(): array {
		$out = array();
		foreach ( array( 'promotion' => Promotions::CPT, 'coupon' => 'shop_coupon' ) as $kind => $type ) {
			$ids = get_posts( array( 'post_type' => $type, 'post_status' => array( 'publish', 'draft' ), 'meta_key' => self::META, 'fields' => 'ids', 'posts_per_page' => 500, 'no_found_rows' => true ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
			foreach ( $ids as $id ) {
				$mark = (string) get_post_meta( (int) $id, self::META, true );
				if ( 0 === strpos( $mark, $kind . '/' ) ) {
					$out[ $mark ] = array( 'kind' => $kind, 'key' => substr( $mark, strlen( $kind ) + 1 ), 'id' => (int) $id, 'active' => 'publish' === get_post_status( (int) $id ), 'hash' => (string) get_post_meta( (int) $id, self::HASH_META, true ) );
				}
			}
		}
		foreach ( Discounts::all() as $d ) {
			if ( '' !== (string) ( $d['series_key'] ?? '' ) ) {
				$out[ 'ship_discount/' . $d['series_key'] ] = array( 'kind' => 'ship_discount', 'key' => (string) $d['series_key'], 'id' => (int) $d['id'], 'active' => ! empty( $d['enabled'] ), 'hash' => (string) ( $d['series_hash'] ?? '' ) );
			}
		}
		$first = get_option( First_Order_Offer::OPTION, array() );
		if ( is_array( $first ) && '' !== (string) ( $first['series_key'] ?? '' ) ) {
			$out[ 'first_order/' . $first['series_key'] ] = array( 'kind' => 'first_order', 'key' => (string) $first['series_key'], 'id' => 0, 'active' => ! empty( $first['enabled'] ), 'hash' => (string) ( $first['series_hash'] ?? '' ) );
		}
		$exit = get_option( Exit_Intent::OPTION, array() );
		if ( is_array( $exit ) && '' !== (string) ( $exit['series_key'] ?? '' ) ) {
			[ $kind, $key ]             = array_pad( explode( '/', (string) $exit['series_key'], 2 ), 2, '' );
			$out[ $kind . '/' . $key ] = array( 'kind' => $kind, 'key' => $key, 'id' => 0, 'active' => ! empty( $exit['enabled'] ), 'hash' => (string) ( $exit['series_hash'] ?? '' ) );
		}
		return $out;
	}

	// ---------- 逐类检查并准备要写的内容 ----------

	private static function text( $v, int $max ): string {
		$v = is_scalar( $v ) ? trim( sanitize_text_field( (string) $v ) ) : '';
		return function_exists( 'mb_substr' ) ? mb_substr( $v, 0, $max ) : substr( $v, 0, $max );
	}

	/** 满减：和本店自己建的、已启用的满减冲突的名称（规则同 Promotions::conflicts，但不看系列下发的）。 */
	private static function store_promotion_conflicts( array $c ): array {
		$names = array();
		$s1    = (int) $c['start'];
		$e1    = $c['end'] ? (int) $c['end'] : PHP_INT_MAX;
		foreach ( get_posts( array( 'post_type' => Promotions::CPT, 'post_status' => 'publish', 'posts_per_page' => 200, 'fields' => 'ids', 'no_found_rows' => true ) ) as $id ) {
			if ( '' !== (string) get_post_meta( (int) $id, self::META, true ) ) {
				continue;
			}
			$o  = Promotions::config( (int) $id );
			$s2 = (int) $o['start'];
			$e2 = $o['end'] ? (int) $o['end'] : PHP_INT_MAX;
			if ( $s1 >= $e2 || $s2 >= $e1 || $o['scope'] !== $c['scope'] || ! $o['tiers'] ) {
				continue;
			}
			if ( 'all' === $c['scope'] || ( 'products' === $c['scope'] && array_intersect( $c['products'], $o['products'] ) ) || ( 'categories' === $c['scope'] && array_intersect( $c['categories'], $o['categories'] ) ) ) {
				$names[] = html_entity_decode( get_the_title( (int) $id ) );
			}
		}
		return $names;
	}

	/**
	 * 一条活动 → [要写的内容, 状态, 说明, 找不到的引用]。状态：ok / skipped / conflict / error。
	 *
	 * @param array $coupons 这一批里要启用的优惠码：码 => config。
	 */
	private static function prepare( string $kind, string $key, bool $active, array $config, array $coupons, int $existing_id ): array {
		$missing = array();
		switch ( $kind ) {
			case 'promotion':
				[ $products, $m1 ]   = self::product_ids( $config['products'] ?? array() );
				[ $categories, $m2 ] = self::category_ids( $config['categories'] ?? array() );
				$missing             = array_merge( $m1, $m2 );
				$name                = self::text( $config['name'] ?? '', Promotions::NAME_MAX );
				$scope               = in_array( $config['scope'] ?? '', array( 'categories', 'products' ), true ) ? (string) $config['scope'] : 'all';
				if ( ( 'products' === $scope && ! $products ) || ( 'categories' === $scope && ! $categories ) ) {
					return array( null, 'skipped', '活动指定的商品 / 专辑在本店一个都找不到', $missing );
				}
				[ $c, $errors ] = Promotions::clean( array_merge( $config, array( 'scope' => $scope, 'products' => $products, 'categories' => $categories, 'customer_rule' => array( 'customers' => 'all' ) ) ) );
				if ( '' === $name ) {
					$errors[] = '活动名称不能为空';
				}
				if ( $errors ) {
					return array( null, 'error', implode( '；', $errors ), $missing );
				}
				if ( $active ) {
					$clash = self::store_promotion_conflicts( $c );
					if ( $clash ) {
						return array( null, 'conflict', '和本店自己建的满减活动冲突：' . implode( '、', array_slice( $clash, 0, 3 ) ), $missing );
					}
				}
				return array( array( 'name' => $name, 'config' => $c ), 'ok', '', $missing );

			case 'coupon':
				$code = wc_format_coupon_code( self::text( $config['code'] ?? '', 50 ) );
				$type = (string) ( $config['type'] ?? '' );
				$amt  = is_numeric( $config['amount'] ?? null ) ? round( (float) $config['amount'], 2 ) : 0.0;
				if ( '' === $code || ! in_array( $type, array( 'percent', 'fixed_cart', 'fixed_product' ), true ) || $amt <= 0 || ( 'percent' === $type && $amt > 100 ) ) {
					return array( null, 'error', '优惠码需要 code、type（percent / fixed_cart / fixed_product）和大于 0 的 amount（按比例时不超过 100）', $missing );
				}
				$same = (int) wc_get_coupon_id_by_code( $code );
				if ( $same && $same !== $existing_id ) {
					$mark = (string) get_post_meta( $same, self::META, true );
					return '' === $mark
						? array( null, 'conflict', '本店已经有自己建的同码优惠券 ' . strtoupper( $code ), $missing )
						: array( null, 'error', '优惠码 ' . strtoupper( $code ) . ' 已被系列里的另一条活动（' . $mark . '）使用', $missing );
				}
				$data = array( 'code' => $code, 'label' => self::text( $config['code'] ?? '', 50 ), 'type' => $type, 'amount' => $amt, 'config' => $config );
				foreach ( array( 'products' => 'product_ids', 'excluded_products' => 'excluded_product_ids' ) as $from => $to ) {
					[ $data[ $to ], $m ] = self::product_ids( $config[ $from ] ?? array() );
					$missing             = array_merge( $missing, $m );
				}
				foreach ( array( 'categories' => 'product_categories', 'excluded_categories' => 'excluded_product_categories' ) as $from => $to ) {
					[ $data[ $to ], $m ] = self::category_ids( $config[ $from ] ?? array() );
					$missing             = array_merge( $missing, $m );
				}
				if ( ( ! empty( $config['products'] ) && ! $data['product_ids'] ) || ( ! empty( $config['categories'] ) && ! $data['product_categories'] ) ) {
					return array( null, 'skipped', '优惠码限定的商品 / 专辑在本店一个都找不到', $missing );
				}
				return array( $data, 'ok', '', $missing );

			case 'ship_discount':
				[ $d, $errors ] = Discounts::normalize( array_merge( $config, array( 'id' => 0, 'enabled' => $active, 'customers' => 'all', 'emails' => array() ) ) );
				return $errors ? array( null, 'error', implode( '；', $errors ), $missing ) : array( $d, 'ok', '', $missing );

			case 'first_order':
				$code = wc_format_coupon_code( self::text( $config['coupon'] ?? '', 50 ) );
				if ( '' === $code ) {
					return array( null, 'error', '首单优惠需要 coupon（绑定哪张优惠码）', $missing );
				}
				if ( isset( $coupons[ $code ] ) ) {
					$k  = $coupons[ $code ];
					$ok = 'percent' === ( $k['type'] ?? '' ) && 5.0 === (float) ( $k['amount'] ?? 0 ) && ! empty( $k['first_order'] ) && 1 === (int) ( $k['usage_limit_per_user'] ?? 0 ) && empty( $k['individual_use'] ) && 'stack' === ( $k['strategy'] ?? 'stack' ) && empty( $k['free_shipping'] );
				} else {
					$id = (int) wc_get_coupon_id_by_code( $code );
					$ok = $id && '' === First_Order_Offer::coupon_issue( new \WC_Coupon( $id ), $active );
				}
				return $ok ? array( array( 'code' => $code ), 'ok', '', $missing ) : array( null, 'error', '首单优惠绑定的券必须存在，并且是：按比例 5%、全局首单、每人限用 1 次、可叠加、不含免运费', $missing );

			case 'exit_intent':
			case 'wheel':
				$raw            = array_merge( $config, array( 'enabled' => $active, 'image' => 0 ) );
				$raw['type']    = 'wheel' === $kind ? 'wheel' : ( 'message' === ( $config['type'] ?? '' ) ? 'message' : 'coupon' );
				$codes          = 'wheel' === $kind ? array_column( (array) ( $config['prizes'] ?? array() ), 'coupon' ) : array( $config['coupon'] ?? '' );
				$codes          = 'message' === $raw['type'] ? array() : array_filter( array_map( 'wc_format_coupon_code', array_map( 'strval', $codes ) ) );
				// 这一批里一起下发的优惠码此刻还没写进店里：先只检查引用对得上，弹窗设置等优惠码写完后再整体校验。
				foreach ( $codes as $code ) {
					if ( ! isset( $coupons[ $code ] ) && ! Exit_Intent::coupon_ok( $code ) ) {
						return array( null, 'error', '弹窗引用的优惠码 ' . strtoupper( $code ) . ' 不存在或已不能用', $missing );
					}
				}
				if ( 'coupon' === $raw['type'] && ! $codes ) {
					return array( null, 'error', '发优惠码的弹窗需要 coupon', $missing );
				}
				return array( $raw, 'ok', '', $missing );
		}
		return array( null, 'error', '不认识的活动类型', $missing );
	}

	/**
	 * 把下发的活动和本店现状比一遍，得出每条要做什么。
	 *
	 * @return array{rows:array[],errors:int}
	 */
	public static function plan( array $items ): array {
		$existing = self::existing();
		$rows     = array();
		$errors   = 0;
		$coupons  = array();
		$seen     = array();
		foreach ( $items as $item ) {
			if ( is_array( $item ) && 'coupon' === ( $item['kind'] ?? '' ) && ! empty( $item['active'] ) && is_array( $item['config'] ?? null ) ) {
				$coupons[ wc_format_coupon_code( (string) ( $item['config']['code'] ?? '' ) ) ] = $item['config'];
			}
		}
		$popups = 0;
		foreach ( $items as $item ) {
			$kind   = is_array( $item ) ? (string) ( $item['kind'] ?? '' ) : '';
			$key    = is_array( $item ) ? strtolower( (string) ( $item['key'] ?? '' ) ) : '';
			$active = is_array( $item ) && ! empty( $item['active'] );
			$config = is_array( $item ) && is_array( $item['config'] ?? null ) ? $item['config'] : null;
			$row    = array( 'kind' => $kind, 'key' => $key, 'active' => $active, 'action' => 'error', 'message' => '', 'missing' => array(), 'id' => 0, 'data' => null, 'hash' => '' );
			$mark   = $kind . '/' . $key;
			if ( in_array( $kind, self::UNSUPPORTED, true ) ) {
				$row['action']  = 'unsupported';
				$row['message'] = '这一版店铺端还不接这类活动';
			} elseif ( ! in_array( $kind, self::KINDS, true ) || ! preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/D', $key ) || null === $config || isset( $seen[ $mark ] ) ) {
				$row['message'] = isset( $seen[ $mark ] ) ? '同一类型里代号重复' : '活动需要 kind、key（小写字母数字）和 config';
			} else {
				$old                                                             = $existing[ $mark ] ?? null;
				$row['id']                                                       = $old ? $old['id'] : 0;
				[ $row['data'], $status, $row['message'], $row['missing'] ] = self::prepare( $kind, $key, $active, $config, $coupons, $row['id'] );
				$row['hash']                                                     = md5( (string) wp_json_encode( array( $active, $config ) ) );
				if ( 'ok' !== $status ) {
					$row['action'] = $status;
				} elseif ( in_array( $kind, array( 'exit_intent', 'wheel' ), true ) && $active && ++$popups > 1 ) {
					$row['message'] = '离开挽回和抽奖转盘共用一个弹窗，同时只能启用一个';
				} else {
					$row['action'] = ! $old ? 'create' : ( $old['hash'] === $row['hash'] && $old['active'] === $active ? 'unchanged' : 'update' );
				}
			}
			$seen[ $mark ] = true;
			$errors       += 'error' === $row['action'] ? 1 : 0;
			$rows[]        = $row;
		}
		// 全量对齐：带标记但这次没下发（或这次没能接上）的，停用。
		foreach ( $existing as $mark => $old ) {
			$kept = false;
			foreach ( $rows as $row ) {
				$kept = $kept || ( $row['kind'] . '/' . $row['key'] === $mark && in_array( $row['action'], array( 'create', 'update', 'unchanged' ), true ) );
			}
			if ( ! $kept && $old['active'] ) {
				$rows[] = array( 'kind' => $old['kind'], 'key' => $old['key'], 'active' => false, 'action' => 'disable', 'message' => '', 'missing' => array(), 'id' => $old['id'], 'data' => null, 'hash' => '' );
			}
		}
		return array( 'rows' => $rows, 'errors' => $errors );
	}

	/** 给总后台看的结果 / 差异。 */
	private static function report( array $plan, bool $dry ): array {
		$summary = array();
		$items   = array();
		foreach ( $plan['rows'] as $row ) {
			$action             = $dry || ! in_array( $row['action'], array( 'create', 'update' ), true ) ? $row['action'] : 'applied';
			$action             = ! $dry && 'disable' === $action ? 'disabled' : $action;
			$summary[ $action ] = ( $summary[ $action ] ?? 0 ) + 1;
			$out                = array( 'kind' => $row['kind'], 'key' => $row['key'], 'status' => $action );
			if ( '' !== $row['message'] ) {
				$out['message'] = $row['message'];
			}
			if ( $row['missing'] ) {
				$out['missing'] = array_values( array_slice( $row['missing'], 0, 50 ) );
			}
			$items[] = $out;
		}
		return array( 'summary' => $summary, 'items' => $items );
	}

	// ---------- 钩子 ----------

	/**
	 * pc_series_apply_campaigns。成功返回摘要数组；试运行返回差异；内容不合规返回 WP_Error（什么都不写）。
	 *
	 * @param mixed $result 前面的模块已经接手时原样返回。
	 */
	public static function apply( $result, $items = array(), $ver = 0, $dry_run = false ) {
		if ( null !== $result || ! self::enabled() ) {
			return $result; // 关着：原样放行，系列同步按“没有人处理”记。
		}
		$items = array_values( array_filter( (array) $items, 'is_array' ) );
		$plan  = self::plan( $items );
		if ( $dry_run ) {
			return self::report( $plan, true );
		}
		if ( $plan['errors'] ) {
			return new \WP_Error( 'pc_series_campaigns_invalid', '有活动的内容不合规，这次一条都没有写入。', array( 'status' => 422, 'report' => self::report( $plan, true ) ) );
		}
		$failed = self::write( $plan, $items, (int) $ver );
		if ( $failed ) {
			return new \WP_Error( 'pc_series_campaigns_write', '写入活动时出错：' . implode( '；', $failed ), array( 'status' => 500, 'report' => self::report( $plan, false ) ) );
		}
		return self::report( $plan, false );
	}

	/** 按计划写入。返回出错说明（正常为空）。 */
	private static function write( array $plan, array $items, int $ver ): array {
		$failed = array();
		$state  = self::state();
		$order  = array( 'coupon' => 0, 'promotion' => 1, 'ship_discount' => 2, 'first_order' => 3, 'exit_intent' => 4, 'wheel' => 4 );
		$rows   = $plan['rows'];
		usort( $rows, static fn( $a, $b ) => ( $order[ $a['kind'] ] ?? 9 ) <=> ( $order[ $b['kind'] ] ?? 9 ) );
		foreach ( $rows as $row ) {
			$mark = $row['kind'] . '/' . $row['key'];
			try {
				if ( 'disable' === $row['action'] ) {
					self::disable( $row, $state );
				} elseif ( in_array( $row['action'], array( 'create', 'update' ), true ) ) {
					self::save_row( $row, $mark, $state );
				}
			} catch ( \Throwable $e ) {
				$failed[] = $mark . '：' . $e->getMessage();
			}
		}
		$state['ver']   = $ver;
		$state['items'] = $items;
		update_option( self::OPTION, $state, false );
		return $failed;
	}

	private static function save_row( array $row, string $mark, array &$state ): void {
		$data = $row['data'];
		switch ( $row['kind'] ) {
			case 'promotion':
				$post = array( 'post_type' => Promotions::CPT, 'post_title' => $data['name'], 'post_status' => $row['active'] ? 'publish' : 'draft' );
				if ( $row['id'] ) {
					$post['ID'] = $row['id'];
				}
				$id = $row['id'] ? wp_update_post( $post, true ) : wp_insert_post( $post, true );
				if ( is_wp_error( $id ) ) {
					throw new \RuntimeException( $id->get_error_message() );
				}
				update_post_meta( (int) $id, Promotions::META, $data['config'] );
				update_post_meta( (int) $id, self::META, $mark );
				update_post_meta( (int) $id, self::HASH_META, $row['hash'] );
				if ( $row['active'] && $data['config']['public'] ) {
					Promotions::ensure_page();
				}
				return;

			case 'coupon':
				$c      = $data['config'];
				$coupon = new \WC_Coupon( $row['id'] );
				$coupon->set_code( $data['code'] );
				$coupon->set_description( self::text( $c['description'] ?? '', 200 ) );
				$coupon->set_discount_type( $data['type'] );
				$coupon->set_amount( $data['amount'] );
				$coupon->set_date_expires( ! empty( $c['end'] ) ? (int) $c['end'] : null );
				$coupon->set_usage_limit( max( 0, (int) ( $c['usage_limit'] ?? 0 ) ) );
				$coupon->set_usage_limit_per_user( max( 0, (int) ( $c['usage_limit_per_user'] ?? 0 ) ) );
				$coupon->set_limit_usage_to_x_items( isset( $c['limit_usage_to_x_items'] ) && (int) $c['limit_usage_to_x_items'] > 0 ? (int) $c['limit_usage_to_x_items'] : null );
				$coupon->set_individual_use( ! empty( $c['individual_use'] ) );
				$coupon->set_free_shipping( ! empty( $c['free_shipping'] ) );
				$coupon->set_exclude_sale_items( ! empty( $c['exclude_sale_items'] ) );
				$coupon->set_minimum_amount( is_numeric( $c['minimum_amount'] ?? null ) && (float) $c['minimum_amount'] > 0 ? (float) $c['minimum_amount'] : '' );
				$coupon->set_maximum_amount( is_numeric( $c['maximum_amount'] ?? null ) && (float) $c['maximum_amount'] > 0 ? (float) $c['maximum_amount'] : '' );
				$coupon->set_product_ids( $data['product_ids'] );
				$coupon->set_excluded_product_ids( $data['excluded_product_ids'] );
				$coupon->set_product_categories( $data['product_categories'] );
				$coupon->set_excluded_product_categories( $data['excluded_product_categories'] );
				$coupon->set_email_restrictions( array() );
				$strategy = in_array( $c['strategy'] ?? '', Coupon_Rules::STRATEGIES, true ) ? (string) $c['strategy'] : 'stack';
				foreach ( array(
					Coupon_Rules::STRATEGY_META  => $strategy,
					Coupon_Rules::START_META     => max( 0, (int) ( $c['start'] ?? 0 ) ),
					Coupon_Rules::FIRST_META     => ! empty( $c['first_order'] ) ? 'yes' : 'no',
					Coupon_Rules::MIN_SPEND_META => is_numeric( $c['min_spend'] ?? null ) ? max( 0, round( (float) $c['min_spend'], 2 ) ) : 0,
					Coupon_Rules::MIN_QTY_META   => max( 0, (int) ( $c['min_qty'] ?? 0 ) ),
					Coupon_Rules::LABEL_META     => $data['label'],
					Coupons::PUBLIC_META         => ! empty( $c['public'] ) ? 'yes' : 'no',
					Coupons::TITLE_META          => self::text( $c['public_title'] ?? '', 80 ),
					Coupons::TEXT_META           => self::text( $c['public_text'] ?? '', 300 ),
					self::META                   => $mark,
					self::HASH_META              => $row['hash'],
				) as $meta => $value ) {
					$coupon->update_meta_data( $meta, $value );
				}
				$id = (int) $coupon->save();
				if ( ! $id ) {
					throw new \RuntimeException( '优惠码保存失败' );
				}
				$want = $row['active'] ? 'publish' : 'draft';
				if ( $want !== get_post_status( $id ) ) {
					wp_update_post( array( 'ID' => $id, 'post_status' => $want ) );
				}
				if ( $row['active'] && ! empty( $c['public'] ) ) {
					Coupons::ensure_page();
				}
				return;

			case 'ship_discount':
				$data['id']          = $row['id'];
				$data['enabled']     = $row['active'];
				$data['series_key']  = $row['key'];
				$data['series_hash'] = $row['hash'];
				Discounts::save( $data );
				return;

			case 'first_order':
				$now = get_option( First_Order_Offer::OPTION, array() );
				if ( is_array( $now ) && '' === (string) ( $now['series_key'] ?? '' ) && ! array_key_exists( 'first_order', $state['store'] ) ) {
					$state['store']['first_order'] = $now; // 接管前本店自己的设置，停用时还原。
				}
				$id = (int) wc_get_coupon_id_by_code( $data['code'] );
				if ( ! $id || '' !== First_Order_Offer::coupon_issue( new \WC_Coupon( $id ), $row['active'] ) ) {
					throw new \RuntimeException( '首单优惠绑定的券 ' . strtoupper( $data['code'] ) . ' 在本店不符合首单券的规则' );
				}
				update_option( First_Order_Offer::OPTION, array( 'enabled' => $row['active'], 'coupon_id' => $id, 'series_key' => $row['key'], 'series_hash' => $row['hash'] ), false );
				return;

			case 'exit_intent':
			case 'wheel':
				$now = get_option( Exit_Intent::OPTION, array() );
				if ( is_array( $now ) && '' === (string) ( $now['series_key'] ?? '' ) && ! array_key_exists( 'exit_intent', $state['store'] ) ) {
					$state['store']['exit_intent'] = $now;
				}
				[ $s, $errors ] = Exit_Intent::clean( $data );
				if ( $errors ) {
					throw new \RuntimeException( implode( '；', $errors ) );
				}
				$s['series_key']  = $mark;
				$s['series_hash'] = $row['hash'];
				update_option( Exit_Intent::OPTION, $s, false );
				return;
		}
	}

	private static function disable( array $row, array &$state ): void {
		switch ( $row['kind'] ) {
			case 'promotion':
			case 'coupon':
				wp_update_post( array( 'ID' => $row['id'], 'post_status' => 'draft' ) );
				delete_post_meta( $row['id'], self::HASH_META );
				return;
			case 'ship_discount':
				Discounts::set_enabled( $row['id'], false );
				return;
			case 'first_order':
				// 一家店只有一份首单设置：还原成接管前本店自己的；没有就关掉。
				$old = $state['store']['first_order'] ?? null;
				update_option( First_Order_Offer::OPTION, is_array( $old ) ? $old : First_Order_Offer::defaults(), false );
				unset( $state['store']['first_order'] );
				return;
			case 'exit_intent':
			case 'wheel':
				$old = $state['store']['exit_intent'] ?? null;
				if ( is_array( $old ) ) {
					update_option( Exit_Intent::OPTION, $old, false );
				} else {
					$now            = (array) get_option( Exit_Intent::OPTION, array() );
					$now['enabled'] = false;
					unset( $now['series_key'], $now['series_hash'] );
					update_option( Exit_Intent::OPTION, $now, false );
				}
				unset( $state['store']['exit_intent'] );
				return;
		}
	}

	/** pc_series_export_campaigns：母版店现在的活动（已结束的不导出），外形同下发。 */
	public static function export( $items = array() ): array {
		$items = is_array( $items ) ? $items : array();
		if ( ! self::enabled() ) {
			return $items;
		}
		$now   = time();
		foreach ( get_posts( array( 'post_type' => Promotions::CPT, 'post_status' => array( 'publish', 'draft' ), 'posts_per_page' => 200, 'fields' => 'ids', 'no_found_rows' => true, 'orderby' => 'ID', 'order' => 'ASC' ) ) as $id ) {
			$c = Promotions::config( (int) $id );
			if ( ! $c['tiers'] || ( $c['end'] && $now >= $c['end'] ) ) {
				continue;
			}
			$mark    = (string) get_post_meta( (int) $id, self::META, true );
			$items[] = array(
				'kind'   => 'promotion',
				'key'    => '' !== $mark ? substr( $mark, 10 ) : 'p' . (int) $id,
				'active' => 'publish' === get_post_status( (int) $id ),
				'config' => array( 'name' => html_entity_decode( get_the_title( (int) $id ) ), 'type' => $c['type'], 'tiers' => $c['tiers'], 'unlimited' => $c['unlimited'], 'start' => $c['start'], 'end' => $c['end'], 'scope' => $c['scope'], 'categories' => self::category_names( $c['categories'] ), 'products' => array_map( array( self::class, 'product_key' ), $c['products'] ), 'sort' => $c['sort'], 'exclude_sale' => $c['exclude_sale'], 'with_coupons' => $c['with_coupons'], 'public' => $c['public'], 'text' => $c['text'] ),
			);
		}
		$codes = array();
		foreach ( get_posts( array( 'post_type' => 'shop_coupon', 'post_status' => array( 'publish', 'draft' ), 'posts_per_page' => 300, 'fields' => 'ids', 'no_found_rows' => true, 'orderby' => 'ID', 'order' => 'ASC' ) ) as $id ) {
			$k       = new \WC_Coupon( (int) $id );
			$expires = $k->get_date_expires();
			// 顾客用积分换的个人券、限定邮箱的券、已过期的券不属于系列活动。
			if ( $k->get_meta( '_pc_loyalty_user' ) || $k->get_email_restrictions() || ( $expires && $expires->getTimestamp() <= $now ) ) {
				continue;
			}
			$mark                    = (string) get_post_meta( (int) $id, self::META, true );
			$key                     = '' !== $mark ? substr( $mark, 7 ) : ( preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/D', $k->get_code() ) ? $k->get_code() : 'c' . (int) $id );
			$codes[ $k->get_code() ] = true;
			$items[]                 = array(
				'kind'   => 'coupon',
				'key'    => $key,
				'active' => 'publish' === get_post_status( (int) $id ),
				'config' => array(
					'code'                   => Coupon_Rules::display_code( $k ),
					'description'            => (string) $k->get_description(),
					'type'                   => $k->get_discount_type(),
					'amount'                 => (float) $k->get_amount(),
					'start'                  => Coupon_Rules::start( $k ),
					'end'                    => $expires ? (int) $expires->getTimestamp() : 0,
					'usage_limit'            => (int) $k->get_usage_limit(),
					'usage_limit_per_user'   => (int) $k->get_usage_limit_per_user(),
					'limit_usage_to_x_items' => $k->get_limit_usage_to_x_items(),
					'individual_use'         => (bool) $k->get_individual_use(),
					'free_shipping'          => (bool) $k->get_free_shipping(),
					'exclude_sale_items'     => (bool) $k->get_exclude_sale_items(),
					'minimum_amount'         => (float) $k->get_minimum_amount(),
					'maximum_amount'         => (float) $k->get_maximum_amount(),
					'products'               => array_map( array( self::class, 'product_key' ), (array) $k->get_product_ids() ),
					'excluded_products'      => array_map( array( self::class, 'product_key' ), (array) $k->get_excluded_product_ids() ),
					'categories'             => self::category_names( (array) $k->get_product_categories() ),
					'excluded_categories'    => self::category_names( (array) $k->get_excluded_product_categories() ),
					'strategy'               => Coupon_Rules::strategy( $k ),
					'first_order'            => 'yes' === get_post_meta( (int) $id, Coupon_Rules::FIRST_META, true ),
					'min_spend'              => (float) get_post_meta( (int) $id, Coupon_Rules::MIN_SPEND_META, true ),
					'min_qty'                => (int) get_post_meta( (int) $id, Coupon_Rules::MIN_QTY_META, true ),
					'public'                 => 'yes' === get_post_meta( (int) $id, Coupons::PUBLIC_META, true ),
					'public_title'           => (string) get_post_meta( (int) $id, Coupons::TITLE_META, true ),
					'public_text'            => (string) get_post_meta( (int) $id, Coupons::TEXT_META, true ),
				),
			);
		}
		foreach ( array_reverse( Discounts::all() ) as $d ) {
			if ( 'ended' === Discounts::status( $d, $now ) || 'all' !== ( $d['customers'] ?? 'all' ) ) {
				continue; // 已结束的、指定顾客的不导出。
			}
			$items[] = array( 'kind' => 'ship_discount', 'key' => '' !== (string) ( $d['series_key'] ?? '' ) ? (string) $d['series_key'] : 's' . (int) $d['id'], 'active' => ! empty( $d['enabled'] ), 'config' => array_intersect_key( $d, array_flip( array( 'name', 'mode', 'kind', 'value', 'start', 'end', 'stages', 'member_day', 'member_days' ) ) ) );
		}
		$first = First_Order_Offer::settings();
		if ( $first['coupon_id'] ) {
			$k = new \WC_Coupon( $first['coupon_id'] );
			if ( $k->get_id() && isset( $codes[ $k->get_code() ] ) ) {
				$items[] = array( 'kind' => 'first_order', 'key' => 'main', 'active' => (bool) $first['enabled'], 'config' => array( 'coupon' => Coupon_Rules::display_code( $k ) ) );
			}
		}
		$exit = Exit_Intent::settings();
		if ( $exit['enabled'] || '' !== (string) $exit['coupon'] || $exit['prizes'] ) {
			$kind    = 'wheel' === $exit['type'] ? 'wheel' : 'exit_intent';
			$items[] = array( 'kind' => $kind, 'key' => 'main', 'active' => (bool) $exit['enabled'], 'config' => array_diff_key( $exit, array_flip( array( 'enabled', 'image', 'series_key', 'series_hash' ) ) ) );
		}
		return $items;
	}

	/** pc_series_snapshot：记下同步前本店接管着的系列活动（恢复时按这份重新对齐）。 */
	public static function snapshot( $snapshot = array() ): array {
		$snapshot              = is_array( $snapshot ) ? $snapshot : array();
		if ( ! self::enabled() ) {
			return $snapshot;
		}
		$snapshot['campaigns'] = self::state();
		return $snapshot;
	}

	/** pc_series_restore：回到快照时的系列活动——那之后新下发的停用，当时在跑的按当时的内容恢复。 */
	public static function restore( $snapshot = array() ): void {
		$saved = is_array( $snapshot ) && is_array( $snapshot['campaigns'] ?? null ) ? $snapshot['campaigns'] : null;
		if ( null === $saved || ! self::enabled() ) {
			return; // 快照里没有活动（比如是这个功能上线前存的）：不动。
		}
		$items = array_values( array_filter( (array) ( $saved['items'] ?? array() ), 'is_array' ) );
		$plan  = self::plan( $items );
		// 恢复时个别条目已经对不上（商品被删等）就跳过它，其余照常恢复。
		foreach ( $plan['rows'] as $i => $row ) {
			if ( 'error' === $row['action'] ) {
				$plan['rows'][ $i ]['action'] = 'skipped';
			}
		}
		self::write( $plan, $items, (int) ( $saved['ver'] ?? 0 ) );
	}
}
