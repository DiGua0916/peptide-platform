<?php
/**
 * 商品卡片悬停内容（PRODUCT-HOVER-01）冒烟测试。开发环境专用，需先运行 wp peptide demo seed。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-product-hover.php
 * 会临时创建一个商品，结束时删除；演示商品的悬停设置会恢复原样。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

use PeptideCore\Admin\Product_Panel;
use PeptideCore\Catalog;
use PeptideCore\Meta;
use PeptideCore\Product_Hover;

$ok = static function ( string $label, bool $cond ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n";
};

$images = get_posts(
	array(
		'post_type'      => 'attachment',
		'post_mime_type' => 'image',
		'posts_per_page' => 2,
		'fields'         => 'ids',
	)
);

$p = new WC_Product_Simple();
$p->set_name( 'PC SMOKE hover product' );
$p->set_status( 'private' );
$p->save();

$ok( 'default is the report placeholder', 'placeholder' === peptide_core_get_product_hover( $p )['mode'] );

$p->update_meta_data( Product_Hover::MODE_META, 'image' );
$p->save();
$h = Product_Hover::for_product( wc_get_product( $p->get_id() ) );
$ok( 'custom image without an image falls back to the placeholder', 'placeholder' === $h['mode'] && 'image' === $h['requested'] );

if ( $images ) {
	$p->update_meta_data( Product_Hover::IMAGE_META, $images[0] );
	$p->update_meta_data( Product_Hover::ALT_META, 'Back of the box' );
	$p->save();
	$h = Product_Hover::for_product( wc_get_product( $p->get_id() ) );
	$ok( 'custom image is returned with src and alt', 'image' === $h['mode'] && '' !== $h['image']['src'] && 'Back of the box' === $h['image']['alt'] );

	$p->update_meta_data( Product_Hover::MODE_META, 'gallery' );
	$p->set_gallery_image_ids( array( $images[ count( $images ) - 1 ] ) );
	$p->save();
	$h = Product_Hover::for_product( wc_get_product( $p->get_id() ) );
	$ok( 'gallery mode uses the first gallery image', 'gallery' === $h['mode'] && str_contains( (string) $h['image']['src'], (string) pathinfo( (string) get_attached_file( $images[ count( $images ) - 1 ] ), PATHINFO_FILENAME ) ) );
	$p->set_gallery_image_ids( array() );
	$p->save();
	$ok( 'gallery mode without gallery falls back to the placeholder', 'placeholder' === Product_Hover::for_product( wc_get_product( $p->get_id() ) )['mode'] );
} else {
	echo "SKIP image tests: no images in the media library\n";
}

$p->update_meta_data( Product_Hover::MODE_META, 'report' );
$p->save();
$ok( 'report mode without batches falls back to the placeholder', 'placeholder' === Product_Hover::for_product( wc_get_product( $p->get_id() ) )['mode'] );

$a1        = Catalog::public_batch_id_by_no( 'DEMO-A-2401' );
$product_a = wc_get_product( (int) get_post_meta( $a1, Meta::BATCH_PRODUCT_ID, true ) );
$before    = $product_a->get_meta( Product_Hover::MODE_META );
$product_a->update_meta_data( Product_Hover::MODE_META, 'report' );
$product_a->save();
$h = Product_Hover::for_product( wc_get_product( $product_a->get_id() ) );
$ok( 'report mode shows the real current report of a public batch', 'report' === $h['mode'] && str_starts_with( (string) $h['report']['batch_no'], 'DEMO-A-' ) && '' !== $h['report']['title'] );
$ok( 'report summary has no file or download link', ! isset( $h['report']['download_url'] ) && ! isset( $h['report']['file'] ) );
$ok( 'demo report is flagged as demo', true === $h['report']['is_demo'] );
'' === $before ? $product_a->delete_meta_data( Product_Hover::MODE_META ) : $product_a->update_meta_data( Product_Hover::MODE_META, $before );
$product_a->save();

$p->update_meta_data( Product_Hover::MODE_META, 'none' );
$p->save();
$ok( 'none mode', 'none' === Product_Hover::for_product( wc_get_product( $p->get_id() ) )['mode'] );

// 保存：清理。
$post = get_posts( array( 'post_type' => 'post', 'numberposts' => 1, 'fields' => 'ids' ) );
Product_Hover::save(
	$p,
	array(
		'pc_hover_mode'  => 'evil',
		'pc_hover_image' => $post ? $post[0] : 123456789,
		'pc_hover_alt'   => '<b>Alt</b> text',
	)
);
$ok( 'invalid mode is saved as placeholder', 'placeholder' === $p->get_meta( Product_Hover::MODE_META ) );
$ok( 'non-image attachment is not saved', 0 === (int) $p->get_meta( Product_Hover::IMAGE_META ) );
$ok( 'alt text is plain text', 'Alt text' === $p->get_meta( Product_Hover::ALT_META ) );

// 商品编辑页的表单保存（WooCommerce 已校验 nonce）。
$_POST = array(
	'pc_hover_mode'  => 'gallery',
	'pc_hover_alt'   => 'Side view',
	'pc_hover_image' => '',
);
Product_Panel::save( $p );
$_POST = array();
$ok( 'product editor form saves the hover mode', 'gallery' === $p->get_meta( Product_Hover::MODE_META ) && 'Side view' === $p->get_meta( Product_Hover::ALT_META ) );
$ok( 'modes list has the five options', array( 'placeholder', 'report', 'gallery', 'image', 'none' ) === array_keys( Product_Hover::modes() ) );

$p->delete( true );
$ok( 'cleanup: test product removed', null === get_post( $p->get_id() ) || 'trash' === get_post_status( $p->get_id() ) );
