<?php
/** Published builder presentation settings; business rules remain in peptide-core. */
defined( 'ABSPATH' ) || exit;

// A builder settings document, not a replacement for WooCommerce's cart route.
add_filter( 'peptide_core_template_documents', function ( array $documents ): array {
    if ( ! isset( $documents['cart'] ) ) { $documents['cart'] = __( 'Shopping cart', 'peptide-site' ); }
    if ( ! isset( $documents['checkout'] ) ) { $documents['checkout'] = __( '结账展示设置', 'peptide-site' ); }
    return $documents;
}, 125 );

add_filter( 'peptide_core_layout_definitions', function ( array $definitions ): array {
    if ( ! class_exists( '\PeptideCore\Modules\Fields' ) ) { return $definitions; }
    $field = '\PeptideCore\Modules\Fields';
    if ( isset( $definitions['faq']['settings'] ) ) {
        $definitions['faq']['settings'][] = $field::f( 'toggle', 'productFallback', __( '商品弹窗通用问答', 'peptide-site' ), false,
            array( 'help' => '在商品弹窗的报告下方展示；当前商品描述已有专属 FAQ 时，优先展示专属问答。本开关不改变首页等其他页面。' ) );
    }
    $definitions['product_experience'] = array(
        'title' => __( '商品弹窗体验', 'peptide-site' ),
        'description' => __( '控制商品弹窗的展示；商品、规格、价格及报告仍从原后台读取。放在通用商品详情文档或某个商品的专属文档中。', 'peptide-site' ),
        'category' => 'product', 'icon' => 'welcome-view-site', 'version' => 1, 'singleRender' => true,
        'editorDefaults' => array( 'paddingTopPx' => array( 'desktop' => 0, 'mobile' => 0 ), 'paddingBottomPx' => array( 'desktop' => 0, 'mobile' => 0 ) ),
        'settings' => array(
            $field::f( 'toggle', 'mobileDialog', __( '使用新版商品弹窗', 'peptide-site' ), true, array( 'group' => 'layout', 'help' => '手机优先的完整商品弹窗；保留原生规格、购物车和结账。' ) ),
            $field::f( 'toggle', 'reportGalleryEnabled', __( '产品图中展示最新报告', 'peptide-site' ), true, array( 'help' => '仅显示当前商品和规格已公开的可用报告；没有报告时只展示产品图。' ) ),
            $field::f( 'toggle', 'stickyPurchaseEnabled', __( '手机底部保留购买操作', 'peptide-site' ), true, array( 'group' => 'layout' ) ),
            $field::f( 'toggle', 'broadcastEnabled', __( '显示商品播报', 'peptide-site' ), true, array( 'help' => '只引用应用中心已应用的文案，姓名、关键词、导入和播放节奏在商品播报插件中设置。' ) ),
            $field::f( 'toggle', 'whatsappEnabled', __( '显示 WhatsApp 联系入口', 'peptide-site' ), false ),
            $field::f( 'text', 'whatsappNumber', __( 'WhatsApp 号码', 'peptide-site' ), '', array( 'show_if' => array( 'whatsappEnabled' => true ), 'help' => '填写国际区号和完整号码，例如区号后接号码；没有有效号码时不显示入口。' ) ),
            $field::f( 'text', 'whatsappLabel', __( '联系入口文字', 'peptide-site' ), 'A little help', array( 'show_if' => array( 'whatsappEnabled' => true ) ) ),
            $field::f( 'text', 'whatsappMessage', __( '预填消息', 'peptide-site' ), '', array( 'show_if' => array( 'whatsappEnabled' => true ), 'help' => '可使用 {product}、{specification}、{sku}；点击后仅打开 WhatsApp，由客户自行发送。' ) ),
        ),
    );
    if ( isset( $definitions['context_content']['settings'] ) ) {
        $definitions['context_content']['settings'] = array_merge( $definitions['context_content']['settings'], array(
            $field::f( 'toggle', 'cart_experience_enabled', __( '购物车使用新版布局', 'peptide-site' ), true, array( 'group' => 'layout', 'help' => '仅购物车文档生效。电脑和手机均使用右侧购物车抽屉，独立购物车页保留作备用入口；交易仍使用 WooCommerce。' ) ),
            $field::f( 'toggle', 'cart_progress_enabled', __( '显示活动进度', 'peptide-site' ), true, array( 'help' => '只展示活动监听插件返回的实际优惠，不在此设置门槛。' ) ),
            $field::f( 'toggle', 'cart_recommendations_enabled', __( '显示接近下一档时的已浏览商品', 'peptide-site' ), true, array( 'help' => '活动插件同时开启推荐且返回有效报价时才展示；不自动加入商品。' ) ),
            $field::f( 'toggle', 'cart_recent_enabled', __( '显示最近浏览', 'peptide-site' ), true, array( 'help' => '引用最近浏览插件的实际记录；没有记录时整块隐藏。' ) ),
            $field::f( 'text', 'cart_title', __( '购物车标题', 'peptide-site' ), 'Shopping cart' ),
            $field::f( 'text', 'cart_continue_label', __( '继续浏览文字', 'peptide-site' ), 'Continue exploring' ),
        ) );
    }
    return $definitions;
}, 125 );

/** Configuration-only block: visible to editors, never an empty storefront section. */
add_filter( 'peptide_core_module_html', function ( $html, array $module, string $context, string $device = 'all' ) {
    if ( 'product_experience' !== ( $module['type'] ?? '' ) ) { return $html; }
    if ( 'editor' !== $context ) { return ''; }
    return '<div class="vc-m__notice"><strong>' . esc_html__( '商品弹窗体验', 'peptide-site' ) . '</strong><p>'
        . esc_html__( '此积木管理商品弹窗展示，客户页面不显示额外卡片。发布后，弹窗使用当前商品的真实描述、规格、图片和公开报告。', 'peptide-site' )
        . '</p><p>' . esc_html__( '播报、加购反馈、最近浏览和活动规则继续在应用中心管理。', 'peptide-site' ) . '</p></div>';
}, 125, 4 );

add_filter( 'peptide_core_module_wrapper', function ( string $html, array $module, bool $editor ): string {
    return ! $editor && 'product_experience' === ( $module['type'] ?? '' ) ? '' : $html;
}, 125, 3 );

/** Find a settings row in published snapshots only. Disabled rows deliberately override fallback. */
function vc_live_commerce_published_module( array $documents, string $type ): ?array {
    if ( ! class_exists( '\PeptideCore\Templates' ) || ! class_exists( '\PeptideCore\Modules\Layout' )
        || ! class_exists( '\PeptideCore\Modules\Renderer' ) ) { return null; }
    $template = \PeptideCore\Templates::current_id();
    if ( ! $template ) { return null; }
    foreach ( $documents as $document ) {
        foreach ( \PeptideCore\Modules\Layout::published_modules( $document, $template ) as $row ) {
            if ( 'vialcove/' . str_replace( '_', '-', $type ) !== ( $row['block'] ?? '' ) ) { continue; }
            $module = \PeptideCore\Modules\Renderer::normalize( $type, (array) ( $row['attrs'] ?? array() ) );
            if ( $module ) { $module['document'] = $document; return $module; }
        }
    }
    return null;
}

/** No published settings block means no automatic migration of an existing storefront. */
function vc_live_product_settings( int $product_id = 0 ): array {
    $out = array(
        'enabled' => false, 'mobileDialog' => false, 'reportGalleryEnabled' => true,
        'stickyPurchaseEnabled' => true, 'broadcastEnabled' => true, 'whatsappEnabled' => false,
        'whatsappNumber' => '', 'whatsappMessage' => '', 'whatsappLabel' => 'A little help',
        'document' => '', 'visibility' => array( 'desktop' => true, 'mobile' => true ),
    );
    $documents = $product_id > 0 ? array( 'product_' . $product_id, 'product' ) : array( 'product' );
    $module = vc_live_commerce_published_module( $documents, 'product_experience' );
    if ( ! $module ) { return $out; }
    foreach ( array( 'mobileDialog', 'reportGalleryEnabled', 'stickyPurchaseEnabled', 'broadcastEnabled', 'whatsappEnabled' ) as $key ) {
        $out[ $key ] = (bool) ( $module['settings'][ $key ] ?? $out[ $key ] );
    }
    foreach ( array( 'whatsappNumber', 'whatsappMessage', 'whatsappLabel' ) as $key ) {
        $out[ $key ] = sanitize_text_field( (string) ( $module['settings'][ $key ] ?? $out[ $key ] ) );
    }
    $number = (string) preg_replace( '/[\s()+.\-]/', '', $out['whatsappNumber'] );
    $out['whatsappNumber'] = preg_match( '/^[1-9][0-9]{6,14}$/D', $number ) ? $number : '';
    $out['whatsappEnabled'] = $out['whatsappEnabled'] && '' !== $out['whatsappNumber'];
    $out['visibility'] = $module['visibility'];
    $out['enabled'] = $module['enabled'] && $out['mobileDialog'] && ( $module['visibility']['desktop'] || $module['visibility']['mobile'] );
    $out['document'] = $module['document'];
    return $out;
}

function vc_live_cart_settings(): array {
    $out = array(
        'enabled' => false, 'cart_experience_enabled' => false,
        'cart_progress_enabled' => true, 'cart_recommendations_enabled' => true, 'cart_recent_enabled' => true,
        'cart_title' => 'Shopping cart', 'cart_continue_label' => 'Continue exploring',
        'document' => '', 'visibility' => array( 'desktop' => true, 'mobile' => true ),
    );
    $module = vc_live_commerce_published_module( array( 'cart' ), 'context_content' );
    if ( ! $module ) { return $out; }
    foreach ( array( 'cart_experience_enabled', 'cart_progress_enabled', 'cart_recommendations_enabled', 'cart_recent_enabled' ) as $key ) {
        $out[ $key ] = (bool) ( $module['settings'][ $key ] ?? $out[ $key ] );
    }
    foreach ( array( 'cart_title', 'cart_continue_label' ) as $key ) {
        $out[ $key ] = sanitize_text_field( (string) ( $module['settings'][ $key ] ?? $out[ $key ] ) );
    }
    $out['visibility'] = $module['visibility'];
    $out['enabled'] = $module['enabled'] && $out['cart_experience_enabled'] && ( $module['visibility']['desktop'] || $module['visibility']['mobile'] );
    $out['document'] = $module['document'];
    return $out;
}

function vc_live_cart_active(): bool { return vc_live_cart_settings()['enabled']; }

/** Presentation only: the published checkout document controls its annotation. */
function vc_checkout_subtotal_settings(): array {
    $out = array( 'enabled' => true, 'visibility' => array( 'desktop' => true, 'mobile' => true ) );
    $module = vc_live_commerce_published_module( array( 'checkout' ), 'context_content' );
    // Existing checkouts keep their approved appearance until configured.
    if ( ! $module ) { return $out; }
    $desktop = (bool) ( $module['settings']['checkout_subtotal_savings_enabled'] ?? true );
    $mobile = (bool) ( $module['mobile']['settings']['checkout_subtotal_savings_enabled'] ?? $desktop );
    $out['visibility'] = array(
        'desktop' => (bool) ( $module['visibility']['desktop'] ?? true ) && $desktop,
        'mobile' => (bool) ( $module['visibility']['mobile'] ?? true ) && $mobile,
    );
    $out['enabled'] = ! empty( $module['enabled'] )
        && ( $out['visibility']['desktop'] || $out['visibility']['mobile'] );
    return $out;
}

/** Public applied plugin snapshots only. Never include history tokens, private history or drafts. */
function vc_live_commerce_public_config(): array {
    $specs = array(
        'broadcast' => array( 'peptide_core_product_broadcast_config', 'product-broadcast' ),
        'cartFeedback' => array( 'peptide_core_cart_feedback_config', 'cart-feedback' ),
        'recentlyViewed' => array( 'peptide_core_recently_viewed_config', 'recently-viewed' ),
        'activity' => array( 'peptide_core_activity_listener_config', 'activity-listener' ),
    );
    $out = array( 'version' => 1 );
    foreach ( $specs as $key => [ $helper, $route ] ) {
        $config = function_exists( $helper ) ? call_user_func( $helper ) : array( 'enabled' => false, 'revision' => 0 );
        $out[ $key ] = array( 'config' => is_array( $config ) ? $config : array( 'enabled' => false, 'revision' => 0 ), 'url' => rest_url( 'peptide/v1/' . $route ) );
    }
    foreach ( array( 'items', 'record', 'clear' ) as $action ) { $out['recentlyViewed'][ $action . 'Url' ] = rest_url( 'peptide/v1/recently-viewed/' . $action ); }
    $out['activity']['recommendationsUrl'] = rest_url( 'peptide/v1/activity-listener/recommendations' );
    return $out;
}

add_action( 'wp_head', function (): void {
    if ( is_admin() ) { return; }
    $config = vc_live_commerce_public_config();
    // The logged-in request nonce is not persisted in any application configuration.
    $config['wpNonce'] = is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '';
    $json = wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE );
    if ( false !== $json ) { wp_print_inline_script_tag( 'window.vcLiveCommerce = ' . $json . ';', array( 'id' => 'vc-live-commerce-config' ) ); }
}, 30 );
