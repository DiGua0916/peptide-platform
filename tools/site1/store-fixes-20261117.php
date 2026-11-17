<?php
/**
 * 1 号站验收后的店铺数据修正（2026-11-17，店主手机验收第 2、5、6、7 项）。
 *
 * 用法（每家店各跑一次）：
 *   wp eval-file tools/site1/store-fixes-20261117.php dry       只打印会改什么，不写库
 *   wp eval-file tools/site1/store-fixes-20261117.php apply     改，改之前的值存进选项 pc_site1_fixes_20261117
 *   wp eval-file tools/site1/store-fixes-20261117.php rollback  按备份改回去（之后又被手动改过的商品跳过）
 *
 * 改的内容：
 * 1. 黑五等“减商品价”的满减活动全部改成草稿（以后的活动都从运费上减，定稿 P0-5 / 店主 11-17 确认）；
 * 2. 运费险显示原价 $200（划线），实收 $0.99 不变；
 * 3. 打开经销商 WhatsApp 弹窗（满 $600，门槛不变）；
 * 4. 配送方式名称 “UPS • FedEx • USPS” 改为 “FedEx”（定稿 P1-7：承运商统一写 FedEx）；
 * 5. 商品改名：Cagriliintide → Cagrilintide，adamax → Adamax，bac.water → Bacteriostatic Water（定稿 P2-1）；
 * 6. 商品正文：“≥ 99(%” → “≥ 99%”；“2-8°C / 2–8°C” 统一写成 “2–8 °C”（定稿 P1-7）。
 *    -20°C、-80°C 这类储存条件不动（是具体的储存说明，要不要改由店主决定）。
 */

defined( 'ABSPATH' ) || exit;

$mode   = $args[0] ?? 'dry';
$apply  = 'apply' === $mode;
$opt    = 'pc_site1_fixes_20261117';
$backup = get_option( $opt, array() );
$log    = static function ( string $line ) { echo $line . "\n"; };

if ( ! in_array( $mode, array( 'dry', 'apply', 'rollback' ), true ) ) {
	$log( '用法：dry | apply | rollback' );
	return;
}

if ( 'rollback' === $mode ) {
	if ( ! $backup ) { $log( '没有备份，什么都没改。' ); return; }
	foreach ( $backup['promotions'] ?? array() as $id => $status ) { wp_update_post( array( 'ID' => (int) $id, 'post_status' => $status ) ); $log( "活动 #$id 恢复为 $status" ); }
	foreach ( array( 'peptide_core_shipping_protection', 'peptide_core_resale_prompt', 'peptide_core_shipping_plans' ) as $key ) {
		if ( array_key_exists( $key, $backup['options'] ?? array() ) ) { update_option( $key, $backup['options'][ $key ] ); $log( "选项 $key 已恢复" ); }
	}
	foreach ( $backup['posts'] ?? array() as $id => $old ) {
		$post = get_post( (int) $id );
		if ( ! $post ) { continue; }
		if ( $post->post_modified_gmt !== ( $old['after_modified'] ?? '' ) ) { $log( "商品 #$id 之后又被改过，跳过" ); continue; }
		wp_update_post( array( 'ID' => (int) $id, 'post_title' => $old['title'], 'post_content' => $old['content'] ) );
		$log( "商品 #$id 已恢复" );
	}
	delete_option( $opt );
	if ( class_exists( '\PeptideCore\Page_Cache' ) ) { \PeptideCore\Page_Cache::flush(); }
	$log( '回滚完成。' );
	return;
}

if ( $apply && $backup ) { $log( '已经应用过（有备份）。要重新应用请先 rollback。' ); return; }
$new_backup = array( 'promotions' => array(), 'options' => array(), 'posts' => array(), 'at' => time() );

// 1. 减商品价的满减活动 → 草稿。
foreach ( get_posts( array( 'post_type' => 'pc_promotion', 'post_status' => 'publish', 'numberposts' => -1 ) ) as $promo ) {
	$log( "活动 #{$promo->ID}「{$promo->post_title}」 publish → draft" );
	if ( $apply ) { $new_backup['promotions'][ $promo->ID ] = 'publish'; wp_update_post( array( 'ID' => $promo->ID, 'post_status' => 'draft' ) ); }
}

// 2. 运费险原价 $200。
$protect = (array) get_option( 'peptide_core_shipping_protection', array() );
$log( '运费险：原价 ' . ( $protect['reference'] ?? '无' ) . ' → 200，实收 ' . ( $protect['price'] ?? '?' ) . ' 不变，开启：' . ( ! empty( $protect['enabled'] ) ? '是' : '否' ) );
if ( $apply ) { $new_backup['options']['peptide_core_shipping_protection'] = $protect; $protect['reference'] = 200; update_option( 'peptide_core_shipping_protection', $protect ); }

// 3. 经销商弹窗。
$resale = (array) get_option( 'peptide_core_resale_prompt', array() );
$log( '经销商弹窗：' . ( ! empty( $resale['enabled'] ) ? '开' : '关' ) . ' → 开，门槛 ' . ( $resale['threshold'] ?? 600 ) );
if ( $apply ) { $new_backup['options']['peptide_core_resale_prompt'] = $resale; $resale['enabled'] = true; $resale['threshold'] = $resale['threshold'] ?? 600; update_option( 'peptide_core_resale_prompt', $resale ); }

// 4. 配送方式名称。
$plans = (array) get_option( 'peptide_core_shipping_plans', array() ); $renamed = 0;
foreach ( $plans as &$plan ) {
	foreach ( $plan['rates'] ?? array() as &$rate ) {
		if ( 'UPS • FedEx • USPS' === ( $rate['name'] ?? '' ) ) { $log( "配送方式「{$rate['name']}」({$rate['id']}) → FedEx" ); $rate['name'] = 'FedEx'; $renamed++; }
	}
	unset( $rate );
}
unset( $plan );
if ( $apply && $renamed ) { $new_backup['options']['peptide_core_shipping_plans'] = get_option( 'peptide_core_shipping_plans' ); update_option( 'peptide_core_shipping_plans', $plans ); }

// 5 + 6. 商品名和正文。
$names = array( 'Cagriliintide' => 'Cagrilintide', 'adamax' => 'Adamax', 'bac.water' => 'Bacteriostatic Water' );
$text  = array( '/≥\s*99\s*\(\s*%/u' => '≥ 99%', '/\b2\s*[-–]\s*8\s*°\s*C/u' => '2–8 °C' );
foreach ( get_posts( array( 'post_type' => 'product', 'post_status' => array( 'publish', 'private', 'draft' ), 'numberposts' => -1 ) ) as $p ) {
	$title = $names[ $p->post_title ] ?? $p->post_title;
	$content = $p->post_content; $changes = array();
	foreach ( $text as $re => $to ) { $content = preg_replace( $re, $to, $content, -1, $count ); if ( $count ) { $changes[] = "$to ×$count"; } }
	if ( $content === $p->post_content ) { $changes = array(); }
	if ( $title === $p->post_title && ! $changes ) { continue; }
	$log( "商品 #{$p->ID}「{$p->post_title}」" . ( $title !== $p->post_title ? " 改名为「{$title}」" : '' ) . ( $changes ? '，正文：' . implode( '，', $changes ) : '' ) );
	if ( $apply ) {
		wp_update_post( array( 'ID' => $p->ID, 'post_title' => $title, 'post_content' => $content ) );
		$new_backup['posts'][ $p->ID ] = array( 'title' => $p->post_title, 'content' => $p->post_content, 'after_modified' => get_post( $p->ID )->post_modified_gmt );
	}
}

if ( $apply ) {
	update_option( $opt, $new_backup, false );
	if ( function_exists( 'wc_delete_product_transients' ) ) { wc_delete_product_transients(); }
	if ( class_exists( 'WC_Cache_Helper' ) ) { WC_Cache_Helper::get_transient_version( 'shipping', true ); }
	if ( class_exists( '\PeptideCore\Page_Cache' ) ) { \PeptideCore\Page_Cache::flush(); }
	$log( '已应用，备份在选项 ' . $opt . '。' );
} else {
	$log( '（预演，没有写库）' );
}
