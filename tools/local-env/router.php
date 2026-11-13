<?php
/** PHP 内置服务器的路由：静态文件直接给，其余交给 WordPress（支持固定链接和 /wp-json/）。 */
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$file = $_SERVER['DOCUMENT_ROOT'] . $path;
if ( '/' !== $path && is_file( $file ) && ! preg_match( '/\.php$/i', $path ) ) {
	return false;
}
if ( is_file( $file ) && preg_match( '/\.php$/i', $path ) ) {
	$_SERVER['SCRIPT_FILENAME'] = $file;
	$_SERVER['SCRIPT_NAME']     = $path;
	chdir( dirname( $file ) );
	require $file;
	return true;
}
if ( is_dir( $file ) && is_file( rtrim( $file, '/' ) . '/index.php' ) ) {
	$index                      = rtrim( $file, '/' ) . '/index.php';
	$_SERVER['SCRIPT_FILENAME'] = $index;
	$_SERVER['SCRIPT_NAME']     = rtrim( $path, '/' ) . '/index.php';
	chdir( dirname( $index ) );
	require $index;
	return true;
}
$_SERVER['SCRIPT_FILENAME'] = $_SERVER['DOCUMENT_ROOT'] . '/index.php';
$_SERVER['SCRIPT_NAME']     = '/index.php';
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
