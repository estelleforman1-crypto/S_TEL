<?php
/**
 * Plugin Name: Jewelry Workflows
 * Description: Commission requests, quotes, deposits, production readiness, balance requests and languages for the jewelry store. WooCommerce stays the source of truth for products, orders and payments.
 * Version: 0.1.0
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * Text Domain: jewelry-workflows
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'JW_VERSION', '0.1.0' );
define( 'JW_FILE', __FILE__ );
define( 'JW_DIR', __DIR__ );

require_once JW_DIR . '/includes/class-jw-settings.php';
require_once JW_DIR . '/includes/class-jw-i18n.php';
require_once JW_DIR . '/includes/class-jw-lock.php';
require_once JW_DIR . '/includes/class-jw-sale-types.php';
require_once JW_DIR . '/includes/class-jw-uploads.php';
require_once JW_DIR . '/includes/class-jw-requests.php';
require_once JW_DIR . '/includes/class-jw-quotes.php';
require_once JW_DIR . '/includes/class-jw-payments.php';
require_once JW_DIR . '/includes/class-jw-emails.php';
require_once JW_DIR . '/includes/class-jw-stock.php';
require_once JW_DIR . '/includes/class-jw-admin.php';

// Language detection must run before WordPress parses the request URL.
JW_I18n::boot();

add_action( 'plugins_loaded', static function () {
	load_plugin_textdomain( 'jewelry-workflows', false, dirname( plugin_basename( JW_FILE ) ) . '/languages' );
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}
	JW_Sale_Types::init();
	JW_Requests::init();
	JW_Quotes::init();
	JW_Payments::init();
	JW_Emails::init();
	JW_Stock::init();
	if ( is_admin() ) {
		JW_Admin::init();
	}
} );

add_action( 'init', array( 'JW_Payments', 'register_statuses' ) );
add_action( 'init', array( 'JW_Requests', 'register_post_type' ) );

// HPOS (custom order tables) compatible: all order access goes through WC CRUD.
add_action( 'before_woocommerce_init', static function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', JW_FILE, true );
	}
} );

register_activation_hook( __FILE__, static function () {
	JW_Uploads::ensure_dir();
	JW_Requests::register_post_type();
	flush_rewrite_rules();
} );
