<?php
/** Run through native WP CLI as the platform master store, never over HTTP. */
defined( 'WP_CLI' ) && WP_CLI || exit( 1 );
$template = PeptideCore\Templates::default_id( false );
$result = array( 'version' => 1, 'documents' => array(), 'images' => array() );
$ids = array();
$collect = static function ( $value ) use ( &$collect, &$ids ): void {
	if ( ! is_array( $value ) ) { return; }
	foreach ( $value as $key => $v ) {
		if ( 'attachmentId' === $key && is_numeric( $v ) && $v > 0 ) { $ids[ (int) $v ] = true; }
		if ( is_array( $v ) ) { $collect( $v ); }
	}
};
foreach ( array( 'home', 'header', 'footer', 'global' ) as $doc ) {
	$modules = PeptideCore\Modules\Layout::published_modules( $doc, $template );
	$result['documents'][ $doc ] = $modules;
	$collect( $modules );
}
$uploads = realpath( wp_upload_dir()['basedir'] );
foreach ( array_keys( $ids ) as $id ) {
	$file = get_attached_file( $id );
	$real = $file ? realpath( $file ) : false;
	if ( ! $real || ! wp_attachment_is_image( $id ) || ! str_starts_with( $real, $uploads . DIRECTORY_SEPARATOR ) || is_link( $file ) || ! in_array( strtolower( pathinfo( $real, PATHINFO_EXTENSION ) ), array( 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif' ), true ) || filesize( $real ) > 10 * 1024 * 1024 ) { continue; }
	$result['images'][] = array( 'old_id' => $id, 'file' => $real, 'title' => get_the_title( $id ), 'alt' => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
}
echo wp_json_encode( $result, JSON_UNESCAPED_UNICODE );
