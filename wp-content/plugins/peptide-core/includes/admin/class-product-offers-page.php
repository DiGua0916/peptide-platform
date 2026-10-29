<?php
/** Dedicated SKU gift and complete bundle editor. */
namespace PeptideCore\Admin;

use PeptideCore\Product_Offers as Offers;

defined( 'ABSPATH' ) || exit;

final class Product_Offers_Page {
	public const SLUG = 'pc-promotions-product-offers';
	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 63 );
		add_action( 'admin_post_pc_product_offer_save', array( self::class, 'save' ) );
		add_action( 'admin_post_pc_product_offer_delete', array( self::class, 'delete' ) );
		add_action( 'wp_ajax_pc_offer_products', array( self::class, 'search' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
	}
	public static function menu(): void { add_submenu_page( 'pc-promotions', __( 'Gifts and product bundles', 'peptide-core' ), __( 'Gifts and product bundles', 'peptide-core' ), Offers::CAP, self::SLUG, array( self::class, 'render' ) ); }
	public static function assets(): void {
		if ( self::SLUG !== sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ) ) { return; }
		Marketing::enqueue_assets(); wp_enqueue_script( 'pc-product-offers-admin', plugins_url( 'assets/product-offers-admin.js', PEPTIDE_CORE_FILE ), array( 'jquery', 'wc-enhanced-select' ), (string) filemtime( PEPTIDE_CORE_DIR . '/assets/product-offers-admin.js' ), true );
	}
	private static function authorize( string $nonce = '' ): void {
		if ( ! current_user_can( Offers::CAP ) ) { wp_die( esc_html__( 'You cannot manage product offers.', 'peptide-core' ), '', array( 'response' => 403 ) ); }
		if ( $nonce ) { check_admin_referer( $nonce ); if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( '', '', array( 'response' => 405 ) ); } }
	}
	public static function search(): void {
		self::authorize(); check_ajax_referer( 'search-products', 'security' ); $term = mb_substr( sanitize_text_field( wp_unslash( $_GET['term'] ?? '' ) ), 0, 100 ); $out = array();
		if ( strlen( $term ) >= 2 ) { foreach ( \WC_Data_Store::load( 'product' )->search_products( $term, '', true, false, 30 ) as $id ) { $p = wc_get_product( $id ); if ( $p && in_array( $p->get_type(), array( 'simple', 'variation' ), true ) && 'publish' === $p->get_status() && ( ! $p->get_parent_id() || 'publish' === get_post_status( $p->get_parent_id() ) ) ) { $out[ $id ] = wp_strip_all_tags( $p->get_formatted_name() ); } } }
		wp_send_json( $out );
	}
	public static function save(): void {
		self::authorize( 'pc_product_offer_save' ); $raw = wp_unslash( $_POST ); $id = absint( $raw['id'] ?? 0 );
		foreach ( array( 'start', 'end' ) as $field ) { $date = sanitize_text_field( $raw[ $field ] ?? '' ); $raw[ $field ] = Marketing::from_local( $date ); if ( $date && ! $raw[ $field ] ) { wp_die( esc_html__( 'Enter valid start and end dates.', 'peptide-core' ), '', array( 'response' => 400 ) ); } }
		$result = Offers::store( $id, $raw );
		if ( is_wp_error( $result ) ) { wp_die( '<p>' . esc_html( $result->get_error_message() ) . '</p><p><a href="' . esc_url( Marketing::url( self::SLUG, $id ? array( 'edit' => $id ) : array( 'new' => 1 ) ) ) . '">' . esc_html__( 'Return to product offers', 'peptide-core' ) . '</a></p>', '', array( 'response' => 400 ) ); }
		wp_safe_redirect( Marketing::url( self::SLUG, array( 'edit' => $result, 'saved' => 1 ) ) ); exit;
	}
	public static function delete(): void {
		$id = absint( $_POST['id'] ?? 0 ); self::authorize( 'pc_product_offer_delete_' . $id );
		if ( ! Offers::get( $id ) || empty( $_POST['confirm_delete'] ) ) { wp_die( esc_html__( 'Confirm deletion using the product offer form.', 'peptide-core' ), '', array( 'response' => 400 ) ); }
		wp_delete_post( $id, true ); wp_safe_redirect( Marketing::url( self::SLUG ) ); exit;
	}
	private static function picker( string $name, array $ids, bool $multiple = true ): string {
		$out = '<select class="wc-product-search" style="width:100%;max-width:640px" name="' . esc_attr( $name ) . '" data-action="pc_offer_products" data-placeholder="' . esc_attr__( 'Search published products or exact variations…', 'peptide-core' ) . '" data-allow_clear="true"' . ( $multiple ? ' multiple' : '' ) . '>';
		if ( ! $multiple ) { $out .= '<option value=""></option>'; }
		foreach ( $ids as $id ) { $p = wc_get_product( $id ); if ( $p ) { $out .= '<option selected value="' . (int) $id . '">' . esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ) . '</option>'; } }
		return $out . '</select>';
	}
	private static function slot( int $index, array $slot ): string {
		return '<tr data-pc-offer-slot><td><input name="slots[' . $index . '][label]" maxlength="100" value="' . esc_attr( $slot['label'] ?? '' ) . '" aria-label="' . esc_attr__( 'Slot label', 'peptide-core' ) . '"></td><td><input type="number" min="1" max="10" name="slots[' . $index . '][qty]" value="' . (int) ( $slot['qty'] ?? 1 ) . '" aria-label="' . esc_attr__( 'Items per bundle', 'peptide-core' ) . '"></td><td>' . self::picker( 'slots[' . $index . '][choices][]', $slot['choices'] ?? array() ) . '</td><td><button type="button" class="button" data-pc-offer-remove>' . esc_html__( 'Remove slot', 'peptide-core' ) . '</button></td></tr>';
	}
	public static function render(): void {
		self::authorize(); $id = absint( $_GET['edit'] ?? 0 ); $offer = $id ? Offers::get( $id ) : null;
		echo '<div class="wrap pc-mk"><h1>' . esc_html__( 'Gifts and product bundles', 'peptide-core' ) . '</h1><p>' . esc_html( Offers::policy() ) . '</p><p>' . esc_html__( 'New offers are disabled drafts unless you explicitly enable them. Products, tax, stock, checkout and order lines stay in WooCommerce.', 'peptide-core' ) . '</p>';
		if ( isset( $_GET['saved'] ) ) { echo '<div class="notice notice-success"><p>' . esc_html__( 'Product offer saved.', 'peptide-core' ) . '</p></div>'; }
		if ( isset( $_GET['new'] ) || $offer ) {
			$o = $offer ?: Offers::defaults() + array( 'id' => 0, 'title' => '' );
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-pc-offer-editor><input type="hidden" name="action" value="pc_product_offer_save"><input type="hidden" name="id" value="' . (int) $o['id'] . '">' . wp_nonce_field( 'pc_product_offer_save', '_wpnonce', false, false );
			echo '<p><label>' . esc_html__( 'Offer name', 'peptide-core' ) . '<br><input class="large-text" name="title" maxlength="160" required value="' . esc_attr( $o['title'] ) . '"></label></p><p><label><input type="checkbox" name="enabled" value="1"' . checked( $o['enabled'], true, false ) . '> ' . esc_html__( 'Enable this offer', 'peptide-core' ) . '</label></p><p><label>' . esc_html__( 'Offer type', 'peptide-core' ) . ' <select name="kind"><option value="gift"' . selected( $o['kind'], 'gift', false ) . '>' . esc_html__( 'Automatic gift SKU', 'peptide-core' ) . '</option><option value="bundle"' . selected( $o['kind'], 'bundle', false ) . '>' . esc_html__( 'Complete product / variation bundle', 'peptide-core' ) . '</option></select></label></p>';
			echo '<p><label>' . esc_html__( 'Starts (store time, optional)', 'peptide-core' ) . ' <input type="datetime-local" name="start" value="' . esc_attr( Marketing::local_input( $o['start'] ) ) . '"></label> <label>' . esc_html__( 'Ends (store time, optional)', 'peptide-core' ) . ' <input type="datetime-local" name="end" value="' . esc_attr( Marketing::local_input( $o['end'] ) ) . '"></label></p>';
			echo '<fieldset data-pc-offer-kind="gift"><legend><h2>' . esc_html__( 'Gift conditions', 'peptide-core' ) . '</h2></legend><p><select name="basis"><option value="spend"' . selected( $o['basis'], 'spend', false ) . '>' . esc_html__( 'Eligible product subtotal', 'peptide-core' ) . '</option><option value="quantity"' . selected( $o['basis'], 'quantity', false ) . '>' . esc_html__( 'Eligible item quantity', 'peptide-core' ) . '</option></select> <label>' . esc_html__( 'Minimum threshold', 'peptide-core' ) . ' <input type="number" min="0.01" step="0.01" name="threshold" value="' . esc_attr( $o['threshold'] ) . '"></label></p><p><label>' . esc_html__( 'Eligible products (empty means all paid products)', 'peptide-core' ) . '</label><br>' . self::picker( 'qualifiers[]', $o['qualifiers'] ) . '</p><p><label>' . esc_html__( 'Gift product / exact variation', 'peptide-core' ) . '</label><br>' . self::picker( 'gift_product', $o['gift_product'] ? array( $o['gift_product'] ) : array(), false ) . '</p><p><label>' . esc_html__( 'Gift quantity per eligible cart', 'peptide-core' ) . ' <input type="number" min="1" max="10" name="gift_qty" value="' . (int) $o['gift_qty'] . '"></label></p><p>' . esc_html__( 'The latest eligible gift offer wins. Gift lines do not qualify for their own threshold; expired, insufficient-stock or no-longer-eligible gifts are removed automatically.', 'peptide-core' ) . '</p></fieldset>';
			echo '<fieldset data-pc-offer-kind="bundle"><legend><h2>' . esc_html__( 'Bundle pricing and SKU slots', 'peptide-core' ) . '</h2></legend><p><select name="price_type"><option value="fixed"' . selected( $o['price_type'], 'fixed', false ) . '>' . esc_html__( 'Fixed complete bundle price', 'peptide-core' ) . '</option><option value="percent"' . selected( $o['price_type'], 'percent', false ) . '>' . esc_html__( 'Percentage off native product prices', 'peptide-core' ) . '</option></select> <input type="number" min="0.01" step="0.01" name="price_value" value="' . esc_attr( $o['price_value'] ) . '" aria-label="' . esc_attr__( 'Bundle price or percentage', 'peptide-core' ) . '"></p><p>' . esc_html__( 'Use one SKU choice per slot for a fixed bundle. Add several exact variations to a slot to let the buyer choose. Every slot is required; quantity changes and removal apply to the complete bundle.', 'peptide-core' ) . '</p><table class="widefat"><thead><tr><th>' . esc_html__( 'Slot label', 'peptide-core' ) . '</th><th>' . esc_html__( 'Items per bundle', 'peptide-core' ) . '</th><th>' . esc_html__( 'Allowed product / variation choices', 'peptide-core' ) . '</th><th></th></tr></thead><tbody data-pc-offer-slots>';
			$slots = $o['slots'] ?: array( array(), array() ); foreach ( $slots as $i => $slot ) { echo self::slot( $i, $slot ); }
			echo '</tbody></table><template data-pc-offer-slot-template>' . self::slot( 9999, array() ) . '</template><p><button type="button" class="button" data-pc-offer-add-slot>' . esc_html__( 'Add slot (maximum 12)', 'peptide-core' ) . '</button></p></fieldset><p><button class="button button-primary">' . esc_html__( 'Save product offer', 'peptide-core' ) . '</button></p></form>';
			if ( $o['id'] ) { echo '<p>' . esc_html__( 'Embed in page content with this shortcode:', 'peptide-core' ) . ' <code>[pc_product_offer id="' . (int) $o['id'] . '"]</code></p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="pc_product_offer_delete"><input type="hidden" name="id" value="' . (int) $o['id'] . '">' . wp_nonce_field( 'pc_product_offer_delete_' . $o['id'], '_wpnonce', false, false ) . '<label><input type="checkbox" name="confirm_delete" value="1" required> ' . esc_html__( 'I confirm deleting this offer. Existing affected bundles will require removal or re-adding.', 'peptide-core' ) . '</label> <button class="button">' . esc_html__( 'Delete offer', 'peptide-core' ) . '</button></form>'; }
		}
		echo '<p><a class="button" href="' . esc_url( Marketing::url( self::SLUG, array( 'new' => 1 ) ) ) . '">' . esc_html__( 'New product offer', 'peptide-core' ) . '</a></p><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Offer name', 'peptide-core' ) . '</th><th>' . esc_html__( 'Type', 'peptide-core' ) . '</th><th>' . esc_html__( 'Status', 'peptide-core' ) . '</th></tr></thead><tbody>';
		foreach ( Offers::all() as $row ) { echo '<tr><td><a href="' . esc_url( Marketing::url( self::SLUG, array( 'edit' => $row['id'] ) ) ) . '">' . esc_html( $row['title'] ) . '</a></td><td>' . esc_html( 'gift' === $row['kind'] ? __( 'Gift', 'peptide-core' ) : __( 'Bundle', 'peptide-core' ) ) . '</td><td>' . esc_html( Offers::active( $row ) ? __( 'Active', 'peptide-core' ) : ( $row['enabled'] ? __( 'Outside active dates', 'peptide-core' ) : __( 'Disabled draft', 'peptide-core' ) ) ) . '</td></tr>'; }
		echo '</tbody></table></div>';
	}
}
