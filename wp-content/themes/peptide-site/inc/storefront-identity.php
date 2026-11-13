<?php
/**
 * What every storefront page says about the store itself: the public brand name, the
 * product use statement (NF-06) and the "Track your order" entry (M11 / FE-11).
 * Kept apart from functions.php so these rules can be loaded and tested on their own.
 */
defined( 'ABSPATH' ) || exit;

/**
 * 前台对外的英文品牌名。店铺名称（后台/总后台里的中文名）只用于后台和邮件，不上前台。
 * 投手在“店铺信息”里填了对外品牌名（选项 pc_public_brand，ST-07）时用它，没填用默认的 VialCovePeptides。
 */
function vc_brand_name(): string {
    $custom = is_callable( array( '\PeptideCore\Admin\Store_Settings', 'public_brand' ) ) ? (string) \PeptideCore\Admin\Store_Settings::public_brand() : '';
    return (string) apply_filters( 'vc_brand_name', '' !== $custom ? $custom : 'VialCovePeptides' );
}

/**
 * The plugin owns this optional statement. Do not invent product-use wording.
 * NF-06: the same markup is printed above the footer on every page except cart / checkout
 * (footer.php) and inside the product dialog (inc/product-dialog-content.php), so it does
 * not depend on which blocks a store's layout happens to contain.
 */
function vc_use_statement_markup(): string {
    $config = vc_site_config();
    $html = wp_kses_post( (string) ( $config['use_statement_html'] ?? '' ) );
    if ( '' === trim( $html ) ) { return ''; }
    return '<section class="vc-use-statement" aria-label="Product use statement"><span class="eyebrow">Product use statement</span><div>' . $html . '</div></section>';
}

function vc_use_statement(): void {
    echo vc_use_statement_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses_post() above.
}

/**
 * “Track your order” entry (M11 / FE-11). Printed only while the store has returning-buyer
 * recognition switched on and a published look-up page; never on that page itself.
 */
function vc_track_order_entry(): void {
    if ( ! is_callable( array( '\PeptideCore\Buyer_Status', 'enabled' ) ) || ! is_callable( array( '\PeptideCore\Order_Lookup', 'page_url' ) ) || ! \PeptideCore\Buyer_Status::enabled() ) { return; }
    $url = (string) \PeptideCore\Order_Lookup::page_url();
    if ( '' === $url || ( is_page() && (int) get_queried_object_id() === (int) get_option( 'pc_track_order_page', 0 ) ) ) { return; }
    echo '<p class="vc-track-order"><a href="' . esc_url( $url ) . '">Track your order ' . vc_icon() . '</a></p>';
}
