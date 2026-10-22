<?php
/**
 * 物流方案和税费方案共用的编辑部件（SHIPPING-PLANS-01 / TAX-PLANS-01）：选择商品、选择区域、弹窗、脚本数据。
 *
 * 商品搜索用 WooCommerce 后台自带的商品搜索下拉（wc-product-search）。区域和运费由 assets/plans-editor.js 在浏览器里编辑。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

defined( 'ABSPATH' ) || exit;

final class Plans_Editor {

	/** @param string $kind shipping | tax */
	public static function enqueue( string $kind ): void {
		$ver = static fn( string $f ): string => PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( PEPTIDE_CORE_DIR . '/assets/' . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_style( 'peptide-core-store-settings', plugins_url( 'assets/store-settings.css', PEPTIDE_CORE_FILE ), array(), $ver( 'store-settings.css' ) );
		wp_enqueue_style( 'peptide-core-plans', plugins_url( 'assets/plans.css', PEPTIDE_CORE_FILE ), array( 'peptide-core-store-settings' ), $ver( 'plans.css' ) );
		wp_enqueue_media();
		wp_enqueue_script( 'peptide-core-plans', plugins_url( 'assets/plans-editor.js', PEPTIDE_CORE_FILE ), array( 'jquery', 'wc-enhanced-select' ), $ver( 'plans-editor.js' ), true );
		wp_localize_script(
			'peptide-core-plans',
			'pcPlans',
			array(
				'kind'      => $kind,
				'countries' => WC()->countries->get_countries(),
				'states'    => array_filter( WC()->countries->get_states() ),
				'currency'  => get_woocommerce_currency(),
				'weight'    => get_option( 'woocommerce_weight_unit', 'kg' ),
				'strings'   => array(
					'all'          => __( 'All', 'peptide-core' ),
					'edit'         => __( 'Edit', 'peptide-core' ),
					'remove'       => __( 'Delete', 'peptide-core' ),
					'noRegions'    => __( 'No regions yet. Use “Add countries”.', 'peptide-core' ),
					'noRates'      => __( 'No rates yet. Use “Add rate”.', 'peptide-core' ),
					/* translators: %d: number of countries */
					'selected'     => __( '%d countries/regions selected', 'peptide-core' ),
					'noLimit'      => __( 'no limit', 'peptide-core' ),
					'subtotal'     => __( 'Order amount', 'peptide-core' ),
					'quantity'     => __( 'Items', 'peptide-core' ),
					'weightRule'   => __( 'Weight', 'peptide-core' ),
					'anyZip'       => '—',
					'confirmClear' => __( 'Remove all regions from this plan?', 'peptide-core' ),
					'confirmDel'   => __( 'Delete this plan? This cannot be undone.', 'peptide-core' ),
					'nameNeeded'   => __( 'Enter a rate name.', 'peptide-core' ),
					'rangeBad'     => __( 'The upper limit must be greater than the lower limit.', 'peptide-core' ),
					'pickIcon'     => __( 'Choose an icon', 'peptide-core' ),
					'useIcon'      => __( 'Use this image', 'peptide-core' ),
					/* translators: %s: plan name */
					'moved'        => __( 'Will be moved out of “%s”', 'peptide-core' ),
					'rate'         => __( 'Tax rate (%)', 'peptide-core' ),
					'rateBad'      => __( 'Enter a tax rate between 0 and 100 for every region.', 'peptide-core' ),
				),
			)
		);
	}

	public static function notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['pc_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved.', 'peptide-core' ) . '</p></div>';
		}
		if ( isset( $_GET['pc_deleted'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Plan deleted.', 'peptide-core' ) . '</p></div>';
		}
		if ( isset( $_GET['pc_error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['pc_error'] ) ) ) . '</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * 选择商品。
	 *
	 * @param int[]           $ids     已选商品。
	 * @param array<int,int>  $map     商品 ID => 所在方案 ID（用于提示“将从其他方案移出”）。
	 */
	public static function products_section( array $ids, string $note, array $map, int $plan_id, array $plan_names = array() ): void {
		echo '<div class="pc-pl-card"><div class="pc-pl-section"><strong>' . esc_html__( 'Products', 'peptide-core' ) . '</strong> <span class="pc-pl-muted">' . esc_html( $note ) . '</span>';
		echo '<span class="pc-pl-actions"><select class="wc-product-search pc-pl-product-search" data-placeholder="' . esc_attr__( 'Search products to add…', 'peptide-core' ) . '" data-action="woocommerce_json_search_products" data-allow_clear="true" style="width:320px"></select></span></div>';
		echo '<table class="pc-pl-table pc-pl-products"><thead><tr><th class="pc-pl-col-act">' . esc_html__( 'Actions', 'peptide-core' ) . '</th><th>' . esc_html__( 'Product', 'peptide-core' ) . '</th><th class="pc-pl-col-status">' . esc_html__( 'Status', 'peptide-core' ) . '</th></tr></thead><tbody>';
		foreach ( $ids as $pid ) {
			$p = wc_get_product( $pid );
			if ( ! $p ) {
				continue;
			}
			echo self::product_row( $p ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '<tr class="pc-pl-products__empty"' . ( $ids ? ' hidden' : '' ) . '><td colspan="3" class="pc-pl-muted">' . esc_html__( 'No products selected.', 'peptide-core' ) . '</td></tr>';
		echo '</tbody></table>';
		$other = array();
		foreach ( $map as $pid => $pl ) {
			if ( $pl !== $plan_id ) {
				$other[ $pid ] = $plan_names[ $pl ] ?? (string) $pl;
			}
		}
		echo '<script type="application/json" class="pc-pl-product-plans">' . wp_json_encode( (object) $other ) . '</script></div>';
	}

	public static function product_row( \WC_Product $p ): string {
		$img    = (string) wp_get_attachment_image_url( (int) $p->get_image_id(), 'thumbnail' );
		$status = 'publish' === $p->get_status() ? '<span class="pc-pl-badge is-on">' . esc_html__( 'Published', 'peptide-core' ) . '</span>' : '<span class="pc-pl-badge">' . esc_html__( 'Unpublished', 'peptide-core' ) . '</span>';
		$code   = $p->get_sku() ? 'SKU: ' . $p->get_sku() : '#' . $p->get_id();
		return '<tr data-id="' . esc_attr( (string) $p->get_id() ) . '"><td class="pc-pl-col-act"><button type="button" class="button-link pc-pl-product-del" aria-label="' . esc_attr__( 'Delete', 'peptide-core' ) . '"><span class="dashicons dashicons-trash"></span></button><input type="hidden" name="products[]" value="' . esc_attr( (string) $p->get_id() ) . '"></td>'
			. '<td><span class="pc-pl-product">' . ( $img ? '<img src="' . esc_url( $img ) . '" alt="">' : '<span class="pc-pl-noimg"></span>' ) . '<span><strong>' . esc_html( $p->get_name() ) . '</strong><small>' . esc_html( $code ) . '</small></span></span></td><td class="pc-pl-col-status">' . $status . '</td></tr>';
	}

	public static function regions_section(): void {
		echo '<div class="pc-pl-card"><div class="pc-pl-section"><strong>' . esc_html__( 'Regions', 'peptide-core' ) . '</strong> <button type="button" class="button-link pc-pl-regions-toggle"><span class="pc-pl-regions-count"></span> <span class="dashicons dashicons-arrow-down-alt2"></span></button>';
		echo '<span class="pc-pl-actions"><button type="button" class="button-link pc-pl-regions-clear">' . esc_html__( 'Remove all', 'peptide-core' ) . '</button> <button type="button" class="button button-primary pc-pl-regions-add">+ ' . esc_html__( 'Add countries', 'peptide-core' ) . '</button></span></div>';
		echo '<div class="pc-pl-scroll"><table class="pc-pl-table pc-pl-regions"><thead><tr><th class="pc-pl-col-act">' . esc_html__( 'Actions', 'peptide-core' ) . '</th><th>' . esc_html__( 'Country/region', 'peptide-core' ) . '</th><th>' . esc_html__( 'States/provinces', 'peptide-core' ) . '</th></tr></thead><tbody></tbody></table></div></div>';
	}

	/** 浏览器里用的弹窗：添加国家、选择州 / 省、编辑运费、复制运费。 */
	public static function dialogs( string $kind ): void {
		echo '<dialog class="pc-pl-dialog pc-pl-countries"><h2>' . esc_html__( 'Add countries', 'peptide-core' ) . '</h2>';
		echo '<p><input type="search" class="large-text pc-pl-countries-search" placeholder="' . esc_attr__( 'Search countries', 'peptide-core' ) . '"></p>';
		echo '<p><label><input type="checkbox" class="pc-pl-countries-all"> ' . esc_html__( 'Select all shown', 'peptide-core' ) . '</label></p>';
		echo '<div class="pc-pl-checklist pc-pl-countries-list"></div>';
		echo '<div class="pc-pl-dialog__foot"><button type="button" class="button pc-pl-close">' . esc_html__( 'Cancel', 'peptide-core' ) . '</button> <button type="button" class="button button-primary pc-pl-countries-ok">' . esc_html__( 'Add', 'peptide-core' ) . '</button></div></dialog>';

		echo '<dialog class="pc-pl-dialog pc-pl-states"><h2 class="pc-pl-states-title"></h2>';
		echo '<p><label class="pc-pl-radio"><input type="radio" name="pc_pl_states_mode" value="all"> ' . esc_html__( 'All states/provinces', 'peptide-core' ) . '</label> <label class="pc-pl-radio"><input type="radio" name="pc_pl_states_mode" value="some"> ' . esc_html__( 'Only these:', 'peptide-core' ) . '</label></p>';
		echo '<div class="pc-pl-checklist pc-pl-states-list"></div>';
		echo '<div class="pc-pl-dialog__foot"><button type="button" class="button pc-pl-close">' . esc_html__( 'Cancel', 'peptide-core' ) . '</button> <button type="button" class="button button-primary pc-pl-states-ok">' . esc_html__( 'OK', 'peptide-core' ) . '</button></div></dialog>';

		if ( 'shipping' !== $kind ) {
			return;
		}
		echo '<dialog class="pc-pl-dialog pc-pl-rate"><h2>' . esc_html__( 'Shipping rate', 'peptide-core' ) . '</h2><div class="pc-pl-rate-form">';
		echo '<p><label class="pc-bc-label"><span class="pc-bc-req">*</span> ' . esc_html__( 'Rate name', 'peptide-core' ) . '</label><input type="text" class="large-text" data-f="name" maxlength="100" placeholder="' . esc_attr__( 'Shown to customers at checkout, e.g. UPS • FedEx • USPS', 'peptide-core' ) . '"></p>';
		echo '<p><label><input type="checkbox" data-f="enabled"> ' . esc_html__( 'Enabled', 'peptide-core' ) . '</label></p>';
		echo '<p><label class="pc-bc-label">' . esc_html__( 'Icon', 'peptide-core' ) . '</label><span class="pc-pl-icon-pick"><span class="pc-pl-icon-img"></span> <button type="button" class="button pc-pl-icon-choose">' . esc_html__( 'Choose image', 'peptide-core' ) . '</button> <button type="button" class="button-link pc-pl-icon-clear">' . esc_html__( 'Remove', 'peptide-core' ) . '</button></span></p>';
		echo '<p><label class="pc-bc-label">' . esc_html__( 'ZIP range', 'peptide-core' ) . '</label><textarea class="large-text" rows="2" data-f="postcodes" placeholder="' . esc_attr__( 'Empty = all ZIP codes. One per line or comma separated: 90001, 900*, 90000-90999', 'peptide-core' ) . '"></textarea></p>';
		echo '<p><label class="pc-bc-label"><span class="pc-bc-req">*</span> ' . esc_html__( 'Fee rule', 'peptide-core' ) . '</label><span class="pc-pl-inline"><select data-f="rule"><option value="subtotal">' . esc_html__( 'Order amount', 'peptide-core' ) . '</option><option value="quantity">' . esc_html__( 'Items', 'peptide-core' ) . '</option><option value="weight">' . esc_html__( 'Weight', 'peptide-core' ) . '</option></select>';
		echo '<input type="number" min="0" step="0.01" data-f="min" class="small-text"> – <input type="number" min="0" step="0.01" data-f="max" class="small-text" placeholder="' . esc_attr__( 'no limit', 'peptide-core' ) . '"> <span class="pc-pl-unit"></span></span></p>';
		echo '<p><label class="pc-bc-label"><span class="pc-bc-req">*</span> ' . esc_html__( 'Shipping fee', 'peptide-core' ) . '</label><span class="pc-pl-inline"><input type="number" min="0" step="0.01" data-f="fee" class="small-text"> ' . esc_html( get_woocommerce_currency() ) . '</span> <span class="pc-pl-muted">' . esc_html__( '0 means free shipping.', 'peptide-core' ) . '</span></p>';
		echo '<p class="pc-pl-error" hidden></p></div>';
		echo '<div class="pc-pl-dialog__foot"><button type="button" class="button pc-pl-close">' . esc_html__( 'Cancel', 'peptide-core' ) . '</button> <button type="button" class="button button-primary pc-pl-rate-ok">' . esc_html__( 'OK', 'peptide-core' ) . '</button></div></dialog>';

		echo '<dialog class="pc-pl-dialog pc-pl-copydlg"><h2>' . esc_html__( 'Copy rates', 'peptide-core' ) . '</h2><p>' . esc_html__( 'Add the rates of another plan to this plan.', 'peptide-core' ) . '</p><p><select class="pc-pl-copy-from"></select></p>';
		echo '<div class="pc-pl-dialog__foot"><button type="button" class="button pc-pl-close">' . esc_html__( 'Cancel', 'peptide-core' ) . '</button> <button type="button" class="button button-primary pc-pl-copy-ok">' . esc_html__( 'Copy', 'peptide-core' ) . '</button></div></dialog>';
	}
}
