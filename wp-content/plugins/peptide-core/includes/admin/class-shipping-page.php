<?php
/**
 * 店铺设置 → 物流设置（SHIPPING-PLANS-01），版式参照 Shopname 的“物流管理”：
 * 列表页分“通用物流 / 自定义物流”两个标签，编辑页有商品（自定义方案）、区域、运费三块。
 *
 * 区域和运费在浏览器里编辑（assets/plans-editor.js），提交时以 JSON 放在隐藏字段里，服务端用 Plans::normalize 清理。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Shipping\Plans;

defined( 'ABSPATH' ) || exit;

final class Shipping_Page {

	public const SLUG = 'pc-shipping';
	public const CAP  = 'manage_woocommerce';

	public static function register_hooks(): void {
		add_action( 'admin_post_pc_shipping_save', array( self::class, 'save' ) );
		add_action( 'admin_post_pc_shipping_delete', array( self::class, 'delete' ) );
		add_action( 'admin_post_pc_shipping_rules', array( self::class, 'save_rules' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	public static function enqueue(): void {
		if ( self::SLUG !== ( $_GET['page'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		Plans_Editor::enqueue( 'shipping' );
	}

	// ---------- 列表 ----------

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['plan'] ) || isset( $_GET['new'] ) ) {
			self::render_edit( absint( $_GET['plan'] ?? 0 ), sanitize_key( wp_unslash( $_GET['new'] ?? '' ) ) );
			return;
		}
		$tab    = 'custom' === ( $_GET['tab'] ?? '' ) ? 'custom' : 'general';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$rules = Plans::rules();

		echo '<div class="wrap pc-pl">';
		Plans_Editor::notices();
		echo '<div class="pc-pl-head"><h1>' . esc_html__( 'Shipping management', 'peptide-core' ) . '</h1>';
		echo '<button type="button" class="button-link pc-pl-rules-open"><span class="dashicons dashicons-admin-generic"></span> ' . esc_html__( 'Rate order and calculation rules', 'peptide-core' ) . '</button>';
		echo '<a class="button button-primary pc-pl-new" href="' . esc_url( self::url( array( 'new' => $tab ) ) ) . '">+ ' . esc_html( 'custom' === $tab ? __( 'New custom shipping', 'peptide-core' ) : __( 'New general shipping', 'peptide-core' ) ) . '</a></div>';

		self::status_notice();

		echo '<div class="pc-pl-card"><nav class="pc-pl-tabs">';
		foreach ( array( 'general' => __( 'General shipping', 'peptide-core' ), 'custom' => __( 'Custom shipping', 'peptide-core' ) ) as $k => $label ) {
			echo '<a href="' . esc_url( self::url( array( 'tab' => $k ) ) ) . '" class="' . ( $k === $tab ? 'is-active' : '' ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav><div class="pc-pl-body">';
		if ( 'general' === $tab ) {
			echo '<h2>' . esc_html__( 'General shipping plans', 'peptide-core' ) . '</h2><div class="pc-pl-info">' . esc_html__( 'These products use the general shipping plans:', 'peptide-core' ) . '<br>1. ' . esc_html__( 'every product that is not in a custom shipping plan;', 'peptide-core' ) . '<br>2. ' . esc_html__( 'new products, automatically.', 'peptide-core' ) . '</div>';
		} else {
			echo '<div class="pc-pl-h2row"><h2>' . esc_html__( 'Custom shipping plans', 'peptide-core' ) . '</h2><form method="get" class="pc-pl-search"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="custom"><input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search by product title', 'peptide-core' ) . '"><button class="button">' . esc_html__( 'Search', 'peptide-core' ) . '</button></form></div>';
			echo '<div class="pc-pl-info">' . esc_html__( 'Set separate shipping rates for specific products.', 'peptide-core' ) . '</div>';
		}
		$plans = Plans::of_type( $tab );
		if ( '' !== $search ) {
			$plans = array_filter(
				$plans,
				static function ( $p ) use ( $search ) {
					foreach ( $p['products'] as $pid ) {
						if ( false !== stripos( get_the_title( $pid ), $search ) ) {
							return true;
						}
					}
					return false;
				}
			);
		}
		if ( ! $plans ) {
			echo '<p class="pc-pl-empty">' . esc_html( '' !== $search ? __( 'No plan contains a matching product.', 'peptide-core' ) : __( 'No plans yet.', 'peptide-core' ) ) . '</p>';
		}
		foreach ( $plans as $p ) {
			self::plan_card( $p );
		}
		echo '</div></div>';

		// 运费排序与计算规则。
		echo '<dialog class="pc-pl-dialog pc-pl-rules"><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="pc_shipping_rules">';
		wp_nonce_field( 'pc_shipping_rules' );
		echo '<h2>' . esc_html__( 'Rate order and calculation rules', 'peptide-core' ) . '</h2>';
		echo '<p><strong>' . esc_html__( 'Order of rates at checkout', 'peptide-core' ) . '</strong></p>';
		echo '<label class="pc-pl-radio"><input type="radio" name="sort" value="price"' . checked( 'price', $rules['sort'], false ) . '> ' . esc_html__( 'Cheapest first', 'peptide-core' ) . '</label>';
		echo '<label class="pc-pl-radio"><input type="radio" name="sort" value="manual"' . checked( 'manual', $rules['sort'], false ) . '> ' . esc_html__( 'In the order of the plan', 'peptide-core' ) . '</label>';
		echo '<p><strong>' . esc_html__( 'When the cart has products from several plans', 'peptide-core' ) . '</strong></p>';
		echo '<label class="pc-pl-radio"><input type="radio" name="combine" value="sum"' . checked( 'sum', $rules['combine'], false ) . '> ' . esc_html__( 'Add up the cheapest rate of each plan', 'peptide-core' ) . '</label>';
		echo '<label class="pc-pl-radio"><input type="radio" name="combine" value="max"' . checked( 'max', $rules['combine'], false ) . '> ' . esc_html__( 'Charge only the highest of those rates', 'peptide-core' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'A cart with products from one plan shows every matching rate of that plan. If any product has no matching rate for the address, checkout shows that the order cannot be shipped there.', 'peptide-core' ) . '</p>';
		echo '<div class="pc-pl-dialog__foot"><button type="button" class="button pc-pl-close">' . esc_html__( 'Cancel', 'peptide-core' ) . '</button> <button class="button button-primary">' . esc_html__( 'Save', 'peptide-core' ) . '</button></div></form></dialog>';
		echo '</div>';
	}

	/** 方案状态：是否接管运费、可销售国家。 */
	private static function status_notice(): void {
		if ( Plans::active() ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Shipping plans are in use: checkout only offers the rates below. The original WooCommerce shipping zones are no longer used.', 'peptide-core' ) . '</p></div>';
		} else {
			$zones = array_map( static fn( $z ) => $z['zone_name'], \WC_Shipping_Zones::get_zones() );
			/* translators: %s: zone names */
			echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( __( 'No plan has regions yet, so checkout still uses the original WooCommerce shipping zones (%s). Once a plan has regions, the plans take over.', 'peptide-core' ), $zones ? implode( ', ', $zones ) : __( 'none', 'peptide-core' ) ) ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=shipping' ) ) . '">' . esc_html__( 'Shipping zones', 'peptide-core' ) . '</a></p></div>';
		}
		$ship = array_keys( WC()->countries->get_shipping_countries() );
		$used = array();
		foreach ( Plans::all() as $p ) {
			foreach ( $p['regions'] as $r ) {
				$used[ $r['country'] ] = true;
			}
		}
		$blocked = array_diff( array_keys( $used ), $ship );
		if ( $blocked ) {
			/* translators: 1: number of countries, 2: allowed countries */
			echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( __( '%1$d countries in your plans cannot be chosen at checkout, because the store only sells to: %2$s.', 'peptide-core' ), count( $blocked ), implode( ', ', array_slice( $ship, 0, 10 ) ) . ( count( $ship ) > 10 ? '…' : '' ) ) ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=general' ) ) . '">' . esc_html__( 'Selling locations', 'peptide-core' ) . '</a></p></div>';
		}
	}

	private static function plan_card( array $p ): void {
		/* translators: %d: number of countries */
		$meta = sprintf( _n( '%d country/region', '%d countries/regions', count( $p['regions'] ), 'peptide-core' ), count( $p['regions'] ) );
		if ( 'custom' === $p['type'] ) {
			/* translators: %d: number of products */
			$meta .= ', ' . sprintf( _n( '%d product selected', '%d products selected', count( $p['products'] ), 'peptide-core' ), count( $p['products'] ) );
		}
		echo '<div class="pc-pl-plan"><div class="pc-pl-plan__head"><strong>' . esc_html( $p['name'] ) . '</strong> <span class="pc-pl-muted">(' . esc_html( $meta ) . ')</span>';
		echo '<a class="button" href="' . esc_url( self::url( array( 'plan' => $p['id'] ) ) ) . '">' . esc_html__( 'Edit plan', 'peptide-core' ) . '</a></div>';
		echo '<table class="pc-pl-table"><thead><tr><th>' . esc_html__( 'Rate name', 'peptide-core' ) . '</th><th>' . esc_html__( 'Fee rule', 'peptide-core' ) . '</th><th>' . esc_html__( 'Shipping fee', 'peptide-core' ) . '</th></tr></thead><tbody>';
		if ( ! $p['rates'] ) {
			echo '<tr><td colspan="3" class="pc-pl-muted">' . esc_html__( 'No rates yet: products in this plan cannot be shipped.', 'peptide-core' ) . '</td></tr>';
		}
		foreach ( $p['rates'] as $r ) {
			echo '<tr class="' . ( $r['enabled'] ? '' : 'is-off' ) . '"><td>' . esc_html( $r['name'] ) . ( $r['enabled'] ? '' : ' <span class="pc-pl-tag">' . esc_html__( 'Off', 'peptide-core' ) . '</span>' ) . '</td><td>' . esc_html( Plans::rule_text( $r ) ) . '</td><td>' . esc_html( wc_format_decimal( $r['fee'], 2 ) . ' ' . get_woocommerce_currency() ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	// ---------- 编辑 ----------

	private static function render_edit( int $id, string $new ): void {
		$plan = $id ? Plans::get( $id ) : null;
		if ( $id && ! $plan ) {
			echo '<div class="wrap"><p>' . esc_html__( 'This plan does not exist.', 'peptide-core' ) . '</p></div>';
			return;
		}
		if ( ! $plan ) {
			$plan = Plans::normalize( array( 'type' => 'custom' === $new ? 'custom' : 'general', 'name' => '' ) );
		}
		$custom = 'custom' === $plan['type'];
		$title  = $plan['id']
			? ( $custom ? __( 'Edit custom shipping plan', 'peptide-core' ) : __( 'Edit general shipping plan', 'peptide-core' ) )
			: ( $custom ? __( 'New custom shipping plan', 'peptide-core' ) : __( 'New general shipping plan', 'peptide-core' ) );
		$others = array();
		foreach ( Plans::all() as $o ) {
			if ( $o['id'] !== $plan['id'] && $o['rates'] ) {
				$others[] = array( 'id' => $o['id'], 'name' => $o['name'], 'rates' => $o['rates'] );
			}
		}

		echo '<div class="wrap pc-pl">';
		Plans_Editor::notices();
		echo '<p class="pc-ss-back"><a href="' . esc_url( self::url( array( 'tab' => $plan['type'] ) ) ) . '">‹ ' . esc_html__( 'Back to shipping management', 'peptide-core' ) . '</a></p>';
		echo '<h1>' . esc_html( $title ) . '</h1>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pc-pl-form" data-kind="shipping">';
		echo '<input type="hidden" name="action" value="pc_shipping_save"><input type="hidden" name="id" value="' . esc_attr( (string) $plan['id'] ) . '"><input type="hidden" name="type" value="' . esc_attr( $plan['type'] ) . '">';
		wp_nonce_field( 'pc_shipping_save' );
		echo '<script type="application/json" class="pc-pl-data">' . wp_json_encode( array( 'regions' => $plan['regions'], 'rates' => $plan['rates'], 'others' => $others, 'icons' => self::icon_urls( $plan['rates'] ) ) ) . '</script>';
		echo '<input type="hidden" name="regions" value=""><input type="hidden" name="rates" value="">';

		echo '<div class="pc-pl-card pc-pl-pad"><label class="pc-bc-label"><span class="pc-bc-req">*</span> ' . esc_html__( 'Plan name', 'peptide-core' ) . '</label><input type="text" name="name" class="large-text" required maxlength="100" value="' . esc_attr( $plan['name'] ) . '" placeholder="' . esc_attr__( 'e.g. Worldwide Express Service', 'peptide-core' ) . '"></div>';

		if ( $custom ) {
			Plans_Editor::products_section( $plan['products'], __( '(Products chosen here use this plan’s rates in its regions.)', 'peptide-core' ), Plans::product_map(), (int) $plan['id'], array_column( Plans::all(), 'name', 'id' ) );
		}
		Plans_Editor::regions_section();

		echo '<div class="pc-pl-card"><div class="pc-pl-section"><strong>' . esc_html__( 'Shipping rates', 'peptide-core' ) . '</strong> <span class="pc-pl-muted">' . esc_html__( 'Keep the ranges continuous, otherwise some buyers cannot place an order. Example: 0-40, 40-50, 50-60, 60 and above (the lower bound is included, the upper bound is not).', 'peptide-core' ) . '</span>';
		echo '<span class="pc-pl-actions"><button type="button" class="button pc-pl-copy"' . ( $others ? '' : ' disabled' ) . '>' . esc_html__( 'Copy rates', 'peptide-core' ) . '</button> <button type="button" class="button button-primary pc-pl-rate-add">+ ' . esc_html__( 'Add rate', 'peptide-core' ) . '</button></span></div>';
		echo '<table class="pc-pl-table pc-pl-rates"><thead><tr><th>' . esc_html__( 'Actions', 'peptide-core' ) . '</th><th>' . esc_html__( 'Enabled', 'peptide-core' ) . '</th><th>' . esc_html__( 'Icon', 'peptide-core' ) . '</th><th>' . esc_html__( 'Rate name', 'peptide-core' ) . '</th><th>' . esc_html__( 'ZIP range', 'peptide-core' ) . '</th><th>' . esc_html__( 'Fee rule', 'peptide-core' ) . '</th><th>' . esc_html__( 'Fee', 'peptide-core' ) . '</th></tr></thead><tbody></tbody></table></div>';

		echo '<div class="pc-bc-bar"><button class="button button-primary button-hero">' . esc_html__( 'Save', 'peptide-core' ) . '</button> <a class="button button-hero" href="' . esc_url( self::url( array( 'tab' => $plan['type'] ) ) ) . '">' . esc_html__( 'Cancel', 'peptide-core' ) . '</a>';
		if ( $plan['id'] ) {
			echo ' <a class="button button-hero pc-pl-delete" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pc_shipping_delete&id=' . $plan['id'] ), 'pc_shipping_delete' ) ) . '">' . esc_html__( 'Delete this plan', 'peptide-core' ) . '</a>';
		}
		echo '</div></form>';
		Plans_Editor::dialogs( 'shipping' );
		echo '</div>';
	}

	/** @return array<int,string> 运费图标 附件ID => 缩略图地址。 */
	private static function icon_urls( array $rates ): array {
		$out = array();
		foreach ( $rates as $r ) {
			if ( $r['icon'] ) {
				$out[ $r['icon'] ] = (string) wp_get_attachment_image_url( $r['icon'], 'thumbnail' );
			}
		}
		return $out;
	}

	// ---------- 保存 ----------

	private static function guard( string $action ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'peptide-core' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * 把后台表单内容转成方案（测试里直接调用）。
	 *
	 * @param array<string,mixed> $in
	 */
	public static function from_form( array $in ): array {
		return array(
			'id'       => absint( $in['id'] ?? 0 ),
			'type'     => (string) ( $in['type'] ?? 'general' ),
			'name'     => (string) ( $in['name'] ?? '' ),
			'products' => (array) ( $in['products'] ?? array() ),
			'regions'  => (array) json_decode( (string) ( $in['regions'] ?? '[]' ), true ),
			'rates'    => (array) json_decode( (string) ( $in['rates'] ?? '[]' ), true ),
		);
	}

	public static function save(): void {
		self::guard( 'pc_shipping_save' );
		$plan = Plans::normalize( self::from_form( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '' === $plan['name'] ) {
			$back = $plan['id'] ? array( 'plan' => $plan['id'] ) : array( 'new' => $plan['type'] );
			wp_safe_redirect( self::url( $back + array( 'pc_error' => rawurlencode( __( 'Enter a plan name.', 'peptide-core' ) ) ) ) );
			exit;
		}
		$plan = Plans::save( $plan );
		wp_safe_redirect( self::url( array( 'tab' => $plan['type'], 'pc_saved' => 1 ) ) );
		exit;
	}

	public static function delete(): void {
		self::guard( 'pc_shipping_delete' );
		$plan = Plans::get( absint( $_GET['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $plan ) {
			Plans::delete( $plan['id'] );
		}
		wp_safe_redirect( self::url( array( 'tab' => $plan ? $plan['type'] : 'general', 'pc_deleted' => 1 ) ) );
		exit;
	}

	public static function save_rules(): void {
		self::guard( 'pc_shipping_rules' );
		Plans::save_rules( array( 'combine' => sanitize_key( wp_unslash( $_POST['combine'] ?? '' ) ), 'sort' => sanitize_key( wp_unslash( $_POST['sort'] ?? '' ) ) ) );
		wp_safe_redirect( self::url( array( 'pc_saved' => 1 ) ) );
		exit;
	}
}
