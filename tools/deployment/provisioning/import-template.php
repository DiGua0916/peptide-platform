<?php
/** Native installation helper. Input paths are supplied by the root-owned broker. */
defined( 'WP_CLI' ) && WP_CLI || exit( 1 );
$file = getenv( 'VIALCOVE_TEMPLATE_FILE' );
if ( ! $file || ! is_readable( $file ) ) { throw new RuntimeException( 'template_file' ); }
$data = json_decode( file_get_contents( $file ), true, 64, JSON_THROW_ON_ERROR );
if ( 1 !== ( $data['version'] ?? 0 ) || ! empty( get_option( 'vialcove_master_imported' ) ) ) { return; }
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/media.php'; // video metadata
wp_set_current_user( (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] );
$map = (array) get_option( 'vialcove_master_image_map', array() );
foreach ( $data['images'] as $image ) {
	if ( isset( $map[ $image['old_id'] ] ) && 'attachment' === get_post_type( $map[ $image['old_id'] ] ) ) { continue; }
	$path = wp_upload_dir()['basedir'] . '/master/' . basename( $image['target'] );
	$mime = wp_check_filetype( $path );
	$id = wp_insert_attachment( array( 'post_title' => sanitize_text_field( $image['title'] ), 'post_status' => 'inherit', 'post_mime_type' => $mime['type'] ), $path );
	if ( ! $id || is_wp_error( $id ) ) { throw new RuntimeException( 'template_image' ); }
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $path ) );
	update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $image['alt'] ) );
	$map[ $image['old_id'] ] = $id;
	update_option( 'vialcove_master_image_map', $map, false );
}
$remap = static function ( $v ) use ( &$remap, $map ) {
	if ( ! is_array( $v ) ) { return $v; }
	foreach ( $v as $key => $value ) {
		$v[ $key ] = 'attachmentId' === $key && is_numeric( $value ) ? ( $map[ (int) $value ] ?? null ) : ( is_array( $value ) ? $remap( $value ) : $value );
	}
	return $v;
};
$template = (int) get_option( 'vialcove_master_template', 0 );
if ( ! $template ) { $template = PeptideCore\Templates::create( '店铺默认母版', '由平台母版初始化，可独立装修。' ); update_option( 'vialcove_master_template', $template, false ); }
if ( ! $template ) { throw new RuntimeException( 'template_create' ); }
foreach ( array( 'home', 'header', 'footer', 'global' ) as $key ) {
	$id = PeptideCore\Templates::document_id( $template, $key );
	$doc = $remap( $data['documents'][ $key ] ?? array() );
	// Links to the master store's domain become this store's own address (footer, buttons, menus).
	if ( ! empty( $data['origins'] ) && class_exists( 'PeptideCore\\Design_Sync' ) ) { $doc = PeptideCore\Design_Sync::rewrite_links( $doc, (array) $data['origins'], PeptideCore\Design_Sync::public_origin() ); }
	$modules = PeptideCore\Modules\Layout::clean_modules( $doc );
	wp_update_post( array( 'ID' => $id, 'post_content' => wp_slash( PeptideCore\Modules\Layout::content_from_modules( $modules ) ) ) );
	$result = PeptideCore\Modules\Layout::publish( $id );
	if ( is_wp_error( $result ) ) { throw new RuntimeException( 'template_publish' ); }
}
PeptideCore\Templates::set_default( $template );
update_option( 'vialcove_master_imported', $template, false );
echo 'Template imported.';
