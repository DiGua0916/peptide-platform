<?php
/** An in-page activity presentation. Native promotion records own all offer content. */
defined( 'ABSPATH' ) || exit;

add_filter( 'peptide_core_layout_definitions', function ( array $defs ): array {
    if ( empty( $defs['promotion_card'] ) || ! class_exists( '\PeptideCore\Modules\Fields' ) ) { return $defs; }
    $defs['promotion_card']['settings'][] = \PeptideCore\Modules\Fields::f( 'select', 'presentation', '活动外观', 'standard', array(
        'options' => array( 'standard' => '原活动卡片', 'campaign_bubble' => 'VialCove 活动气泡', 'campaign_announcement' => 'VialCove 站内活动横条' ), 'group' => 'layout', 'panel' => 'vialcove-campaign',
    ) );
    $defs['promotion_card']['panels'][] = array( 'id' => 'vialcove-campaign', 'label' => '活动外观', 'initialOpen' => true, 'fields' => array( 'presentation' ) );
    return $defs;
}, 125 );

function vc_campaign_bubble_html( $html, array $module, string $context, string $device ) {
    $s = $module['settings'] ?? array();
    $presentation = $s['presentation'] ?? 'standard';
    if ( 'promotion_card' !== ( $module['type'] ?? '' ) || ! in_array( $presentation, array( 'campaign_bubble', 'campaign_announcement' ), true ) ) { return $html; }
    if ( 'promotion' !== ( $s['activityType'] ?? 'promotion' ) || ! function_exists( 'peptide_core_get_campaign_announcement' ) || ! class_exists( '\PeptideCore\Modules\Commerce_Blocks' ) ) { return ''; }
    $id = (int) ( $s['promotion'] ?? 0 );
    $activity = peptide_core_get_campaign_announcement( array( 'announcement' => true, 'announcementSource' => 'promotion', 'announcementPromotion' => $id ), is_front_page() );
    if ( $id < 1 || true !== ( $activity['visible'] ?? false ) || $id !== ( $activity['promotionId'] ?? 0 ) || ! in_array( $activity['status'] ?? '', array( 'active', 'upcoming' ), true ) ) { return ''; }
    // Reuse the native eligible-customer/tax/currency/template/terms renderer.
    // Calling it directly does not run the filter again or write a cart.
    $native = \PeptideCore\Modules\Commerce_Blocks::render( null, $module, $context, $device );
    if ( ! is_string( $native ) || ! str_contains( $native, 'data-pc-campaign ' ) ) { return ''; }
    if ( '' === trim( (string) ( $s['title'] ?? '' ) ) && '黑色星期五' === ( $activity['title'] ?? '' ) ) {
        $native = str_replace( 'class="pc-campaign__title">黑色星期五</h2>', 'class="pc-campaign__title">Black Friday</h2>', $native );
    }
    // Compact only the native default wording. Formatted amounts are copied
    // intact; merchant templates, quantities, FREE/bundle rules and terms stay native.
    if ( '' === trim( (string) ( $s['conditionTemplateHtml'] ?? '' ) ) ) {
        $native = preg_replace_callback( '~(<div class="pc-campaign__condition">)Spend ([^<>]+), get(</div>)~',
            static fn( $match ) => $match[1] . 'Spend ' . $match[2] . $match[3], $native );
    }
    if ( '' === trim( (string) ( $s['discountTemplateHtml'] ?? '' ) ) ) {
        $native = preg_replace_callback( '~(<div class="pc-campaign__discount">)([^<>]+) OFF(</div>)~',
            static fn( $match ) => $match[1] . $match[2] . '<small>off</small>' . $match[3], $native );
    }
    if ( 'upcoming' === $activity['status'] ) { $native = str_replace( '</header>', '<p class="vc-campaign-bubble__status">Coming soon</p></header>', $native ); }
    if ( 'campaign_announcement' === $presentation ) {
        // Keep the native rail, tiers and controls. Only make its ordinary manual
        // scroll surface keyboard reachable before the native script enhances it.
        $native = preg_replace_callback( '~<div class="pc-campaign__tiers"([^>]*)>~', static function ( $match ) {
            $attributes = $match[1];
            if ( ! preg_match( '~\btabindex\s*=~i', $attributes ) ) { $attributes .= ' tabindex="0"'; }
            if ( ! preg_match( '~\baria-label\s*=~i', $attributes ) ) { $attributes .= ' aria-label="Promotion offers"'; }
            return '<div class="pc-campaign__tiers"' . $attributes . '>';
        }, $native );
        $sticky = is_front_page() && 'editor' !== $context ? ' data-vc-campaign-sticky' : '';
        return '<div class="vc-campaign-strip" data-vc-campaign-strip' . $sticky . '>' . $native . '</div>';
    }
    return '<div class="vc-campaign-bubble" data-vc-campaign-bubble>' . $native . '</div>';
}
// Before the native priority-10 renderer: opt-in presentation, one native render.
add_filter( 'peptide_core_module_html', 'vc_campaign_bubble_html', 9, 4 );

function vc_campaign_bubble_assets(): void {
    $dir = get_template_directory() . '/assets/';
    wp_enqueue_style( 'vc-campaign-bubble', get_template_directory_uri() . '/assets/campaign-bubble.css', array(), (string) filemtime( $dir . 'campaign-bubble.css' ) );
    if ( ! is_admin() ) {
        wp_enqueue_script( 'vc-campaign-announcement', get_template_directory_uri() . '/assets/campaign-announcement.js', array( 'pc-commerce-blocks' ), (string) filemtime( $dir . 'campaign-announcement.js' ), true );
        if ( is_front_page() ) {
            wp_enqueue_script( 'vc-campaign-strip-sticky', get_template_directory_uri() . '/assets/campaign-strip-sticky.js', array(), (string) filemtime( $dir . 'campaign-strip-sticky.js' ), true );
        }
    }
}
add_action( 'wp_enqueue_scripts', 'vc_campaign_bubble_assets', 96 );
add_action( 'enqueue_block_assets', function (): void { if ( is_admin() ) { vc_campaign_bubble_assets(); } }, 96 );
