<?php
/**
 * 营销活动 → 优惠码（COUPON-02），版式参照 Shopname 的“优惠码”列表页和新建优惠码页。
 *
 * 优惠码就是 WooCommerce 的优惠券：活动名称 = 优惠券描述，优惠码 = 优惠券代码，结束时间 = 到期时间，
 * 优惠券数量 = 使用次数上限，每人限用 = 每位用户使用次数上限，指定顾客 = 允许的邮箱，部分商品 / 专辑 = 指定商品 / 分类。
 * WooCommerce 没有的规则（开始时间、全局首单、按适用商品计算的门槛、和满减活动的叠加策略、商品排序）存在优惠券的自定义字段里，
 * 由 PeptideCore\Coupon_Rules 检查。是否公开在优惠码列表由 PeptideCore\Coupons 处理。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Coupon_Rules;
use PeptideCore\Coupons;
use PeptideCore\Shipping\Discounts;

defined( 'ABSPATH' ) || exit;

final class Coupons_Page {

	public const SLUG     = 'pc-coupons';
	public const NAME_MAX = 50;
	public const CODE_MAX = 15;

	public static function register_hooks(): void {
		add_action( 'admin_post_pc_coupon_save', array( self::class, 'save' ) );
		add_action( 'admin_post_pc_coupon_toggle', array( self::class, 'toggle' ) );
		add_action( 'admin_post_pc_coupon_bulk', array( self::class, 'bulk' ) );
		add_action( 'admin_post_pc_coupon_settings', array( self::class, 'save_settings' ) );
	}

	public static function url( array $args = array() ): string {
		return Marketing::url( self::SLUG, $args );
	}

	// ---------- 列表 ----------

	public static function render(): void {
		if ( ! current_user_can( Marketing::CAP ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['edit'] ) || isset( $_GET['new'] ) ) {
			self::render_edit( absint( $_GET['edit'] ?? 0 ) );
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$rows = array();
		$ids  = get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future' ),
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'orderby'        => array( 'date' => 'DESC', 'ID' => 'DESC' ),
				'no_found_rows'  => true,
			)
		);
		foreach ( $ids as $id ) {
			$coupon = new \WC_Coupon( (int) $id );
			$rows[] = array(
				'id'     => (int) $id,
				'coupon' => $coupon,
				'status' => Coupon_Rules::status( $coupon ),
				'search' => $coupon->get_description() . ' ' . $coupon->get_code(),
			);
		}
		$rows           = Marketing::filter_rows( $rows );
		[ $page, $per ] = Marketing::current_paging();
		$shown          = array_slice( $rows, ( $page - 1 ) * $per, $per );

		echo '<div class="wrap pc-pl pc-mk">';
		Marketing::notices();
		Marketing::list_head( __( 'Coupon codes', 'peptide-core' ), self::url( array( 'new' => 1 ) ), __( 'New coupon code', 'peptide-core' ), true );
		echo '<div class="pc-pl-card">';
		Marketing::tabs_and_search( self::SLUG, __( 'Search by promotion name or code', 'peptide-core' ) );
		Marketing::bulk_open( 'pc_coupon_bulk' );
		echo '<div class="pc-mk-scroll"><table class="pc-pl-table pc-mk-table"><thead><tr>' . Marketing::check_head() . '<th class="pc-mk-col-act">' . esc_html__( 'Actions', 'peptide-core' ) . '</th><th>' . esc_html__( 'Promotion name', 'peptide-core' ) . '</th><th>' . esc_html__( 'Coupon code', 'peptide-core' ) . '</th><th>' . esc_html__( 'Promotion time', 'peptide-core' ) . '</th><th>' . esc_html__( 'Discount rules', 'peptide-core' ) . '</th><th>' . esc_html__( 'Product scope', 'peptide-core' ) . '</th><th>' . esc_html__( 'Promotion status', 'peptide-core' ) . '</th><th>' . esc_html__( 'Usage', 'peptide-core' ) . '</th></tr></thead><tbody>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( ! $shown ) {
			echo '<tr><td colspan="9" class="pc-pl-empty">' . esc_html__( 'No coupon codes yet.', 'peptide-core' ) . '</td></tr>';
		}
		foreach ( $shown as $r ) {
			$coupon  = $r['coupon'];
			$id      = $r['id'];
			$on      = 'publish' === get_post_status( $id );
			$start   = Coupon_Rules::start( $coupon );
			$expires = $coupon->get_date_expires();
			$limit   = (int) $coupon->get_usage_limit();
			$used    = (int) $coupon->get_usage_count();
			echo '<tr>' . Marketing::check_cell( $id ) . '<td class="pc-mk-col-act">' . Marketing::row_actions( self::url( array( 'edit' => $id ) ), wp_nonce_url( admin_url( 'admin-post.php?action=pc_coupon_toggle&id=' . $id . '&on=' . ( $on ? 0 : 1 ) ), 'pc_coupon_toggle' ), $on ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td><a class="pc-mk-name" href="' . esc_url( self::url( array( 'edit' => $id ) ) ) . '">' . esc_html( '' !== $coupon->get_description() ? $coupon->get_description() : '—' ) . '</a></td><td><code>' . esc_html( Coupon_Rules::display_code( $coupon ) ) . '</code></td>';
			echo '<td class="pc-mk-time-cell">' . esc_html( $start ? Marketing::date_text( $start ) : Marketing::date_text( (int) strtotime( (string) get_post_field( 'post_date_gmt', $id ) . ' UTC' ) ) ) . ' ~<br>' . esc_html( $expires ? Marketing::date_text( $expires->getTimestamp() ) : __( 'No end date', 'peptide-core' ) ) . '</td>';
			echo '<td>' . esc_html( Coupon_Rules::rule_text( $coupon ) ) . '</td><td>' . esc_html( self::scope_text( $coupon ) ) . '</td><td>' . Marketing::badge( $r['status'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . esc_html__( 'Used / left', 'peptide-core' ) . '<br>' . esc_html( $used . '/' . ( $limit ? max( 0, $limit - $used ) : __( 'Unlimited', 'peptide-core' ) ) ) . '</td></tr>';
		}
		echo '</tbody></table></div></form>';
		Marketing::pager( self::SLUG, count( $rows ), $page, $per );
		echo '</div>';
		Marketing::page_settings_dialog( 'pc_coupon_settings', __( 'Coupon code page settings', 'peptide-core' ), Coupons::page_id(), Coupons::SHORTCODE, __( 'The storefront page that lists the coupon codes marked “Show in the coupon list”.', 'peptide-core' ) );
		echo '</div>';
	}

	public static function scope_text( \WC_Coupon $coupon ): string {
		if ( $coupon->get_product_ids() ) {
			/* translators: %d: number of products */
			return sprintf( _n( '%d product', '%d products', count( $coupon->get_product_ids() ), 'peptide-core' ), count( $coupon->get_product_ids() ) );
		}
		if ( $coupon->get_product_categories() ) {
			$names = array();
			foreach ( $coupon->get_product_categories() as $tid ) {
				$t = get_term( (int) $tid, 'product_cat' );
				if ( $t instanceof \WP_Term ) {
					$names[] = html_entity_decode( $t->name );
				}
			}
			/* translators: %s: collection names */
			return sprintf( __( 'Collections: %s', 'peptide-core' ), implode( ', ', $names ) );
		}
		return __( 'Whole store', 'peptide-core' );
	}

	// ---------- 编辑 ----------

	/** 编辑页显示的内容（来自优惠券，或上次出错时填写的内容）。 */
	private static function form_values( int $id ): array {
		$f = array(
			'id'          => $id,
			'name'        => '',
			'code'        => '',
			'strategy'    => 'no_stack',
			'time_mode'   => 'range',
			'start'       => time(),
			'end'         => 0,
			'limit_mode'  => 'none',
			'usage_limit' => '',
			'user_mode'   => 'none',
			'per_user'    => '',
			'public'      => 'no',
			'condition'   => 'none',
			'cond_value'  => '',
			'dtype'       => 'percent',
			'amount'      => '',
			'customers'   => 'all',
			'emails'      => array(),
			'customer_rule' => array(),
			'scope'       => 'all',
			'products'    => array(),
			'categories'  => array(),
			'sort'        => 'date_desc',
		);
		if ( $id ) {
			$c         = new \WC_Coupon( $id );
			$expires   = $c->get_date_expires();
			$min_spend = (float) get_post_meta( $id, Coupon_Rules::MIN_SPEND_META, true );
			$min_qty   = (int) get_post_meta( $id, Coupon_Rules::MIN_QTY_META, true );
			$wc_min    = (float) $c->get_minimum_amount();
			$first     = 'yes' === get_post_meta( $id, Coupon_Rules::FIRST_META, true );
			$start     = Coupon_Rules::start( $c );
			$f         = array_merge(
				$f,
				array(
					'name'        => $c->get_description(),
					'code'        => Coupon_Rules::display_code( $c ),
					'strategy'    => Coupon_Rules::strategy( $c ),
					'time_mode'   => $expires ? 'range' : 'long',
					'start'       => $start ? $start : (int) strtotime( (string) get_post_field( 'post_date_gmt', $id ) . ' UTC' ),
					'end'         => $expires ? $expires->getTimestamp() : 0,
					'limit_mode'  => $c->get_usage_limit() ? 'some' : 'none',
					'usage_limit' => $c->get_usage_limit() ? (string) $c->get_usage_limit() : '',
					'user_mode'   => $first ? 'first' : ( $c->get_usage_limit_per_user() ? 'some' : 'none' ),
					'per_user'    => ! $first && $c->get_usage_limit_per_user() ? (string) $c->get_usage_limit_per_user() : '',
					'public'      => 'yes' === get_post_meta( $id, Coupons::PUBLIC_META, true ) ? 'yes' : 'no',
					'condition'   => $min_spend > 0 || ( ! $min_qty && $wc_min > 0 ) ? 'spend' : ( $min_qty ? 'qty' : 'none' ),
					'cond_value'  => $min_spend > 0 ? (string) $min_spend : ( $min_qty ? (string) $min_qty : ( $wc_min > 0 ? (string) $wc_min : '' ) ),
					'dtype'       => 'percent' === $c->get_discount_type() ? 'percent' : 'fixed_cart',
					'amount'      => (string) $c->get_amount(),
					'customers'   => $c->get_email_restrictions() ? 'emails' : 'all',
					'emails'      => $c->get_email_restrictions(),
					'customer_rule' => (array) get_post_meta( $id, \PeptideCore\Customer_Segments::COUPON_META, true ),
					'scope'       => $c->get_product_ids() ? 'products' : ( $c->get_product_categories() ? 'categories' : 'all' ),
					'products'    => array_map( 'intval', $c->get_product_ids() ),
					'categories'  => array_map( 'intval', $c->get_product_categories() ),
					'sort'        => (string) get_post_meta( $id, Coupon_Rules::SORT_META, true ),
				)
			);
		}
		$kept = Marketing::kept_form( 'coupon' );
		if ( $kept && (int) ( $kept['id'] ?? 0 ) === $id ) {
			$f = array_merge( $f, array_intersect_key( $kept, $f ) );
		}
		return $f;
	}

	private static function render_edit( int $id ): void {
		if ( $id && 'shop_coupon' !== get_post_type( $id ) ) {
			echo '<div class="wrap"><p>' . esc_html__( 'This coupon code does not exist.', 'peptide-core' ) . '</p></div>';
			return;
		}
		$f   = self::form_values( $id );
		$cur = get_woocommerce_currency();

		echo '<div class="wrap pc-pl pc-mk pc-mk-edit">';
		Marketing::notices();
		Marketing::edit_head( self::url(), $id ? __( 'Edit coupon code', 'peptide-core' ) : __( 'New coupon code', 'peptide-core' ) );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pc-mk-form" data-kind="coupon">';
		echo '<input type="hidden" name="action" value="pc_coupon_save"><input type="hidden" name="id" value="' . (int) $id . '">';
		wp_nonce_field( 'pc_coupon_save' );

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Marketing 的小部件已转义。
		echo '<div class="pc-mk-top">';
		Marketing::card_open( __( 'Basic information', 'peptide-core' ) );
		echo '<div class="pc-mk-field pc-mk-narrow">' . Marketing::label( __( 'Promotion name', 'peptide-core' ), true, __( 'For your own reference in the coupon list; customers see the code, not this name.', 'peptide-core' ), 'pc-mk-name' ) . Marketing::counted( 'name', (string) $f['name'], self::NAME_MAX, __( 'Enter the promotion name, e.g. Black Friday Discounts', 'peptide-core' ), 'pc-mk-name', true ) . '</div>';
		echo '<div class="pc-mk-field pc-mk-narrow"><div class="pc-mk-label-row">' . Marketing::label( __( 'Coupon code', 'peptide-core' ), true, '', 'pc-mk-code' ) . '<button type="button" class="button-link pc-mk-random">' . esc_html__( 'Random code', 'peptide-core' ) . '</button></div>' . Marketing::counted( 'code', (string) $f['code'], self::CODE_MAX, __( 'Enter a code', 'peptide-core' ), 'pc-mk-code', true ) . '<p class="pc-bc-note">' . esc_html__( 'Letters, digits, “-” and “_”. Customers can type it in any letter case.', 'peptide-core' ) . '</p></div>';
		echo '<div class="pc-mk-field">' . Marketing::label( __( 'Combining with promotions', 'peptide-core' ), false, __( "Do not combine: while a spend-and-save promotion gives a discount in the cart, this code cannot be used.\nCombine: the code and the promotions both apply.\nReplace: when this code is used, spend-and-save promotions do not apply.", 'peptide-core' ) ) . '<div class="pc-mk-radios">';
		foreach ( array( 'no_stack' => __( 'Do not combine with promotions', 'peptide-core' ), 'stack' => __( 'Combine with promotions', 'peptide-core' ), 'replace' => __( 'Replace promotions', 'peptide-core' ) ) as $k => $text ) {
			echo Marketing::radio( 'strategy', $k, (string) $f['strategy'], $text );
		}
		echo '</div></div>';
		Marketing::time_fields( (int) $f['start'], (int) $f['end'], 'long' === $f['time_mode'] );
		echo '<div class="pc-mk-field">' . Marketing::label( __( 'Number of coupons', 'peptide-core' ) ) . '<div class="pc-mk-radios">' . Marketing::radio( 'limit_mode', 'none', (string) $f['limit_mode'], __( 'Unlimited', 'peptide-core' ) ) . Marketing::radio( 'limit_mode', 'some', (string) $f['limit_mode'], __( 'Limited', 'peptide-core' ) ) . '<span class="pc-mk-inline" data-show="limit_mode=some"><input type="number" min="1" step="1" name="usage_limit" class="small-text" value="' . esc_attr( (string) $f['usage_limit'] ) . '"> ' . esc_html__( 'uses in total', 'peptide-core' ) . '</span></div></div>';
		echo '<div class="pc-mk-field">' . Marketing::label( __( 'Limit per customer', 'peptide-core' ) ) . '<div class="pc-mk-radios">' . Marketing::radio( 'user_mode', 'none', (string) $f['user_mode'], __( 'Unlimited', 'peptide-core' ) ) . Marketing::radio( 'user_mode', 'some', (string) $f['user_mode'], __( 'Limited', 'peptide-core' ) ) . '<span class="pc-mk-inline" data-show="user_mode=some"><input type="number" min="1" step="1" name="per_user" class="small-text" value="' . esc_attr( (string) $f['per_user'] ) . '"> ' . esc_html__( 'times', 'peptide-core' ) . '</span>' . Marketing::radio( 'user_mode', 'first', (string) $f['user_mode'], __( 'First order only', 'peptide-core' ) ) . Marketing::help( __( 'Only customers who have never ordered in this store can use the code: checked against the account and the checkout email, at checkout. Each of them can use it once.', 'peptide-core' ) ) . '</div></div>';
		echo '<div class="pc-mk-field">' . Marketing::label( __( 'Show in the coupon list', 'peptide-core' ), false, __( 'Yes: the code is listed on the storefront coupon page so customers can copy it. Codes for specific customers are never listed.', 'peptide-core' ) ) . '<div class="pc-mk-radios">' . Marketing::radio( 'public', 'yes', (string) $f['public'], __( 'Yes', 'peptide-core' ) ) . Marketing::radio( 'public', 'no', (string) $f['public'], __( 'No', 'peptide-core' ) ) . '</div></div>';
		Marketing::card_close();
		$detail = $id && 'yes' === $f['public'] ? Coupons::detail_url( (string) $f['code'] ) : '';
		Marketing::seo_card( '' !== $detail ? $detail : Coupons::list_url(), Coupons::page_id(), __( 'The coupon page is created automatically when the first code is shown in the coupon list.', 'peptide-core' ) );
		echo '</div>';

		Marketing::card_open( __( 'Discount rules', 'peptide-core' ) );
		echo '<div class="pc-mk-field">' . Marketing::label( __( 'Discount condition', 'peptide-core' ), false, __( "No minimum: the code works on any cart.\nMinimum amount / Minimum items: only the products this code applies to count (see Scope), before discounts, taxes and shipping.", 'peptide-core' ) ) . '<div class="pc-mk-inline"><select name="condition" class="pc-mk-select">';
		foreach ( array( 'none' => __( 'No minimum', 'peptide-core' ), 'spend' => __( 'Minimum amount', 'peptide-core' ), 'qty' => __( 'Minimum items', 'peptide-core' ) ) as $k => $text ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $k, $f['condition'], false ) . '>' . esc_html( $text ) . '</option>';
		}
		echo '</select><span class="pc-mk-addon" data-show="condition=spend|qty"><input type="number" min="0" step="0.01" name="cond_value" value="' . esc_attr( (string) $f['cond_value'] ) . '" placeholder="' . esc_attr__( 'Enter', 'peptide-core' ) . '"><span class="pc-mk-addon__unit" data-unit-spend="' . esc_attr( $cur ) . '" data-unit-qty="' . esc_attr__( 'items', 'peptide-core' ) . '">' . esc_html( 'qty' === $f['condition'] ? __( 'items', 'peptide-core' ) : $cur ) . '</span></span></div></div>';
		echo '<div class="pc-mk-field">' . Marketing::label( __( 'Discount type', 'peptide-core' ) ) . '<div class="pc-mk-inline"><select name="dtype" class="pc-mk-select">';
		foreach ( array( 'percent' => __( 'Percentage', 'peptide-core' ), 'fixed_cart' => __( 'Fixed amount', 'peptide-core' ) ) as $k => $text ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $k, $f['dtype'], false ) . '>' . esc_html( $text ) . '</option>';
		}
		echo '</select><span class="pc-mk-addon"><input type="number" min="0" step="0.01" name="amount" value="' . esc_attr( (string) $f['amount'] ) . '" placeholder="' . esc_attr__( 'Enter', 'peptide-core' ) . '" required><span class="pc-mk-addon__unit" data-unit-percent="% off" data-unit-fixed_cart="' . esc_attr( $cur ) . '">' . esc_html( 'percent' === $f['dtype'] ? '% off' : $cur ) . '</span></span></div></div>';
		Marketing::card_close();
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

		$rule = $f['customer_rule'] ?? ( $id ? get_post_meta( $id, \PeptideCore\Customer_Segments::COUPON_META, true ) : array() );
		$rule = is_array( $rule ) ? $rule : array();
		Marketing::customers_card( (string) ( $rule['customers'] ?? $f['customers'] ), (array) $f['emails'], false, __( 'Specific customers: only these emails can use the code (WooCommerce checks the checkout email).', 'peptide-core' ), $rule );
		Marketing::scope_card(
			(string) $f['scope'],
			(array) $f['products'],
			(array) $f['categories'],
			(string) $f['sort'],
			array(
				'all'        => __( 'The code can be used on all products.', 'peptide-core' ),
				'products'   => __( 'The code only discounts the chosen products.', 'peptide-core' ),
				'categories' => __( 'The code only discounts the products of the chosen collections.', 'peptide-core' ),
			)
		);
		$extra = $id ? ' <a class="button-link pc-mk-classic" href="' . esc_url( admin_url( 'post.php?post=' . $id . '&action=edit&pc_classic=1' ) ) . '">' . esc_html__( 'More settings (WooCommerce coupon editor)', 'peptide-core' ) . '</a>' : '';
		Marketing::save_bar( self::url(), $extra );
		echo '</form></div>';
	}

	// ---------- 保存 ----------

	/**
	 * 保存一个优惠码（后台和测试共用）。$in 是编辑页表单的内容。有错误时不保存。
	 *
	 * @param array<string,mixed> $in
	 * @return array{0:int,1:string[],2:array} [优惠券 ID, 错误, 表单内容]
	 */
	public static function store( int $id, array $in ): array {
		$len    = static fn( string $s ): int => function_exists( 'mb_strlen' ) ? mb_strlen( $s ) : strlen( $s );
		$errors = array();
		if ( $id && 'shop_coupon' !== get_post_type( $id ) ) {
			return array( 0, array( __( 'This coupon code does not exist.', 'peptide-core' ) ), array() );
		}
		[ $start, $end, $time_errors ] = Marketing::read_time( $in );
		$f = array(
			'id'          => $id,
			'name'        => trim( sanitize_text_field( (string) ( $in['name'] ?? '' ) ) ),
			'code'        => trim( sanitize_text_field( (string) ( $in['code'] ?? '' ) ) ),
			'strategy'    => in_array( $in['strategy'] ?? '', Coupon_Rules::STRATEGIES, true ) ? (string) $in['strategy'] : 'no_stack',
			'time_mode'   => 'long' === ( $in['time_mode'] ?? '' ) ? 'long' : 'range',
			'start'       => $start,
			'end'         => $end,
			'limit_mode'  => 'some' === ( $in['limit_mode'] ?? '' ) ? 'some' : 'none',
			'usage_limit' => absint( $in['usage_limit'] ?? 0 ),
			'user_mode'   => in_array( $in['user_mode'] ?? '', array( 'some', 'first' ), true ) ? (string) $in['user_mode'] : 'none',
			'per_user'    => absint( $in['per_user'] ?? 0 ),
			'public'      => 'yes' === ( $in['public'] ?? '' ) ? 'yes' : 'no',
			'condition'   => in_array( $in['condition'] ?? '', array( 'spend', 'qty' ), true ) ? (string) $in['condition'] : 'none',
			'cond_value'  => is_numeric( $in['cond_value'] ?? null ) ? (float) $in['cond_value'] : 0.0,
			'dtype'       => 'fixed_cart' === ( $in['dtype'] ?? '' ) ? 'fixed_cart' : 'percent',
			'amount'      => is_numeric( $in['amount'] ?? null ) ? round( (float) $in['amount'], 2 ) : 0.0,
			'customers'   => in_array( $in['customers'] ?? '', array( 'emails', 'tags', 'levels' ), true ) ? (string) $in['customers'] : 'all',
			'emails'      => Discounts::emails( $in['emails'] ?? '' ),
			'scope'       => in_array( $in['scope'] ?? '', array( 'products', 'categories' ), true ) ? (string) $in['scope'] : 'all',
			'products'    => array_values( array_filter( array_map( 'absint', (array) ( $in['products'] ?? array() ) ), static fn( $p ) => $p && 'product' === get_post_type( $p ) ) ),
			'categories'  => array_values( array_filter( array_map( 'absint', (array) ( $in['categories'] ?? array() ) ), static fn( $t ) => $t && term_exists( $t, 'product_cat' ) ) ),
			'sort'        => isset( \PeptideCore\Promotions::SORTS[ $in['sort'] ?? '' ] ) ? (string) $in['sort'] : 'date_desc',
		);
		[ $f['customer_rule'], $segment_errors ] = \PeptideCore\Customer_Segments::clean_rule( array_merge( $in, array( 'customers' => $f['customers'] ) ) );
		$errors = array_merge( $errors, $segment_errors );
		if ( 'all' !== $f['customers'] ) { $f['public'] = 'no'; }
		if ( '' === $f['name'] ) {
			$errors[] = __( 'Enter a promotion name.', 'peptide-core' );
		} elseif ( $len( $f['name'] ) > self::NAME_MAX ) {
			/* translators: %d: number of characters */
			$errors[] = sprintf( __( 'The name can have at most %d characters.', 'peptide-core' ), self::NAME_MAX );
		}
		if ( '' === $f['code'] ) {
			$errors[] = __( 'Enter a coupon code.', 'peptide-core' );
		} elseif ( $len( $f['code'] ) > self::CODE_MAX || ! preg_match( '/^[A-Za-z0-9_-]+$/', $f['code'] ) ) {
			/* translators: %d: number of characters */
			$errors[] = sprintf( __( 'The code can have up to %d letters, digits, “-” and “_”.', 'peptide-core' ), self::CODE_MAX );
		} elseif ( wc_get_coupon_id_by_code( $f['code'], $id ) ) {
			$errors[] = __( 'This code is already used by another coupon.', 'peptide-core' );
		}
		$errors = array_merge( $errors, $time_errors );
		if ( $start && $end && $end <= $start ) {
			$errors[] = __( 'The end time must be after the start time.', 'peptide-core' );
		}
		if ( 'some' === $f['limit_mode'] && $f['usage_limit'] < 1 ) {
			$errors[] = __( 'Enter how many times the code can be used.', 'peptide-core' );
		}
		if ( 'some' === $f['user_mode'] && $f['per_user'] < 1 ) {
			$errors[] = __( 'Enter how many times each customer can use the code.', 'peptide-core' );
		}
		if ( 'none' !== $f['condition'] && $f['cond_value'] <= 0 ) {
			$errors[] = __( 'Enter the minimum amount or number of items.', 'peptide-core' );
		}
		if ( $f['amount'] <= 0 ) {
			$errors[] = __( 'Enter a discount greater than zero.', 'peptide-core' );
		} elseif ( 'percent' === $f['dtype'] && $f['amount'] > 100 ) {
			$errors[] = __( 'A percentage discount can be at most 100%.', 'peptide-core' );
		}
		if ( 'emails' === $f['customers'] && ! $f['emails'] ) {
			$errors[] = __( 'Enter at least one customer email.', 'peptide-core' );
		}
		if ( 'products' === $f['scope'] && ! $f['products'] ) {
			$errors[] = __( 'Choose at least one product, or apply the code to all products.', 'peptide-core' );
		}
		if ( 'categories' === $f['scope'] && ! $f['categories'] ) {
			$errors[] = __( 'Choose at least one collection, or apply the code to all products.', 'peptide-core' );
		}
		if ( $errors ) {
			return array( $id, $errors, $f );
		}

		$enabled = ! $id || 'publish' === get_post_status( $id );
		$coupon  = $id ? new \WC_Coupon( $id ) : new \WC_Coupon();
		$coupon->set_code( $f['code'] );
		$coupon->set_description( $f['name'] );
		$coupon->set_discount_type( $f['dtype'] );
		$coupon->set_amount( $f['amount'] );
		$coupon->set_date_expires( $end ? $end : null );
		$coupon->set_usage_limit( 'some' === $f['limit_mode'] ? $f['usage_limit'] : 0 );
		$coupon->set_usage_limit_per_user( 'first' === $f['user_mode'] ? 1 : ( 'some' === $f['user_mode'] ? $f['per_user'] : 0 ) );
		$coupon->set_email_restrictions( 'emails' === $f['customers'] ? $f['emails'] : array() );
		$coupon->set_product_ids( 'products' === $f['scope'] ? $f['products'] : array() );
		$coupon->set_product_categories( 'categories' === $f['scope'] ? $f['categories'] : array() );
		// 门槛只按适用的商品计算（Coupon_Rules），不用 WooCommerce 按整个购物车计算的最低消费。
		$coupon->set_minimum_amount( '' );
		$saved = $coupon->save();
		if ( ! $saved ) {
			return array( $id, array( __( 'The coupon code could not be saved.', 'peptide-core' ) ), $f );
		}
		if ( ! $enabled || 'publish' !== get_post_status( $saved ) ) {
			wp_update_post( array( 'ID' => $saved, 'post_status' => $enabled ? 'publish' : 'draft' ) );
		}
		update_post_meta( $saved, Coupon_Rules::STRATEGY_META, $f['strategy'] );
		update_post_meta( $saved, Coupon_Rules::START_META, $start );
		update_post_meta( $saved, Coupon_Rules::FIRST_META, 'first' === $f['user_mode'] ? 'yes' : 'no' );
		update_post_meta( $saved, Coupon_Rules::MIN_SPEND_META, 'spend' === $f['condition'] ? round( $f['cond_value'], 2 ) : 0 );
		update_post_meta( $saved, Coupon_Rules::MIN_QTY_META, 'qty' === $f['condition'] ? max( 1, (int) round( $f['cond_value'] ) ) : 0 );
		update_post_meta( $saved, Coupon_Rules::SORT_META, $f['sort'] );
		update_post_meta( $saved, Coupon_Rules::LABEL_META, $f['code'] );
		update_post_meta( $saved, \PeptideCore\Customer_Segments::COUPON_META, $f['customer_rule'] );
		update_post_meta( $saved, Coupons::PUBLIC_META, $f['public'] );
		if ( 'yes' === $f['public'] ) {
			Coupons::ensure_page();
		}
		return array( (int) $saved, array(), $f );
	}

	public static function save(): void {
		Marketing::guard( 'pc_coupon_save' );
		$in                    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 在 store() 里逐项清理。
		$id                    = absint( $in['id'] ?? 0 );
		[ $saved, $errors, $f ] = self::store( $id, $in );
		if ( $errors ) {
			Marketing::keep_form( 'coupon', $f, $errors );
			wp_safe_redirect( self::url( $id ? array( 'edit' => $id ) : array( 'new' => 1 ) ) );
			exit;
		}
		wp_safe_redirect( self::url( array( 'pc_saved' => $saved ) ) );
		exit;
	}

	private static function set_enabled( int $id, bool $on ): bool {
		if ( 'shop_coupon' !== get_post_type( $id ) ) {
			return false;
		}
		wp_update_post( array( 'ID' => $id, 'post_status' => $on ? 'publish' : 'draft' ) );
		return true;
	}

	public static function toggle(): void {
		Marketing::guard( 'pc_coupon_toggle' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$done = self::set_enabled( absint( $_GET['id'] ?? 0 ), ! empty( $_GET['on'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		wp_safe_redirect( self::url( array( 'pc_done' => (int) $done ) ) );
		exit;
	}

	public static function bulk(): void {
		Marketing::guard( 'pc_coupon_bulk' );
		$op   = sanitize_key( wp_unslash( $_POST['bulk'] ?? '' ) );
		$done = 0;
		foreach ( Marketing::posted_ids() as $id ) {
			if ( 'shop_coupon' !== get_post_type( $id ) ) {
				continue;
			}
			if ( 'delete' === $op ) {
				wp_trash_post( $id ); // 和 WooCommerce 一样放进回收站（可以在 WooCommerce 原优惠券列表里恢复）。
				++$done;
			} elseif ( self::set_enabled( $id, 'enable' === $op ) ) {
				++$done;
			}
		}
		wp_safe_redirect( self::url( array( 'pc_done' => $done ) ) );
		exit;
	}

	public static function save_settings(): void {
		Marketing::guard( 'pc_coupon_settings' );
		Marketing::use_page( absint( $_POST['page_id'] ?? 0 ), Coupons::PAGE_OPTION, Coupons::SHORTCODE, 'Coupons', 'coupons' );
		wp_safe_redirect( self::url( array( 'pc_saved' => 1 ) ) );
		exit;
	}
}
