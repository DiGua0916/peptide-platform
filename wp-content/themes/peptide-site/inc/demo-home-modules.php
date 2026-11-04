<?php
/** Approved storefront presentations, using the existing editable layout documents. */
defined( 'ABSPATH' ) || exit;

/** Add fields and their editor panel without replacing the plugin's schema. */
function vc_demo_home_fields( array &$definition, array $fields, bool $items = false ): void {
    $target =& $definition;
    if ( $items ) { $target =& $definition['items']; }
    $key = $items ? 'fields' : 'settings';
    $keys = array_column( $target[ $key ], 'key' );
    foreach ( $fields as $field ) {
        if ( ! in_array( $field['key'], $keys, true ) ) { $target[ $key ][] = $field; }
    }
    if ( isset( $target['panels'] ) ) {
        $target['panels'][] = array( 'id' => 'vialcove-demo', 'label' => 'VialCove 首页排版', 'initialOpen' => true, 'fields' => array_column( $fields, 'key' ) );
    }
}

add_filter( 'peptide_core_layout_definitions', function ( array $defs ): array {
    if ( ! class_exists( '\PeptideCore\Modules\Fields' ) ) { return $defs; }
    $f = array( '\PeptideCore\Modules\Fields', 'f' );
    foreach ( array( 'hero_h1', 'info_list', 'report_feature', 'counter', 'card_list', 'faq', 'cta', 'rich_text' ) as $type ) {
        if ( ! isset( $defs[ $type ] ) ) { continue; }
        $key = 'info_list' === $type ? 'visualPresentation' : 'presentation';
        $found = false;
        foreach ( $defs[ $type ]['settings'] as &$field ) {
            if ( $key === $field['key'] ) { $field['options']['demo'] = 'VialCove 精简首页'; $found = true; }
        }
        unset( $field );
        if ( ! $found ) { vc_demo_home_fields( $defs[ $type ], array( $f( 'select', $key, '首页呈现', 'standard', array( 'group' => 'layout', 'options' => array( 'standard' => '原排版', 'demo' => 'VialCove 精简首页' ) ) ) ) ); }
        elseif ( isset( $defs[ $type ]['panels'] ) ) { $defs[ $type ]['panels'][] = array( 'id' => 'vialcove-presentation', 'label' => '首页呈现', 'fields' => array( $key ) ); }
    }
    vc_demo_home_fields( $defs['hero_h1'], array(
        $f( 'text', 'eyebrow', '主标题上方短句', '' ),
        $f( 'product', 'featuredProduct', '主视觉关联商品', null, array( 'help' => '选择后主图跟随后台排序的第一个可见规格；点击打开商品弹窗，商品详情链接保留。' ) ),
        $f( 'text', 'catalogCaption', '目录数量说明', '', array( 'help' => '用 {count} 表示实时可见商品数量。' ) ),
    ) );
    vc_demo_home_fields( $defs['info_list'], array(
        $f( 'text', 'subtext', '第二行说明', '' ),
        $f( 'link', 'link', '点击入口', null ),
    ), true );
    vc_demo_home_fields( $defs['report_feature'], array(
        $f( 'link', 'secondaryLink', '次要入口', null ),
        $f( 'textarea', 'points', '报告内容标签', '', array( 'help' => '一行一个标签。' ) ),
        $f( 'text', 'previewCaption', '证书预览提示', '' ),
        $f( 'toggle', 'showContext', '显示报告归属独立说明', true, array( 'help' => '仅控制首页独立说明块；打开报告时仍保留原文件与归属状态。' ) ),
    ) );
    vc_demo_home_fields( $defs['report_feature'], array(
        $f( 'number', 'reportId', '公开 COA 资料编号', 0, array( 'min' => 0, 'max' => 2147483647, 'help' => '公开资料库报告 ID；取消公开后这里也停止展示。' ) ),
        $f( 'image', 'image', '其它参考图', null, array( 'help' => '仅报告编号为0时使用。' ) ),
        $f( 'link', 'reportLink', '其它参考文件链接', null, array( 'help' => '仅报告编号为0时使用。' ) ),
    ), true );
    vc_demo_home_fields( $defs['counter'], array(
        $f( 'select', 'valueSource', '数字来源', 'manual', array( 'options' => array( 'manual' => '手工数字', 'products' => '实时可见商品数量', 'specifications' => '实时可见规格数量' ), 'help' => '实时数量来自 WooCommerce 当前公开目录，不使用预览快照。' ) ),
        $f( 'number', 'displayCap', '概括显示阈值', 0, array( 'min' => 0, 'max' => 999999, 'help' => '0显示实际数字；超过阈值时显示阈值与加号，不改变真实统计。' ) ),
    ), true );
    foreach ( $defs['counter']['settings'] as &$field ) {
        if ( 'numberSizePx' === $field['key'] ) { $field['uiMax'] = 120; }
    }
    unset( $field );
    vc_demo_home_fields( $defs['card_list'], array(
        $f( 'text', 'eyebrow', '小标题', '' ),
        $f( 'select', 'demoCardStyle', '信息排版', 'steps', array( 'options' => array( 'steps' => '步骤', 'reasons' => '选择理由' ) ) ),
    ) );
    vc_demo_home_fields( $defs['card_list'], array( $f( 'text', 'emphasis', '重点短句', '' ) ), true );
    vc_demo_home_fields( $defs['faq'], array( $f( 'image', 'supportImage', '标题旁插画', null ) ) );
    return $defs;
}, 120 );

/** Request-local public catalogue counts. No additional business records or cache of prices. */
function vc_demo_home_catalog_counts(): array {
    static $counts = null;
    if ( null !== $counts ) { return $counts; }
    $counts = array( 'products' => 0, 'specifications' => 0 );
    if ( ! function_exists( 'wc_get_products' ) ) { return $counts; }
    foreach ( wc_get_products( array( 'status' => 'publish', 'limit' => -1, 'return' => 'objects' ) ) as $product ) {
        if ( ! \PeptideCore\Modules\Products::public_catalog_product( $product ) ) { continue; }
        $specs = 0;
        if ( $product->is_type( 'variable' ) ) {
            foreach ( $product->get_visible_children() as $id ) {
                $variation = wc_get_product( $id );
                if ( $variation instanceof WC_Product_Variation && $variation->get_parent_id() === $product->get_id() && $variation->variation_is_visible() && '' !== $variation->get_price() ) { ++$specs; }
            }
        } elseif ( '' !== $product->get_price() ) { $specs = 1; }
        if ( $specs ) { ++$counts['products']; $counts['specifications'] += $specs; }
    }
    return $counts;
}

/** Only public library DTOs may provide controlled document/thumbnail URLs. */
function vc_demo_home_report( int $id ): ?array {
    static $lookups = array();
    if ( ! is_callable( array( '\PeptideCore\Report_Archive', 'get_public' ) ) || $id < 1 ) { return null; }
    if ( ! array_key_exists( $id, $lookups ) ) { $lookups[ $id ] = \PeptideCore\Report_Archive::get_public( $id ); }
    return $lookups[ $id ];
}

function vc_demo_home_items( array $module, string $context ): array {
    return array_values( array_filter( $module['items'] ?? array(), static fn( $item ) => ! empty( $item['enabled'] ) || 'editor' === $context ) );
}

function vc_demo_home_item_attrs( array $item ): string {
    return ' data-module-item="' . esc_attr( $item['id'] ) . '"' . ( empty( $item['enabled'] ) ? ' data-vc-demo-disabled="true"' : '' );
}

function vc_demo_home_description( array $settings ): string {
    return null !== ( $settings['descriptionHtml'] ?? null ) ? wp_kses_post( $settings['descriptionHtml'] ) : nl2br( esc_html( $settings['description'] ?? '' ) );
}

function vc_demo_home_image( ?array $image, string $class = '', string $sizes = '96px' ): string {
    return $image ? vc_module_picture( array( 'desktop' => $image, 'mobile' => null ), $class, $sizes ) : '';
}

/** Rendering remains inside the plugin's device-aware, editable outer section. */
add_filter( 'peptide_core_module_html', function ( $html, array $module, string $context, string $device ) {
    $key = 'info_list' === $module['type'] ? 'visualPresentation' : 'presentation';
    if ( null !== $html || 'demo' !== ( $module['settings'][ $key ] ?? '' ) ) { return $html; }
    if ( ! in_array( $module['type'], array( 'hero_h1', 'info_list', 'report_feature', 'counter', 'card_list', 'faq', 'cta', 'rich_text' ), true ) ) { return $html; }
    ob_start();
    get_template_part( 'template-parts/modules/demo-' . str_replace( '_', '-', $module['type'] ), null, array( 'module' => $module, 'context' => $context, 'device' => $device ) );
    return ob_get_clean();
}, 15, 4 );

function vc_demo_home_enqueue(): void {
    $dir = get_template_directory();
    $uri = get_template_directory_uri();
    wp_enqueue_style( 'vialcove-demo-home', $uri . '/assets/demo-home.css', array( 'vialcove-modules-home' ), (string) filemtime( $dir . '/assets/demo-home.css' ) );
    if ( ! is_admin() ) { wp_enqueue_script( 'vialcove-demo-home', $uri . '/assets/demo-home.js', array(), (string) filemtime( $dir . '/assets/demo-home.js' ), true ); }
}
add_action( 'wp_enqueue_scripts', 'vc_demo_home_enqueue', 35 );
add_action( 'enqueue_block_assets', function (): void { if ( is_admin() && wp_style_is( 'vialcove-modules-home', 'registered' ) ) { vc_demo_home_enqueue(); } }, 35 );
