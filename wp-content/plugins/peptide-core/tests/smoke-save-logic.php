<?php
/**
 * 后台保存逻辑冒烟测试（开发环境专用，需先运行 wp peptide demo seed）。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-save-logic.php
 * 会临时创建并删除测试批次 / 报告，结束时恢复演示数据原状。不要在正式站点运行。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

// 测试中创建的订单不发送通知邮件。
add_filter( 'pre_wp_mail', '__return_false' );

use PeptideCore\Meta;
wp_set_current_user( 1 );
$ok = static function ( $label, $cond ) { echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n"; };
$ids = get_posts( array( 'post_type' => 'pc_batch', 'meta_key' => '_pc_batch_no', 'meta_value' => 'DEMO-A-2401', 'fields' => 'ids' ) );
$batch_a1 = (int) $ids[0];
$product  = (int) get_post_meta( $batch_a1, Meta::BATCH_PRODUCT_ID, true );

// 1. 重复批次号：应退回草稿，不写入批次号
$dup = wp_insert_post( array( 'post_type' => 'pc_batch', 'post_status' => 'publish', 'post_title' => 'tmp' ) );
$_POST = array( 'pc_batch_nonce' => wp_create_nonce( 'pc_save_batch' ), 'pc_batch_no' => 'demo-a-2401', 'pc_product_id' => $product, 'pc_visibility' => 'public', 'pc_status' => 'active' );
\PeptideCore\Admin\Batch_Metabox::save( $dup, get_post( $dup ) );
$ok( 'duplicate batch number rejected', '' === get_post_meta( $dup, Meta::BATCH_NO, true ) );
$ok( 'duplicate batch forced to draft', 'draft' === get_post_status( $dup ) );

// 2. 合法新批次：小写转大写、标题同步
$_POST['pc_batch_no'] = ' demo-a-2499 ';
\PeptideCore\Admin\Batch_Metabox::save( $dup, get_post( $dup ) );
$ok( 'batch number normalized to DEMO-A-2499', 'DEMO-A-2499' === get_post_meta( $dup, Meta::BATCH_NO, true ) );
$ok( 'title synced', 'DEMO-A-2499' === get_the_title( $dup ) );
wp_delete_post( $dup, true );

// 3. 新报告发布：版本 +1，旧版本变 superseded
$old = get_posts( array( 'post_type' => 'pc_report', 'meta_key' => '_pc_batch_id', 'meta_value' => $batch_a1, 'fields' => 'ids' ) )[0];
$file = get_post_meta( $old, Meta::REPORT_FILE, true );
$new = wp_insert_post( array( 'post_type' => 'pc_report', 'post_status' => 'publish', 'post_title' => 'Demo v2', 'meta_input' => array( Meta::REPORT_FILE => $file ) ) );
$_POST = array( 'pc_report_nonce' => wp_create_nonce( 'pc_save_report' ), 'pc_batch_id' => $batch_a1, 'pc_type' => 'coa', 'pc_report_status' => 'published', 'pc_access' => 'public', 'pc_is_demo' => '1' );
\PeptideCore\Admin\Report_Metabox::save( $new, get_post( $new ) );
$ok( 'new report version is 2', 2 === (int) get_post_meta( $new, Meta::REPORT_VERSION, true ) );
$ok( 'old report superseded', 'superseded' === get_post_meta( $old, Meta::REPORT_STATUS, true ) );
$ok( 'old report points to new', $new === (int) get_post_meta( $old, Meta::REPORT_SUPERSEDED_BY, true ) );

// 4. 没有文件不能发布
$nofile = wp_insert_post( array( 'post_type' => 'pc_report', 'post_status' => 'publish', 'post_title' => 'No file' ) );
\PeptideCore\Admin\Report_Metabox::save( $nofile, get_post( $nofile ) );
$ok( 'report without file kept as draft', 'draft' === get_post_meta( $nofile, Meta::REPORT_STATUS, true ) );

// 还原：删除测试报告（新报告共用演示文件，先去掉文件元数据避免误删），恢复旧报告
delete_post_meta( $new, Meta::REPORT_FILE );
wp_delete_post( $new, true );
wp_delete_post( $nofile, true );
update_post_meta( $old, Meta::REPORT_STATUS, 'published' );
delete_post_meta( $old, Meta::REPORT_SUPERSEDED_BY );
$ok( 'demo file still exists after cleanup', is_file( \PeptideCore\Private_Files::path( $file['stored'] ) ) );
delete_transient( 'pc_admin_notices_1' );
