<?php
/**
 * 营销活动（MARKETING-01）：满减活动、优惠码、运费折扣三个页面共用的部分，版式参照 Shopname 的“营销活动”。
 *
 * - 列表页：标题 + 新建按钮、全部 / 未开始 / 进行中 / 已结束标签、搜索（最多 32 字）、表格（勾选、编辑、停用 / 启用、状态）、
 *   批量启用 / 停用 / 删除、分页（每页条数、跳到第几页）。
 * - 编辑页：返回、白色卡片（左边是分组名）、问号说明、带字数的输入框、活动时间（时间段 / 长期，店铺时区）、
 *   适用顾客、适用范围（全部商品 / 部分商品 / 专辑）、底部固定的保存栏。
 * - 原来的 WooCommerce 营销菜单（概览、优惠券）和满减活动的文章列表不再使用，打开旧地址时转到新页面。
 *   需要 WooCommerce 优惠券的其他设置（排除商品、最高消费等）时，可以从优惠码编辑页进入 WooCommerce 原编辑页（地址带 pc_classic=1）。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Promotions;

defined( 'ABSPATH' ) || exit;

final class Marketing {

	public const CAP      = 'edit_shop_coupons';
	public const PER_PAGE = array( 10, 20, 50, 100 );
	public const TABS     = array( 'all', 'upcoming', 'active', 'ended' );

	/** 保存出错时，表单内容暂存到这里，回到编辑页时原样显示。 */
	private const FORM_KEY = 'pc_mk_form_';

	public static function register_hooks(): void {
		add_action( 'admin_init', array( self::class, 'redirect_old_screens' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	public static function pages(): array {
		return array( Promotions_Page::SLUG, Coupons_Page::SLUG, Shipping_Discounts_Page::SLUG );
	}

	/** 旧地址转到新页面：WooCommerce 的营销概览、优惠券列表 / 编辑页，满减活动的文章列表 / 编辑页。 */
	public static function redirect_old_screens(): void {
		global $pagenow;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( wp_doing_ajax() || ! current_user_can( self::CAP ) || ! empty( $_GET['pc_classic'] ) || 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
			return;
		}
		$type   = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
		$post   = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$path   = isset( $_GET['path'] ) ? sanitize_text_field( wp_unslash( $_GET['path'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$slugs = array( 'shop_coupon' => Coupons_Page::SLUG, Promotions::CPT => Promotions_Page::SLUG );
		$to    = '';
		if ( 'edit.php' === $pagenow && isset( $slugs[ $type ] ) ) {
			$to = self::url( $slugs[ $type ] );
		} elseif ( 'post-new.php' === $pagenow && isset( $slugs[ $type ] ) ) {
			$to = self::url( $slugs[ $type ], array( 'new' => 1 ) );
		} elseif ( 'post.php' === $pagenow && $post && 'edit' === $action && isset( $slugs[ get_post_type( $post ) ] ) ) {
			$to = self::url( $slugs[ get_post_type( $post ) ], array( 'edit' => $post ) );
		} elseif ( 'admin.php' === $pagenow && 'wc-admin' === $page && str_starts_with( $path, '/marketing' ) ) {
			$to = self::url( Promotions_Page::SLUG );
		}
		if ( '' !== $to ) {
			wp_safe_redirect( $to );
			exit;
		}
	}

	public static function url( string $slug, array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . $slug ) );
	}

	public static function enqueue(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $page, self::pages(), true ) ) {
			return;
		}
		self::enqueue_assets();
	}

	/** 营销活动的表单样式和脚本（应用中心的设置页也用）。 */
	public static function enqueue_assets(): void {
		$ver = static fn( string $f ): string => PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( PEPTIDE_CORE_DIR . '/assets/' . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_style( 'peptide-core-store-settings', plugins_url( 'assets/store-settings.css', PEPTIDE_CORE_FILE ), array(), $ver( 'store-settings.css' ) );
		wp_enqueue_style( 'peptide-core-plans', plugins_url( 'assets/plans.css', PEPTIDE_CORE_FILE ), array( 'peptide-core-store-settings' ), $ver( 'plans.css' ) );
		wp_enqueue_style( 'peptide-core-marketing', plugins_url( 'assets/marketing.css', PEPTIDE_CORE_FILE ), array( 'peptide-core-plans' ), $ver( 'marketing.css' ) );
		wp_enqueue_script( 'peptide-core-marketing', plugins_url( 'assets/marketing.js', PEPTIDE_CORE_FILE ), array( 'jquery', 'wc-enhanced-select' ), $ver( 'marketing.js' ), true );
		wp_localize_script(
			'peptide-core-marketing',
			'pcMarketing',
			array(
				'currency' => get_woocommerce_currency(),
				'maxTiers' => Promotions::MAX_TIERS,
				'strings'  => array(
					/* translators: %d: tier number */
					'tier'         => __( 'Tier %d', 'peptide-core' ),
					/* translators: %d: stage number */
					'stage'        => __( 'Stage %d', 'peptide-core' ),
					'remove'       => __( 'Delete', 'peptide-core' ),
					'confirmDel'   => __( 'Delete the selected items?', 'peptide-core' ),
					'pickFirst'    => __( 'Select at least one item first.', 'peptide-core' ),
					/* translators: %s: promotion name */
					'inOther'      => __( 'Already in “%s” – saving will be refused if the times overlap.', 'peptide-core' ),
					'noProducts'   => __( 'No products selected.', 'peptide-core' ),
				),
			)
		);
	}

	// ---------- 小部件 ----------

	/** 问号说明（深色提示框，和基础配置共用样式）。 */
	public static function help( string $tip ): string {
		return '<span class="pc-bc-help" tabindex="0" data-tip="' . esc_attr( $tip ) . '"><span class="dashicons dashicons-editor-help" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html( $tip ) . '</span></span>';
	}

	/** 字段标题：必填星号、问号说明。 */
	public static function label( string $text, bool $required = false, string $tip = '', string $for = '' ): string {
		return '<label class="pc-mk-label"' . ( '' !== $for ? ' for="' . esc_attr( $for ) . '"' : '' ) . '>' . ( $required ? '<span class="pc-bc-req">*</span> ' : '' ) . esc_html( $text ) . '</label>' . ( '' !== $tip ? ' ' . self::help( $tip ) : '' );
	}

	/** 带字数统计的输入框（例如 0/50）。 */
	public static function counted( string $name, string $value, int $max, string $placeholder = '', string $id = '', bool $required = false ): string {
		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
		return '<span class="pc-mk-count"><input type="text" name="' . esc_attr( $name ) . '"' . ( '' !== $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . ' value="' . esc_attr( $value ) . '" maxlength="' . (int) $max . '" placeholder="' . esc_attr( $placeholder ) . '"' . ( $required ? ' required' : '' ) . '><small>' . (int) $len . '/' . (int) $max . '</small></span>';
	}

	public static function radio( string $name, string $value, string $current, string $text, bool $disabled = false ): string {
		return '<label class="pc-mk-radio' . ( $disabled ? ' is-disabled' : '' ) . '"><input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . checked( $value, $current, false ) . disabled( $disabled, true, false ) . '> ' . esc_html( $text ) . '</label>';
	}

	/** 白色卡片，左边是分组名。 */
	public static function card_open( string $title, string $extra_class = '', string $tip = '' ): void {
		echo '<section class="pc-mk-card ' . esc_attr( $extra_class ) . '"><div class="pc-mk-card__title">' . esc_html( $title ) . ( '' !== $tip ? ' ' . self::help( $tip ) : '' ) . '</div><div class="pc-mk-card__body">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- help() 已转义。
	}

	public static function card_close(): void {
		echo '</div></section>';
	}

	/** “GMT+08:00” 形式的店铺时区。 */
	public static function tz_label(): string {
		$offset = wp_timezone()->getOffset( new \DateTimeImmutable( 'now', wp_timezone() ) );
		$sign   = $offset < 0 ? '-' : '+';
		$offset = abs( $offset );
		return sprintf( 'GMT%s%02d:%02d', $sign, intdiv( $offset, 3600 ), intdiv( $offset % 3600, 60 ) );
	}

	public static function local_input( int $ts ): string {
		return $ts ? wp_date( 'Y-m-d\TH:i:s', $ts ) : '';
	}

	public static function from_local( $value ): int {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value ) {
			return 0;
		}
		$d = date_create_immutable( str_replace( 'T', ' ', $value ), wp_timezone() );
		return $d ? $d->getTimestamp() : 0;
	}

	public static function date_text( int $ts ): string {
		return $ts ? wp_date( 'Y-m-d H:i:s', $ts ) : '';
	}

	/** 活动时间：时间段 / 长期 + 开始、结束。$long 为 true 表示长期（没有结束时间）。 */
	public static function time_fields( int $start, int $end, bool $long, string $prefix = '' ): void {
		echo '<div class="pc-mk-field pc-mk-time">';
		/* translators: %s: time zone, e.g. GMT+08:00 */
		echo '<div class="pc-mk-label-row"><span class="pc-mk-label">' . esc_html__( 'Promotion time', 'peptide-core' ) . '</span> <span class="pc-pl-muted">(' . esc_html( self::tz_label() ) . ')</span>' . ( current_user_can( 'manage_options' ) ? ' <a href="' . esc_url( admin_url( 'admin.php?page=' . Store_Settings::SYSTEM_SLUG . '&section=basic' ) ) . '">' . esc_html__( 'Set time zone', 'peptide-core' ) . '</a>' : '' ) . '</div>';
		echo '<div class="pc-mk-radios">' . self::radio( $prefix . 'time_mode', 'range', $long ? 'long' : 'range', __( 'Time period', 'peptide-core' ) ) . self::radio( $prefix . 'time_mode', 'long', $long ? 'long' : 'range', __( 'Long-term', 'peptide-core' ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="pc-mk-range"><input type="datetime-local" step="1" name="' . esc_attr( $prefix ) . 'start" value="' . esc_attr( self::local_input( $start ? $start : time() ) ) . '" aria-label="' . esc_attr__( 'Start time', 'peptide-core' ) . '">';
		echo '<span class="pc-mk-range__to">' . esc_html__( 'to', 'peptide-core' ) . '</span><input type="datetime-local" step="1" class="pc-mk-end" name="' . esc_attr( $prefix ) . 'end" value="' . esc_attr( $long ? '' : self::local_input( $end ) ) . '" aria-label="' . esc_attr__( 'End time', 'peptide-core' ) . '"' . ( $long ? ' disabled' : '' ) . '></div>';
		echo '</div>';
	}

	/** 读取活动时间：[start, end, errors]。时间段必须有结束时间。 */
	public static function read_time( array $in, string $prefix = '' ): array {
		$long  = 'long' === ( $in[ $prefix . 'time_mode' ] ?? '' );
		$start = self::from_local( $in[ $prefix . 'start' ] ?? '' );
		$end   = $long ? 0 : self::from_local( $in[ $prefix . 'end' ] ?? '' );
		$errors = array();
		if ( ! $start ) {
			$errors[] = __( 'Choose the start time.', 'peptide-core' );
		}
		if ( ! $long && ! $end ) {
			$errors[] = __( 'Choose the end time, or choose “Long-term”.', 'peptide-core' );
		}
		return array( $start, $end, $errors );
	}

	/** 适用顾客：所有人 / 指定顾客（邮箱）；顾客标签、级别、访客 ID 本站没有，显示为不可选并说明。 */
	public static function customers_card( string $mode, array $emails, bool $visitor_id = false, string $tip = '' ): void {
		self::card_open( __( 'Eligible customers', 'peptide-core' ), 'pc-mk-customers', $tip );
		echo '<div class="pc-mk-stack">';
		echo self::radio( 'customers', 'all', $mode, __( 'Everyone', 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::radio( 'customers', 'emails', $mode, __( 'Specific customers', 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="pc-mk-sub" data-show="customers=emails"><textarea name="emails" rows="4" class="large-text" placeholder="' . esc_attr__( 'One email per line, e.g. buyer@example.com', 'peptide-core' ) . '">' . esc_textarea( implode( "\n", $emails ) ) . '</textarea><p class="pc-bc-note">' . esc_html__( 'Matched against the email entered at checkout (or the account email of a signed-in customer).', 'peptide-core' ) . '</p></div>';
		$na = array( 'tags' => __( 'Specific customer tags', 'peptide-core' ) );
		if ( $visitor_id ) {
			$na['visitor'] = __( 'Specific visitor IDs', 'peptide-core' );
		}
		$na['levels'] = __( 'Specific customer levels', 'peptide-core' );
		foreach ( $na as $k => $text ) {
			echo self::radio( 'customers_na', $k, '', $text, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '<p class="pc-bc-note">' . esc_html( $visitor_id ? __( 'Customer tags and levels do not exist on this site yet, and visitor tracking is still a test feature, so these options cannot be chosen.', 'peptide-core' ) : __( 'Customer tags and levels do not exist on this site yet, so these options cannot be chosen.', 'peptide-core' ) ) . '</p>';
		echo '</div>';
		self::card_close();
	}

	/**
	 * 适用范围：全部商品 / 部分商品（商品表格）/ 专辑（商品分类）+ 活动商品默认排序。
	 *
	 * @param array<string,string> $notes    all / products / categories => 说明
	 * @param array<int,string>    $in_other 商品 ID => 已参加的其他活动名称（满减活动用）
	 */
	public static function scope_card( string $scope, array $products, array $categories, string $sort, array $notes, array $in_other = array() ): void {
		self::card_open( __( 'Scope', 'peptide-core' ), 'pc-mk-scope' );
		$opts = array(
			'all'        => __( 'Choose all products', 'peptide-core' ),
			'products'   => __( 'Choose some products', 'peptide-core' ),
			'categories' => __( 'Choose collections', 'peptide-core' ),
		);
		foreach ( $opts as $k => $text ) {
			echo '<div class="pc-mk-option"><label class="pc-mk-big"><input type="radio" name="scope" value="' . esc_attr( $k ) . '"' . checked( $k, $scope, false ) . '> ' . esc_html( $text ) . '</label><p class="pc-mk-hint">' . esc_html( $notes[ $k ] ?? '' ) . '</p>';
			if ( 'products' === $k ) {
				echo '<div class="pc-mk-sub" data-show="scope=products">';
				self::products_table( $products, $in_other );
				echo '</div>';
			} elseif ( 'categories' === $k ) {
				echo '<div class="pc-mk-sub" data-show="scope=categories">';
				self::categories_list( $categories );
				echo '</div>';
			}
			echo '</div>';
		}
		echo '<div class="pc-mk-field pc-mk-sort">' . self::label( __( 'Default product order', 'peptide-core' ), false, __( 'The order of the products on the storefront page of this promotion. Customers can still re-sort the list.', 'peptide-core' ), 'pc-mk-sort' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<select name="sort" id="pc-mk-sort">';
		foreach ( self::sort_labels() as $k => $text ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $k, $sort, false ) . '>' . esc_html( $text ) . '</option>';
		}
		echo '</select></div>';
		self::card_close();
	}

	public static function sort_labels(): array {
		return array(
			'date_desc'  => __( 'Newest first', 'peptide-core' ),
			'date_asc'   => __( 'Oldest first', 'peptide-core' ),
			'price_asc'  => __( 'Price: low to high', 'peptide-core' ),
			'price_desc' => __( 'Price: high to low', 'peptide-core' ),
			'sales_desc' => __( 'Best selling', 'peptide-core' ),
			'title_asc'  => __( 'Name: A to Z', 'peptide-core' ),
		);
	}

	/** 选中的商品：商品搜索 + 表格（图片、名称、SKU、状态）。 */
	public static function products_table( array $ids, array $in_other = array() ): void {
		echo '<div class="pc-mk-products"><div class="pc-mk-products__bar"><select class="wc-product-search pc-mk-product-search" data-placeholder="' . esc_attr__( 'Search products to add…', 'peptide-core' ) . '" data-action="woocommerce_json_search_products" data-allow_clear="true"></select></div>';
		echo '<table class="pc-pl-table pc-pl-products"><thead><tr><th class="pc-pl-col-act">' . esc_html__( 'Actions', 'peptide-core' ) . '</th><th>' . esc_html__( 'Product', 'peptide-core' ) . '</th><th class="pc-pl-col-status">' . esc_html__( 'Status', 'peptide-core' ) . '</th></tr></thead><tbody>';
		foreach ( $ids as $pid ) {
			$p = wc_get_product( $pid );
			if ( ! $p ) {
				continue;
			}
			$row = Plans_Editor::product_row( $p );
			if ( isset( $in_other[ (int) $pid ] ) ) {
				/* translators: %s: promotion name */
				$row = str_replace( '</strong>', '</strong><small class="pc-pl-warn">' . esc_html( sprintf( __( 'Already in “%s” – saving will be refused if the times overlap.', 'peptide-core' ), $in_other[ (int) $pid ] ) ) . '</small>', $row );
			}
			echo $row; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- product_row() 已转义。
		}
		echo '<tr class="pc-pl-products__empty"' . ( $ids ? ' hidden' : '' ) . '><td colspan="3" class="pc-pl-muted">' . esc_html__( 'No products selected.', 'peptide-core' ) . '</td></tr>';
		echo '</tbody></table><script type="application/json" class="pc-mk-in-other">' . wp_json_encode( (object) $in_other ) . '</script></div>';
	}

	/** 专辑 = 商品分类，勾选。 */
	public static function categories_list( array $selected ): void {
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name' ) );
		if ( is_wp_error( $terms ) || ! $terms ) {
			echo '<p class="pc-pl-muted">' . esc_html__( 'There are no collections yet.', 'peptide-core' ) . ' <a href="' . esc_url( admin_url( 'edit-tags.php?taxonomy=product_cat&post_type=product' ) ) . '">' . esc_html__( 'Add collections', 'peptide-core' ) . '</a></p>';
			return;
		}
		echo '<div class="pc-pl-checklist pc-mk-cats">';
		foreach ( $terms as $t ) {
			/* translators: 1: collection name, 2: number of products */
			echo '<label><input type="checkbox" name="categories[]" value="' . (int) $t->term_id . '"' . checked( in_array( (int) $t->term_id, $selected, true ), true, false ) . '> ' . esc_html( html_entity_decode( $t->name ) ) . ' <span class="pc-pl-muted">(' . (int) $t->count . ')</span></label>';
		}
		echo '</div>';
	}

	/** 右上角的 SEO 卡片：前台页面地址、编辑页面、查看页面。 */
	public static function seo_card( string $url, int $page_id, string $empty_note ): void {
		echo '<aside class="pc-mk-card pc-mk-seo"><div class="pc-mk-seo__head"><strong>SEO</strong><span>';
		if ( $page_id ) {
			echo '<a href="' . esc_url( (string) get_edit_post_link( $page_id ) ) . '"><span class="dashicons dashicons-edit" aria-hidden="true"></span>' . esc_html__( 'Edit page', 'peptide-core' ) . '</a>';
		}
		if ( '' !== $url ) {
			echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener"><span class="dashicons dashicons-visibility" aria-hidden="true"></span>' . esc_html__( 'View', 'peptide-core' ) . '</a>';
		}
		echo '</span></div>';
		echo '' !== $url ? '<p class="pc-mk-seo__url">' . esc_html( $url ) . '</p>' : '<p class="pc-pl-muted">' . esc_html( $empty_note ) . '</p>';
		echo '</aside>';
	}

	// ---------- 列表页 ----------

	/** 列表页顶部：标题、设置、新建按钮。 */
	public static function list_head( string $title, string $new_url, string $new_label, bool $settings = false ): void {
		echo '<div class="pc-pl-head pc-mk-head"><h1>' . esc_html( $title ) . '</h1>';
		if ( $settings ) {
			echo '<button type="button" class="button-link pc-mk-settings-open"><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span> ' . esc_html__( 'Settings', 'peptide-core' ) . '</button>';
		}
		echo '<a class="button button-primary pc-pl-new" href="' . esc_url( $new_url ) . '">+ ' . esc_html( $new_label ) . '</a></div>';
	}

	public static function current_tab(): string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $tab, self::TABS, true ) ? $tab : 'all';
	}

	public static function current_search(): string {
		$s = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, 32 ) : substr( $s, 0, 32 );
	}

	/** @return array{0:int,1:int} [第几页, 每页条数] */
	public static function current_paging(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$per  = isset( $_GET['per_page'] ) ? absint( $_GET['per_page'] ) : 50;
		$page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return array( $page, in_array( $per, self::PER_PAGE, true ) ? $per : 50 );
	}

	/** 标签 + 搜索框（+ 右边的提示）。 */
	public static function tabs_and_search( string $slug, string $placeholder, string $note = '' ): void {
		$tab    = self::current_tab();
		$labels = array(
			'all'      => __( 'All', 'peptide-core' ),
			'upcoming' => __( 'Not started', 'peptide-core' ),
			'active'   => __( 'In progress', 'peptide-core' ),
			'ended'    => __( 'Ended', 'peptide-core' ),
		);
		echo '<nav class="pc-pl-tabs">';
		foreach ( $labels as $k => $text ) {
			echo '<a href="' . esc_url( self::url( $slug, array( 'tab' => $k ) ) ) . '" class="' . ( $k === $tab ? 'is-active' : '' ) . '">' . esc_html( $text ) . '</a>';
		}
		echo '</nav><div class="pc-mk-toolbar"><form method="get" class="pc-mk-search"><input type="hidden" name="page" value="' . esc_attr( $slug ) . '"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
		echo self::counted( 's', self::current_search(), 32, $placeholder ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<button class="button pc-mk-search__btn" aria-label="' . esc_attr__( 'Search', 'peptide-core' ) . '"><span class="dashicons dashicons-search" aria-hidden="true"></span></button></form>';
		if ( '' !== $note ) {
			echo '<p class="pc-mk-note">' . esc_html( $note ) . '</p>';
		}
		echo '</div>';
	}

	public static function status_label( string $status ): string {
		$labels = array(
			'off'      => __( 'Disabled', 'peptide-core' ),
			'upcoming' => __( 'Not started', 'peptide-core' ),
			'active'   => __( 'In progress', 'peptide-core' ),
			'ended'    => __( 'Ended', 'peptide-core' ),
		);
		return $labels[ $status ] ?? $status;
	}

	public static function badge( string $status ): string {
		return '<span class="pc-mk-badge is-' . esc_attr( $status ) . '">' . esc_html( self::status_label( $status ) ) . '</span>';
	}

	/** 批量操作的表单开头（表格里的勾选框属于这个表单）。 */
	public static function bulk_open( string $action ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pc-mk-bulk"><input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( $action );
		echo '<div class="pc-mk-bulkbar" hidden><span class="pc-mk-bulkbar__count"></span><select name="bulk"><option value="enable">' . esc_html__( 'Enable', 'peptide-core' ) . '</option><option value="disable">' . esc_html__( 'Disable', 'peptide-core' ) . '</option><option value="delete">' . esc_html__( 'Delete', 'peptide-core' ) . '</option></select> <button class="button">' . esc_html__( 'Apply', 'peptide-core' ) . '</button></div>';
	}

	/** 一行的操作：编辑、停用 / 启用。 */
	public static function row_actions( string $edit_url, string $toggle_url, bool $enabled ): string {
		return '<a class="pc-mk-icon" href="' . esc_url( $edit_url ) . '" title="' . esc_attr__( 'Edit', 'peptide-core' ) . '"><span class="dashicons dashicons-edit" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'Edit', 'peptide-core' ) . '</span></a>'
			. '<a class="pc-mk-icon" href="' . esc_url( $toggle_url ) . '" title="' . esc_attr( $enabled ? __( 'Disable', 'peptide-core' ) : __( 'Enable', 'peptide-core' ) ) . '"><span class="dashicons ' . ( $enabled ? 'dashicons-dismiss' : 'dashicons-yes-alt' ) . '" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html( $enabled ? __( 'Disable', 'peptide-core' ) : __( 'Enable', 'peptide-core' ) ) . '</span></a>';
	}

	public static function check_cell( int $id ): string {
		return '<td class="pc-mk-col-check"><input type="checkbox" name="ids[]" value="' . (int) $id . '" aria-label="' . esc_attr__( 'Select', 'peptide-core' ) . '"></td>';
	}

	public static function check_head(): string {
		return '<th class="pc-mk-col-check"><input type="checkbox" class="pc-mk-check-all" aria-label="' . esc_attr__( 'Select all', 'peptide-core' ) . '"></th>';
	}

	/** 分页：共 N 条、上一页 / 页码 / 下一页、每页条数、前往第几页。 */
	public static function pager( string $slug, int $total, int $page, int $per ): void {
		$pages = max( 1, (int) ceil( $total / $per ) );
		$args  = array( 'tab' => self::current_tab(), 's' => self::current_search(), 'per_page' => $per );
		$link  = static fn( int $p ) => self::url( $slug, $args + array( 'paged' => $p ) );
		/* translators: %d: number of records */
		echo '<div class="pc-mk-pager"><span>' . esc_html( sprintf( _n( '%d record', '%d records', $total, 'peptide-core' ), $total ) ) . '</span>';
		echo $page > 1 ? '<a class="pc-mk-page" href="' . esc_url( $link( $page - 1 ) ) . '" aria-label="' . esc_attr__( 'Previous page', 'peptide-core' ) . '">‹</a>' : '<span class="pc-mk-page is-disabled">‹</span>';
		for ( $p = max( 1, $page - 3 ); $p <= min( $pages, $page + 3 ); $p++ ) {
			echo $p === $page ? '<span class="pc-mk-page is-current">' . (int) $p . '</span>' : '<a class="pc-mk-page" href="' . esc_url( $link( $p ) ) . '">' . (int) $p . '</a>';
		}
		echo $page < $pages ? '<a class="pc-mk-page" href="' . esc_url( $link( $page + 1 ) ) . '" aria-label="' . esc_attr__( 'Next page', 'peptide-core' ) . '">›</a>' : '<span class="pc-mk-page is-disabled">›</span>';
		echo '<form method="get" class="pc-mk-pager__form"><input type="hidden" name="page" value="' . esc_attr( $slug ) . '"><input type="hidden" name="tab" value="' . esc_attr( $args['tab'] ) . '"><input type="hidden" name="s" value="' . esc_attr( $args['s'] ) . '">';
		echo '<select name="per_page" class="pc-mk-autosubmit" aria-label="' . esc_attr__( 'Items per page', 'peptide-core' ) . '">';
		foreach ( self::PER_PAGE as $n ) {
			/* translators: %d: number of items */
			echo '<option value="' . (int) $n . '"' . selected( $n, $per, false ) . '>' . esc_html( sprintf( __( '%d / page', 'peptide-core' ), $n ) ) . '</option>';
		}
		echo '</select> <label>' . esc_html__( 'Go to', 'peptide-core' ) . ' <input type="number" name="paged" min="1" max="' . (int) $pages . '" value="' . (int) $page . '" class="small-text"> ' . esc_html__( 'page', 'peptide-core' ) . '</label></form></div>';
	}

	/** 按标签和搜索筛选，再分页。$rows 每一项要有 status 和 search（用于搜索的文字）。 */
	public static function filter_rows( array $rows ): array {
		$tab    = self::current_tab();
		$search = self::current_search();
		return array_values(
			array_filter(
				$rows,
				static function ( $r ) use ( $tab, $search ) {
					if ( 'all' !== $tab && $tab !== $r['status'] ) {
						return false;
					}
					return '' === $search || false !== stripos( $r['search'], $search );
				}
			)
		);
	}

	/** 批量操作提交的 ID。 */
	public static function posted_ids(): array {
		return array_values( array_filter( array_map( 'absint', (array) ( $_POST['ids'] ?? array() ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- 调用前已检查 nonce。
	}

	// ---------- 提示与出错时保留表单 ----------

	public static function notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['pc_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved.', 'peptide-core' ) . '</p></div>';
		}
		if ( isset( $_GET['pc_done'] ) ) {
			/* translators: %d: number of items */
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( _n( '%d item updated.', '%d items updated.', absint( $_GET['pc_done'] ), 'peptide-core' ), absint( $_GET['pc_done'] ) ) ) . '</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$errors = get_transient( 'pc_mk_errors_' . get_current_user_id() );
		if ( is_array( $errors ) && $errors ) {
			delete_transient( 'pc_mk_errors_' . get_current_user_id() );
			echo '<div class="notice notice-error"><p>' . implode( '<br>', array_map( 'esc_html', $errors ) ) . '</p></div>';
		}
	}

	/** 下一页显示的错误。 */
	public static function flash_errors( array $errors ): void {
		set_transient( 'pc_mk_errors_' . get_current_user_id(), array_values( $errors ), 120 );
	}

	/** 保存出错：记下错误和填写的内容，回到编辑页时显示。 */
	public static function keep_form( string $kind, array $form, array $errors ): void {
		self::flash_errors( $errors );
		set_transient( self::FORM_KEY . $kind . '_' . get_current_user_id(), $form, 300 );
	}

	/** 上次出错时填写的内容（读一次就删掉）。 */
	public static function kept_form( string $kind ): ?array {
		$key  = self::FORM_KEY . $kind . '_' . get_current_user_id();
		$form = get_transient( $key );
		if ( ! is_array( $form ) ) {
			return null;
		}
		delete_transient( $key );
		return $form;
	}

	public static function guard( string $action ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'peptide-core' ), 403 );
		}
		check_admin_referer( $action );
	}

	/** 编辑页底部固定的保存栏。 */
	public static function save_bar( string $cancel_url, string $extra = '' ): void {
		echo '<div class="pc-bc-bar pc-mk-bar"><a class="button button-hero" href="' . esc_url( $cancel_url ) . '">' . esc_html__( 'Cancel', 'peptide-core' ) . '</a> <button class="button button-primary button-hero">' . esc_html__( 'Save', 'peptide-core' ) . '</button>' . $extra . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $extra 由调用方转义。
	}

	/** 编辑页顶部：返回 + 标题。 */
	public static function edit_head( string $back_url, string $title ): void {
		echo '<p class="pc-mk-back"><a href="' . esc_url( $back_url ) . '"><span class="dashicons dashicons-arrow-left-alt" aria-hidden="true"></span> ' . esc_html__( 'Back', 'peptide-core' ) . '</a></p><h1 class="pc-mk-title">' . esc_html( $title ) . '</h1>';
	}

	/** 设置弹窗：选择前台页面（页面里没有短代码时自动加上）。 */
	public static function page_settings_dialog( string $action, string $title, int $page_id, string $shortcode, string $note ): void {
		echo '<dialog class="pc-pl-dialog pc-mk-settings"><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( $action );
		echo '<h2>' . esc_html( $title ) . '</h2><p><label class="pc-mk-label" for="pc-mk-page">' . esc_html__( 'Storefront page', 'peptide-core' ) . '</label></p>';
		wp_dropdown_pages(
			array(
				'name'              => 'page_id',
				'id'                => 'pc-mk-page',
				'selected'          => $page_id, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				'show_option_none'  => esc_html__( '— Create a new page —', 'peptide-core' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				'option_none_value' => '0',
				'post_status'       => array( 'publish', 'draft', 'private' ),
			)
		);
		/* translators: %s: shortcode */
		echo '<p class="pc-bc-note">' . esc_html( $note ) . ' ' . esc_html( sprintf( __( 'If the page does not contain %s yet, it is added at the end of the page.', 'peptide-core' ), '[' . $shortcode . ']' ) ) . '</p>';
		echo '<div class="pc-pl-dialog__foot"><button type="button" class="button pc-pl-close">' . esc_html__( 'Cancel', 'peptide-core' ) . '</button> <button class="button button-primary">' . esc_html__( 'Save', 'peptide-core' ) . '</button></div></form></dialog>';
	}

	/** 保存设置弹窗选的页面：返回页面 ID。 */
	public static function use_page( int $page_id, string $option, string $shortcode, string $title, string $slug ): int {
		if ( $page_id && 'page' === get_post_type( $page_id ) ) {
			$content = (string) get_post_field( 'post_content', $page_id );
			if ( ! has_shortcode( $content, $shortcode ) ) {
				wp_update_post( array( 'ID' => $page_id, 'post_content' => rtrim( $content ) . "\n\n<!-- wp:shortcode -->[" . $shortcode . ']<!-- /wp:shortcode -->' ) );
			}
		} else {
			$page_id = (int) wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_name'    => $slug,
					'post_content' => '<!-- wp:shortcode -->[' . $shortcode . ']<!-- /wp:shortcode -->',
				)
			);
		}
		if ( $page_id ) {
			update_option( $option, $page_id, false );
		}
		return $page_id;
	}
}
