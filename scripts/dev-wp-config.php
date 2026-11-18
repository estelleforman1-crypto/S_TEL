<?php
/**
 * DEVELOPMENT ONLY. Loaded from wp-config.php by scripts/setup-dev.sh.
 *
 * Makes WordPress follow the address the visitor used: http://localhost:8080
 * for the tests, and https://<codespace>-8080.app.github.dev in GitHub
 * Codespaces. Without this, WordPress redirects every request to the stored
 * site URL. It trusts X-Forwarded-* headers, so never use it in production.
 */
if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] ) {
	$_SERVER['HTTPS'] = 'on';
}
if ( ! empty( $_SERVER['HTTP_X_FORWARDED_HOST'] ) ) {
	$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_X_FORWARDED_HOST'];
}
if ( ! empty( $_SERVER['HTTP_HOST'] ) && preg_match( '/^[A-Za-z0-9.\-]+(:\d+)?$/', $_SERVER['HTTP_HOST'] ) ) {
	define( 'WP_HOME', ( empty( $_SERVER['HTTPS'] ) ? 'http' : 'https' ) . '://' . $_SERVER['HTTP_HOST'] );
	define( 'WP_SITEURL', WP_HOME );
}
