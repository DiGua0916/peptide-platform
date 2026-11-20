<?php
/**
 * Site 1 (2026-11-19, owner approved): link the 21 public COA reports that have no product,
 * because their source name differs from the product name (TB-500 vs "TB500(Thymosin B4 Acetate)").
 * Usage: wp eval-file coa-link-unlinked-20261119.php [dry|apply|revert]   (default dry)
 * Only touches reports that are public, have no batch and no product, and whose source name is in the map.
 * apply saves the previous values in option peptide_site1_coa_link_backup; revert puts them back.
 */
use PeptideCore\Meta; use PeptideCore\Post_Types; use PeptideCore\Report_Archive;
$mode = $args[0] ?? 'dry';
$backup_key = 'peptide_site1_coa_link_backup';
if ( 'revert' === $mode ) {
	foreach ( (array) get_option( $backup_key, array() ) as $id => $old ) {
		update_post_meta( (int) $id, Meta::REPORT_PRODUCT_ID, (int) $old['product'] );
		update_post_meta( (int) $id, Meta::REPORT_SPECIFICATION, (string) $old['spec'] );
		echo "reverted #$id\n";
	}
	delete_option( $backup_key );
	if ( class_exists( 'PeptideCore\Page_Cache' ) ) { PeptideCore\Page_Cache::flush(); }
	return;
}
// Source product name on the report => product title on the store (looked up by title, so IDs need not match).
$map = array(
	'TB-500' => 'TB500(Thymosin B4 Acetate)', 'Semaglutide' => 'Semaglutide', 'Melanotan-1' => 'Melanotan I',
	'Kisspeptin' => 'KissPeptin-10', 'Oxytocin' => 'Oxytocin Acetate', 'HGH_Somatropin' => 'HGH 191AA (Somatropin)', 'Tirzepatide' => 'Tirzepatide',
);
// Melanotan I sells MT-1 / MT-2 / ML10 at 10mg; a Melanotan-1 report belongs to the MT-1 option only,
// so its specification must equal that option's full label for the product popup to pick it.
$spec_override = array( 'Melanotan-1' => '10mg*10vials / MT-1' );
$by_title = array();
foreach ( array_unique( $map ) as $title ) {
	$found = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'title' => $title, 'numberposts' => 2, 'fields' => 'ids' ) );
	if ( 1 !== count( $found ) ) { WP_CLI::error( "product \"$title\": found " . count( $found ) ); }
	$by_title[ $title ] = (int) $found[0];
}
$backup = array(); $n = 0;
foreach ( get_posts( array( 'post_type' => Post_Types::REPORT, 'post_status' => array( 'draft', 'publish' ), 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) {
	$pub = Report_Archive::get_public( $id, false );
	if ( ! $pub || $pub['product'] || (int) get_post_meta( $id, Meta::REPORT_BATCH_ID, true ) > 0 ) { continue; }
	$src = (string) get_post_meta( $id, Meta::REPORT_SOURCE_PRODUCT, true );
	if ( ! isset( $map[ $src ] ) ) { echo "left alone #$id ($src)\n"; continue; }
	$pid = $by_title[ $map[ $src ] ];
	$old_spec = (string) get_post_meta( $id, Meta::REPORT_SPECIFICATION, true );
	$new_spec = $spec_override[ $src ] ?? $old_spec;
	echo ( 'apply' === $mode ? 'linked' : 'would link' ), " #$id $src {$old_spec} {$pub['issued_on']} -> #$pid {$map[$src]}", $new_spec !== $old_spec ? " (spec -> $new_spec)" : '', "\n";
	if ( 'apply' === $mode ) {
		$backup[ $id ] = array( 'product' => (int) get_post_meta( $id, Meta::REPORT_PRODUCT_ID, true ), 'spec' => $old_spec );
		update_post_meta( $id, Meta::REPORT_PRODUCT_ID, $pid );
		if ( $new_spec !== $old_spec ) { update_post_meta( $id, Meta::REPORT_SPECIFICATION, $new_spec ); }
	}
	++$n;
}
if ( 'apply' === $mode ) {
	update_option( $backup_key, $backup + (array) get_option( $backup_key, array() ), false );
	if ( class_exists( 'PeptideCore\Page_Cache' ) ) { PeptideCore\Page_Cache::flush(); }
}
echo "$mode: $n reports\n";
