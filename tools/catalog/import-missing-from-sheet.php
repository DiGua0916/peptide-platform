<?php
// 补 6 个表里有、店里没有的商品（2026-11-09）。幂等：按 _pc_spu 跳过已存在的。
require_once ABSPATH . 'wp-admin/includes/file.php'; require_once ABSPATH . 'wp-admin/includes/media.php'; require_once ABSPATH . 'wp-admin/includes/image.php';
$data = json_decode( file_get_contents( $args[0] ), true ); $run = '20261109-missing-from-sheet';
global $wpdb;
function pc_cat( $name ) { $name = trim( html_entity_decode( $name ) ); if ( '' === $name ) return 0; $t = get_term_by( 'name', $name, 'product_cat' ); if ( ! $t ) { $t = get_term_by( 'name', htmlspecialchars( $name ), 'product_cat' ); } if ( $t ) return (int) $t->term_id; $r = wp_insert_term( $name, 'product_cat' ); return is_wp_error( $r ) ? 0 : (int) $r['term_id']; }
function pc_img( $url, $parent, $alt, $filename ) { $tmp = download_url( $url, 60 ); if ( is_wp_error( $tmp ) ) { echo "    image failed: ", $tmp->get_error_message(), "\n"; return 0; } $ext = pathinfo( parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) ?: 'png'; $id = media_handle_sideload( array( 'name' => sanitize_file_name( $filename . '.' . $ext ), 'tmp_name' => $tmp ), $parent, $alt ); if ( is_wp_error( $id ) ) { @unlink( $tmp ); echo "    sideload failed: ", $id->get_error_message(), "\n"; return 0; } update_post_meta( $id, '_wp_attachment_image_alt', $alt ); return (int) $id; }
foreach ( $data as $title => $d ) {
  $m = $d['main']; $spu = (string) ( $m['商品spu'] ?? '' ); $name = trim( preg_replace( '/\s+/', ' ', str_replace( array( '（', '）' ), array( '(', ')' ), $title ) ) );
  $exists = $spu ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_pc_spu' AND meta_value=%s LIMIT 1", $spu ) ) : 0;
  if ( $exists ) { echo "skip (exists #$exists): $name\n"; continue; }
  $p = new WC_Product_Variable(); $p->set_name( $name ); $p->set_status( ( $m['商品上架'] ?? 'Y' ) === 'Y' ? 'publish' : 'draft' );
  if ( ! empty( $m['SEO URL Handle'] ) ) $p->set_slug( sanitize_title( $m['SEO URL Handle'] ) );
  $p->set_description( (string) ( $m['商品描述'] ?? '' ) ); $p->set_short_description( (string) ( $m['简短描述'] ?? '' ) ); $p->set_sku( $spu );
  $cats = array_filter( array_map( 'pc_cat', explode( ',', (string) ( $m['专辑名称'] ?? '' ) ) ) ); $p->set_category_ids( array_values( array_unique( $cats ) ) );
  $specs = array(); foreach ( $d['variants'] as $v ) { $s = trim( (string) ( $v['款式1'] ?? '' ) ); if ( $s !== '' && ! in_array( $s, $specs, true ) ) $specs[] = $s; }
  $attr = new WC_Product_Attribute(); $attr->set_name( 'Specifications' ); $attr->set_options( $specs ); $attr->set_visible( true ); $attr->set_variation( true ); $attr->set_position( 0 ); $p->set_attributes( array( $attr ) );
  $p->set_default_attributes( array( 'specifications' => $specs[0] ?? '' ) );
  $p->update_meta_data( '_pc_spu', $spu ); $p->update_meta_data( '_pc_product_subtitle', (string) ( $m['商品副标题'] ?? '' ) ); $p->update_meta_data( '_pc_catalog_import_run', $run );
  $pid = $p->save(); echo "created #$pid: $name (", count( $d['variants'] ), " variants)\n";
  // images: main list, then variant images
  $main_imgs = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $m['商品图片*'] ?? '' ) ) ) ) ); $img_ids = array(); $url_to_id = array();
  foreach ( $main_imgs as $i => $u ) { $id = pc_img( $u, $pid, $name, ( $spu ?: $pid ) . '_' . $name . '_' . ( $i + 1 ) ); if ( $id ) { $img_ids[] = $id; $url_to_id[ $u ] = $id; } }
  if ( $img_ids ) { $p->set_image_id( $img_ids[0] ); $p->set_gallery_image_ids( array_slice( $img_ids, 1 ) ); $p->save(); }
  foreach ( $d['variants'] as $v ) {
    $spec = trim( (string) ( $v['款式1'] ?? '' ) ); $sale = (float) ( $v['商品售价*'] ?? 0 ); $reg = (float) ( $v['商品原价'] ?? 0 );
    $var = new WC_Product_Variation(); $var->set_parent_id( $pid ); $var->set_attributes( array( 'specifications' => $spec ) ); $var->set_status( 'publish' );
    if ( ! empty( $v['商品SKU'] ) && ! wc_get_product_id_by_sku( $v['商品SKU'] ) ) $var->set_sku( (string) $v['商品SKU'] );
    if ( $reg > $sale && $sale > 0 ) { $var->set_regular_price( (string) $reg ); $var->set_sale_price( (string) $sale ); } else { $var->set_regular_price( (string) $sale ); }
    if ( ( $m['跟踪库存'] ?? 'N' ) === 'Y' ) { $var->set_manage_stock( true ); $var->set_stock_quantity( (int) (float) ( $v['商品库存'] ?? 0 ) ); } else { $var->set_manage_stock( false ); $var->set_stock_status( 'instock' ); }
    $vu = trim( (string) ( $v['商品图片*'] ?? '' ) ); if ( $vu !== '' ) { $vid = $url_to_id[ $vu ] ?? pc_img( $vu, $pid, $name . ' ' . $spec, ( $v['商品SKU'] ?? $spu ) . '_' . $name . '_' . $spec ); if ( $vid ) { $url_to_id[ $vu ] = $vid; $var->set_image_id( $vid ); } } elseif ( $img_ids ) { $var->set_image_id( $img_ids[0] ); }
    $var->update_meta_data( '_pc_catalog_import_run', $run ); $vid2 = $var->save(); echo "  variation #$vid2 $spec reg=", $var->get_regular_price(), " sale=", $var->get_sale_price(), " sku=", $var->get_sku(), " stock=", var_export( $var->get_stock_quantity(), true ), "\n";
  }
  WC_Product_Variable::sync( $pid ); wc_delete_product_transients( $pid );
}
if ( class_exists( 'PeptideCore\Page_Cache' ) ) PeptideCore\Page_Cache::flush(); echo "done\n";
