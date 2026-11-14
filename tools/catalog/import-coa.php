<?php
/**
 * Import a batch of COA PDFs into the public report library (2026-11-14 batch).
 * Usage: wp eval-file import-coa.php <dir>   (dir holds manifest.json, the PDFs and thumb/NNN.jpg)
 * Idempotent: a PDF whose sha256 is already stored on a report is skipped.
 * Each report links to its product (by slug) and specification; the product dialog
 * shows the newest report for the selected specification automatically.
 */
use PeptideCore\Meta;
use PeptideCore\Post_Types;
use PeptideCore\Private_Files;
use PeptideCore\Report_Archive;

$dir = rtrim( (string) ( $args[0] ?? '' ), '/' );
$rows = json_decode( (string) file_get_contents( $dir . '/manifest.json' ), true );
if ( ! is_array( $rows ) ) { WP_CLI::error( 'manifest.json missing or invalid' ); }
$import_key = 'coa-2026-11-14';
global $wpdb;
$created = 0; $skipped = 0;
foreach ( $rows as $r ) {
	$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = %s AND pm.meta_key = %s AND pm.meta_value = %s LIMIT 1", Post_Types::REPORT, Meta::REPORT_SOURCE_SHA256, $r['sha256'] ) );
	if ( $exists ) { echo "skip {$r['seq']} (exists #$exists)\n"; ++$skipped; continue; }
	$product = get_page_by_path( $r['product_slug'], OBJECT, 'product' );
	if ( ! $product ) { echo "NO PRODUCT {$r['seq']} {$r['product_slug']}\n"; continue; }
	$pdf = $dir . '/' . $r['file'];
	if ( ! hash_equals( $r['sha256'], hash_file( 'sha256', $pdf ) ) ) { echo "HASH MISMATCH {$r['seq']}\n"; continue; }
	$id = wp_insert_post( array( 'post_type' => Post_Types::REPORT, 'post_status' => 'draft', 'post_title' => $r['title'], 'post_author' => 1 ), true );
	if ( is_wp_error( $id ) ) { echo "FAIL {$r['seq']} ", $id->get_error_message(), "\n"; continue; }
	$file = Private_Files::store_local_file( $pdf );
	if ( is_wp_error( $file ) ) { wp_delete_post( $id, true ); echo "FAIL file {$r['seq']} ", $file->get_error_message(), "\n"; continue; }
	$file['original'] = sanitize_file_name( $r['original'] );
	// The file meta must come first: changing it clears the thumbnail, hash and public flag.
	update_post_meta( $id, Meta::REPORT_FILE, $file );
	$meta = array(
		Meta::REPORT_BATCH_ID => 0, Meta::REPORT_PRODUCT_ID => (int) $product->ID, Meta::REPORT_TYPE => 'coa',
		Meta::REPORT_VERSION => 0, Meta::REPORT_STATUS => 'draft', Meta::REPORT_ACCESS => 'admin', Meta::REPORT_IS_DEMO => '',
		Meta::REPORT_ISSUED_ON => $r['issued_on'], Meta::REPORT_ISSUER_NAME => $r['issuer'], Meta::REPORT_SPECIFICATION => $r['spec'],
		Meta::REPORT_REFERENCE_ID => $r['reference'], Meta::REPORT_SOURCE_PRODUCT => $r['source_product'],
		Meta::REPORT_SOURCE_CLIENT => $r['client'], Meta::REPORT_SOURCE_LOT => $r['lot'], Meta::REPORT_SOURCE_SHA256 => $r['sha256'],
		'_pc_coa_import_key' => $import_key, '_pc_coa_source_sequence' => (int) $r['seq'], '_pc_coa_original_filename' => $r['original'],
	);
	foreach ( $meta as $k => $v ) { update_post_meta( $id, $k, $v ); }
	$thumb_path = $dir . '/' . $r['thumb'];
	$thumb = is_file( $thumb_path ) ? Private_Files::store_local_file( $thumb_path ) : null;
	if ( is_array( $thumb ) ) {
		$size = getimagesize( $thumb_path );
		$thumb['original'] = 'coa-' . $r['seq'] . '.jpg'; $thumb['source_sha256'] = $r['sha256'];
		$thumb['width'] = (int) ( $size[0] ?? 0 ); $thumb['height'] = (int) ( $size[1] ?? 0 );
		update_post_meta( $id, Report_Archive::THUMB_META, $thumb );
	} else { echo "  no thumbnail for {$r['seq']}\n"; }
	update_post_meta( $id, Report_Archive::PUBLIC_META, '1' );
	$public = Report_Archive::get_public( $id );
	echo "created #$id {$r['seq']} {$product->post_title} {$r['spec']} {$r['issued_on']} ", $public ? 'public' : 'NOT PUBLIC', $public && $public['thumbnail_url'] ? ' +thumb' : '', "\n";
	++$created;
}
if ( class_exists( 'PeptideCore\Page_Cache' ) ) { PeptideCore\Page_Cache::flush(); }
echo "done: created $created, skipped $skipped\n";
