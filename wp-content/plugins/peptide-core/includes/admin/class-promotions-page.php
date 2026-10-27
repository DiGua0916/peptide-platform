<?php
/**
 * 营销活动 → 满减活动（PROMO-02），版式参照 Shopname 的“满减活动”列表页和新建活动页。
 * 规则和计算在 PeptideCore\Promotions；这里只有页面和表单。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Promotions;

defined( 'ABSPATH' ) || exit;

final class Promotions_Page {

	public const SLUG = 'pc-promotions';

	public static function register_hooks(): void {
		add_action( 'admin_post_pc_promo_save', array( self::class, 'save' ) );
		add_action( 'admin_post_pc_promo_toggle', array( self::class, 'toggle' ) );
		add_action( 'admin_post_pc_promo_bulk', array( self::class, 'bulk' ) );
		add_action( 'admin_post_pc_promo_settings', array( self::class, 'save_settings' ) );
	}

	public static function url( array $args = array() ): string {
		return Marketing::url( self::SLUG, $args );
	}

	public static function type_labels(): array {
		return array(
			'spend_amount'  => __( 'Spend & save', 'peptide-core' ),
			'spend_percent' => __( 'Spend & get % off', 'peptide-core' ),
			'qty_amount'    => __( 'Buy items & save', 'peptide-core' ),
			'qty_percent'   => __( 'Buy items & get % off', 'peptide-core' ),
			'nth_percent'   => __( 'Xth item % off', 'peptide-core' ),
			'bundle_price'  => __( 'Bundle price', 'peptide-core' ),
			'buy_x_free_y'  => __( 'Buy X, get Y free', 'peptide-core' ),
		);
	}

	/** 每种类型一个层级的写法：{min}、{value} 是输入框，{cur} 是货币。 */
	public static function tier_templates(): array {
		return array(
			'spend_amount'  => __( 'Spend {min} {cur}, save {value} {cur}', 'peptide-core' ),
			'spend_percent' => __( 'Spend {min} {cur}, get {value} % off', 'peptide-core' ),
			'qty_amount'    => __( 'Buy {min} items, save {value} {cur}', 'peptide-core' ),
			'qty_percent'   => __( 'Buy {min} items, get {value} % off', 'peptide-core' ),
			'nth_percent'   => __( 'Item no. {min} gets {value} % off', 'peptide-core' ),
			'bundle_price'  => __( 'Any {min} items for {value} {cur}', 'peptide-core' ),
			'buy_x_free_y'  => __( 'Buy {min} items, {value} of them free', 'peptide-core' ),
		);
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
				'post_type'      => Promotions::CPT,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'orderby'        => array( 'date' => 'DESC', 'ID' => 'DESC' ),
				'no_found_rows'  => true,
			)
		);
		foreach ( $ids as $id ) {
			$rows[] = array(
				'id'     => (int) $id,
				'status' => Promotions::status( (int) $id ),
				'search' => html_entity_decode( get_the_title( (int) $id ) ),
			);
		}
		$rows             = Marketing::filter_rows( $rows );
		[ $page, $per ]   = Marketing::current_paging();
		$shown            = array_slice( $rows, ( $page - 1 ) * $per, $per );

		echo '<div class="wrap pc-pl pc-mk">';
		Marketing::notices();
		Marketing::list_head( __( 'Spend-and-save promotions', 'peptide-core' ), self::url( array( 'new' => 1 ) ), __( 'New promotion', 'peptide-core' ), true );
		echo '<div class="pc-pl-card">';
		Marketing::tabs_and_search( self::SLUG, __( 'Search by promotion name', 'peptide-core' ) );
		Marketing::bulk_open( 'pc_promo_bulk' );
		echo '<div class="pc-mk-scroll"><table class="pc-pl-table pc-mk-table"><thead><tr>' . Marketing::check_head() . '<th class="pc-mk-col-act">' . esc_html__( 'Actions', 'peptide-core' ) . '</th><th>' . esc_html__( 'Promotion name', 'peptide-core' ) . '</th><th>' . esc_html__( 'Promotion time', 'peptide-core' ) . '</th><th>' . esc_html__( 'Discount rules', 'peptide-core' ) . '</th><th>' . esc_html__( 'Product scope', 'peptide-core' ) . '</th><th>' . esc_html__( 'Promotion status', 'peptide-core' ) . '</th></tr></thead><tbody>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( ! $shown ) {
			echo '<tr><td colspan="7" class="pc-pl-empty">' . esc_html__( 'No promotions yet.', 'peptide-core' ) . '</td></tr>';
		}
		foreach ( $shown as $r ) {
			$id = $r['id'];
			$c  = Promotions::config( $id );
			$on = 'publish' === get_post_status( $id );
			echo '<tr>' . Marketing::check_cell( $id ) . '<td class="pc-mk-col-act">' . Marketing::row_actions( self::url( array( 'edit' => $id ) ), wp_nonce_url( admin_url( 'admin-post.php?action=pc_promo_toggle&id=' . $id . '&on=' . ( $on ? 0 : 1 ) ), 'pc_promo_toggle' ), $on ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td><a class="pc-mk-name" href="' . esc_url( self::url( array( 'edit' => $id ) ) ) . '">' . esc_html( $r['search'] ) . '</a></td>';
			echo '<td class="pc-mk-time-cell">' . esc_html( Marketing::date_text( (int) $c['start'] ) ) . ' ~<br>' . esc_html( $c['end'] ? Marketing::date_text( (int) $c['end'] ) : __( 'Long-term', 'peptide-core' ) ) . '</td>';
			echo '<td>' . implode( '<br>', array_map( 'esc_html', Promotions::rules_text( $c ) ) ) . '</td>';
			echo '<td>' . esc_html( self::scope_text( $c ) ) . '</td><td>' . Marketing::badge( $r['status'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</tbody></table></div></form>';
		Marketing::pager( self::SLUG, count( $rows ), $page, $per );
		echo '</div>';
		Marketing::page_settings_dialog( 'pc_promo_settings', __( 'Promotion page settings', 'peptide-core' ), Promotions::page_id(), Promotions::SHORTCODE, __( 'The storefront page that lists the running promotions; each promotion has its own detail page with its products.', 'peptide-core' ) );
		echo '</div>';
	}

	public static function scope_text( array $c ): string {
		if ( 'products' === $c['scope'] ) {
			/* translators: %d: number of products */
			return sprintf( _n( '%d product', '%d products', count( $c['products'] ), 'peptide-core' ), count( $c['products'] ) );
		}
		if ( 'categories' === $c['scope'] ) {
			$names = array();
			foreach ( $c['categories'] as $tid ) {
				$t = get_term( $tid, 'product_cat' );
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

	private static function render_edit( int $id ): void {
		if ( $id && Promotions::CPT !== get_post_type( $id ) ) {
			echo '<div class="wrap"><p>' . esc_html__( 'This promotion does not exist.', 'peptide-core' ) . '</p></div>';
			return;
		}
		$c     = $id ? Promotions::config( $id ) : Promotions::defaults();
		$title = $id ? html_entity_decode( get_the_title( $id ) ) : '';
		$long  = $id && ! $c['end'];
		$kept  = Marketing::kept_form( 'promo' );
		if ( $kept && (int) ( $kept['id'] ?? 0 ) === $id ) {
			$title = (string) ( $kept['name'] ?? '' );
			$long  = 'long' === ( $kept['time_mode'] ?? '' );
			$c     = array_merge( $c, array_intersect_key( $kept['config'] ?? array(), $c ) );
		}
		if ( ! in_array( $c['type'], Promotions::TYPES, true ) ) {
			$c['type'] = 'spend_amount';
		}
		$cur      = get_woocommerce_currency();
		$in_other = array();
		foreach ( get_posts( array( 'post_type' => Promotions::CPT, 'post_status' => 'publish', 'posts_per_page' => 200, 'fields' => 'ids', 'exclude' => $id ? array( $id ) : array(), 'no_found_rows' => true ) ) as $oid ) {
			$o = Promotions::config( (int) $oid );
			if ( 'products' === $o['scope'] && 'ended' !== Promotions::status( (int) $oid ) ) {
				foreach ( $o['products'] as $pid ) {
					$in_other[ (int) $pid ] = html_entity_decode( get_the_title( (int) $oid ) );
				}
			}
		}

		echo '<div class="wrap pc-pl pc-mk pc-mk-edit">';
		Marketing::notices();
		Marketing::edit_head( self::url(), $id ? __( 'Edit promotion', 'peptide-core' ) : __( 'New promotion', 'peptide-core' ) );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pc-mk-form" data-kind="promotion">';
		echo '<input type="hidden" name="action" value="pc_promo_save"><input type="hidden" name="id" value="' . (int) $id . '">';
		wp_nonce_field( 'pc_promo_save' );

		echo '<div class="pc-mk-top">';
		Marketing::card_open( __( 'Basic information', 'peptide-core' ) );
		echo '<div class="pc-mk-field">' . Marketing::label( __( 'Promotion name', 'peptide-core' ), true, __( 'Shown to customers in the cart, at checkout and on the order as the name of the discount, and as the title of the promotion page.', 'peptide-core' ), 'pc-mk-name' ) . Marketing::counted( 'name', $title, Promotions::NAME_MAX, __( 'Enter the promotion name, e.g. Black Friday Discounts', 'peptide-core' ), 'pc-mk-name', true ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		Marketing::time_fields( (int) $c['start'], (int) $c['end'], $long );
		echo '<div class="pc-mk-field">' . Marketing::label( __( 'Discount type', 'peptide-core' ), false, __( "Spend & save: spend \$300, save \$25.\nSpend & get % off: spend \$300, get 10% off.\nBuy items & save: buy 3 items, save \$10.\nBuy items & get % off: buy 3 items, get 15% off.\nXth item % off: the 2nd item gets 50% off (Shopname “2nd item at 50%”).\nBundle price: any 3 items for \$99.\nBuy X, get Y free: buy 3 items, the cheapest one is free.\nOnly the products in this promotion count.", 'peptide-core' ) ) . '<div class="pc-mk-radios pc-mk-types">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		foreach ( self::type_labels() as $k => $text ) {
			echo Marketing::radio( 'type', $k, $c['type'], $text ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div></div>';
		Marketing::card_close();
		$page_id = Promotions::page_id();
		$url     = $id && 'publish' === get_post_status( $id ) ? Promotions::detail_url( $id ) : Promotions::list_url();
		Marketing::seo_card( $url, $page_id, __( 'The promotions page is created automatically when the first promotion is enabled.', 'peptide-core' ) );
		echo '</div>';

		Marketing::card_open( __( 'Discount rules', 'peptide-core' ) );
		$tiers = $c['tiers'] ? $c['tiers'] : array( array( 'min' => '', 'value' => '' ) );
		echo '<div class="pc-mk-tiers" data-templates="' . esc_attr( (string) wp_json_encode( self::tier_templates() ) ) . '" data-currency="' . esc_attr( $cur ) . '">';
		foreach ( $tiers as $i => $t ) {
			self::tier_row( (int) $i, $c['type'], $t, $cur );
		}
		echo '</div>';
		echo '<p class="pc-mk-unlimited" data-types="' . esc_attr( implode( ' ', Promotions::REPEATABLE ) ) . '"><label><input type="checkbox" name="unlimited" value="1"' . checked( $c['unlimited'], true, false ) . '> ' . esc_html__( 'No upper limit', 'peptide-core' ) . '</label> ' . Marketing::help( __( "The discount repeats each time the threshold is reached again: spend \$100 save \$10 gives \$20 off at \$200, \$30 off at \$300, and so on.\nWorks with one tier only.", 'peptide-core' ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		/* translators: %d: maximum number of tiers */
		echo '<p class="pc-mk-tier-add-row"><button type="button" class="button pc-mk-tier-add">+ ' . esc_html__( 'Add tier', 'peptide-core' ) . '</button> <span class="pc-pl-muted">' . esc_html( sprintf( __( 'Up to %d tiers. The highest tier reached applies.', 'peptide-core' ), Promotions::MAX_TIERS ) ) . '</span></p>';
		Marketing::card_close();

		Marketing::scope_card(
			$c['scope'],
			$c['products'],
			$c['categories'],
			$c['sort'],
			array(
				'all'        => __( 'Every product can use this promotion; products that are in another promotion are left out automatically.', 'peptide-core' ),
				'products'   => __( 'Only the chosen products can use this promotion. Choose products that are not in another promotion at the same time.', 'peptide-core' ),
				'categories' => __( 'The products of the chosen collections can use this promotion. A product in several collections uses the promotion of the most recently created collection promotion.', 'peptide-core' ),
			),
			$in_other
		);

		Marketing::card_open( __( 'Usage rules', 'peptide-core' ), 'pc-mk-rules' );
		echo '<p>' . esc_html__( 'You can choose all products, some products or collections for this promotion.', 'peptide-core' ) . '</p><p>' . esc_html__( 'Notes:', 'peptide-core' ) . '</p><ol>';
		echo '<li>' . esc_html__( 'At the same time, a product or a collection can only be in one promotion, and only one promotion can cover all products.', 'peptide-core' ) . '</li>';
		echo '<li>' . esc_html__( 'In an order, a product only joins one promotion, by priority: specific products > specific collections > all products.', 'peptide-core' ) . '</li></ol>';
		echo '<p>' . esc_html__( 'Example: product a, collection A and all products each have a promotion at the same time, and product a is in collection A. When a customer buys product a, the promotion of product a applies (if its threshold is not reached, there is no discount); product a does not join the promotions of collection A or of all products.', 'peptide-core' ) . '</p>';
		Marketing::card_close();

		Marketing::save_bar( self::url() );
		echo '</form></div>';
	}

	/** 一个层级：标题、按类型写法排好的输入框、删除。 */
	private static function tier_row( int $i, string $type, array $t, string $cur ): void {
		$tpl = self::tier_templates()[ $type ] ?? self::tier_templates()['spend_amount'];
		/* translators: %d: tier number */
		echo '<div class="pc-mk-tier"><div class="pc-mk-tier__head"><strong>' . esc_html( sprintf( __( 'Tier %d', 'peptide-core' ), $i + 1 ) ) . '</strong> <button type="button" class="button-link pc-mk-tier-del"' . ( 0 === $i ? ' hidden' : '' ) . '>' . esc_html__( 'Delete', 'peptide-core' ) . '</button></div><div class="pc-mk-tier__row">';
		foreach ( preg_split( '/(\{min\}|\{value\}|\{cur\})/', $tpl, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY ) as $part ) {
			if ( '{min}' === $part || '{value}' === $part ) {
				$key = '{min}' === $part ? 'min' : 'value';
				echo '<input type="number" min="0" step="0.01" class="pc-mk-num" data-k="' . esc_attr( $key ) . '" name="tiers[' . (int) $i . '][' . esc_attr( $key ) . ']" value="' . esc_attr( (string) ( $t[ $key ] ?? '' ) ) . '" placeholder="' . esc_attr__( 'Enter', 'peptide-core' ) . '">';
			} elseif ( '{cur}' === $part ) {
				echo '<span class="pc-mk-unit">' . esc_html( $cur ) . '</span>';
			} elseif ( '' !== trim( $part ) ) {
				echo '<span class="pc-mk-unit">' . esc_html( trim( $part ) ) . '</span>';
			}
		}
		echo '</div></div>';
	}

	// ---------- 保存 ----------

	/**
	 * 编辑页表单 => [名称, clean() 的输入, 时间错误]（测试里直接调用）。
	 *
	 * @param array<string,mixed> $in
	 */
	public static function from_form( array $in ): array {
		[ $start, $end, $errors ] = Marketing::read_time( $in );
		$raw = array(
			'type'       => (string) ( $in['type'] ?? '' ),
			'tiers'      => is_array( $in['tiers'] ?? null ) ? array_values( $in['tiers'] ) : array(),
			'unlimited'  => ! empty( $in['unlimited'] ),
			'start'      => $start,
			'end'        => $end,
			'scope'      => (string) ( $in['scope'] ?? 'all' ),
			'products'   => (array) ( $in['products'] ?? array() ),
			'categories' => (array) ( $in['categories'] ?? array() ),
			'sort'       => (string) ( $in['sort'] ?? '' ),
			'public'     => true,
		);
		return array( (string) ( $in['name'] ?? '' ), $raw, $errors );
	}

	public static function save(): void {
		Marketing::guard( 'pc_promo_save' );
		$in                          = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 在 Promotions::clean() 里逐项清理。
		$id                          = absint( $in['id'] ?? 0 );
		[ $name, $raw, $time_errors ] = self::from_form( $in );
		$enabled                     = ! $id || 'publish' === get_post_status( $id );
		$errors                      = $time_errors;
		if ( ! $errors ) {
			[ $saved, $errors ] = Promotions::store( $id, $name, $raw, $enabled );
		}
		if ( $errors ) {
			// 层级按填写的原样保留（clean() 会去掉不合理的层级）。
			$tiers = array_values( array_filter( $raw['tiers'], 'is_array' ) );
			$keep  = array_merge( Promotions::clean( $raw )[0], array( 'tiers' => array_slice( $tiers, 0, Promotions::MAX_TIERS ), 'unlimited' => $raw['unlimited'] ) );
			Marketing::keep_form( 'promo', array( 'id' => $id, 'name' => $name, 'time_mode' => (string) ( $in['time_mode'] ?? '' ), 'config' => $keep ), $errors );
			wp_safe_redirect( self::url( $id ? array( 'edit' => $id ) : array( 'new' => 1 ) ) );
			exit;
		}
		wp_safe_redirect( self::url( array( 'pc_saved' => 1 ) ) );
		exit;
	}

	public static function toggle(): void {
		Marketing::guard( 'pc_promo_toggle' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$errors = Promotions::set_enabled( absint( $_GET['id'] ?? 0 ), ! empty( $_GET['on'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( $errors ) {
			Marketing::flash_errors( $errors );
			wp_safe_redirect( self::url() );
		} else {
			wp_safe_redirect( self::url( array( 'pc_done' => 1 ) ) );
		}
		exit;
	}

	public static function bulk(): void {
		Marketing::guard( 'pc_promo_bulk' );
		$op     = sanitize_key( wp_unslash( $_POST['bulk'] ?? '' ) );
		$done   = 0;
		$errors = array();
		foreach ( Marketing::posted_ids() as $id ) {
			if ( Promotions::CPT !== get_post_type( $id ) ) {
				continue;
			}
			if ( 'delete' === $op ) {
				wp_delete_post( $id, true ); // 满减活动没有回收站页面，直接删除；订单里的费用行保留活动名称。
				++$done;
				continue;
			}
			$e = Promotions::set_enabled( $id, 'enable' === $op );
			if ( $e ) {
				$errors = array_merge( $errors, array_map( static fn( $m ) => html_entity_decode( get_the_title( $id ) ) . ': ' . $m, $e ) );
			} else {
				++$done;
			}
		}
		if ( $errors ) {
			Marketing::flash_errors( $errors );
		}
		wp_safe_redirect( self::url( array( 'pc_done' => $done ) ) );
		exit;
	}

	public static function save_settings(): void {
		Marketing::guard( 'pc_promo_settings' );
		Marketing::use_page( absint( $_POST['page_id'] ?? 0 ), Promotions::PAGE_OPTION, Promotions::SHORTCODE, 'Offers', 'offers' );
		wp_safe_redirect( self::url( array( 'pc_saved' => 1 ) ) );
		exit;
	}
}
