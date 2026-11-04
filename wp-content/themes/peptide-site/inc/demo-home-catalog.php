<?php
/** Editable homepage catalogue presentation. WooCommerce owns every product and price. */
defined( 'ABSPATH' ) || exit;

add_filter( 'peptide_core_layout_definitions', function ( array $defs ): array {
    if ( ! isset( $defs['product_collection'] ) ) { return $defs; }
    $def =& $defs['product_collection'];
    foreach ( $def['settings'] as &$field ) {
        if ( 'cardPresentation' === $field['key'] ) { $field['options']['demo'] = 'Demo 首页选购'; }
        if ( 'products' === $field['key'] ) {
            // The editor's show_if supports AND only; share the existing picker
            // between manual selection and the optional full-catalogue promotion.
            unset( $field['show_if'] );
            $field['help'] = '手选来源：仅显示这些商品，并按列表排序。Demo 完整目录：这些商品优先展示，其余商品继续按所选排序显示；分类来源只优先展示属于该分类的商品。其它排版和关闭完整目录时，此列表只用于手选来源。';
        }
    }
    unset( $field );
    $f = '\PeptideCore\Modules\Fields';
    $def['settings'][] = $f::f( 'toggle', 'demoFullCatalog', '载入此来源的完整商品目录', true, array( 'help' => 'Demo 排版中可筛选完整目录（最多200款）；关闭后沿用原商品数量。手选来源始终保留手选商品顺序。' ) );
    $def['settings'][] = $f::f( 'number', 'demoPageSize', '每次显示商品数', 8, array( 'min' => 4, 'max' => 24 ) );
    $def['settings'][] = $f::f( 'text', 'demoSearchLabel', '搜索提示', 'Find a peptide' );
    $def['settings'][] = $f::f( 'text', 'demoMoreLabel', '展开商品按钮', 'View the full collection' );
    $def['settings'][] = $f::f( 'text', 'demoPriceNote', '价格说明', 'Every price follows the specification shown.' );
    $def['settings'][] = $f::f( 'select', 'catalogLayout', '选购区布局', 'standard', array( 'options' => array( 'standard' => '原目录排版', 'product_first' => '商品优先（紧凑首屏）' ), 'group' => 'layout' ) );
    if ( isset( $def['panels'] ) ) { $def['panels'][] = array( 'id' => 'demo-catalog', 'label' => 'Demo 首页选购', 'fields' => array( 'cardPresentation','catalogLayout','demoFullCatalog','demoPageSize','demoSearchLabel','demoMoreLabel','demoPriceNote' ) ); }
    return $defs;
}, 140 );

function vc_demo_catalog_products( array $module ): array {
    if ( ! function_exists( 'wc_get_products' ) ) { return array(); }
    $s = $module['settings'];
    if ( empty( $s['demoFullCatalog'] ) || 'manual' === $s['source'] ) { return \PeptideCore\Modules\Products::for_module( $module ); }
    $args = array( 'status' => 'publish', 'visibility' => 'visible', 'limit' => 200, 'has_password' => false, 'orderby' => array( 'menu_order' => 'ASC', 'title' => 'ASC', 'ID' => 'ASC' ), 'order' => 'ASC' );
    if ( 'category' === $s['source'] ) {
        $term = get_term( (int) $s['category'], 'product_cat' );
        if ( ! $term instanceof WP_Term ) { return array(); }
        $args['category'] = array( $term->slug );
    }
    if ( 'newest' === $s['sort'] ) { $args['orderby'] = 'date'; $args['order'] = 'DESC'; }
    elseif ( 'title' === $s['sort'] ) { $args['orderby'] = 'title'; }
    elseif ( in_array( $s['sort'], array( 'price_asc','price_desc' ), true ) ) { $args['orderby'] = 'price'; $args['order'] = 'price_desc' === $s['sort'] ? 'DESC' : 'ASC'; }
    if ( isset( $term ) && $term instanceof WP_Term && class_exists( '\PeptideCore\Collections' ) ) { $args = \PeptideCore\Collections::product_query_args( $term->term_id, $args ); }
    $products = array_values( array_filter( wc_get_products( $args ), array( '\PeptideCore\Modules\Products', 'public_catalog_product' ) ) );
    // Promotion belongs to this block, not global WooCommerce product menu_order.
    // Only query-eligible products can be promoted, preserving category boundaries.
    $remaining = array();
    foreach ( $products as $product ) { $remaining[ $product->get_id() ] = $product; }
    $promoted = array();
    foreach ( (array) ( $s['products'] ?? array() ) as $row ) {
        $id = absint( $row['settings']['product'] ?? 0 );
        if ( false === ( $row['enabled'] ?? true ) || ! isset( $remaining[ $id ] ) ) { continue; }
        $promoted[] = $remaining[ $id ];
        unset( $remaining[ $id ] );
    }
    return array_merge( $promoted, array_values( $remaining ) );
}

function vc_demo_home_active(): bool {
    if ( ! is_front_page() || ! class_exists( '\PeptideCore\Modules\Layout' ) ) { return false; }
    $template = \PeptideCore\Templates::previewing_id();
    if ( $template ) {
        $draft = \PeptideCore\Modules\Layout::preview_draft( $template, 'home' );
        $rows = $draft['modules'] ?? \PeptideCore\Modules\Layout::modules_from_content( (string) get_post_field( 'post_content', \PeptideCore\Templates::document_id( $template, 'home', false ) ) );
    } else { $rows = \PeptideCore\Modules\Layout::published_modules( 'home' ); }
    foreach ( $rows as $row ) {
        if ( ! ( $row['attrs']['enabled'] ?? true ) ) { continue; }
        if ( 'demo' === ( $row['attrs']['settings']['presentation'] ?? '' ) || 'demo' === ( $row['attrs']['settings']['cardPresentation'] ?? '' ) ) { return true; }
    }
    return false;
}
add_filter( 'body_class', function ( array $classes ): array { if ( vc_demo_home_active() ) { $classes[] = 'vc-demo-home'; } return $classes; } );

add_filter( 'peptide_core_module_html', function ( $html, array $module, string $context, string $device ) {
    if ( 'product_collection' !== $module['type'] || 'demo' !== ( $module['settings']['cardPresentation'] ?? '' ) ) { return $html; }
    ob_start(); get_template_part( 'template-parts/modules/demo-catalog', null, array( 'module' => $module, 'context' => $context ) ); return ob_get_clean();
}, 15, 4 );

add_action( 'wp_enqueue_scripts', function () {
    $base = get_template_directory(); $uri = get_template_directory_uri();
    wp_enqueue_style( 'vialcove-demo-catalog', $uri . '/assets/demo-catalog.css', array( 'vialcove-modules-home' ), (string) filemtime( $base . '/assets/demo-catalog.css' ) );
    wp_enqueue_script( 'vialcove-demo-catalog', $uri . '/assets/demo-catalog.js', array(), (string) filemtime( $base . '/assets/demo-catalog.js' ), true );
}, 45 );
