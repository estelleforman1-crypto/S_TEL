<?php
/**
 * Plugin Name: Jewelry Store DEVELOPMENT sandbox
 * Description: DEVELOPMENT ONLY. Catches all email into files and adds a clearly labeled demo payment gateway. Linked by scripts/setup-dev.sh. Never deploy.
 */

defined( 'ABSPATH' ) || exit;

// Refuse to run on a production site, even if someone copies this file there.
if ( 'production' === wp_get_environment_type() ) {
	return;
}

/* ---------- Mail sandbox: nothing leaves the machine ---------- */

add_filter(
	'pre_wp_mail',
	static function ( $null, $atts ) {
		$dir = defined( 'JW_DEV_MAIL_DIR' ) ? JW_DEV_MAIL_DIR : WP_CONTENT_DIR . '/jw-dev-mail';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( get_option( 'jw_dev_mail_fail' ) ) {
			return false; // Simulated delivery failure for tests.
		}
		$file = sprintf( '%s/%s-%019d.json', $dir, gmdate( 'Ymd-His' ), hrtime( true ) ); // Sortable by name.
		file_put_contents( $file, wp_json_encode( $atts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		return true;
	},
	10,
	2
);

/* ---------- Demo gateway: simulates an external payment page and a signed server callback ---------- */

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		class JW_Dev_Demo_Gateway extends WC_Payment_Gateway {
			public function __construct() {
				$this->id                 = 'jw_demo';
				$this->method_title       = 'DEMO payment (development only)';
				$this->method_description = 'Simulated gateway for local development. No money moves.';
				$this->title              = 'DEMO payment – not a real payment';
				$this->description        = 'Development only. You will see a simulated payment page.';
				$this->has_fields         = false;
				$this->supports           = array( 'products', 'refunds' );
				$this->enabled            = 'yes';
			}

			public function process_payment( $order_id ) {
				$order = wc_get_order( $order_id );
				$order->add_order_note( 'DEMO gateway: customer sent to the simulated payment page.' );
				return array(
					'result'   => 'success',
					'redirect' => add_query_arg( array( 'jw-demo-pay' => $order_id, 'key' => $order->get_order_key() ), home_url( '/' ) ),
				);
			}

			public function process_refund( $order_id, $amount = null, $reason = '' ) {
				wc_get_order( $order_id )->add_order_note( 'DEMO gateway: refund of ' . $amount . ' simulated.' );
				return true;
			}

			public static function secret(): string {
				return hash_hmac( 'sha256', 'jw-demo-gateway', wp_salt( 'secure_auth' ) );
			}

			public static function sign( array $p ): string {
				return hash_hmac( 'sha256', $p['order_id'] . '|' . $p['result'] . '|' . $p['txn'] . '|' . $p['amount'], self::secret() );
			}
		}

		add_filter( 'woocommerce_payment_gateways', static fn( $g ) => array_merge( $g, array( 'JW_Dev_Demo_Gateway' ) ) );
	},
	20
);

// Simulated external payment page. In a real gateway this page is on the provider's site.
add_action(
	'template_redirect',
	static function () {
		if ( empty( $_GET['jw-demo-pay'] ) ) {
			return;
		}
		$order = wc_get_order( absint( $_GET['jw-demo-pay'] ) );
		if ( ! $order || ! hash_equals( $order->get_order_key(), (string) ( $_GET['key'] ?? '' ) ) ) {
			wp_die( 'Unknown order.', 404 );
		}
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			$result  = 'approve' === ( $_POST['result'] ?? '' ) ? 'approved' : 'declined';
			$payload = array( 'order_id' => $order->get_id(), 'result' => $result, 'txn' => 'DEMO-' . wp_generate_password( 10, false ), 'amount' => $order->get_total() );
			$payload['sig'] = JW_Dev_Demo_Gateway::sign( $payload );
			// Server-to-server notification, as a real provider sends it. The browser return alone proves nothing.
			wp_remote_post( add_query_arg( 'wc-api', 'jw_demo_callback', home_url( '/' ) ), array( 'body' => $payload, 'timeout' => 20, 'sslverify' => false ) );
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}
		nocache_headers();
		echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>DEMO payment</title><body style="font-family:sans-serif;max-width:480px;margin:40px auto;padding:0 16px">';
		echo '<p style="background:#ffe08a;padding:8px;font-weight:bold">DEMO PAYMENT PAGE – DEVELOPMENT ONLY – NO MONEY MOVES</p>';
		echo '<h1>Pay ' . wp_kses_post( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ) . '</h1><p>Order #' . (int) $order->get_id() . '</p>';
		echo '<form method="post"><button name="result" value="approve" style="padding:12px 20px">Approve (simulate success)</button> <button name="result" value="decline" style="padding:12px 20px">Decline (simulate failure)</button></form></body>';
		exit;
	}
);

// Gateway callback endpoint. Verifies the signature and the amount, then lets WooCommerce complete the payment.
add_action(
	'woocommerce_api_jw_demo_callback',
	static function () {
		$p = array_map( 'sanitize_text_field', wp_unslash( $_POST ) );
		if ( empty( $p['sig'] ) || ! hash_equals( JW_Dev_Demo_Gateway::sign( $p ), $p['sig'] ) ) {
			status_header( 403 );
			exit( 'bad signature' );
		}
		$order = wc_get_order( absint( $p['order_id'] ) );
		if ( ! $order || abs( (float) $order->get_total() - (float) $p['amount'] ) > 0.001 ) {
			status_header( 400 );
			exit( 'bad order or amount' );
		}
		if ( 'approved' === $p['result'] ) {
			$order->payment_complete( $p['txn'] ); // WooCommerce ignores repeats on a paid order.
		} elseif ( $order->has_status( array( 'pending' ) ) ) {
			$order->update_status( 'failed', 'DEMO gateway: payment declined.' );
		}
		exit( 'ok' );
	}
);
