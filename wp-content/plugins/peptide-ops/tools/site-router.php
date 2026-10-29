<?php
// Only used by the controlled local development web server.
$root = realpath( $_SERVER['DOCUMENT_ROOT'] ?? '' );
$request = rawurldecode( (string) parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH ) );
if ( ! $root || str_contains( $request, "\0" ) || str_contains( $request, '\\' ) ) { http_response_code( 400 ); exit; }
$file = realpath( $root . '/' . ltrim( $request, '/' ) );
$base = rtrim( str_replace( '\\', '/', $root ), '/' ) . '/';
$path = false === $file ? '' : str_replace( '\\', '/', $file );
$allowed = DIRECTORY_SEPARATOR === '\\' ? str_starts_with( strtolower( $path ), strtolower( $base ) ) : str_starts_with( $path, $base );
if ( $file && ! $allowed && $file !== $root ) { http_response_code( 403 ); exit; }
if ( preg_match( '~(?:^|/)(?:\.[^/]+|wp-config\.php|debug\.log)(?:/|$)~i', $request ) ) { http_response_code( 403 ); exit; }
if ( $file && $allowed && is_file( $file ) ) { return false; }
require $root . '/index.php';
