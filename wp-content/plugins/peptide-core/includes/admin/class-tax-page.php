<?php
/**
 * 店铺设置 → 税费设置（TAX-PLANS-01）：税费方案列表、启用 / 关闭税费、新建 / 编辑方案。
 * 版式参照 Shopname 的“新建税费方案”：方案名称、选择区域（每个区域填税率）、特定商品。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Tax\Plans;

defined( 'ABSPATH' ) || exit;

final class Tax_Page {

	public const SLUG = 'pc-tax';
	public const CAP  = 'manage_woocommerce';

	public static function register_hooks(): void {
		add_action( 'admin_post_pc_tax_save', array( self::class, 'save' ) );
		add_action( 'admin_post_pc_tax_delete', array( self::class, 'delete' ) );
		add_action( 'admin_post_pc_enable_tax', array( self::class, 'toggle' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	public static function enqueue(): void {
		if ( self::SLUG === ( $_GET['page'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			Plans_Editor::enqueue( 'tax' );
		}
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['plan'] ) || isset( $_GET['new'] ) ) {
			self::render_edit( absint( $_GET['plan'] ?? 0 ) );
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$on = wc_tax_enabled();
		echo '<div class="wrap pc-pl">';
		Plans_Editor::notices();
		echo '<div class="pc-pl-head"><h1>' . esc_html__( 'Tax plans', 'peptide-core' ) . '</h1>';
		echo '<a class="button button-primary pc-pl-new" href="' . esc_url( self::url( array( 'new' => 1 ) ) ) . '">+ ' . esc_html__( 'New tax plan', 'peptide-core' ) . '</a></div>';

		echo '<div class="pc-pl-card pc-pl-pad pc-tx-switch"><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="pc_enable_tax"><input type="hidden" name="on" value="' . ( $on ? '0' : '1' ) . '">';
		wp_nonce_field( 'pc_enable_tax' );
		echo '<strong>' . esc_html( $on ? __( 'Taxes are on', 'peptide-core' ) : __( 'Taxes are turned off', 'peptide-core' ) ) . '</strong> ';
		echo '<span class="pc-pl-muted">' . esc_html( $on ? __( 'Checkout adds tax according to the plans below (based on the customer’s shipping address).', 'peptide-core' ) : __( 'Checkout does not add any tax right now; the plans below have no effect until taxes are on.', 'peptide-core' ) ) . '</span> ';
		submit_button( $on ? __( 'Turn off taxes', 'peptide-core' ) : __( 'Turn on taxes', 'peptide-core' ), $on ? 'secondary' : 'primary', 'submit', false );
		echo '</form><p class="pc-pl-muted">' . esc_html__( 'Prices are entered without tax; tax is added at checkout. US sales tax depends on the states where you have to collect it. Check with your accountant before adding rates.', 'peptide-core' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=tax' ) ) . '">' . esc_html__( 'WooCommerce tax options', 'peptide-core' ) . '</a></p></div>';

		$plans = Plans::all();
		echo '<div class="pc-pl-card"><table class="pc-pl-table"><thead><tr><th>' . esc_html__( 'Plan name', 'peptide-core' ) . '</th><th>' . esc_html__( 'Regions and rates', 'peptide-core' ) . '</th><th>' . esc_html__( 'Products', 'peptide-core' ) . '</th><th>' . esc_html__( 'Tax on shipping', 'peptide-core' ) . '</th><th class="pc-pl-col-status">' . esc_html__( 'Actions', 'peptide-core' ) . '</th></tr></thead><tbody>';
		if ( ! $plans ) {
			echo '<tr><td colspan="5" class="pc-pl-empty">' . esc_html__( 'No tax plans yet.', 'peptide-core' ) . '</td></tr>';
		}
		foreach ( $plans as $p ) {
			$regions = array_map( array( Plans::class, 'region_text' ), $p['regions'] );
			$shown   = implode( '; ', array_slice( $regions, 0, 4 ) ) . ( count( $regions ) > 4 ? ' …' : '' );
			/* translators: %d: number of products */
			$scope = 'all' === $p['scope'] ? __( 'All taxable products', 'peptide-core' ) : sprintf( _n( '%d product selected', '%d products selected', count( $p['products'] ), 'peptide-core' ), count( $p['products'] ) );
			echo '<tr><td><strong>' . esc_html( $p['name'] ) . '</strong></td><td>' . esc_html( $shown ? $shown : '—' ) . '</td><td>' . esc_html( $scope ) . '</td><td>' . esc_html( $p['shipping'] ? __( 'Yes', 'peptide-core' ) : __( 'No', 'peptide-core' ) ) . '</td>';
			echo '<td class="pc-pl-col-status"><a class="button" href="' . esc_url( self::url( array( 'plan' => $p['id'] ) ) ) . '">' . esc_html__( 'Edit plan', 'peptide-core' ) . '</a></td></tr>';
		}
		echo '</tbody></table></div></div>';
	}

	private static function render_edit( int $id ): void {
		$plan = $id ? Plans::get( $id ) : null;
		if ( $id && ! $plan ) {
			echo '<div class="wrap"><p>' . esc_html__( 'This plan does not exist.', 'peptide-core' ) . '</p></div>';
			return;
		}
		$plan = $plan ? $plan : Plans::normalize( array() );
		echo '<div class="wrap pc-pl">';
		Plans_Editor::notices();
		echo '<p class="pc-ss-back"><a href="' . esc_url( self::url() ) . '">‹ ' . esc_html__( 'Back to tax plans', 'peptide-core' ) . '</a></p>';
		echo '<h1>' . esc_html( $plan['id'] ? __( 'Edit tax plan', 'peptide-core' ) : __( 'New tax plan', 'peptide-core' ) ) . '</h1>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pc-pl-form" data-kind="tax">';
		echo '<input type="hidden" name="action" value="pc_tax_save"><input type="hidden" name="id" value="' . esc_attr( (string) $plan['id'] ) . '">';
		wp_nonce_field( 'pc_tax_save' );
		echo '<script type="application/json" class="pc-pl-data">' . wp_json_encode( array( 'regions' => $plan['regions'] ) ) . '</script><input type="hidden" name="regions" value=""><input type="hidden" name="rates" value="">';

		echo '<div class="pc-pl-card pc-pl-pad"><label class="pc-bc-label"><span class="pc-bc-req">*</span> ' . esc_html__( 'Tax plan name', 'peptide-core' ) . '</label><input type="text" name="name" class="large-text" required maxlength="100" value="' . esc_attr( $plan['name'] ) . '" placeholder="' . esc_attr__( 'Enter the tax plan name, e.g. California sales tax', 'peptide-core' ) . '"><p class="pc-pl-muted">' . esc_html__( 'Shown to customers as the name of the tax line at checkout.', 'peptide-core' ) . '</p>';
		echo '<label><input type="checkbox" name="shipping" value="1"' . checked( $plan['shipping'], true, false ) . '> ' . esc_html__( 'Also charge this tax on shipping', 'peptide-core' ) . '</label></div>';

		Plans_Editor::regions_section( true );

		echo '<div class="pc-pl-card pc-pl-pad"><strong>' . esc_html__( 'Products', 'peptide-core' ) . '</strong> <span class="pc-pl-muted">' . esc_html__( 'If the regions and rates apply to all taxable products, you do not need to pick products. If they only apply to some taxable products, pick those products.', 'peptide-core' ) . '</span>';
		echo '<p><label class="pc-pl-radio"><input type="radio" name="scope" value="all"' . checked( 'all', $plan['scope'], false ) . '> ' . esc_html__( 'All taxable products', 'peptide-core' ) . '</label> <label class="pc-pl-radio"><input type="radio" name="scope" value="products"' . checked( 'products', $plan['scope'], false ) . '> ' . esc_html__( 'Only some products', 'peptide-core' ) . '</label></p></div>';
		echo '<div class="pc-tx-products"' . ( 'products' === $plan['scope'] ? '' : ' hidden' ) . '>';
		Plans_Editor::products_section( $plan['products'], __( '(These products use this plan instead of the plans for all taxable products.)', 'peptide-core' ), Plans::product_map(), (int) $plan['id'], array_column( Plans::all(), 'name', 'id' ) );
		echo '</div>';

		echo '<div class="pc-bc-bar"><button class="button button-primary button-hero">' . esc_html__( 'Save', 'peptide-core' ) . '</button> <a class="button button-hero" href="' . esc_url( self::url() ) . '">' . esc_html__( 'Cancel', 'peptide-core' ) . '</a>';
		if ( $plan['id'] ) {
			echo ' <a class="button button-hero pc-pl-delete" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pc_tax_delete&id=' . $plan['id'] ), 'pc_tax_delete' ) ) . '">' . esc_html__( 'Delete this plan', 'peptide-core' ) . '</a>';
		}
		echo '</div></form>';
		Plans_Editor::dialogs( 'tax' );
		echo '</div>';
	}

	// ---------- 保存 ----------

	private static function guard( string $action ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'peptide-core' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * @param array<string,mixed> $in
	 */
	public static function from_form( array $in ): array {
		return array(
			'id'       => absint( $in['id'] ?? 0 ),
			'name'     => (string) ( $in['name'] ?? '' ),
			'scope'    => (string) ( $in['scope'] ?? 'all' ),
			'products' => (array) ( $in['products'] ?? array() ),
			'regions'  => (array) json_decode( (string) ( $in['regions'] ?? '[]' ), true ),
			'shipping' => ! empty( $in['shipping'] ),
		);
	}

	public static function save(): void {
		self::guard( 'pc_tax_save' );
		$plan = Plans::normalize( self::from_form( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '' === $plan['name'] ) {
			wp_safe_redirect( self::url( ( $plan['id'] ? array( 'plan' => $plan['id'] ) : array( 'new' => 1 ) ) + array( 'pc_error' => rawurlencode( __( 'Enter a plan name.', 'peptide-core' ) ) ) ) );
			exit;
		}
		Plans::save( $plan );
		wp_safe_redirect( self::url( array( 'pc_saved' => 1 ) ) );
		exit;
	}

	public static function delete(): void {
		self::guard( 'pc_tax_delete' );
		Plans::delete( absint( $_GET['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		wp_safe_redirect( self::url( array( 'pc_deleted' => 1 ) ) );
		exit;
	}

	/** 开启 / 关闭 WooCommerce 税费。 */
	public static function toggle(): void {
		self::guard( 'pc_enable_tax' );
		update_option( 'woocommerce_calc_taxes', empty( $_POST['on'] ) ? 'no' : 'yes' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		wp_safe_redirect( self::url( array( 'pc_saved' => 1 ) ) );
		exit;
	}
}
