<?php
/**
 * 公开优惠码（COUPON-01），对应 Shopname 的“优惠码列表页 / 优惠码详情页”。优惠码本身就是 WooCommerce 的优惠券。
 *
 * - 后台：优惠券编辑页“常规”里加“在优惠码页面公开展示”、展示标题、展示说明、排序，并显示列表页 / 分享链接；
 *   优惠券列表多一列“公开”。
 * - 公开条件：已发布、勾选公开、未过期、还有剩余次数、**没有设置允许的邮箱**（指定客户的优惠码不公开，也不泄露邮箱）。
 * - 前台：优惠码页面（短代码 [pc_coupons]，第一次公开优惠码时自动建好已发布的 Coupons 页面）。
 *   不带参数时显示列表，?code=XXX 显示详情。主题可以用 template-parts/coupons/list.php、detail.php 覆盖默认样式。
 * - 分享链接：任何前台网址加 ?pc_coupon=XXX。购物车里有商品时立刻使用；购物车还是空的时先记下，加入商品后自动使用
 *   （还没达到最低消费时继续等待，过期或无效时放弃）。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Coupons {

	public const PUBLIC_META = '_pc_public';
	public const TITLE_META  = '_pc_public_title';
	public const TEXT_META   = '_pc_public_text';
	public const ORDER_META  = '_pc_public_order';

	public const PAGE_OPTION = 'peptide_core_coupons_page';
	public const SHORTCODE   = 'pc_coupons';
	public const APPLY_ARG   = 'pc_coupon';
	public const CODE_ARG    = 'code';

	private const SESSION_KEY = 'pc_pending_coupon';

	public static function register_hooks(): void {
		add_shortcode( self::SHORTCODE, array( self::class, 'shortcode' ) );
		add_action( 'wp_loaded', array( self::class, 'handle_apply_link' ), 30 );
		add_action( 'woocommerce_add_to_cart', array( self::class, 'apply_pending' ), 30 );
		add_action( 'woocommerce_after_cart_item_quantity_update', array( self::class, 'apply_pending' ), 30 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
		if ( is_admin() ) {
			add_action( 'woocommerce_coupon_options', array( self::class, 'render_fields' ), 10, 2 );
			add_action( 'woocommerce_coupon_options_save', array( self::class, 'save_fields' ), 10, 2 );
			add_filter( 'manage_edit-shop_coupon_columns', array( self::class, 'add_column' ), 20 );
			add_action( 'manage_shop_coupon_posts_custom_column', array( self::class, 'render_column' ), 10, 2 );
		}
	}

	// ---------- 公开的优惠码 ----------

	/** 优惠码本身还能不能用：'' / unpublished / expired / used_up。 */
	public static function unusable_reason( \WC_Coupon $coupon ): string {
		if ( 'publish' !== get_post_status( $coupon->get_id() ) ) {
			return 'unpublished';
		}
		$expires = $coupon->get_date_expires();
		if ( $expires && $expires->getTimestamp() < time() ) {
			return 'expired';
		}
		if ( $coupon->get_usage_limit() > 0 && $coupon->get_usage_count() >= $coupon->get_usage_limit() ) {
			return 'used_up';
		}
		return '';
	}

	/** 不能公开的原因（空字符串表示可以公开）：上面的原因，或 private（没勾选公开）/ emails（限定了邮箱）。 */
	public static function hidden_reason( \WC_Coupon $coupon ): string {
		$reason = self::unusable_reason( $coupon );
		if ( '' !== $reason ) {
			return $reason;
		}
		if ( 'yes' !== get_post_meta( $coupon->get_id(), self::PUBLIC_META, true ) ) {
			return 'private';
		}
		return $coupon->get_email_restrictions() ? 'emails' : '';
	}

	/**
	 * 可以公开展示的优惠码，按排序、到期时间排列。
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function public_list(): array {
		$ids = get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'meta_key'       => self::PUBLIC_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'no_found_rows'  => true,
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$coupon = new \WC_Coupon( $id );
			if ( '' === self::hidden_reason( $coupon ) ) {
				$out[] = self::view( $coupon );
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return array( $a['order'], $a['expires'] ?? '9999', $a['code'] ) <=> array( $b['order'], $b['expires'] ?? '9999', $b['code'] );
			}
		);
		return $out;
	}

	/** 一张可以公开的优惠码；不存在或不能公开时返回 null。 */
	public static function get_public( string $code ): ?array {
		$code = wc_format_coupon_code( $code );
		if ( '' === $code ) {
			return null;
		}
		$id = wc_get_coupon_id_by_code( $code );
		if ( ! $id ) {
			return null;
		}
		$coupon = new \WC_Coupon( $id );
		return '' === self::hidden_reason( $coupon ) ? self::view( $coupon ) : null;
	}

	private static function money( $amount ): string {
		return html_entity_decode( wp_strip_all_tags( wc_price( (float) $amount ) ) );
	}

	/** 优惠码的公开信息（不含邮箱限制等内部信息）。 */
	public static function view( \WC_Coupon $coupon ): array {
		$id     = $coupon->get_id();
		$type   = $coupon->get_discount_type();
		$amount = (float) $coupon->get_amount();
		if ( 'percent' === $type ) {
			/* translators: %s: percentage */
			$value = sprintf( __( '%s%% off', 'peptide-core' ), wc_format_localized_decimal( $amount ) );
		} elseif ( 'fixed_product' === $type ) {
			/* translators: %s: amount */
			$value = sprintf( __( '%s off each eligible item', 'peptide-core' ), self::money( $amount ) );
		} else {
			/* translators: %s: amount */
			$value = sprintf( __( '%s off your order', 'peptide-core' ), self::money( $amount ) );
		}
		$names = static function ( array $ids, string $kind ): array {
			$out = array();
			foreach ( array_slice( $ids, 0, 30 ) as $oid ) {
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
		$expires = $coupon->get_date_expires();
		$title   = trim( (string) get_post_meta( $id, self::TITLE_META, true ) );
		$min     = (float) $coupon->get_minimum_amount();
		$max     = (float) $coupon->get_maximum_amount();
		$limit   = (int) $coupon->get_usage_limit();
		return array(
			'id'                  => $id,
			'code'                => strtoupper( $coupon->get_code() ),
			'title'               => '' !== $title ? $title : $value,
			'text'                => (string) get_post_meta( $id, self::TEXT_META, true ),
			'type'                => $type,
			'amount'              => $amount,
			'value'               => $value,
			'free_shipping'       => $coupon->get_free_shipping(),
			'minimum_spend'       => $min > 0 ? $min : null,
			'minimum_spend_text'  => $min > 0 ? self::money( $min ) : '',
			'maximum_spend'       => $max > 0 ? $max : null,
			'maximum_spend_text'  => $max > 0 ? self::money( $max ) : '',
			'expires'             => $expires ? $expires->date( 'c' ) : null,
			'expires_text'        => $expires ? wc_format_datetime( $expires ) : '',
			'uses_left'           => $limit > 0 ? max( 0, $limit - (int) $coupon->get_usage_count() ) : null,
			'limit_per_customer'  => (int) $coupon->get_usage_limit_per_user() ?: null,
			'individual_use'      => $coupon->get_individual_use(),
			'exclude_sale_items'  => $coupon->get_exclude_sale_items(),
			'products'            => $names( $coupon->get_product_ids(), 'product' ),
			'categories'          => $names( $coupon->get_product_categories(), 'category' ),
			'excluded_products'   => $names( $coupon->get_excluded_product_ids(), 'product' ),
			'excluded_categories' => $names( $coupon->get_excluded_product_categories(), 'category' ),
			'order'               => (int) get_post_meta( $id, self::ORDER_META, true ),
			'url'                 => self::detail_url( $coupon->get_code() ),
			'apply_url'           => self::apply_url( $coupon->get_code() ),
		);
	}

	// ---------- 页面与网址 ----------

	public static function page_id(): int {
		$id = (int) get_option( self::PAGE_OPTION, 0 );
		return $id && 'page' === get_post_type( $id ) && 'trash' !== get_post_status( $id ) ? $id : 0;
	}

	/** 优惠码页面不存在时建一个已发布的页面（内容就是短代码）。 */
	public static function ensure_page(): int {
		$id = self::page_id();
		if ( $id ) {
			return $id;
		}
		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Coupons',
				'post_name'    => 'coupons',
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

	public static function detail_url( string $code ): string {
		$list = self::list_url();
		return '' !== $list ? add_query_arg( self::CODE_ARG, rawurlencode( strtoupper( $code ) ), $list ) : '';
	}

	/** 分享链接：打开后自动使用优惠码。有优惠码页面时指向详情页，否则指向商店。 */
	public static function apply_url( string $code ): string {
		$base = self::detail_url( $code );
		$base = '' !== $base ? $base : (string) wc_get_page_permalink( 'shop' );
		return add_query_arg( self::APPLY_ARG, rawurlencode( strtoupper( $code ) ), $base );
	}

	// ---------- 分享链接自动使用 ----------

	public static function handle_apply_link(): void {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! isset( $_GET[ self::APPLY_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$code = wc_format_coupon_code( sanitize_text_field( wp_unslash( $_GET[ self::APPLY_ARG ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $code && function_exists( 'WC' ) && WC()->cart && WC()->session ) {
			self::remember( $code );
		}
		wp_safe_redirect( remove_query_arg( self::APPLY_ARG ) );
		exit;
	}

	/**
	 * 记下优惠码并尽量立刻使用。返回状态：applied / pending / invalid。
	 */
	public static function remember( string $code ): string {
		$code   = wc_format_coupon_code( $code );
		$coupon = new \WC_Coupon( $code );
		if ( ! $coupon->get_id() ) {
			wc_add_notice( __( 'This coupon code does not exist.', 'peptide-core' ), 'error' );
			return 'invalid';
		}
		if ( WC()->cart->has_discount( $code ) ) {
			return 'applied';
		}
		if ( ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}
		$state = self::check( $coupon );
		if ( 'valid' === $state && WC()->cart->apply_coupon( $code ) ) {
			WC()->session->set( self::SESSION_KEY, null );
			return 'applied';
		}
		if ( 'invalid' === $state ) {
			WC()->session->set( self::SESSION_KEY, null );
			wc_add_notice( __( 'This coupon has ended or can no longer be used.', 'peptide-core' ), 'error' );
			return 'invalid';
		}
		WC()->session->set( self::SESSION_KEY, $code );
		/* translators: %s: coupon code */
		wc_add_notice( sprintf( __( 'Coupon %s is saved and will be applied once your cart meets its conditions.', 'peptide-core' ), strtoupper( $code ) ), 'notice' );
		return 'pending';
	}

	/** 不出提示地检查：valid / wait（例如还没达到最低消费、购物车为空）/ invalid。 */
	private static function check( \WC_Coupon $coupon ): string {
		// 分享链接也可以用没有公开的优惠码，只要它本身还能用。
		if ( '' !== self::unusable_reason( $coupon ) ) {
			return 'invalid';
		}
		if ( WC()->cart->is_empty() ) {
			return 'wait';
		}
		// 过期、用完、未发布在上面已经排除；剩下的原因（最低消费、指定商品、每人限用次数等）以后可能满足，继续等待。
		// WooCommerce 返回的错误不带具体原因代码，所以这里不再细分。
		return true === ( new \WC_Discounts( WC()->cart ) )->is_coupon_valid( $coupon ) ? 'valid' : 'wait';
	}

	/** 加入商品或修改数量后，使用之前记下的优惠码。 */
	public static function apply_pending(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->session || ! WC()->cart ) {
			return;
		}
		$code = (string) WC()->session->get( self::SESSION_KEY, '' );
		if ( '' === $code ) {
			return;
		}
		if ( WC()->cart->has_discount( $code ) ) {
			WC()->session->set( self::SESSION_KEY, null );
			return;
		}
		$coupon = new \WC_Coupon( $code );
		$state  = $coupon->get_id() ? self::check( $coupon ) : 'invalid';
		if ( 'valid' === $state ) {
			WC()->cart->apply_coupon( $code );
			WC()->session->set( self::SESSION_KEY, null );
		} elseif ( 'invalid' === $state ) {
			WC()->session->set( self::SESSION_KEY, null );
		}
	}

	// ---------- 前台页面 ----------

	public static function enqueue(): void {
		if ( ! self::page_id() || ! is_page( self::page_id() ) ) {
			return;
		}
		$file = PEPTIDE_CORE_DIR . '/assets/coupons.css';
		wp_enqueue_style( 'peptide-core-coupons', plugins_url( 'assets/coupons.css', PEPTIDE_CORE_FILE ), array(), PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( $file ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/** [pc_coupons]：列表；地址带 ?code= 时显示这一张的详情。 */
	public static function shortcode(): string {
		$code = isset( $_GET[ self::CODE_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::CODE_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $code ) {
			return self::render( 'detail', array( 'coupon' => self::get_public( $code ), 'list_url' => self::list_url() ) );
		}
		return self::render( 'list', array( 'coupons' => self::public_list() ) );
	}

	/** 主题有 template-parts/coupons/{list|detail}.php 时用主题的，否则用插件默认样式。 */
	private static function render( string $view, array $args ): string {
		ob_start();
		if ( locate_template( 'template-parts/coupons/' . $view . '.php' ) ) {
			get_template_part( 'template-parts/coupons/' . $view, null, $args );
		} elseif ( 'detail' === $view ) {
			self::fallback_detail( $args['coupon'], $args['list_url'] );
		} else {
			self::fallback_list( $args['coupons'] );
		}
		return (string) ob_get_clean();
	}

	private static function conditions( array $c ): array {
		$out = array();
		if ( $c['minimum_spend_text'] ) {
			/* translators: %s: amount */
			$out[] = sprintf( __( 'Minimum spend %s', 'peptide-core' ), $c['minimum_spend_text'] );
		}
		if ( $c['maximum_spend_text'] ) {
			/* translators: %s: amount */
			$out[] = sprintf( __( 'Maximum spend %s', 'peptide-core' ), $c['maximum_spend_text'] );
		}
		if ( $c['free_shipping'] ) {
			$out[] = __( 'Includes free shipping', 'peptide-core' );
		}
		if ( $c['expires_text'] ) {
			/* translators: %s: date */
			$out[] = sprintf( __( 'Valid until %s', 'peptide-core' ), $c['expires_text'] );
		}
		if ( null !== $c['uses_left'] ) {
			/* translators: %d: number of uses */
			$out[] = sprintf( _n( '%d use left', '%d uses left', $c['uses_left'], 'peptide-core' ), $c['uses_left'] );
		}
		if ( $c['limit_per_customer'] ) {
			/* translators: %d: number of uses */
			$out[] = sprintf( _n( 'Limit %d per customer', 'Limit %d per customer', $c['limit_per_customer'], 'peptide-core' ), $c['limit_per_customer'] );
		}
		if ( $c['individual_use'] ) {
			$out[] = __( 'Cannot be combined with other coupons', 'peptide-core' );
		}
		if ( $c['exclude_sale_items'] ) {
			$out[] = __( 'Not valid on sale items', 'peptide-core' );
		}
		return $out;
	}

	private static function copy_button( string $code ): string {
		return '<button type="button" class="pc-coupon__copy" data-pc-copy="' . esc_attr( $code ) . '" data-copied="' . esc_attr__( 'Copied', 'peptide-core' ) . '">' . esc_html__( 'Copy code', 'peptide-core' ) . '</button>';
	}

	private static function copy_script(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		echo "<script>document.addEventListener('click',function(e){var b=e.target.closest&&e.target.closest('[data-pc-copy]');if(!b)return;var t=b.getAttribute('data-pc-copy');var ok=function(){var o=b.textContent;b.textContent=b.getAttribute('data-copied');setTimeout(function(){b.textContent=o;},1500);};if(navigator.clipboard){navigator.clipboard.writeText(t).then(ok);}else{var i=document.createElement('input');i.value=t;document.body.appendChild(i);i.select();document.execCommand('copy');i.remove();ok();}});</script>";
	}

	private static function fallback_list( array $coupons ): void {
		if ( ! $coupons ) {
			echo '<p class="pc-coupons__empty">' . esc_html__( 'There are no public offers right now. Please check back later.', 'peptide-core' ) . '</p>';
			return;
		}
		echo '<div class="pc-coupons">';
		foreach ( $coupons as $c ) {
			echo '<article class="pc-coupon">';
			echo '<div class="pc-coupon__value">' . esc_html( $c['title'] ) . '</div>';
			if ( '' !== $c['text'] ) {
				echo '<p class="pc-coupon__text">' . esc_html( $c['text'] ) . '</p>';
			}
			$conditions = self::conditions( $c );
			if ( $conditions ) {
				echo '<p class="pc-coupon__terms">' . esc_html( implode( ' · ', $conditions ) ) . '</p>';
			}
			echo '<div class="pc-coupon__actions"><code class="pc-coupon__code">' . esc_html( $c['code'] ) . '</code>' . self::copy_button( $c['code'] ) . '<a class="pc-coupon__more" href="' . esc_url( $c['url'] ) . '">' . esc_html__( 'Details', 'peptide-core' ) . '</a></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- copy_button 已转义。
			echo '</article>';
		}
		echo '</div>';
		self::copy_script();
	}

	private static function fallback_detail( ?array $c, string $list_url ): void {
		if ( ! $c ) {
			echo '<p class="pc-coupons__empty">' . esc_html__( 'This offer is not available. It may have ended.', 'peptide-core' ) . '</p>';
			if ( '' !== $list_url ) {
				echo '<p><a href="' . esc_url( $list_url ) . '">' . esc_html__( 'See current offers', 'peptide-core' ) . '</a></p>';
			}
			return;
		}
		echo '<article class="pc-coupon pc-coupon--detail">';
		echo '<div class="pc-coupon__value">' . esc_html( $c['title'] ) . '</div>';
		if ( '' !== $c['text'] ) {
			echo '<p class="pc-coupon__text">' . esc_html( $c['text'] ) . '</p>';
		}
		echo '<div class="pc-coupon__actions"><code class="pc-coupon__code">' . esc_html( $c['code'] ) . '</code>' . self::copy_button( $c['code'] ) . '<a class="pc-coupon__apply" href="' . esc_url( $c['apply_url'] ) . '">' . esc_html__( 'Apply to my cart', 'peptide-core' ) . '</a></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- copy_button 已转义。
		$conditions = self::conditions( $c );
		if ( $conditions ) {
			echo '<ul class="pc-coupon__list">';
			foreach ( $conditions as $line ) {
				echo '<li>' . esc_html( $line ) . '</li>';
			}
			echo '</ul>';
		}
		$groups = array(
			'products'            => __( 'Valid on these products', 'peptide-core' ),
			'categories'          => __( 'Valid in these categories', 'peptide-core' ),
			'excluded_products'   => __( 'Not valid on these products', 'peptide-core' ),
			'excluded_categories' => __( 'Not valid in these categories', 'peptide-core' ),
		);
		foreach ( $groups as $key => $label ) {
			if ( ! $c[ $key ] ) {
				continue;
			}
			echo '<h3 class="pc-coupon__heading">' . esc_html( $label ) . '</h3><ul class="pc-coupon__list">';
			foreach ( $c[ $key ] as $item ) {
				echo '<li>' . ( '' !== $item['url'] ? '<a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['name'] ) . '</a>' : esc_html( $item['name'] ) ) . '</li>';
			}
			echo '</ul>';
		}
		if ( '' !== $list_url ) {
			echo '<p><a class="pc-coupon__more" href="' . esc_url( $list_url ) . '">' . esc_html__( 'All offers', 'peptide-core' ) . '</a></p>';
		}
		echo '</article>';
		self::copy_script();
	}

	// ---------- 后台 ----------

	public static function render_fields( int $coupon_id, \WC_Coupon $coupon ): void {
		echo '<div class="options_group pc-coupon-public">';
		woocommerce_wp_checkbox(
			array(
				'id'          => self::PUBLIC_META,
				'label'       => __( 'Show publicly', 'peptide-core' ),
				'description' => __( 'List this coupon on the storefront coupons page so customers can copy it.', 'peptide-core' ),
				'value'       => 'yes' === get_post_meta( $coupon_id, self::PUBLIC_META, true ) ? 'yes' : 'no',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => self::TITLE_META,
				'label'       => __( 'Public title', 'peptide-core' ),
				'placeholder' => __( 'e.g. 10% off your first order (empty = automatic)', 'peptide-core' ),
				'value'       => (string) get_post_meta( $coupon_id, self::TITLE_META, true ),
			)
		);
		woocommerce_wp_textarea_input(
			array(
				'id'    => self::TEXT_META,
				'label' => __( 'Public description', 'peptide-core' ),
				'value' => (string) get_post_meta( $coupon_id, self::TEXT_META, true ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => self::ORDER_META,
				'label'             => __( 'Sort order', 'peptide-core' ),
				'type'              => 'number',
				'custom_attributes' => array( 'step' => '1' ),
				'value'             => (string) (int) get_post_meta( $coupon_id, self::ORDER_META, true ),
			)
		);
		$code = $coupon->get_code();
		if ( '' !== $code ) {
			$reason  = self::hidden_reason( $coupon );
			$reasons = array(
				'unpublished' => __( 'Not shown: the coupon is not published.', 'peptide-core' ),
				'private'     => __( 'Not shown: “Show publicly” is off.', 'peptide-core' ),
				'emails'      => __( 'Not shown: coupons limited to specific emails are never listed publicly.', 'peptide-core' ),
				'expired'     => __( 'Not shown: the coupon has expired.', 'peptide-core' ),
				'used_up'     => __( 'Not shown: the usage limit has been reached.', 'peptide-core' ),
			);
			echo '<p class="form-field"><label>' . esc_html__( 'Storefront', 'peptide-core' ) . '</label>';
			if ( '' === $reason && '' !== self::detail_url( $code ) ) {
				echo '<a href="' . esc_url( self::detail_url( $code ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'View on the coupons page', 'peptide-core' ) . '</a>';
			} elseif ( isset( $reasons[ $reason ] ) ) {
				echo esc_html( $reasons[ $reason ] );
			}
			echo '</p><p class="form-field"><label>' . esc_html__( 'Share link', 'peptide-core' ) . '</label><input type="text" readonly class="short" onfocus="this.select()" value="' . esc_attr( self::apply_url( $code ) ) . '"> <span class="description">' . esc_html__( 'Opening this link applies the coupon automatically.', 'peptide-core' ) . '</span></p>';
		}
		echo '</div>';
	}

	public static function save_fields( int $post_id, \WC_Coupon $coupon ): void {
		// WooCommerce 已经校验过 nonce 和权限（woocommerce_meta_nonce）。
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$public = isset( $_POST[ self::PUBLIC_META ] );
		update_post_meta( $post_id, self::PUBLIC_META, $public ? 'yes' : 'no' );
		$title = isset( $_POST[ self::TITLE_META ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::TITLE_META ] ) ) : '';
		$text  = isset( $_POST[ self::TEXT_META ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ self::TEXT_META ] ) ) : '';
		update_post_meta( $post_id, self::TITLE_META, function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 80 ) : substr( $title, 0, 80 ) );
		update_post_meta( $post_id, self::TEXT_META, function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 500 ) : substr( $text, 0, 500 ) );
		update_post_meta( $post_id, self::ORDER_META, isset( $_POST[ self::ORDER_META ] ) ? (int) $_POST[ self::ORDER_META ] : 0 );
		// phpcs:enable
		if ( $public ) {
			self::ensure_page();
		}
	}

	public static function add_column( array $columns ): array {
		$columns['pc_public'] = __( 'Public', 'peptide-core' );
		return $columns;
	}

	public static function render_column( string $column, int $post_id ): void {
		if ( 'pc_public' !== $column ) {
			return;
		}
		$reason = self::hidden_reason( new \WC_Coupon( $post_id ) );
		if ( '' === $reason ) {
			echo '<span class="dashicons dashicons-visibility" aria-hidden="true"></span> ' . esc_html__( 'Listed', 'peptide-core' );
		} elseif ( 'private' !== $reason && 'yes' === get_post_meta( $post_id, self::PUBLIC_META, true ) ) {
			echo esc_html__( 'Public, but not listed now', 'peptide-core' );
		} else {
			echo '—';
		}
	}
}
