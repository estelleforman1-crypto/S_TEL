<?php
// Router for the PHP built-in server: serve real files, send the rest to WordPress.
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
// Diagnostic for npm run doctor: which host and scheme headers reach the server (development only).
if ( '/__jw-headers' === $path ) {
	header( 'Content-Type: text/plain' );
	foreach ( $_SERVER as $k => $v ) {
		if ( str_starts_with( $k, 'HTTP_' ) && ! in_array( $k, array( 'HTTP_COOKIE', 'HTTP_AUTHORIZATION' ), true ) ) {
			echo $k, ': ', is_string( $v ) ? $v : '', "\n";
		}
	}
	return true;
}
$file = $_SERVER['DOCUMENT_ROOT'] . $path;
if ( '/' !== $path && is_file( $file ) ) {
	if ( str_ends_with( $file, '.php' ) ) {
		chdir( dirname( $file ) );
		require $file;
		return;
	}
	return false;
}
if ( is_dir( $file ) && is_file( rtrim( $file, '/' ) . '/index.php' ) && str_contains( $path, '/wp-admin' ) ) {
	chdir( rtrim( $file, '/' ) );
	require rtrim( $file, '/' ) . '/index.php';
	return;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
chdir( $_SERVER['DOCUMENT_ROOT'] );
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
