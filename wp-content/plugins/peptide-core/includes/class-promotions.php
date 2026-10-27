<?php
/**
 * 满减活动（PROMO-01，2026-10-23 按 Shopname 的“营销活动 → 满减活动”改版为 PROMO-02）。
 *
 * 后台：营销活动 → 满减活动（Admin\Promotions_Page）。每个活动：
 * - 优惠类型：满额减元、满额打折、满件减元、满件打折、第 X 件 Y% off、满件一口价、满 X 免 Y，最多 5 个层级；
 *   “上不封顶”时只有一个层级，优惠按门槛重复计算（每满 $100 减 $10，每买 3 件免 1 件……）。
 * - 时间：时间段（开始 / 结束，店铺时区）或长期；
 * - 适用范围：全部商品 / 部分商品 / 专辑（商品分类）；活动页面里商品的默认排序。
 * 发布 = 启用，草稿 = 停用。活动名称就是给客户看的标题（例如 “Black Friday Extravaganza”）。
 *
 * Shopname 的使用规则：
 * - 同一时间同一商品 / 专辑只能有一个满减活动，同一时间只能有一个全部商品的活动（保存和启用时检查，见 conflicts()）；
 * - 一个商品下单时只参加一个满减活动，优先级：指定商品 > 指定专辑 > 全部商品；没达到这个活动的门槛就不优惠，
 *   不会改用别的活动。商品在多个专辑里时用创建时间最近的专辑活动。不同商品可以分别参加不同活动，优惠相加。
 * - 优惠码的“优惠规则策略”（不叠加 / 叠加 / 替换满减活动）见 Coupon_Rules。旧数据里“不能和优惠码同时使用”的活动，
 *   购物车里有优惠码时仍然不生效。
 *
 * 购物车：每次计算总价时，每个生效的活动加一笔负数费用（不计税），订单里这笔费用记着活动 ID。
 *
 * 前台：
 * - 活动页面（短代码 [pc_promotions]，第一次启用活动时自动建好），?promo=<ID> 显示详情和参加活动的商品（按活动设置的排序）；
 *   主题可以用 template-parts/promotions/list.php、detail.php 覆盖；
 * - 购物车进度（“再买 $20 立减 $10”）：peptide_core_get_promotion_progress()，
 *   区块购物车 / 抽屉在 Store API 购物车的 extensions.peptide_core.promotion 里也能读到（Api\Store_Api 注册）；
 * - 商品参加的活动：peptide_core_get_product_promotions( $product )。
 *
 * 时间必须是真实的活动时间：到期自动结束，不做假倒计时。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Promotions {

	public const CPT         = 'pc_promotion';
	public const META        = '_pc_promo';
	public const FEE_META    = '_pc_promotion_id';
	public const PAGE_OPTION = 'peptide_core_promotions_page';
	public const SHORTCODE   = 'pc_promotions';
	public const ID_ARG      = 'promo';
	public const MAX_TIERS   = 5;
	public const NAME_MAX    = 50;

	/** 优惠类型。 */
	public const TYPES = array( 'spend_amount', 'spend_percent', 'qty_amount', 'qty_percent', 'nth_percent', 'bundle_price', 'buy_x_free_y' );

	/** 可以“上不封顶”（按门槛重复计算）的类型。 */
	public const REPEATABLE = array( 'spend_amount', 'qty_amount', 'nth_percent', 'bundle_price', 'buy_x_free_y' );

	/** 活动页面里商品的排序方式 => [orderby, order]（WooCommerce [products] 短代码的参数）。 */
	public const SORTS = array(
		'date_desc'  => array( 'date', 'DESC' ),
		'date_asc'   => array( 'date', 'ASC' ),
		'price_asc'  => array( 'price', 'ASC' ),
		'price_desc' => array( 'price', 'DESC' ),
		'sales_desc' => array( 'popularity', 'DESC' ),
		'title_asc'  => array( 'title', 'ASC' ),
	);

	/** 本次计算优惠最多的活动（兼容旧代码）。 */
	private static ?array $applied = null;

	/** 活动页面正在列出的活动（woocommerce_shortcode_products_query 用来排除别的活动的商品）。 */
	private static ?array $listing = null;

	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'register_post_type' ) );
		add_shortcode( self::SHORTCODE, array( self::class, 'shortcode' ) );
		add_action( 'woocommerce_cart_calculate_fees', array( self::class, 'apply_to_cart' ), 20 );
		add_action( 'woocommerce_checkout_create_order_fee_item', array( self::class, 'tag_fee_item' ), 10, 4 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_filter( 'woocommerce_shortcode_products_query', array( self::class, 'listing_query' ), 10, 2 );
	}

	public static function register_post_type(): void {
		register_post_type(
			self::CPT,
			array(
				'labels'          => array(
					'name'          => __( 'Spend-and-save promotions', 'peptide-core' ),
					'singular_name' => __( 'Promotion', 'peptide-core' ),
				),
				'public'          => false,
				// 后台用自己的页面（营销活动 → 满减活动），不用 WordPress 的文章编辑页。
				'show_ui'         => false,
				'show_in_rest'    => false,
				'supports'        => array( 'title' ),
				// 用优惠券的权限：能管理优惠券的人（店长、管理员）就能管理满减活动。
				'capability_type' => 'shop_coupon',
				'map_meta_cap'    => true,
			)
		);
	}

	// ---------- 设置 ----------

	public static function defaults(): array {
		return array(
			'type'         => '',          // 见 TYPES；旧数据为空时按 basis / reward 推出来
			'basis'        => 'spend',     // spend：满金额；quantity：按件数（由 type 决定）
			'reward'       => 'amount',    // amount：减金额 / 一口价 / 免件；percent：打折（由 type 决定）
			'tiers'        => array(),     // [{min, value}]，按 min 从小到大
			'unlimited'    => false,       // 上不封顶
			'start'        => 0,           // UTC 时间戳，0 = 不限
			'end'          => 0,
			'scope'        => 'all',       // all / categories（专辑）/ products
			'categories'   => array(),
			'products'     => array(),
			'sort'         => 'date_desc',
			'exclude_sale' => false,
			'with_coupons' => true,
			'public'       => false,
			'text'         => '',
		);
	}

	public static function config( int $id ): array {
		$c = get_post_meta( $id, self::META, true );
		$c = array_merge( self::defaults(), is_array( $c ) ? $c : array() );
		if ( ! in_array( $c['type'], self::TYPES, true ) ) {
			$c['type'] = self::legacy_type( (string) $c['basis'], (string) $c['reward'] );
		}
		return $c;
	}

	private static function legacy_type( string $basis, string $reward ): string {
		return ( 'quantity' === $basis ? 'qty' : 'spend' ) . '_' . ( 'percent' === $reward ? 'percent' : 'amount' );
	}

	/** 门槛是件数（除了满额减元、满额打折）。 */
	public static function by_quantity( array $c ): bool {
		return ! in_array( $c['type'] ?? '', array( 'spend_amount', 'spend_percent' ), true );
	}

	/**
	 * 清理设置。返回 [config, errors]。
	 *
	 * @return array{0:array,1:string[]}
	 */
	public static function clean( array $raw ): array {
		$errors = array();
		$c      = self::defaults();
		$type   = (string) ( $raw['type'] ?? '' );
		if ( ! in_array( $type, self::TYPES, true ) ) {
			$type = self::legacy_type( (string) ( $raw['basis'] ?? '' ), (string) ( $raw['reward'] ?? '' ) );
		}
		$c['type']   = $type;
		$c['basis']  = in_array( $type, array( 'spend_amount', 'spend_percent' ), true ) ? 'spend' : 'quantity';
		$c['reward'] = in_array( $type, array( 'spend_percent', 'qty_percent', 'nth_percent' ), true ) ? 'percent' : 'amount';
		$tiers       = array();
		foreach ( is_array( $raw['tiers'] ?? null ) ? $raw['tiers'] : array() as $t ) {
			$min = is_array( $t ) && is_numeric( $t['min'] ?? null ) ? (float) $t['min'] : 0.0;
			$val = is_array( $t ) && is_numeric( $t['value'] ?? null ) ? (float) $t['value'] : 0.0;
			if ( $min <= 0 && $val <= 0 ) {
				continue; // 空行
			}
			if ( $min <= 0 || $val <= 0 ) {
				$errors[] = __( 'Each tier needs a threshold and a discount greater than zero.', 'peptide-core' );
				continue;
			}
			if ( 'spend' !== $c['basis'] ) {
				$min = (float) max( 1, (int) round( $min ) );
			}
			$error = self::tier_error( $type, $min, $val );
			if ( '' !== $error ) {
				$errors[] = $error;
				continue;
			}
			if ( 'buy_x_free_y' === $type ) {
				$val = (float) (int) round( $val );
			}
			$tiers[ (string) $min ] = array( 'min' => $min, 'value' => round( $val, 2 ) );
		}
		ksort( $tiers, SORT_NUMERIC );
		$c['tiers']     = array_slice( array_values( $tiers ), 0, self::MAX_TIERS );
		$c['unlimited'] = ! empty( $raw['unlimited'] ) && in_array( $type, self::REPEATABLE, true );
		if ( $c['unlimited'] && count( $c['tiers'] ) > 1 ) {
			$errors[]   = __( '“No upper limit” works with one tier only.', 'peptide-core' );
			$c['tiers'] = array_slice( $c['tiers'], 0, 1 );
		}
		$prev = 0.0;
		foreach ( $c['tiers'] as $t ) {
			// 一口价是总价，件数多时可以更贵；其他类型门槛越高，优惠不能越少。
			if ( 'bundle_price' !== $type && $t['value'] < $prev ) {
				$errors[] = __( 'Higher tiers should give at least the same discount as lower tiers.', 'peptide-core' );
				break;
			}
			$prev = $t['value'];
		}
		if ( ! $c['tiers'] ) {
			$errors[] = __( 'Add at least one tier.', 'peptide-core' );
		}
		$c['start'] = max( 0, (int) ( $raw['start'] ?? 0 ) );
		$c['end']   = max( 0, (int) ( $raw['end'] ?? 0 ) );
		if ( $c['start'] && $c['end'] && $c['end'] <= $c['start'] ) {
			$errors[] = __( 'The end time must be after the start time.', 'peptide-core' );
		}
		$c['scope']      = in_array( $raw['scope'] ?? '', array( 'categories', 'products' ), true ) ? $raw['scope'] : 'all';
		$c['categories'] = array_values( array_unique( array_filter( array_map( 'absint', is_array( $raw['categories'] ?? null ) ? $raw['categories'] : array() ), static fn( $id ) => $id && term_exists( $id, 'product_cat' ) ) ) );
		$c['products']   = array_values( array_unique( array_filter( array_map( 'absint', is_array( $raw['products'] ?? null ) ? $raw['products'] : array() ), static fn( $id ) => $id && 'product' === get_post_type( $id ) ) ) );
		if ( 'categories' === $c['scope'] && ! $c['categories'] ) {
			$errors[] = __( 'Choose at least one collection, or apply the promotion to all products.', 'peptide-core' );
		}
		if ( 'products' === $c['scope'] && ! $c['products'] ) {
			$errors[] = __( 'Choose at least one product, or apply the promotion to all products.', 'peptide-core' );
		}
		$c['sort']         = isset( self::SORTS[ $raw['sort'] ?? '' ] ) ? (string) $raw['sort'] : 'date_desc';
		$c['exclude_sale'] = ! empty( $raw['exclude_sale'] );
		$c['with_coupons'] = ! array_key_exists( 'with_coupons', $raw ) || ! empty( $raw['with_coupons'] );
		$c['public']       = ! empty( $raw['public'] );
		$text              = is_scalar( $raw['text'] ?? null ) ? sanitize_textarea_field( (string) $raw['text'] ) : '';
		$c['text']         = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 500 ) : substr( $text, 0, 500 );
		return array( $c, array_values( array_unique( $errors ) ) );
	}

	/** 一个层级的门槛和优惠是否合理，不合理时返回错误信息。 */
	private static function tier_error( string $type, float $min, float $val ): string {
		switch ( $type ) {
			case 'spend_percent':
			case 'qty_percent':
				return $val >= 100 ? __( 'A percentage discount must be below 100%.', 'peptide-core' ) : '';
			case 'spend_amount':
				return $val >= $min ? __( 'A fixed discount must be smaller than the amount the customer has to spend.', 'peptide-core' ) : '';
			case 'nth_percent':
				return $min < 2 || $val > 100 ? __( 'For “Xth item Y% off”, X must be at least 2 and Y at most 100 (100 = free).', 'peptide-core' ) : '';
			case 'bundle_price':
				return $min < 2 ? __( 'A bundle price needs at least 2 items.', 'peptide-core' ) : '';
			case 'buy_x_free_y':
				return $min < 2 || round( $val ) < 1 || round( $val ) >= $min ? __( 'For “buy X, Y free”, X must be at least 2 and Y smaller than X.', 'peptide-core' ) : '';
		}
		return '';
	}

	/**
	 * Shopname 的冲突规则：同一时间同一商品 / 专辑只能有一个满减活动，同一时间只能有一个全部商品的活动。
	 * 只和其他已启用、时间有重叠的活动比较。返回错误信息。
	 */
	public static function conflicts( array $c, int $exclude_id = 0 ): array {
		$errors = array();
		$ids    = get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'exclude'        => $exclude_id ? array( $exclude_id ) : array(),
				'no_found_rows'  => true,
			)
		);
		$s1 = (int) $c['start'];
		$e1 = $c['end'] ? (int) $c['end'] : PHP_INT_MAX;
		foreach ( $ids as $id ) {
			$o  = self::config( (int) $id );
			$s2 = (int) $o['start'];
			$e2 = $o['end'] ? (int) $o['end'] : PHP_INT_MAX;
			if ( $s1 >= $e2 || $s2 >= $e1 || $o['scope'] !== $c['scope'] || ! $o['tiers'] ) {
				continue;
			}
			$name = html_entity_decode( get_the_title( (int) $id ) );
			if ( 'all' === $c['scope'] ) {
				/* translators: %s: promotion name */
				$errors[] = sprintf( __( 'Only one promotion for all products can run at a time. “%s” runs at the same time.', 'peptide-core' ), $name );
			} elseif ( 'products' === $c['scope'] && array_intersect( $c['products'], $o['products'] ) ) {
				$names = array_map( static fn( $pid ) => html_entity_decode( get_the_title( $pid ) ), array_slice( array_values( array_intersect( $c['products'], $o['products'] ) ), 0, 3 ) );
				/* translators: 1: product names, 2: promotion name */
				$errors[] = sprintf( __( '%1$s already in “%2$s” at the same time. A product can only be in one promotion at a time.', 'peptide-core' ), implode( ', ', $names ), $name );
			} elseif ( 'categories' === $c['scope'] && array_intersect( $c['categories'], $o['categories'] ) ) {
				$names = array();
				foreach ( array_slice( array_values( array_intersect( $c['categories'], $o['categories'] ) ), 0, 3 ) as $tid ) {
					$term    = get_term( $tid, 'product_cat' );
					$names[] = $term instanceof \WP_Term ? html_entity_decode( $term->name ) : (string) $tid;
				}
				/* translators: 1: collection names, 2: promotion name */
				$errors[] = sprintf( __( 'The collection %1$s is already in “%2$s” at the same time. A collection can only be in one promotion at a time.', 'peptide-core' ), implode( ', ', $names ), $name );
			}
		}
		return $errors;
	}

	/**
	 * 保存一个活动（后台编辑页和测试共用）。$raw 是 clean() 的输入；启用时检查冲突。有错误时不保存。
	 *
	 * @return array{0:int,1:string[]} [活动 ID, 错误]
	 */
	public static function store( int $id, string $title, array $raw, bool $enabled ): array {
		if ( $id && self::CPT !== get_post_type( $id ) ) {
			return array( 0, array( __( 'This promotion does not exist.', 'peptide-core' ) ) );
		}
		$errors = array();
		$title  = trim( sanitize_text_field( $title ) );
		if ( '' === $title ) {
			$errors[] = __( 'Enter a promotion name.', 'peptide-core' );
		} elseif ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $title ) : strlen( $title ) ) > self::NAME_MAX ) {
			/* translators: %d: number of characters */
			$errors[] = sprintf( __( 'The name can have at most %d characters.', 'peptide-core' ), self::NAME_MAX );
		}
		[ $c, $more ] = self::clean( $raw );
		$errors       = array_merge( $errors, $more );
		if ( $id ) {
			// 编辑页没有的旧设置保持不变。
			$old = self::config( $id );
			foreach ( array( 'with_coupons', 'exclude_sale', 'text' ) as $key ) {
				if ( ! array_key_exists( $key, $raw ) ) {
					$c[ $key ] = $old[ $key ];
				}
			}
		}
		if ( ! $errors && $enabled ) {
			$errors = self::conflicts( $c, $id );
		}
		if ( $errors ) {
			return array( $id, array_values( array_unique( $errors ) ) );
		}
		$post = array(
			'post_type'   => self::CPT,
			'post_title'  => $title,
			'post_status' => $enabled ? 'publish' : 'draft',
		);
		if ( $id ) {
			$post['ID'] = $id;
			$saved      = wp_update_post( $post, true );
		} else {
			$saved = wp_insert_post( $post, true );
		}
		if ( is_wp_error( $saved ) ) {
			return array( $id, array( $saved->get_error_message() ) );
		}
		update_post_meta( (int) $saved, self::META, $c );
		if ( $enabled && $c['public'] ) {
			self::ensure_page();
		}
		return array( (int) $saved, array() );
	}

	/** 启用 / 停用。启用时检查规则和冲突，返回错误。 */
	public static function set_enabled( int $id, bool $on ): array {
		if ( self::CPT !== get_post_type( $id ) ) {
			return array( __( 'This promotion does not exist.', 'peptide-core' ) );
		}
		if ( $on ) {
			$c = self::config( $id );
			if ( ! $c['tiers'] ) {
				return array( __( 'Add at least one tier.', 'peptide-core' ) );
			}
			$errors = self::conflicts( $c, $id );
			if ( $errors ) {
				return $errors;
			}
		}
		wp_update_post( array( 'ID' => $id, 'post_status' => $on ? 'publish' : 'draft' ) );
		if ( $on && self::config( $id )['public'] ) {
			self::ensure_page();
		}
		return array();
	}

	/** upcoming / active / ended / off（停用或没有层级）。 */
	public static function status( int $id, ?int $now = null ): string {
		$now = $now ?? time();
		$c   = self::config( $id );
		if ( 'publish' !== get_post_status( $id ) || ! $c['tiers'] ) {
			return 'off';
		}
		if ( $c['start'] && $now < $c['start'] ) {
			return 'upcoming';
		}
		if ( $c['end'] && $now >= $c['end'] ) {
			return 'ended';
		}
		return 'active';
	}

	/** @return int[] 进行中的活动 ID，创建时间最近的在前。 */
	public static function active_ids( ?int $now = null ): array {
		$ids = get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'orderby'        => array( 'date' => 'DESC', 'ID' => 'DESC' ),
				'no_found_rows'  => true,
			)
		);
		return array_values( array_filter( array_map( 'intval', $ids ), static fn( $id ) => 'active' === self::status( $id, $now ) ) );
	}

	// ---------- 文字 ----------

	private static function money( float $amount ): string {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ) );
	}

	public static function reward_text( array $c, array $tier ): string {
		$type = $c['type'] ?? '';
		if ( 'bundle_price' === $type ) {
			/* translators: %s: price */
			return sprintf( __( 'all for %s', 'peptide-core' ), self::money( (float) $tier['value'] ) );
		}
		if ( 'buy_x_free_y' === $type ) {
			/* translators: %d: number of items */
			return sprintf( _n( '%d item free', '%d items free', (int) $tier['value'], 'peptide-core' ), (int) $tier['value'] );
		}
		/* translators: %s: percentage */
		return 'percent' === $c['reward'] ? sprintf( __( '%s%% off', 'peptide-core' ), wc_format_localized_decimal( $tier['value'] ) ) : sprintf( __( '%s off', 'peptide-core' ), self::money( (float) $tier['value'] ) );
	}

	public static function tier_text( array $c, array $tier ): string {
		$type   = $c['type'] ?? '';
		$repeat = ! empty( $c['unlimited'] );
		$min    = (int) $tier['min'];
		switch ( $type ) {
			case 'nth_percent':
				$text = $repeat
					/* translators: 1: item position, 2: percentage */
					? sprintf( __( 'Every %1$dth item %2$s%% off', 'peptide-core' ), $min, wc_format_localized_decimal( $tier['value'] ) )
					/* translators: 1: item position, 2: percentage */
					: sprintf( __( 'Item no. %1$d %2$s%% off', 'peptide-core' ), $min, wc_format_localized_decimal( $tier['value'] ) );
				return $text;
			case 'bundle_price':
				/* translators: 1: number of items, 2: price */
				$text = sprintf( __( 'Any %1$d items for %2$s', 'peptide-core' ), $min, self::money( (float) $tier['value'] ) );
				return $repeat ? $text . ' ' . __( '(repeats)', 'peptide-core' ) : $text;
			case 'buy_x_free_y':
				/* translators: 1: number of items to buy, 2: number of free items */
				$text = sprintf( __( 'Buy %1$d, the cheapest %2$d free', 'peptide-core' ), $min, (int) $tier['value'] );
				return $repeat ? $text . ' ' . __( '(repeats)', 'peptide-core' ) : $text;
			case 'spend_amount':
				if ( $repeat ) {
					/* translators: 1: amount, 2: discount */
					return sprintf( __( 'Every %1$s spent, %2$s off', 'peptide-core' ), self::money( (float) $tier['min'] ), self::money( (float) $tier['value'] ) );
				}
				break;
			case 'qty_amount':
				if ( $repeat ) {
					/* translators: 1: number of items, 2: discount */
					return sprintf( __( 'Every %1$d items, %2$s off', 'peptide-core' ), $min, self::money( (float) $tier['value'] ) );
				}
				break;
		}
		if ( 'quantity' === $c['basis'] ) {
			/* translators: 1: number of items, 2: reward */
			return sprintf( _n( 'Buy %1$d item, get %2$s', 'Buy %1$d items, get %2$s', $min, 'peptide-core' ), $min, self::reward_text( $c, $tier ) );
		}
		/* translators: 1: amount, 2: reward */
		return sprintf( __( 'Spend %1$s, get %2$s', 'peptide-core' ), self::money( (float) $tier['min'] ), self::reward_text( $c, $tier ) );
	}

	/** @return string[] 每个层级一行。 */
	public static function rules_text( array $c ): array {
		return array_map( static fn( $t ) => self::tier_text( $c, $t ), $c['tiers'] );
	}

	// ---------- 计算 ----------

	/** 商品是否在活动范围内（变体按父商品判断分类和商品范围）。 */
	public static function covers( array $c, \WC_Product $product ): bool {
		$parent = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		if ( $c['exclude_sale'] && $product->is_on_sale() ) {
			return false;
		}
		if ( 'products' === $c['scope'] ) {
			return in_array( $parent, $c['products'], true ) || in_array( $product->get_id(), $c['products'], true );
		}
		if ( 'categories' === $c['scope'] ) {
			return (bool) array_intersect( wc_get_product_cat_ids( $parent ), $c['categories'] );
		}
		return true;
	}

	/**
	 * 商品参加哪个活动：指定商品 > 指定专辑 > 全部商品，同一级别里取创建时间最近的。没有时返回 0。
	 *
	 * @param int[] $ids 候选活动，创建时间最近的在前（active_ids() 的顺序）。
	 */
	public static function owner( \WC_Product $product, array $ids ): int {
		foreach ( array( 'products', 'categories', 'all' ) as $level ) {
			foreach ( $ids as $id ) {
				$c = self::config( $id );
				if ( $c['scope'] === $level && self::covers( $c, $product ) ) {
					return (int) $id;
				}
			}
		}
		return 0;
	}

	/**
	 * 一个活动在这份购物车里的情况：参加活动商品的小计 / 件数、达到的层级、优惠金额、下一档。
	 *
	 * @param string[]|null $keys 只计算这些购物车行（分给这个活动的商品）；null = 活动范围内的全部商品。
	 */
	public static function evaluate( int $id, \WC_Cart $cart, ?array $keys = null ): array {
		$c        = self::config( $id );
		$subtotal = 0.0;
		$qty      = 0;
		$units    = array(); // 每一件的单价，用于第 X 件、一口价、满 X 免 Y。
		foreach ( $cart->get_cart() as $key => $item ) {
			$product = $item['data'] ?? null;
			if ( null !== $keys && ! in_array( (string) $key, $keys, true ) ) {
				continue;
			}
			if ( ! $product instanceof \WC_Product || ( null === $keys && ! self::covers( $c, $product ) ) ) {
				continue;
			}
			$line      = (float) ( $item['line_subtotal'] ?? 0 );
			$n         = (int) ( $item['quantity'] ?? 0 );
			$subtotal += $line;
			$qty      += $n;
			for ( $i = 0; $i < min( $n, 500 ); $i++ ) {
				$units[] = $n ? $line / $n : 0.0;
			}
		}
		rsort( $units, SORT_NUMERIC );
		$measure = self::by_quantity( $c ) ? (float) $qty : $subtotal;
		$reached = null;
		$next    = null;
		foreach ( $c['tiers'] as $t ) {
			if ( $measure >= $t['min'] ) {
				$reached = $t;
			} elseif ( null === $next ) {
				$next = $t;
			}
		}
		$discount = 0.0;
		if ( $reached && $subtotal > 0 ) {
			$discount = round( min( self::discount_for( $c, $measure, $units ), $subtotal ), wc_get_price_decimals() );
		}
		if ( $c['unlimited'] && $c['tiers'] ) {
			// 上不封顶：下一档就是再满一次门槛。
			$t    = $c['tiers'][0];
			$next = array( 'min' => $t['min'] * ( floor( $measure / $t['min'] ) + 1 ), 'value' => $t['value'] );
		}
		return array(
			'id'       => $id,
			'config'   => $c,
			'subtotal' => $subtotal,
			'quantity' => $qty,
			'measure'  => $measure,
			'reached'  => $reached,
			'next'     => $next,
			'discount' => $discount,
		);
	}

	/**
	 * 优惠金额。单价从高到低排好：
	 * - 满额 / 满件打折：参加活动商品的小计打折（达到的最高层级）；
	 * - 满额 / 满件减元：减一次，上不封顶时每满一次门槛减一次；
	 * - 第 X 件 Y% off：达到的每个层级各算一次（第 2 件 30% off、第 3 件 50% off）；上不封顶时每 X 件里最便宜的一件打折；
	 * - 满件一口价：最贵的 N 件按一口价算（比原价贵就不优惠），上不封顶时每 N 件一组；
	 * - 满 X 免 Y：X 件里最便宜的 Y 件免费，上不封顶时每 X 件一组。
	 *
	 * @param float[] $units
	 */
	private static function discount_for( array $c, float $measure, array $units ): float {
		$reached = array_values( array_filter( $c['tiers'], static fn( $t ) => $measure >= $t['min'] ) );
		if ( ! $reached ) {
			return 0.0;
		}
		$top    = end( $reached );
		$min    = (float) $top['min'];
		$value  = (float) $top['value'];
		$groups = $c['unlimited'] ? (int) floor( $measure / $min ) : 1;
		$sum    = array_sum( $units );
		switch ( $c['type'] ) {
			case 'spend_percent':
			case 'qty_percent':
				return $sum * $value / 100;
			case 'spend_amount':
			case 'qty_amount':
				return $value * max( 1, $groups );
			case 'nth_percent':
				$off = 0.0;
				if ( $c['unlimited'] ) {
					for ( $g = 1; $g <= $groups; $g++ ) {
						$off += ( $units[ (int) ( $g * $min ) - 1 ] ?? 0.0 ) * $value / 100;
					}
					return $off;
				}
				foreach ( $reached as $t ) {
					$off += ( $units[ (int) $t['min'] - 1 ] ?? 0.0 ) * (float) $t['value'] / 100;
				}
				return $off;
			case 'bundle_price':
			case 'buy_x_free_y':
				$off  = 0.0;
				$size = (int) $min;
				for ( $g = 0; $g < $groups; $g++ ) {
					$group = array_slice( $units, $g * $size, $size );
					if ( count( $group ) < $size ) {
						break;
					}
					$off += 'bundle_price' === $c['type'] ? max( 0.0, array_sum( $group ) - $value ) : array_sum( array_slice( $group, -1 * (int) $value ) );
				}
				return $off;
		}
		return 0.0;
	}

	/**
	 * 把购物车里的商品分给活动（每个商品只参加一个活动，见 owner()）。
	 *
	 * @param int[] $ids
	 * @return array<int,string[]> 活动 ID => 购物车行 key
	 */
	public static function assign( \WC_Cart $cart, array $ids ): array {
		$out = array();
		foreach ( $cart->get_cart() as $key => $item ) {
			$product = $item['data'] ?? null;
			$owner   = $product instanceof \WC_Product ? self::owner( $product, $ids ) : 0;
			if ( $owner ) {
				$out[ $owner ][] = (string) $key;
			}
		}
		return $out;
	}

	/** 这份购物车里可以生效的活动。$skip_code：计算时当作没有用这张优惠码（Coupon_Rules 检查“不叠加”时用）。 */
	public static function usable_ids( \WC_Cart $cart, string $skip_code = '' ): array {
		$codes = array_values( array_filter( $cart->get_applied_coupons(), static fn( $code ) => wc_format_coupon_code( $code ) !== wc_format_coupon_code( $skip_code ) ) );
		foreach ( $codes as $code ) {
			if ( 'replace' === Coupon_Rules::strategy( new \WC_Coupon( $code ) ) ) {
				return array(); // 用了“替换满减活动”的优惠码。
			}
		}
		return array_values( array_filter( self::active_ids(), static fn( $id ) => ! $codes || self::config( $id )['with_coupons'] ) );
	}

	/**
	 * 生效的活动和各自的优惠，优惠多的在前。
	 *
	 * @return array{applied:array[],evals:array[]}
	 */
	public static function discounts( \WC_Cart $cart, string $skip_code = '' ): array {
		$applied = array();
		$evals   = array();
		foreach ( self::assign( $cart, self::usable_ids( $cart, $skip_code ) ) as $id => $keys ) {
			$e       = self::evaluate( (int) $id, $cart, $keys );
			$evals[] = $e;
			if ( $e['discount'] > 0 ) {
				$applied[] = array(
					'id'            => $e['id'],
					'title'         => html_entity_decode( get_the_title( $e['id'] ) ),
					'tier'          => self::tier_text( $e['config'], $e['reached'] ),
					'discount'      => $e['discount'],
					'discount_text' => self::money( $e['discount'] ),
					'url'           => self::detail_url( $e['id'] ),
				);
			}
		}
		usort( $applied, static fn( $a, $b ) => $b['discount'] <=> $a['discount'] );
		return array( 'applied' => $applied, 'evals' => $evals );
	}

	/**
	 * 当前购物车生效的活动，以及离下一个更大优惠还差多少。
	 * applied：优惠最多的活动（旧字段）；有优惠时另有 applied_all（全部生效的活动）和 discount（合计）。
	 *
	 * @return array{applied:?array,next:?array}
	 */
	public static function progress( ?\WC_Cart $cart = null ): array {
		$cart = $cart ?? ( function_exists( 'WC' ) ? WC()->cart : null );
		$out  = array( 'applied' => null, 'next' => null );
		if ( ! $cart instanceof \WC_Cart || $cart->is_empty() ) {
			return $out;
		}
		$d = self::discounts( $cart );
		if ( $d['applied'] ) {
			$out['applied']     = $d['applied'][0];
			$out['applied_all'] = $d['applied'];
			$out['discount']    = round( array_sum( array_column( $d['applied'], 'discount' ) ), wc_get_price_decimals() );
		}
		// 下一档：购物车里已经有这个活动的商品、离下一档最近的那个。
		$next = null;
		foreach ( $d['evals'] as $e ) {
			if ( ! $e['next'] || 0 === $e['quantity'] ) {
				continue;
			}
			$missing = $e['next']['min'] - $e['measure'];
			$ratio   = $missing / $e['next']['min'];
			if ( $missing > 0 && ( ! $next || $ratio < $next['ratio'] ) ) {
				$next = array( 'e' => $e, 't' => $e['next'], 'missing' => $missing, 'ratio' => $ratio );
			}
		}
		if ( $next ) {
			$c         = $next['e']['config'];
			$items     = self::by_quantity( $c );
			$remaining = $items ? (int) ceil( $next['missing'] ) : round( $next['missing'], wc_get_price_decimals() );
			$rtext     = $items
				/* translators: %d: number of items */
				? sprintf( _n( '%d more item', '%d more items', (int) $remaining, 'peptide-core' ), (int) $remaining )
				: self::money( (float) $remaining );
			$out['next'] = array(
				'id'             => $next['e']['id'],
				'title'          => html_entity_decode( get_the_title( $next['e']['id'] ) ),
				'basis'          => $c['basis'],
				'remaining'      => $remaining,
				'remaining_text' => $rtext,
				'reward_text'    => self::reward_text( $c, $next['t'] ),
				'message'        => $items
					/* translators: 1: number of items still needed, e.g. "2 more items", 2: reward */
					? sprintf( __( 'Add %1$s to get %2$s', 'peptide-core' ), $rtext, self::reward_text( $c, $next['t'] ) )
					/* translators: 1: amount still needed, 2: reward */
					: sprintf( __( 'Add %1$s more to get %2$s', 'peptide-core' ), $rtext, self::reward_text( $c, $next['t'] ) ),
				'url'            => self::detail_url( $next['e']['id'] ),
			);
		}
		return $out;
	}

	/** woocommerce_cart_calculate_fees：每个生效的活动加一笔负数费用。 */
	public static function apply_to_cart( \WC_Cart $cart ): void {
		self::$applied = null;
		$p             = self::progress( $cart );
		if ( ! $p['applied'] ) {
			return;
		}
		self::$applied = $p['applied'];
		foreach ( $p['applied_all'] as $a ) {
			$cart->fees_api()->add_fee(
				array(
					'id'      => 'pc-promo-' . $a['id'],
					'name'    => $a['title'],
					'amount'  => -1 * $a['discount'],
					'taxable' => false,
				)
			);
		}
	}

	/** 订单的费用行记下活动 ID。 */
	public static function tag_fee_item( $item, $fee_key, $fee, $order ): void {
		if ( is_object( $fee ) && isset( $fee->id ) && preg_match( '/^pc-promo-(\d+)$/', (string) $fee->id, $m ) ) {
			$item->add_meta_data( self::FEE_META, (int) $m[1], true );
		}
	}

	// ---------- 公开信息 ----------

	public static function view( int $id ): array {
		$c     = self::config( $id );
		$names = static function ( array $ids, string $kind ): array {
			$out = array();
			foreach ( array_slice( $ids, 0, 50 ) as $oid ) {
				if ( 'product' === $kind ) {
					$p = wc_get_product( $oid );
					if ( $p && 'publish' === $p->get_status() ) {
						$out[] = array( 'id' => (int) $oid, 'name' => $p->get_name(), 'url' => $p->get_permalink() );
					}
				} else {
					$t = get_term( $oid, 'product_cat' );
					if ( $t instanceof \WP_Term ) {
						$link  = get_term_link( $t );
						$out[] = array( 'id' => (int) $oid, 'name' => html_entity_decode( $t->name ), 'url' => is_wp_error( $link ) ? '' : (string) $link );
					}
				}
			}
			return $out;
		};
		$date = static fn( int $ts ) => $ts ? wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), $ts ) : '';
		return array(
			'id'           => $id,
			'title'        => html_entity_decode( get_the_title( $id ) ),
			'text'         => $c['text'],
			'status'       => self::status( $id ),
			'type'         => $c['type'],
			'basis'        => $c['basis'],
			'reward'       => $c['reward'],
			'unlimited'    => $c['unlimited'],
			'tiers'        => array_map( static fn( $t ) => array( 'min' => $t['min'], 'value' => $t['value'], 'text' => self::tier_text( $c, $t ) ), $c['tiers'] ),
			'starts'       => $c['start'] ? gmdate( 'c', $c['start'] ) : null,
			'starts_text'  => $date( $c['start'] ),
			'ends'         => $c['end'] ? gmdate( 'c', $c['end'] ) : null,
			'ends_text'    => $date( $c['end'] ),
			'scope'        => $c['scope'],
			'categories'   => 'categories' === $c['scope'] ? $names( $c['categories'], 'category' ) : array(),
			'products'     => 'products' === $c['scope'] ? $names( $c['products'], 'product' ) : array(),
			'sort'         => $c['sort'],
			'exclude_sale' => $c['exclude_sale'],
			'with_coupons' => $c['with_coupons'],
			'url'          => self::detail_url( $id ),
		);
	}

	/** 公开展示、进行中或即将开始的活动。 */
	public static function public_list(): array {
		$ids = get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'orderby'        => 'menu_order date',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			if ( self::config( (int) $id )['public'] && in_array( self::status( (int) $id ), array( 'active', 'upcoming' ), true ) ) {
				$out[] = self::view( (int) $id );
			}
		}
		return $out;
	}

	/** 一个公开的活动；不公开、停用或不存在时返回 null。已结束的活动仍然返回（status=ended），方便详情页说明已结束。 */
	public static function get_public( int $id ): ?array {
		if ( self::CPT !== get_post_type( $id ) || ! self::config( $id )['public'] || 'off' === self::status( $id ) ) {
			return null;
		}
		return self::view( $id );
	}

	/** 商品参加的进行中、公开的活动（按优先级只有一个）。 */
	public static function for_product( \WC_Product $product ): array {
		$owner = self::owner( $product, self::active_ids() );
		return $owner && self::config( $owner )['public'] ? array( self::view( $owner ) ) : array();
	}

	// ---------- 页面 ----------

	public static function page_id(): int {
		$id = (int) get_option( self::PAGE_OPTION, 0 );
		return $id && 'page' === get_post_type( $id ) && 'trash' !== get_post_status( $id ) ? $id : 0;
	}

	public static function ensure_page(): int {
		$id = self::page_id();
		if ( $id ) {
			return $id;
		}
		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Offers',
				'post_name'    => 'offers',
				'post_content' => '<!-- wp:shortcode -->[' . self::SHORTCODE . ']<!-- /wp:shortcode -->',
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return 0;
		}
		update_option( self::PAGE_OPTION, (int) $id, false );
		return (int) $id;
	}

	public static function list_url(): string {
		$id = self::page_id();
		return $id && 'publish' === get_post_status( $id ) ? (string) get_permalink( $id ) : '';
	}

	public static function detail_url( int $id ): string {
		$list = self::list_url();
		return '' !== $list ? add_query_arg( self::ID_ARG, $id, $list ) : '';
	}

	public static function enqueue(): void {
		if ( ! self::page_id() || ! is_page( self::page_id() ) ) {
			return;
		}
		// 和优惠码页面共用默认样式。
		$file = PEPTIDE_CORE_DIR . '/assets/coupons.css';
		wp_enqueue_style( 'peptide-core-coupons', plugins_url( 'assets/coupons.css', PEPTIDE_CORE_FILE ), array(), PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( $file ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	public static function shortcode(): string {
		$id = isset( $_GET[ self::ID_ARG ] ) ? absint( $_GET[ self::ID_ARG ] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$view = $id ? 'detail' : 'list';
		$args = $id ? array( 'promotion' => self::get_public( $id ), 'list_url' => self::list_url() ) : array( 'promotions' => self::public_list() );
		ob_start();
		if ( locate_template( 'template-parts/promotions/' . $view . '.php' ) ) {
			get_template_part( 'template-parts/promotions/' . $view, null, $args );
		} elseif ( $id ) {
			self::fallback_detail( $args['promotion'], $args['list_url'] );
		} else {
			self::fallback_list( $args['promotions'] );
		}
		return (string) ob_get_clean();
	}

	/**
	 * 活动详情页的商品（WooCommerce [products] 短代码），按活动设置的排序。
	 * 全部商品 / 专辑的活动会排除已经参加更优先活动的商品（指定商品的活动）。
	 */
	public static function products_html( array $p ): string {
		$c = self::config( (int) $p['id'] );
		[ $orderby, $order ] = self::SORTS[ $c['sort'] ] ?? self::SORTS['date_desc'];
		$atts = array( 'limit="12"', 'columns="4"', 'paginate="true"', 'orderby="' . $orderby . '"', 'order="' . $order . '"' );
		if ( 'products' === $c['scope'] ) {
			$atts[] = 'ids="' . implode( ',', array_map( 'intval', $c['products'] ) ) . '"';
		} elseif ( 'categories' === $c['scope'] ) {
			$slugs = array();
			foreach ( $c['categories'] as $tid ) {
				$t = get_term( $tid, 'product_cat' );
				if ( $t instanceof \WP_Term ) {
					$slugs[] = $t->slug;
				}
			}
			$atts[] = 'category="' . esc_attr( implode( ',', $slugs ) ) . '"';
		}
		self::$listing = $c + array( 'id' => (int) $p['id'] );
		$html          = do_shortcode( '[products ' . implode( ' ', $atts ) . ']' );
		self::$listing = null;
		return (string) $html;
	}

	/** 活动页面的商品查询：去掉参加了更优先活动的商品。 */
	public static function listing_query( $args, $atts ) {
		if ( ! self::$listing || 'products' === self::$listing['scope'] || ! is_array( $args ) ) {
			return $args;
		}
		$exclude = array();
		$cats    = array();
		foreach ( self::active_ids() as $id ) {
			if ( $id === self::$listing['id'] ) {
				continue;
			}
			$o = self::config( $id );
			if ( 'products' === $o['scope'] ) {
				$exclude = array_merge( $exclude, $o['products'] );
			} elseif ( 'categories' === $o['scope'] && 'all' === self::$listing['scope'] ) {
				$cats = array_merge( $cats, $o['categories'] );
			}
		}
		if ( $exclude ) {
			$args['post__not_in'] = array_values( array_unique( array_merge( (array) ( $args['post__not_in'] ?? array() ), $exclude ) ) );
		}
		if ( $cats ) {
			$args['tax_query']   = (array) ( $args['tax_query'] ?? array() ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			$args['tax_query'][] = array(
				'taxonomy' => 'product_cat',
				'field'    => 'term_id',
				'terms'    => array_values( array_unique( $cats ) ),
				'operator' => 'NOT IN',
			);
		}
		return $args;
	}

	private static function dates( array $p ): string {
		if ( 'upcoming' === $p['status'] && $p['starts_text'] ) {
			/* translators: %s: date */
			return sprintf( __( 'Starts %s', 'peptide-core' ), $p['starts_text'] );
		}
		if ( 'ended' === $p['status'] ) {
			return __( 'This offer has ended.', 'peptide-core' );
		}
		/* translators: %s: date */
		return $p['ends_text'] ? sprintf( __( 'Ends %s', 'peptide-core' ), $p['ends_text'] ) : '';
	}

	private static function fallback_list( array $list ): void {
		if ( ! $list ) {
			echo '<p class="pc-coupons__empty">' . esc_html__( 'There are no offers right now. Please check back later.', 'peptide-core' ) . '</p>';
			return;
		}
		echo '<div class="pc-coupons">';
		foreach ( $list as $p ) {
			echo '<article class="pc-coupon"><div class="pc-coupon__value">' . esc_html( $p['title'] ) . '</div>';
			echo '<ul class="pc-coupon__list">';
			foreach ( $p['tiers'] as $t ) {
				echo '<li>' . esc_html( $t['text'] ) . '</li>';
			}
			echo '</ul>';
			$d = self::dates( $p );
			if ( '' !== $d ) {
				echo '<p class="pc-coupon__terms">' . esc_html( $d ) . '</p>';
			}
			echo '<div class="pc-coupon__actions"><a class="pc-coupon__more" href="' . esc_url( $p['url'] ) . '">' . esc_html__( 'Details', 'peptide-core' ) . '</a></div></article>';
		}
		echo '</div>';
	}

	private static function fallback_detail( ?array $p, string $list_url ): void {
		if ( ! $p ) {
			echo '<p class="pc-coupons__empty">' . esc_html__( 'This offer is not available.', 'peptide-core' ) . '</p>';
		} else {
			echo '<article class="pc-coupon pc-coupon--detail"><div class="pc-coupon__value">' . esc_html( $p['title'] ) . '</div>';
			if ( '' !== $p['text'] ) {
				echo '<p class="pc-coupon__text">' . esc_html( $p['text'] ) . '</p>';
			}
			echo '<ul class="pc-coupon__list">';
			foreach ( $p['tiers'] as $t ) {
				echo '<li>' . esc_html( $t['text'] ) . '</li>';
			}
			echo '</ul>';
			$notes = array_filter(
				array(
					self::dates( $p ),
					'all' === $p['scope'] ? __( 'Applies to all products.', 'peptide-core' ) : '',
					$p['exclude_sale'] ? __( 'Items already on sale do not count.', 'peptide-core' ) : '',
					$p['with_coupons'] ? '' : __( 'Cannot be combined with coupon codes.', 'peptide-core' ),
					__( 'The discount is applied automatically in the cart. Each item joins one offer only.', 'peptide-core' ),
				)
			);
			echo '<p class="pc-coupon__terms">' . esc_html( implode( ' · ', $notes ) ) . '</p>';
			if ( $p['categories'] ) {
				echo '<h3 class="pc-coupon__heading">' . esc_html__( 'Eligible collections', 'peptide-core' ) . '</h3><ul class="pc-coupon__list">';
				foreach ( $p['categories'] as $item ) {
					echo '<li>' . ( '' !== $item['url'] ? '<a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['name'] ) . '</a>' : esc_html( $item['name'] ) ) . '</li>';
				}
				echo '</ul>';
			}
			echo '</article>';
			if ( 'ended' !== $p['status'] ) {
				echo '<div class="pc-coupon__products">' . self::products_html( $p ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce 短代码输出。
			}
		}
		if ( '' !== $list_url ) {
			echo '<p><a class="pc-coupon__more" href="' . esc_url( $list_url ) . '">' . esc_html__( 'All offers', 'peptide-core' ) . '</a></p>';
		}
	}
}
