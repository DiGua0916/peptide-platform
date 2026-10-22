<?php
/**
 * 满减活动（PROMO-01），对应 Shopname 的“满减活动列表页 / 详情页”。
 *
 * 后台：营销（没有营销菜单时在 WooCommerce 下面）→ 满减活动。每个活动：
 * - 条件：按金额（满 $100）或按件数（满 2 件），最多 5 档；
 * - 优惠：减固定金额或打百分比折扣（按参加活动商品的小计计算）；
 * - 时间：开始 / 结束（店铺时区），都可以留空；
 * - 范围：全部商品 / 指定分类 / 指定商品，可以排除促销中的商品；
 * - 是否可以和优惠码同时使用；是否在活动页面公开展示。
 * 发布 = 启用，草稿 = 停用。活动名称就是给客户看的标题（例如 “Spend $100, save $10”）。
 *
 * 购物车：每次计算总价时，在所有进行中的活动里选**优惠最多的一个**，加一笔负数费用（不计税），
 * 订单里这笔费用记着活动 ID。不会同时叠加多个满减活动。
 *
 * 前台：
 * - 活动页面（短代码 [pc_promotions]，第一次公开活动时自动建好），?promo=<ID> 显示详情；
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

	private const NONCE = 'pc_promo_nonce';

	/** 本次计算选中的活动，结账时写进订单的费用行。 */
	private static ?array $applied = null;

	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'register_post_type' ) );
		add_shortcode( self::SHORTCODE, array( self::class, 'shortcode' ) );
		add_action( 'woocommerce_cart_calculate_fees', array( self::class, 'apply_to_cart' ), 20 );
		add_action( 'woocommerce_checkout_create_order_fee_item', array( self::class, 'tag_fee_item' ), 10, 4 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
		if ( is_admin() ) {
			add_action( 'admin_menu', array( self::class, 'add_menu' ), 70 );
			add_filter( 'parent_file', array( self::class, 'parent_file' ) );
			add_action( 'add_meta_boxes_' . self::CPT, array( self::class, 'add_meta_box' ) );
			add_action( 'save_post_' . self::CPT, array( self::class, 'save' ), 10, 2 );
			add_filter( 'manage_' . self::CPT . '_posts_columns', array( self::class, 'columns' ) );
			add_action( 'manage_' . self::CPT . '_posts_custom_column', array( self::class, 'render_column' ), 10, 2 );
			add_filter( 'enter_title_here', array( self::class, 'title_placeholder' ), 10, 2 );
		}
	}

	public static function register_post_type(): void {
		register_post_type(
			self::CPT,
			array(
				'labels'          => array(
					'name'          => __( 'Spend-and-save promotions', 'peptide-core' ),
					'singular_name' => __( 'Promotion', 'peptide-core' ),
					'add_new_item'  => __( 'Add promotion', 'peptide-core' ),
					'edit_item'     => __( 'Edit promotion', 'peptide-core' ),
					'menu_name'     => __( 'Spend-and-save', 'peptide-core' ),
					'not_found'     => __( 'No promotions yet.', 'peptide-core' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => false,
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
			'basis'        => 'spend',     // spend：满金额；quantity：满件数
			'reward'       => 'amount',    // amount：减金额；percent：打折（百分比）
			'tiers'        => array(),     // [{min, value}]，按 min 从小到大
			'start'        => 0,           // UTC 时间戳，0 = 不限
			'end'          => 0,
			'scope'        => 'all',       // all / categories / products
			'categories'   => array(),
			'products'     => array(),
			'exclude_sale' => false,
			'with_coupons' => true,
			'public'       => false,
			'text'         => '',
		);
	}

	public static function config( int $id ): array {
		$c = get_post_meta( $id, self::META, true );
		return array_merge( self::defaults(), is_array( $c ) ? $c : array() );
	}

	/**
	 * 清理设置。返回 [config, errors]。
	 *
	 * @return array{0:array,1:string[]}
	 */
	public static function clean( array $raw ): array {
		$errors = array();
		$c      = self::defaults();
		$c['basis']  = 'quantity' === ( $raw['basis'] ?? '' ) ? 'quantity' : 'spend';
		$c['reward'] = 'percent' === ( $raw['reward'] ?? '' ) ? 'percent' : 'amount';
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
			if ( 'quantity' === $c['basis'] ) {
				$min = (float) max( 1, (int) round( $min ) );
			}
			if ( 'percent' === $c['reward'] && $val >= 100 ) {
				$errors[] = __( 'A percentage discount must be below 100%.', 'peptide-core' );
				continue;
			}
			if ( 'amount' === $c['reward'] && 'spend' === $c['basis'] && $val >= $min ) {
				$errors[] = __( 'A fixed discount must be smaller than the amount the customer has to spend.', 'peptide-core' );
				continue;
			}
			$tiers[ (string) $min ] = array( 'min' => $min, 'value' => round( $val, 2 ) );
		}
		ksort( $tiers, SORT_NUMERIC );
		$c['tiers'] = array_slice( array_values( $tiers ), 0, self::MAX_TIERS );
		$prev       = 0.0;
		foreach ( $c['tiers'] as $t ) {
			if ( $t['value'] < $prev ) {
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
		$c['categories'] = array_values( array_filter( array_map( 'absint', is_array( $raw['categories'] ?? null ) ? $raw['categories'] : array() ), static fn( $id ) => $id && term_exists( $id, 'product_cat' ) ) );
		$c['products']   = array_values( array_filter( array_map( 'absint', is_array( $raw['products'] ?? null ) ? $raw['products'] : array() ), static fn( $id ) => $id && 'product' === get_post_type( $id ) ) );
		if ( 'categories' === $c['scope'] && ! $c['categories'] ) {
			$errors[] = __( 'Choose at least one category, or apply the promotion to all products.', 'peptide-core' );
		}
		if ( 'products' === $c['scope'] && ! $c['products'] ) {
			$errors[] = __( 'Choose at least one product, or apply the promotion to all products.', 'peptide-core' );
		}
		$c['exclude_sale'] = ! empty( $raw['exclude_sale'] );
		$c['with_coupons'] = ! empty( $raw['with_coupons'] );
		$c['public']       = ! empty( $raw['public'] );
		$text              = is_scalar( $raw['text'] ?? null ) ? sanitize_textarea_field( (string) $raw['text'] ) : '';
		$c['text']         = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 500 ) : substr( $text, 0, 500 );
		return array( $c, array_values( array_unique( $errors ) ) );
	}

	/** upcoming / active / ended / off（草稿或没有档位）。 */
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

	/** @return int[] 进行中的活动 ID。 */
	public static function active_ids( ?int $now = null ): array {
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
		return array_values( array_filter( array_map( 'intval', $ids ), static fn( $id ) => 'active' === self::status( $id, $now ) ) );
	}

	// ---------- 计算 ----------

	private static function money( float $amount ): string {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ) );
	}

	public static function reward_text( array $c, array $tier ): string {
		/* translators: %s: percentage */
		return 'percent' === $c['reward'] ? sprintf( __( '%s%% off', 'peptide-core' ), wc_format_localized_decimal( $tier['value'] ) ) : sprintf( __( '%s off', 'peptide-core' ), self::money( $tier['value'] ) );
	}

	public static function tier_text( array $c, array $tier ): string {
		if ( 'quantity' === $c['basis'] ) {
			/* translators: 1: number of items, 2: reward */
			return sprintf( _n( 'Buy %1$d item, get %2$s', 'Buy %1$d items, get %2$s', (int) $tier['min'], 'peptide-core' ), (int) $tier['min'], self::reward_text( $c, $tier ) );
		}
		/* translators: 1: amount, 2: reward */
		return sprintf( __( 'Spend %1$s, get %2$s', 'peptide-core' ), self::money( $tier['min'] ), self::reward_text( $c, $tier ) );
	}

	/** 商品是否参加这个活动（变体按父商品判断分类和商品范围）。 */
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
	 * 一个活动在这份购物车里的情况：参加活动商品的小计 / 件数、达到的档位、优惠金额、下一档。
	 */
	public static function evaluate( int $id, \WC_Cart $cart ): array {
		$c        = self::config( $id );
		$subtotal = 0.0;
		$qty      = 0;
		foreach ( $cart->get_cart() as $item ) {
			$product = $item['data'] ?? null;
			if ( $product instanceof \WC_Product && self::covers( $c, $product ) ) {
				$subtotal += (float) ( $item['line_subtotal'] ?? 0 );
				$qty      += (int) ( $item['quantity'] ?? 0 );
			}
		}
		$measure = 'quantity' === $c['basis'] ? (float) $qty : $subtotal;
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
			$discount = 'percent' === $c['reward'] ? $subtotal * $reached['value'] / 100 : min( $reached['value'], $subtotal );
			$discount = round( $discount, wc_get_price_decimals() );
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
	 * 当前购物车用哪个活动（优惠最多的一个），以及离下一个更大优惠还差多少。
	 *
	 * @return array{applied:?array,next:?array}
	 */
	public static function progress( ?\WC_Cart $cart = null ): array {
		$cart = $cart ?? ( function_exists( 'WC' ) ? WC()->cart : null );
		$out  = array( 'applied' => null, 'next' => null );
		if ( ! $cart instanceof \WC_Cart || $cart->is_empty() ) {
			return $out;
		}
		$has_coupons = (bool) $cart->get_applied_coupons();
		$best        = null;
		$evals       = array();
		foreach ( self::active_ids() as $id ) {
			$e = self::evaluate( $id, $cart );
			if ( $has_coupons && ! $e['config']['with_coupons'] ) {
				continue;
			}
			$evals[] = $e;
			if ( $e['discount'] > 0 && ( ! $best || $e['discount'] > $best['discount'] ) ) {
				$best = $e;
			}
		}
		if ( $best ) {
			$out['applied'] = array(
				'id'            => $best['id'],
				'title'         => html_entity_decode( get_the_title( $best['id'] ) ),
				'tier'          => self::tier_text( $best['config'], $best['reached'] ),
				'discount'      => $best['discount'],
				'discount_text' => self::money( $best['discount'] ),
				'url'           => self::detail_url( $best['id'] ),
			);
		}
		// 下一档：只提示比现在更优惠、差距最小的那一个。
		$current = $best ? $best['discount'] : 0.0;
		$next    = null;
		foreach ( $evals as $e ) {
			if ( ! $e['next'] || 0 === $e['quantity'] ) {
				continue; // 已经是最高档，或者购物车里还没有参加这个活动的商品。
			}
			$t        = $e['next'];
			$would    = 'percent' === $e['config']['reward'] ? $e['subtotal'] * $t['value'] / 100 : $t['value'];
			$missing  = $t['min'] - $e['measure'];
			if ( $would <= $current ) {
				continue;
			}
			$ratio = $missing / $t['min'];
			if ( ! $next || $ratio < $next['ratio'] ) {
				$next = array( 'e' => $e, 't' => $t, 'missing' => $missing, 'ratio' => $ratio );
			}
		}
		if ( $next ) {
			$c         = $next['e']['config'];
			$remaining = 'quantity' === $c['basis'] ? (int) ceil( $next['missing'] ) : round( $next['missing'], wc_get_price_decimals() );
			$rtext     = 'quantity' === $c['basis']
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
				/* translators: 1: amount or number of items still needed, 2: reward */
				'message'        => sprintf( __( 'Add %1$s more to get %2$s', 'peptide-core' ), $rtext, self::reward_text( $c, $next['t'] ) ),
				'url'            => self::detail_url( $next['e']['id'] ),
			);
		}
		return $out;
	}

	/** woocommerce_cart_calculate_fees：加一笔负数费用。 */
	public static function apply_to_cart( \WC_Cart $cart ): void {
		self::$applied = null;
		$p             = self::progress( $cart );
		if ( ! $p['applied'] ) {
			return;
		}
		self::$applied = $p['applied'];
		$cart->fees_api()->add_fee(
			array(
				'id'      => 'pc-promo-' . $p['applied']['id'],
				'name'    => $p['applied']['title'],
				'amount'  => -1 * $p['applied']['discount'],
				'taxable' => false,
			)
		);
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
			'basis'        => $c['basis'],
			'reward'       => $c['reward'],
			'tiers'        => array_map( static fn( $t ) => array( 'min' => $t['min'], 'value' => $t['value'], 'text' => self::tier_text( $c, $t ) ), $c['tiers'] ),
			'starts'       => $c['start'] ? gmdate( 'c', $c['start'] ) : null,
			'starts_text'  => $date( $c['start'] ),
			'ends'         => $c['end'] ? gmdate( 'c', $c['end'] ) : null,
			'ends_text'    => $date( $c['end'] ),
			'scope'        => $c['scope'],
			'categories'   => 'categories' === $c['scope'] ? $names( $c['categories'], 'category' ) : array(),
			'products'     => 'products' === $c['scope'] ? $names( $c['products'], 'product' ) : array(),
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

	/** 一个公开的活动；不公开、草稿或不存在时返回 null。已结束的活动仍然返回（status=ended），方便详情页说明已结束。 */
	public static function get_public( int $id ): ?array {
		if ( self::CPT !== get_post_type( $id ) || ! self::config( $id )['public'] || 'off' === self::status( $id ) ) {
			return null;
		}
		return self::view( $id );
	}

	/** 商品参加的进行中、公开的活动。 */
	public static function for_product( \WC_Product $product ): array {
		$out = array();
		foreach ( self::active_ids() as $id ) {
			$c = self::config( $id );
			if ( $c['public'] && self::covers( $c, $product ) ) {
				$out[] = self::view( $id );
			}
		}
		return $out;
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
					__( 'The discount is applied automatically in the cart. Only the best offer applies.', 'peptide-core' ),
				)
			);
			echo '<p class="pc-coupon__terms">' . esc_html( implode( ' · ', $notes ) ) . '</p>';
			foreach ( array( 'categories' => __( 'Eligible categories', 'peptide-core' ), 'products' => __( 'Eligible products', 'peptide-core' ) ) as $key => $label ) {
				if ( $p[ $key ] ) {
					echo '<h3 class="pc-coupon__heading">' . esc_html( $label ) . '</h3><ul class="pc-coupon__list">';
					foreach ( $p[ $key ] as $item ) {
						echo '<li>' . ( '' !== $item['url'] ? '<a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['name'] ) . '</a>' : esc_html( $item['name'] ) ) . '</li>';
					}
					echo '</ul>';
				}
			}
			echo '</article>';
		}
		if ( '' !== $list_url ) {
			echo '<p><a class="pc-coupon__more" href="' . esc_url( $list_url ) . '">' . esc_html__( 'All offers', 'peptide-core' ) . '</a></p>';
		}
	}

	// ---------- 后台 ----------

	public static function add_menu(): void {
		global $submenu;
		$parent = isset( $submenu['woocommerce-marketing'] ) ? 'woocommerce-marketing' : 'woocommerce';
		add_submenu_page( $parent, __( 'Spend-and-save promotions', 'peptide-core' ), __( 'Spend-and-save', 'peptide-core' ), 'edit_shop_coupons', 'edit.php?post_type=' . self::CPT );
	}

	public static function parent_file( $parent_file ) {
		global $submenu;
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && self::CPT === $screen->post_type ) {
			return isset( $submenu['woocommerce-marketing'] ) ? 'woocommerce-marketing' : 'woocommerce';
		}
		return $parent_file;
	}

	public static function title_placeholder( $text, $post ) {
		return $post instanceof \WP_Post && self::CPT === $post->post_type ? __( 'Name shown to customers, e.g. Spend $100, save $10', 'peptide-core' ) : $text;
	}

	public static function add_meta_box(): void {
		add_meta_box( 'pc-promo', __( 'Promotion rules', 'peptide-core' ), array( self::class, 'render_meta_box' ), self::CPT, 'normal', 'high' );
	}

	private static function local_input( int $ts ): string {
		return $ts ? wp_date( 'Y-m-d\TH:i', $ts ) : '';
	}

	private static function from_local( string $value ): int {
		if ( '' === $value ) {
			return 0;
		}
		$d = date_create_immutable( $value, wp_timezone() );
		return $d ? $d->getTimestamp() : 0;
	}

	public static function render_meta_box( \WP_Post $post ): void {
		$c       = self::config( $post->ID );
		$errors  = get_transient( 'pc_promo_errors_' . $post->ID );
		$cats    = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		$prods   = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => 500, 'orderby' => 'title', 'order' => 'ASC', 'fields' => 'ids' ) );
		$tiers   = array_pad( $c['tiers'], self::MAX_TIERS, array( 'min' => '', 'value' => '' ) );
		$status  = self::status( $post->ID );
		$labels  = array(
			'off'      => __( 'Off (draft or no tiers)', 'peptide-core' ),
			'upcoming' => __( 'Scheduled', 'peptide-core' ),
			'active'   => __( 'Running', 'peptide-core' ),
			'ended'    => __( 'Ended', 'peptide-core' ),
		);
		wp_nonce_field( self::NONCE, self::NONCE );
		if ( is_array( $errors ) && $errors ) {
			delete_transient( 'pc_promo_errors_' . $post->ID );
			echo '<div class="notice notice-error inline"><p>' . esc_html( implode( ' ', $errors ) ) . '</p><p>' . esc_html__( 'The promotion was saved as a draft until this is fixed.', 'peptide-core' ) . '</p></div>';
		}
		?>
		<p><strong><?php esc_html_e( 'Status', 'peptide-core' ); ?>:</strong> <?php echo esc_html( $labels[ $status ] ); ?>. <?php esc_html_e( 'Publish to turn the promotion on; switch it back to draft to turn it off.', 'peptide-core' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Condition', 'peptide-core' ); ?></th>
				<td>
					<label><input type="radio" name="pc_promo[basis]" value="spend" <?php checked( 'spend', $c['basis'] ); ?>> <?php esc_html_e( 'Cart amount (spend $X)', 'peptide-core' ); ?></label><br>
					<label><input type="radio" name="pc_promo[basis]" value="quantity" <?php checked( 'quantity', $c['basis'] ); ?>> <?php esc_html_e( 'Number of items (buy X items)', 'peptide-core' ); ?></label>
					<p class="description"><?php esc_html_e( 'Only eligible items count, before coupons, taxes and shipping.', 'peptide-core' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Discount', 'peptide-core' ); ?></th>
				<td>
					<label><input type="radio" name="pc_promo[reward]" value="amount" <?php checked( 'amount', $c['reward'] ); ?>> <?php esc_html_e( 'Fixed amount off', 'peptide-core' ); ?></label><br>
					<label><input type="radio" name="pc_promo[reward]" value="percent" <?php checked( 'percent', $c['reward'] ); ?>> <?php esc_html_e( 'Percentage off the eligible items', 'peptide-core' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Tiers', 'peptide-core' ); ?></th>
				<td>
					<table class="widefat striped" style="max-width:420px">
						<thead><tr><th><?php esc_html_e( 'Threshold (amount or items)', 'peptide-core' ); ?></th><th><?php esc_html_e( 'Discount (amount or %)', 'peptide-core' ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( $tiers as $i => $t ) : ?>
								<tr>
									<td><input type="number" step="0.01" min="0" name="pc_promo[tiers][<?php echo (int) $i; ?>][min]" value="<?php echo esc_attr( (string) $t['min'] ); ?>"></td>
									<td><input type="number" step="0.01" min="0" name="pc_promo[tiers][<?php echo (int) $i; ?>][value]" value="<?php echo esc_attr( (string) $t['value'] ); ?>"></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p class="description"><?php esc_html_e( 'Example: 100 → 10 and 200 → 25 means spend $100 save $10, spend $200 save $25. The highest tier reached applies. Leave unused rows empty.', 'peptide-core' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Dates', 'peptide-core' ); ?></th>
				<td>
					<label><?php esc_html_e( 'Start', 'peptide-core' ); ?> <input type="datetime-local" name="pc_promo[start]" value="<?php echo esc_attr( self::local_input( $c['start'] ) ); ?>"></label>
					<label><?php esc_html_e( 'End', 'peptide-core' ); ?> <input type="datetime-local" name="pc_promo[end]" value="<?php echo esc_attr( self::local_input( $c['end'] ) ); ?>"></label>
					<?php /* translators: %s: timezone name */ ?>
					<p class="description"><?php echo esc_html( sprintf( __( 'Store time zone: %s. Leave empty for no limit. Use the real dates of the offer.', 'peptide-core' ), wp_timezone_string() ) ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Eligible products', 'peptide-core' ); ?></th>
				<td>
					<select name="pc_promo[scope]">
						<option value="all" <?php selected( 'all', $c['scope'] ); ?>><?php esc_html_e( 'All products', 'peptide-core' ); ?></option>
						<option value="categories" <?php selected( 'categories', $c['scope'] ); ?>><?php esc_html_e( 'Selected categories', 'peptide-core' ); ?></option>
						<option value="products" <?php selected( 'products', $c['scope'] ); ?>><?php esc_html_e( 'Selected products', 'peptide-core' ); ?></option>
					</select>
					<p><label><?php esc_html_e( 'Categories', 'peptide-core' ); ?><br>
						<select name="pc_promo[categories][]" multiple size="6" style="min-width:320px">
							<?php foreach ( is_wp_error( $cats ) ? array() : $cats as $t ) : ?>
								<option value="<?php echo (int) $t->term_id; ?>" <?php selected( in_array( (int) $t->term_id, $c['categories'], true ) ); ?>><?php echo esc_html( html_entity_decode( $t->name ) ); ?></option>
							<?php endforeach; ?>
						</select></label></p>
					<p><label><?php esc_html_e( 'Products', 'peptide-core' ); ?><br>
						<select name="pc_promo[products][]" multiple size="8" style="min-width:320px">
							<?php foreach ( $prods as $pid ) : ?>
								<option value="<?php echo (int) $pid; ?>" <?php selected( in_array( (int) $pid, $c['products'], true ) ); ?>><?php echo esc_html( html_entity_decode( get_the_title( $pid ) ) ); ?></option>
							<?php endforeach; ?>
						</select></label></p>
					<p class="description"><?php esc_html_e( 'Hold Ctrl (Cmd on Mac) to choose several.', 'peptide-core' ); ?></p>
					<label><input type="checkbox" name="pc_promo[exclude_sale]" value="1" <?php checked( $c['exclude_sale'] ); ?>> <?php esc_html_e( 'Items already on sale do not count', 'peptide-core' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Other', 'peptide-core' ); ?></th>
				<td>
					<label><input type="checkbox" name="pc_promo[with_coupons]" value="1" <?php checked( $c['with_coupons'] ); ?>> <?php esc_html_e( 'Can be combined with coupon codes', 'peptide-core' ); ?></label><br>
					<label><input type="checkbox" name="pc_promo[public]" value="1" <?php checked( $c['public'] ); ?>> <?php esc_html_e( 'Show on the storefront offers page', 'peptide-core' ); ?></label>
					<p><label><?php esc_html_e( 'Public description', 'peptide-core' ); ?><br><textarea name="pc_promo[text]" rows="3" cols="60"><?php echo esc_textarea( $c['text'] ); ?></textarea></label></p>
					<?php if ( 'publish' === $post->post_status && $c['public'] && '' !== self::detail_url( $post->ID ) ) : ?>
						<p><a href="<?php echo esc_url( self::detail_url( $post->ID ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View on the offers page', 'peptide-core' ); ?></a></p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function save( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$raw          = isset( $_POST['pc_promo'] ) && is_array( $_POST['pc_promo'] ) ? wp_unslash( $_POST['pc_promo'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 在 clean() 里逐项清理。
		$raw['start'] = self::from_local( is_string( $raw['start'] ?? null ) ? $raw['start'] : '' );
		$raw['end']   = self::from_local( is_string( $raw['end'] ?? null ) ? $raw['end'] : '' );
		[ $c, $errors ] = self::clean( $raw );
		update_post_meta( $post_id, self::META, $c );
		if ( $errors ) {
			set_transient( 'pc_promo_errors_' . $post_id, $errors, 120 );
			if ( 'publish' === $post->post_status ) {
				// 规则有错时不启用。
				remove_action( 'save_post_' . self::CPT, array( self::class, 'save' ), 10 );
				wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
				add_action( 'save_post_' . self::CPT, array( self::class, 'save' ), 10, 2 );
			}
			return;
		}
		if ( $c['public'] ) {
			self::ensure_page();
		}
	}

	public static function columns( array $columns ): array {
		$date = $columns['date'] ?? null;
		unset( $columns['date'] );
		$columns['pc_rules']  = __( 'Tiers', 'peptide-core' );
		$columns['pc_dates']  = __( 'Dates', 'peptide-core' );
		$columns['pc_status'] = __( 'Status', 'peptide-core' );
		$columns['pc_public'] = __( 'Public', 'peptide-core' );
		if ( $date ) {
			$columns['date'] = $date;
		}
		return $columns;
	}

	public static function render_column( string $column, int $post_id ): void {
		$c = self::config( $post_id );
		if ( 'pc_rules' === $column ) {
			echo esc_html( implode( ' · ', array_map( static fn( $t ) => self::tier_text( $c, $t ), $c['tiers'] ) ) ?: '—' );
		} elseif ( 'pc_dates' === $column ) {
			$f = static fn( int $ts ) => $ts ? wp_date( 'Y-m-d H:i', $ts ) : '∞';
			echo esc_html( ( $c['start'] ? $f( $c['start'] ) : '—' ) . ' → ' . $f( $c['end'] ) );
		} elseif ( 'pc_status' === $column ) {
			$labels = array(
				'off'      => __( 'Off', 'peptide-core' ),
				'upcoming' => __( 'Scheduled', 'peptide-core' ),
				'active'   => __( 'Running', 'peptide-core' ),
				'ended'    => __( 'Ended', 'peptide-core' ),
			);
			echo esc_html( $labels[ self::status( $post_id ) ] );
		} elseif ( 'pc_public' === $column ) {
			echo $c['public'] ? '<span class="dashicons dashicons-visibility" aria-hidden="true"></span>' : '—';
		}
	}
}
