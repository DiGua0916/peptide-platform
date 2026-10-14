<?php
/**
 * 导入功能冒烟测试（BE-06，开发环境专用）。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-import.php
 * 在临时目录生成一个小报价表、CSV 和报告文件，导入到标签 smoke-import，结束时用 delete 全部删除。
 * 不会碰到其他导入标签或演示数据。不要在正式站点运行。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

use PeptideCore\Batch_Importer;
use PeptideCore\Catalog;
use PeptideCore\Cli\Demo_Pdf;
use PeptideCore\Meta;
use PeptideCore\Private_Files;

add_filter( 'pre_wp_mail', '__return_false' );

$ok = static function ( string $label, bool $cond ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n";
};
$cli = static fn( string $cmd ) => WP_CLI::runcommand(
	$cmd,
	array(
		'return'     => 'all',
		'launch'     => false,
		'exit_error' => false,
	)
);

$tag = 'smoke-import';
$dir = trailingslashit( get_temp_dir() ) . 'pc-smoke-import-' . wp_generate_password( 6, false );
wp_mkdir_p( $dir . '/reports' );

// 1. 生成一个最小的报价表 xlsx（结构与用户报价表相同：A 编号、B 产品名（合并单元格）、C 规格、D 价格）。
$strings = array( 'Quote', 'Code', 'Product', 'Spec', 'Price', 'SMKA1', 'Smoke Peptide', '5mg*10vials', 'SMKA2', '10mg*10vials', 'SMKB1', 'Smoke Single', '2mg*10vials' );
$sst     = '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
foreach ( $strings as $s ) {
	$sst .= '<si><t>' . esc_html( $s ) . '</t></si>';
}
$sst  .= '</sst>';
$cell  = static fn( $ref, $idx ) => '<c r="' . $ref . '" t="s"><v>' . $idx . '</v></c>';
$num   = static fn( $ref, $v ) => '<c r="' . $ref . '"><v>' . $v . '</v></c>';
$sheet = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
	. '<row r="1">' . $cell( 'A1', 0 ) . '</row>'
	. '<row r="2">' . $cell( 'A2', 1 ) . $cell( 'B2', 2 ) . $cell( 'C2', 3 ) . $cell( 'D2', 4 ) . '</row>'
	. '<row r="3">' . $cell( 'A3', 5 ) . $cell( 'B3', 6 ) . $cell( 'C3', 7 ) . $num( 'D3', 11 ) . '</row>'
	. '<row r="4">' . $cell( 'A4', 8 ) . $cell( 'C4', 9 ) . $num( 'D4', 22 ) . '</row>'
	. '<row r="5">' . $cell( 'A5', 10 ) . $cell( 'B5', 11 ) . $cell( 'C5', 12 ) . $num( 'D5', 7 ) . '</row>'
	. '</sheetData></worksheet>';
$zip = new ZipArchive();
$zip->open( $dir . '/quote.xlsx', ZipArchive::CREATE );
$zip->addFromString( 'xl/sharedStrings.xml', $sst );
$zip->addFromString( 'xl/worksheets/sheet1.xml', $sheet );
$zip->close();

$res = $cli( 'peptide import quote ' . escapeshellarg( $dir . '/quote.xlsx' ) . ' --tag=' . $tag );
$ok( 'quote import succeeded', 0 === $res->return_code );
$var    = wc_get_product( wc_get_product_id_by_sku( 'SMKA1' ) );
$parent = $var ? wc_get_product( $var->get_parent_id() ) : null;
$ok( 'merged rows became one variable product', $parent && $parent->is_type( 'variable' ) && 'Smoke Peptide' === $parent->get_name() && 2 === count( $parent->get_children() ) );
$ok( 'variation price from sheet', $var && '11' === $var->get_regular_price() );
$single = wc_get_product( wc_get_product_id_by_sku( 'SMKB1' ) );
$ok( 'single row became simple product', $single && $single->is_type( 'simple' ) && '7' === $single->get_regular_price() );
$ok( 'imported products marked demo', $parent && '1' === $parent->get_meta( Meta::PRODUCT_IS_DEMO ) );
$res = $cli( 'peptide import quote ' . escapeshellarg( $dir . '/quote.xlsx' ) . ' --tag=' . $tag );
$ok( 'second import with same tag refused', 0 !== $res->return_code );

// 2. 批次与报告 CSV。
file_put_contents( $dir . '/reports/smoke-coa.pdf', Demo_Pdf::build( array( 'SMOKE TEST DOCUMENT' ) ) );
file_put_contents( $dir . '/reports/fake.pdf', 'not really a pdf' );
$csv = "sku,batch_no,manufactured_on,report_pending,is_demo,report_file,report_type,report_access\n"
	. "SMKA2,SMOKE-B-001,2026-09-01,,yes,smoke-coa.pdf,coa,registered\n"
	. "SMKA1,SMOKE-B-001,,,yes,,,\n"
	. "SMKB1,SMOKE-B-002,,yes,yes,,,\n"
	. "NOPE-SKU,SMOKE-B-003,,,,,,\n"
	. "SMKB1,SMOKE-B-004,2026-13-45,,,,,\n"
	. "SMKB1,SMOKE-B-005,,,,missing.pdf,coa,public\n";
file_put_contents( $dir . '/batches.csv', $csv );

$rows = Batch_Importer::read_csv( $dir . '/batches.csv' );
$plan = Batch_Importer::plan( $rows, $dir . '/reports', false );
$ok( 'plan: 2 valid batches', array( 'SMOKE-B-001', 'SMOKE-B-002' ) === array_keys( $plan['batches'] ) );
$ok( 'plan: 3 lines rejected (unknown sku, bad date, missing file)', 3 === count( $plan['errors'] ) );
$ok( 'plan: one batch for two pack sizes', 2 === count( $plan['batches']['SMOKE-B-001']['variation_ids'] ) );

$result = Batch_Importer::run( $plan['batches'], $tag );
$ok( 'run: no errors', array() === $result['errors'] );
$b1  = $result['created']['SMOKE-B-001']['batch_id'] ?? 0;
$r1  = $result['created']['SMOKE-B-001']['report_ids'][0] ?? 0;
$file = get_post_meta( $r1, Meta::REPORT_FILE, true );
$ok( 'report stored in private folder', is_array( $file ) && is_file( Private_Files::path( $file['stored'] ) ) && 'application/pdf' === $file['mime'] );
$ok( 'report published v1 with access from CSV', 'published' === get_post_meta( $r1, Meta::REPORT_STATUS, true ) && 1 === (int) get_post_meta( $r1, Meta::REPORT_VERSION, true ) && 'registered' === get_post_meta( $r1, Meta::REPORT_ACCESS, true ) );
$ok( 'batch visible through lookup API logic', $b1 === Catalog::public_batch_id_by_no( 'SMOKE-B-001' ) && 'restricted' === Catalog::batch( $b1, 0 )['report_state'] );
$ok( 'pending batch flagged', 'pending' === Catalog::batch( $result['created']['SMOKE-B-002']['batch_id'], 0 )['report_state'] );

// 内容不是 PDF 的“.pdf”文件会被拒绝。
$bad = Private_Files::store_local_file( $dir . '/reports/fake.pdf' );
$ok( 'fake pdf rejected', is_wp_error( $bad ) && 'pc_upload_type' === $bad->get_error_code() );
if ( ! is_wp_error( $bad ) ) {
	Private_Files::delete( $bad['stored'] );
}

// 3. 已存在的批次：不加 --update 时拒绝，加了以后补充新版本报告。
file_put_contents( $dir . '/update.csv', "sku,batch_no,report_file,report_type\nSMKA2,SMOKE-B-001,smoke-coa.pdf,coa\n" );
$rows = Batch_Importer::read_csv( $dir . '/update.csv' );
$ok( 'existing batch refused without --update', array() === Batch_Importer::plan( $rows, $dir . '/reports', false )['batches'] );
$up = Batch_Importer::run( Batch_Importer::plan( $rows, $dir . '/reports', true )['batches'], $tag );
$r2 = $up['created']['SMOKE-B-001']['report_ids'][0] ?? 0;
$ok( 'update adds v2 and supersedes v1', 2 === (int) get_post_meta( $r2, Meta::REPORT_VERSION, true ) && 'superseded' === get_post_meta( $r1, Meta::REPORT_STATUS, true ) );

// 4. Excel 中文 Windows 默认编码（GB18030）的 CSV。
file_put_contents( $dir . '/gbk.csv', mb_convert_encoding( "sku,batch_no,origin_note,is_demo\nSMKB1,SMOKE-B-006,中国工厂（测试）,yes\n", 'GB18030', 'UTF-8' ) );
$plan = Batch_Importer::plan( Batch_Importer::read_csv( $dir . '/gbk.csv' ), '', false );
$ok( 'GB18030 CSV decoded', '中国工厂（测试）' === ( $plan['batches']['SMOKE-B-006']['origin_note'] ?? '' ) );

// 5. 按标签删除：商品、批次、报告和文件都被清理。
$stored = array( $file['stored'], get_post_meta( $r2, Meta::REPORT_FILE, true )['stored'] ?? '' );
$res    = $cli( 'peptide import delete --tag=' . $tag . ' --yes' );
$ok( 'delete by tag succeeded', 0 === $res->return_code );
$ok( 'products removed', ! wc_get_product_id_by_sku( 'SMKA1' ) && ! wc_get_product_id_by_sku( 'SMKB1' ) );
$ok( 'batches and reports removed', array() === Batch_Importer::tagged_ids( $tag ) );
$ok( 'report files removed', ! is_file( (string) Private_Files::path( $stored[0] ) ) && ! is_file( (string) Private_Files::path( $stored[1] ) ) );

// 清理临时目录。
foreach ( array( 'reports/smoke-coa.pdf', 'reports/fake.pdf', 'quote.xlsx', 'batches.csv', 'update.csv', 'gbk.csv' ) as $f ) {
	wp_delete_file( $dir . '/' . $f );
}
@rmdir( $dir . '/reports' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
$ok( 'temp folder cleaned', ! is_dir( $dir ) );
