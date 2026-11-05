<?php
/** Merchant-editable presentation controls, using the native layout editor. */
defined( 'ABSPATH' ) || exit;

// Run after Registry has completed its standard and reference panel metadata.
// No second settings store, price rules, products or cart transport is introduced.
add_filter( 'peptide_core_layout_modules', 'vc_ui_presentation_definitions', 180 );

/** Put familiar editing tasks first while retaining fields/panels added by peers. */
function vc_ui_presentation_panels( array $definition, array $groups ): array {
    $fields = array_column( $definition['settings'] ?? array(), null, 'key' );
    $panels = array(); $ids = array();
    foreach ( $groups as $id => $group ) {
        $keys = array_values( array_filter( $group['fields'], static fn( $key ) => isset( $fields[ $key ] ) ) );
        if ( ! $keys ) { continue; }
        $panels[] = array( 'id' => $id, 'label' => $group['label'], 'initialOpen' => $group['open'] ?? false, 'fields' => $keys );
        $ids[ $id ] = true;
    }
    foreach ( $definition['panels'] ?? array() as $panel ) {
        if ( ! is_array( $panel ) || isset( $ids[ $panel['id'] ?? '' ] ) ) { continue; }
        $panels[] = $panel;
    }
    $definition['panels'] = $panels;
    return $definition;
}

/** Adds fields once; persisted keys/defaults and other modules stay intact. */
function vc_ui_presentation_definitions( array $definitions ): array {
    if ( ! class_exists( '\\PeptideCore\\Modules\\Fields' ) ) { return $definitions; }
    $field = '\\PeptideCore\\Modules\\Fields';
    if ( isset( $definitions['product_collection'] ) ) {
        $def =& $definitions['product_collection'];
        if ( ! in_array( 'catalogQuickAdd', array_column( $def['settings'] ?? array(), 'key' ), true ) ) {
            $def['settings'][] = $field::f( 'toggle', 'catalogQuickAdd', '商品卡片直接加购', true, array(
                'show_if' => array( 'cardPresentation' => 'demo' ),
                'help' => '开启后将当前展示规格加入购物车；规格与图片按商品后台顺序，成交价和库存由 WooCommerce 重新确认。关闭后保留选择规格入口，不影响点击图片查看详情。',
            ) );
        }
        $def = vc_ui_presentation_panels( $def, array(
            'vc-catalog-purchase' => array( 'label' => '快捷购买', 'open' => true, 'fields' => array( 'catalogQuickAdd' ) ),
            'vc-display' => array( 'label' => '电脑、手机与区域间距', 'fields' => array( 'showDesktop', 'showMobile', 'paddingTopPx', 'paddingBottomPx', 'backgroundColor' ) ),
        ) );
        unset( $def );
    }
    if ( isset( $definitions['context_content'] ) ) {
        $def =& $definitions['context_content'];
        if ( ! in_array( 'checkout_subtotal_savings_enabled', array_column( $def['settings'] ?? array(), 'key' ), true ) ) {
            $def['settings'][] = $field::f( 'toggle', 'checkout_subtotal_savings_enabled', '小计显示商品原价与节省金额', true, array(
                'help' => '仅结账文档生效。使用原生订单摘要的小计与服务端商品价差，原生应付额不变；没有有效优惠时自动隐藏。不包含优惠券或活动减免，避免重复计算。',
            ) );
        }
        $def = vc_ui_presentation_panels( $def, array(
            'vc-cart-layout' => array( 'label' => '购物车布局与文案', 'open' => true, 'fields' => array( 'cart_experience_enabled', 'cart_title', 'cart_continue_label' ) ),
            'vc-cart-features' => array( 'label' => '购物车功能', 'fields' => array( 'cart_progress_enabled', 'cart_recommendations_enabled', 'cart_recent_enabled' ) ),
            'vc-checkout-summary' => array( 'label' => '结账优惠展示', 'open' => true, 'fields' => array( 'checkout_subtotal_savings_enabled' ) ),
            'vc-display' => array( 'label' => '电脑、手机与区域间距', 'fields' => array( 'showDesktop', 'showMobile', 'paddingTopPx', 'paddingBottomPx', 'backgroundColor' ) ),
        ) );
        unset( $def );
    }
    if ( isset( $definitions['product_experience'] ) ) {
        $definitions['product_experience'] = vc_ui_presentation_panels( $definitions['product_experience'], array(
            'vc-dialog-layout' => array( 'label' => '弹窗与购买操作', 'open' => true, 'fields' => array( 'mobileDialog', 'stickyPurchaseEnabled' ) ),
            'vc-dialog-media' => array( 'label' => '商品图片与报告', 'open' => true, 'fields' => array( 'reportGalleryEnabled' ) ),
            'vc-dialog-broadcast' => array( 'label' => '商品播报', 'fields' => array( 'broadcastEnabled' ) ),
            'vc-dialog-contact' => array( 'label' => '联系入口', 'fields' => array( 'whatsappEnabled', 'whatsappNumber', 'whatsappLabel', 'whatsappMessage' ) ),
            'vc-display' => array( 'label' => '电脑、手机与区域间距', 'fields' => array( 'showDesktop', 'showMobile', 'paddingTopPx', 'paddingBottomPx', 'backgroundColor' ) ),
        ) );
    }
    if ( isset( $definitions['site_footer'] ) ) {
        $definitions['site_footer'] = vc_ui_presentation_panels( $definitions['site_footer'], array(
            'vc-footer-layout' => array( 'label' => '页脚区域样式', 'open' => true, 'fields' => array( 'presentation', 'footerExpanded' ) ),
            'vc-footer-brand' => array( 'label' => '品牌图片与介绍', 'open' => true, 'fields' => array( 'logo', 'logoWidth', 'brandName', 'description' ) ),
            'vc-footer-navigation' => array( 'label' => '商品导航', 'fields' => array( 'footerShopTitle', 'menuId', 'demoLinks' ) ),
            'vc-footer-links' => array( 'label' => '帮助与资料链接', 'fields' => array( 'footerHelpTitle', 'footerServiceLinks', 'footerResourcesTitle', 'footerResourceLinks' ) ),
            'vc-footer-contact' => array( 'label' => '官方联系渠道', 'open' => true, 'fields' => array( 'footerContactTitle', 'footerUseSupport' ) ),
            'vc-footer-notice' => array( 'label' => '声明、版权与订阅', 'fields' => array( 'demoNotice', 'copyright', 'showPolicies', 'showSubscribe', 'subscribeTitle' ) ),
            'vc-footer-mobile' => array( 'label' => '手机底部导航', 'fields' => array( 'mobileNav', 'mobileNavSize', 'mobileNavHeight', 'mobileNavIconSize', 'mobileNavFontSize', 'mobileLinks' ) ),
            'vc-footer-colors' => array( 'label' => '文字与背景颜色', 'fields' => array( 'footerBackground', 'footerText' ) ),
            'vc-display' => array( 'label' => '电脑、手机与区域间距', 'fields' => array( 'showDesktop', 'showMobile', 'paddingTopPx', 'paddingBottomPx', 'backgroundColor' ) ),
        ) );
        // The native row editor keeps enabled, add/remove, sort, clone and mobile
        // overrides. A destination chooses its real icon in the expanded footer.
        if ( ! empty( $definitions['site_footer']['items']['fields'] ) ) {
            $definitions['site_footer']['items']['panels'] = array_merge( array(
                array( 'id' => 'vc-footer-account', 'label' => '官方账号与链接', 'initialOpen' => true, 'fields' => array( 'label', 'url' ) ),
            ), array_values( array_filter( $definitions['site_footer']['items']['panels'] ?? array(), static fn( $panel ) => 'vc-footer-account' !== ( $panel['id'] ?? '' ) ) ) );
            foreach ( $definitions['site_footer']['items']['fields'] as &$item_field ) {
                if ( 'url' === $item_field['key'] ) {
                    $item_field['help'] = '填写完整官方主页地址、mailto:邮箱或tel:电话；留空不展示。新版页脚按真实地址匹配 WhatsApp、Instagram、Facebook、Telegram 等图标，不使用虚构账号。';
                }
            }
            unset( $item_field );
        }
    }
    return $definitions;
}
