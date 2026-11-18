<?php
/**
 * Integration tests. They run inside WordPress against the real database and
 * real WooCommerce objects (no mocks except the labeled dev mail sandbox and
 * the DEMO gateway). Run: tests/run-integration.sh
 *
 * Each test creates its own fixtures, so the suite can run on a seeded dev site.
 */

use Automattic\WooCommerce\Checkout\Helpers\ReserveStock;
use Automattic\WooCommerce\Checkout\Helpers\ReserveStockException;

$GLOBALS['jw_results'] = array( 'pass' => 0, 'fail' => 0, 'failures' => array() );

function jw_assert( bool $cond, string $msg ): void {
	if ( $cond ) {
		$GLOBALS['jw_results']['pass']++;
		WP_CLI::log( "  ok   {$msg}" );
	} else {
		$GLOBALS['jw_results']['fail']++;
		$GLOBALS['jw_results']['failures'][] = $msg;
		WP_CLI::log( "  FAIL {$msg}" );
	}
}
function jw_eq( $expected, $actual, string $msg ): void {
	jw_assert( $expected == $actual, $msg . ( $expected == $actual ? '' : ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')' ) );
}
function jw_test( string $name, callable $fn ): void {
	WP_CLI::log( "\n# {$name}" );
	try {
		$fn();
	} catch ( Throwable $e ) {
		jw_assert( false, 'exception: ' . get_class( $e ) . ': ' . $e->getMessage() . ' @' . basename( $e->getFile() ) . ':' . $e->getLine() );
	}
}
function jw_mail_count(): int {
	return count( glob( JW_DEV_MAIL_DIR . '/*.json' ) ?: array() );
}
function jw_last_mail(): array {
	$files = glob( JW_DEV_MAIL_DIR . '/*.json' ) ?: array();
	sort( $files );
	return $files ? json_decode( file_get_contents( end( $files ) ), true ) : array();
}
function jw_product( string $slug ): WC_Product {
	return wc_get_product( get_page_by_path( $slug, OBJECT, 'product' )->ID );
}
function jw_checkout_order( WC_Product $product, int $qty = 1 ): WC_Order {
	$order = wc_create_order( array( 'status' => 'pending' ) );
	$order->add_product( $product, $qty );
	$order->set_billing_email( 'buyer@example.test' );
	$order->set_payment_method( 'jw_demo' );
	$order->set_created_via( 'checkout' );
	$order->calculate_totals( false );
	$order->save();
	return $order;
}
function jw_pay( WC_Order $order, string $txn = '' ): void {
	wc_get_order( $order->get_id() )->payment_complete( $txn ?: 'TEST-' . wp_generate_password( 6, false ) );
}
function jw_request( string $type, array $extra = array() ): int {
	$base = array(
		'type'    => $type,
		'name'    => 'Test Customer',
		'email'   => 'customer@example.test',
		'country' => 'IL',
		'city'    => 'Tel Aviv',
		'lang'    => 'he',
		'brief'   => 'A test brief',
	);
	$id = JW_Requests::create( array_merge( $base, $extra ) );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() );
	}
	return $id;
}
function jw_band_request(): int {
	$band = jw_product( 'demo-band-ring' );
	return jw_request( 'made_to_order', array( 'product_id' => $band->get_id(), 'qty' => 1, 'attributes' => array( 'attribute_pa_ring-size' => '52', 'attribute_pa_material' => 'demo-silver' ) ) );
}
/** Quote -> send -> accept -> pay deposit. Returns [parent, deposit order]. */
function jw_accepted_quote( array $q = array( 'unit_price' => '600', 'shipping' => '40' ) ): array {
	$rid   = jw_band_request();
	$order = JW_Quotes::save_quote( $rid, $q );
	JW_Quotes::send( $order );
	$token = jw_token_for( $order );
	$dep   = JW_Quotes::accept( wc_get_order( $order->get_id() ), $token );
	jw_pay( $dep );
	return array( wc_get_order( $order->get_id() ), wc_get_order( $dep->get_id() ) );
}
function jw_token_for( WC_Order $order ): string {
	$link = (string) wc_get_order( $order->get_id() )->get_meta( '_jw_quote_link' );
	parse_str( (string) wp_parse_url( $link, PHP_URL_QUERY ), $q );
	return $q['k'] ?? '';
}

update_option( 'jw_dev_mail_fail', 0 );
JW_Settings::update( array( 'mto_deposit_percent' => 50, 'mto_deposit_basis' => 'total', 'mto_deposit_confirmed' => 0 ) );

// ------------------------------------------------------------------
jw_test( 'Ready stock: quantity-one race (stock reservation)', function () {
	$p = jw_product( 'demo-one-of-a-kind-pendant' );
	wc_update_product_stock( $p, 1, 'set' );
	$a = jw_checkout_order( $p );
	$b = jw_checkout_order( $p );
	$rs = new ReserveStock();
	$rs->reserve_stock_for_order( $a, 15 );
	$second_blocked = false;
	try {
		$rs->reserve_stock_for_order( $b, 15 );
	} catch ( ReserveStockException $e ) {
		$second_blocked = true;
	}
	jw_assert( $second_blocked, 'second buyer cannot reserve the last unit' );
	jw_pay( $a );
	jw_eq( 0, wc_get_product( $p->get_id() )->get_stock_quantity(), 'stock is 0 after the first buyer pays' );
	jw_eq( 'outofstock', wc_get_product( $p->get_id() )->get_stock_status(), 'product shows as sold out' );
	jw_assert( ! wc_get_product( $p->get_id() )->is_in_stock(), 'cart/checkout validation sees it out of stock' );
	$b->update_status( 'cancelled' );
	$rs->release_stock_for_order( $b );
} );

jw_test( 'Ready stock: duplicate payment callback', function () {
	$p = jw_product( 'demo-signet-ring' );
	wc_update_product_stock( $p, 3, 'set' );
	$o = jw_checkout_order( $p );
	jw_pay( $o, 'TXN-1' );
	jw_pay( $o, 'TXN-1' );
	jw_pay( $o, 'TXN-1' );
	jw_eq( 2, wc_get_product( $p->get_id() )->get_stock_quantity(), 'stock reduced exactly once' );
	jw_eq( 'processing', wc_get_order( $o->get_id() )->get_status(), 'order paid once, processing' );
} );

function jw_expire_reservations(): void {
	global $wpdb;
	$wpdb->query( "UPDATE {$wpdb->wc_reserved_stock} SET expires = '2000-01-01 00:00:00'" );
}
function jw_age_order( WC_Order $o, int $seconds ): void {
	global $wpdb;
	$past_local = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $seconds );
	$past_gmt   = gmdate( 'Y-m-d H:i:s', time() - $seconds );
	if ( Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
		$wpdb->update( $wpdb->prefix . 'wc_orders', array( 'date_updated_gmt' => $past_gmt ), array( 'id' => $o->get_id() ) );
	}
	$wpdb->update( $wpdb->posts, array( 'post_modified' => $past_local, 'post_modified_gmt' => $past_gmt ), array( 'ID' => $o->get_id() ) );
	clean_post_cache( $o->get_id() );
	wp_cache_flush();
}

jw_test( 'Ready stock: late payment after the hold expired and the piece sold', function () {
	$p = jw_product( 'demo-one-of-a-kind-pendant' );
	wc_update_product_stock( $p, 1, 'set' );
	$rs = new ReserveStock();
	$a  = jw_checkout_order( $p );
	$rs->reserve_stock_for_order( $a, 15 );
	jw_expire_reservations(); // Buyer A's hold ran out while A was on the payment page.
	$b = jw_checkout_order( $p );
	$rs->reserve_stock_for_order( $b, 15 );
	jw_pay( $b );
	jw_eq( 0, wc_get_product( $p->get_id() )->get_stock_quantity(), 'buyer B bought the piece' );
	jw_pay( $a, 'LATE-A' ); // A's payment provider confirms late.
	jw_pay( $a, 'LATE-A' );
	$a = wc_get_order( $a->get_id() );
	jw_eq( 0, wc_get_product( $p->get_id() )->get_stock_quantity(), 'stock is not driven below zero (no oversell)' );
	jw_assert( (bool) $a->get_meta( '_jw_needs_reconciliation' ), 'late order flagged for refund or resolution' );
	jw_eq( 1, count( array_filter( wc_get_order_notes( array( 'order_id' => $a->get_id() ) ), static fn( $n ) => str_contains( $n->content, 'no longer in stock' ) ) ), 'one reconciliation note, not one per callback' );
} );

jw_test( 'Ready stock: payment arriving after cancellation', function () {
	$p = jw_product( 'demo-signet-ring' );
	wc_update_product_stock( $p, 3, 'set' );
	$o = jw_checkout_order( $p );
	wc_get_order( $o->get_id() )->update_status( 'cancelled', 'Unpaid order cancelled - time limit reached.' );
	jw_pay( $o, 'LATE-1' );
	jw_pay( $o, 'LATE-1' );
	$o = wc_get_order( $o->get_id() );
	jw_eq( 'cancelled', $o->get_status(), 'late payment does not silently revive the cancelled order' );
	jw_eq( 3, wc_get_product( $p->get_id() )->get_stock_quantity(), 'no stock change' );
	jw_assert( (bool) $o->get_meta( '_jw_needs_reconciliation' ), 'order flagged for manual reconciliation' );
	jw_eq( 'LATE-1', $o->get_transaction_id(), 'transaction id kept for the refund' );
	jw_assert( in_array( $o->get_id(), array_map( static fn( $x ) => $x->get_id(), JW_Payments::find_orders( array( 'wc-cancelled' ), '_jw_needs_reconciliation' ) ), true ), 'owner overview lists it' );
} );

jw_test( 'Abandoned payment: hold expires, order cancelled, stock available again', function () {
	$p = jw_product( 'demo-one-of-a-kind-pendant' );
	wc_update_product_stock( $p, 1, 'set' );
	$rs = new ReserveStock();
	$o  = jw_checkout_order( $p );
	$rs->reserve_stock_for_order( $o, 15 );
	jw_eq( 1, $rs->get_reserved_stock( $p ), 'unit is reserved while the buyer pays' );
	jw_expire_reservations();
	jw_age_order( $o, 3600 );
	wc_cancel_unpaid_orders();
	$o = wc_get_order( $o->get_id() );
	jw_eq( 'cancelled', $o->get_status(), 'unpaid order cancelled after the hold time' );
	jw_eq( 0, $rs->get_reserved_stock( $p ), 'nothing reserved any more' );
	jw_eq( 1, wc_get_product( $p->get_id() )->get_stock_quantity(), 'stock was never reduced' );
	jw_assert( ! $o->is_paid(), 'order stays unpaid' );
} );

jw_test( 'Made to order: request keeps options and destination, no cart purchase', function () {
	$band = jw_product( 'demo-band-ring' );
	jw_assert( ! $band->is_purchasable(), 'made-to-order product is not purchasable through the cart' );
	$var_ids = $band->get_children();
	jw_assert( ! wc_get_product( $var_ids[0] )->is_purchasable(), 'its variations are not purchasable either' );
	jw_assert( false === WC()->cart || ! @WC()->cart->add_to_cart( $band->get_id(), 1, $var_ids[0] ), 'add_to_cart is refused' );
	$rid = jw_band_request();
	$m   = static fn( $k ) => get_post_meta( $rid, $k, true );
	jw_eq( 'made_to_order', $m( '_jw_type' ), 'type stored' );
	jw_eq( $band->get_id(), (int) $m( '_jw_product_id' ), 'product stored' );
	$v = wc_get_product( (int) $m( '_jw_variation_id' ) );
	jw_eq( array( 'pa_ring-size' => '52', 'pa_material' => 'demo-silver' ), $v->get_attributes(), 'matching variation found' );
	jw_eq( 'IL', $m( '_jw_country' ), 'destination stored' );
	jw_eq( 'he', $m( '_jw_lang' ), 'customer language stored' );
	jw_eq( 'new', $m( '_jw_state' ), 'state is new' );
	jw_assert( ! get_post_meta( $rid, '_jw_order_id', true ), 'no order or payment exists before the quote' );
	$err = JW_Requests::create( array( 'type' => 'made_to_order', 'name' => 'X', 'email' => 'x@example.test', 'product_id' => $band->get_id(), 'attributes' => array( 'attribute_pa_ring-size' => '52', 'attribute_pa_material' => 'demo-silver' ) ) );
	jw_assert( is_wp_error( $err ) && 'country' === $err->get_error_code(), 'a destination is required so delivery can be quoted' );
} );

jw_test( 'Made to order: 50% deposit on accepted quote, price snapshot', function () {
	$rid   = jw_band_request();
	$order = JW_Quotes::save_quote( $rid, array( 'unit_price' => '600', 'shipping' => '40', 'lead_time' => '4 weeks' ) );
	jw_assert( $order instanceof WC_Order, 'quote order created' );
	jw_eq( 640.0, (float) $order->get_total(), 'full total = item + quoted delivery' );
	jw_eq( 320.0, (float) $order->get_meta( '_jw_deposit_amount' ), 'deposit = 50% of the full total (demo basis)' );
	jw_assert( str_contains( $order->get_meta( '_jw_deposit_rule' ), 'DEMO ASSUMPTION' ), 'demo basis is labeled' );
	jw_assert( ! $order->needs_payment(), 'the quote order itself cannot be paid in full by link' );
	$variation = wc_get_product( (int) get_post_meta( $rid, '_jw_variation_id', true ) );
	$variation->set_regular_price( '999' );
	$variation->save();
	jw_eq( 640.0, (float) wc_get_order( $order->get_id() )->get_total(), 'later product price edit does not change the quote' );
	$variation->set_regular_price( '600' );
	$variation->save();

	$before = jw_mail_count();
	jw_assert( true === JW_Quotes::send( $order ), 'quote sent' );
	jw_eq( $before + 1, jw_mail_count(), 'one quote email captured by the sandbox' );
	$mail = jw_last_mail();
	jw_assert( str_contains( $mail['message'] ?? '', '/he/' ), 'quote email link uses the customer language (he)' );
	$token = jw_token_for( $order );
	$dep   = JW_Quotes::accept( wc_get_order( $order->get_id() ), $token );
	jw_assert( $dep instanceof WC_Order, 'accepting creates the deposit request' );
	jw_eq( 320.0, (float) $dep->get_total(), 'deposit request amount = accepted quote deposit' );
	jw_assert( $dep->needs_payment(), 'deposit request is payable' );
	$again = JW_Quotes::accept( wc_get_order( $order->get_id() ), $token );
	jw_eq( $dep->get_id(), $again->get_id(), 'accepting twice reuses the same deposit request' );
	jw_eq( 1, count( JW_Payments::requests( wc_get_order( $order->get_id() ) ) ), 'still one payment request' );
	jw_pay( $dep );
	$p = wc_get_order( $order->get_id() );
	$a = JW_Payments::amounts( $p );
	jw_eq( 320.0, $a['paid'], 'paid = deposit' );
	jw_eq( 320.0, $a['balance'], 'remaining balance = 320' );
	jw_eq( 'deposit_paid', JW_Payments::payment_state( $p ), 'payment state: deposit paid' );
	jw_eq( JW_Payments::STATUS, $p->get_status(), 'deposit does not mark the order paid in full' );
	jw_eq( 'completed', wc_get_order( $dep->get_id() )->get_status(), 'the deposit record itself is complete' );
} );

jw_test( 'Made to order: merchandise basis is configurable', function () {
	JW_Settings::update( array( 'mto_deposit_basis' => 'merchandise' ) );
	$order = JW_Quotes::save_quote( jw_band_request(), array( 'unit_price' => '600', 'shipping' => '40' ) );
	jw_eq( 300.0, (float) $order->get_meta( '_jw_deposit_amount' ), 'deposit = 50% of items only' );
	JW_Settings::update( array( 'mto_deposit_basis' => 'total' ) );
} );

jw_test( 'Bespoke: explicit deposit, never an assumed 50%', function () {
	$rid = jw_request( 'bespoke', array( 'brief' => 'A custom ring' ) );
	$err = JW_Quotes::save_quote( $rid, array( 'description' => 'Custom ring', 'price' => '2000', 'shipping' => '50' ) );
	jw_assert( is_wp_error( $err ) && 'jw_deposit' === $err->get_error_code(), 'a bespoke quote without a deposit amount is refused' );
	jw_eq( 0, count( JW_Payments::find_orders( array_keys( wc_get_order_statuses() ), '_jw_request_id', (string) $rid ) ), 'the refused quote left no order behind' );
	$order = JW_Quotes::save_quote( $rid, array( 'description' => 'Custom ring', 'price' => '2000', 'shipping' => '50', 'deposit' => '500' ) );
	jw_eq( 2050.0, (float) $order->get_total(), 'bespoke total' );
	jw_eq( 500.0, (float) $order->get_meta( '_jw_deposit_amount' ), 'deposit is the explicit amount, not 1025' );
	JW_Quotes::send( $order );
	$dep = JW_Quotes::accept( wc_get_order( $order->get_id() ), jw_token_for( $order ) );
	jw_eq( 500.0, (float) $dep->get_total(), 'deposit request = 500' );
} );

jw_test( 'Mark ready: one balance request and one localized email; repeat is safe', function () {
	[ $p ] = jw_accepted_quote();
	$before = jw_mail_count();
	$res    = JW_Payments::mark_ready( $p );
	jw_assert( is_array( $res ) && $res['request'] instanceof WC_Order, 'balance request created' );
	jw_eq( 320.0, (float) $res['request']->get_total(), 'balance = total - paid' );
	jw_eq( 'sent', $res['email'], 'email handed to mail system' );
	jw_eq( $before + 1, jw_mail_count(), 'exactly one email' );
	$mail = jw_last_mail();
	jw_assert( str_contains( $mail['message'] ?? '', 'order-pay' ) && str_contains( $mail['message'] ?? '', '/he/' ), 'email contains the Hebrew payment link' );
	$p2   = wc_get_order( $p->get_id() );
	jw_eq( 'ready', $p2->get_meta( '_jw_production' ), 'production = ready' );
	jw_eq( 'balance_due', JW_Payments::payment_state( $p2 ), 'payment state = balance due (not paid)' );
	jw_eq( JW_Payments::STATUS, $p2->get_status(), 'not fulfilled' );
	$res2 = JW_Payments::mark_ready( $p2 );
	jw_eq( $res['request']->get_id(), $res2['request']->get_id(), 'repeat reuses the same balance request' );
	jw_eq( 'skipped', $res2['email'], 'repeat does not send a second email' );
	jw_eq( $before + 1, jw_mail_count(), 'still exactly one email' );
	jw_eq( 1, count( JW_Payments::requests( $p2, array( 'balance' ) ) ), 'one balance request in total' );
} );

jw_test( 'Mark ready: mail failure is visible, retry reuses the request', function () {
	[ $p ] = jw_accepted_quote();
	update_option( 'jw_dev_mail_fail', 1 );
	$res = JW_Payments::mark_ready( $p );
	update_option( 'jw_dev_mail_fail', 0 );
	jw_eq( 'failed', $res['email'], 'failure reported' );
	$req = $res['request'];
	jw_eq( 'failed', wc_get_order( $req->get_id() )->get_meta( '_jw_email_status' ), 'failure recorded on the request' );
	$notes = wc_get_order_notes( array( 'order_id' => $p->get_id() ) );
	jw_assert( (bool) array_filter( $notes, static fn( $n ) => str_contains( $n->content, 'FAILED' ) ), 'failure written to the order timeline' );
	jw_assert( JW_Payments::send_balance_email( wc_get_order( $req->get_id() ) ), 'retry succeeds' );
	jw_eq( $req->get_id(), JW_Payments::open_request( wc_get_order( $p->get_id() ), 'balance' )->get_id(), 'retry used the same payment request' );
	jw_eq( 2, count( (array) wc_get_order( $req->get_id() )->get_meta( '_jw_email_attempts' ) ), 'both attempts recorded' );
} );

jw_test( 'Fulfillment gate and balance payment', function () {
	[ $p ] = jw_accepted_quote();
	$p->set_status( 'completed' );
	$p->save();
	jw_eq( JW_Payments::STATUS, wc_get_order( $p->get_id() )->get_status(), 'cannot complete (ship) while the balance is unpaid' );
	$p = wc_get_order( $p->get_id() );
	$p->set_status( 'processing' );
	$p->save();
	jw_eq( JW_Payments::STATUS, wc_get_order( $p->get_id() )->get_status(), 'cannot mark processing while the balance is unpaid' );
	$res = JW_Payments::mark_ready( wc_get_order( $p->get_id() ) );
	jw_pay( $res['request'] );
	jw_pay( $res['request'] ); // Duplicate callback.
	$p = wc_get_order( $p->get_id() );
	jw_eq( 0.0, JW_Payments::amounts( $p )['balance'], 'outstanding = 0' );
	jw_eq( 640.0, JW_Payments::amounts( $p )['paid'], 'paid = full total, counted once' );
	jw_eq( 'paid_in_full', JW_Payments::payment_state( $p ), 'paid in full' );
	jw_eq( 'processing', $p->get_status(), 'fulfillment enabled (processing), not shipped automatically' );
	$p->set_status( 'completed' );
	$p->save();
	jw_eq( 'completed', wc_get_order( $p->get_id() )->get_status(), 'owner can now complete it' );
} );

jw_test( 'Quote revision and cancellation invalidate old links', function () {
	$rid   = jw_band_request();
	$order = JW_Quotes::save_quote( $rid, array( 'unit_price' => '600', 'shipping' => '40' ) );
	JW_Quotes::send( $order );
	$old_token = jw_token_for( $order );
	$dep       = JW_Quotes::accept( wc_get_order( $order->get_id() ), $old_token );
	jw_assert( $dep instanceof WC_Order, 'v1 deposit request created' );
	// Owner revises before payment. (Accepted quotes are revised by re-opening: simulate customer asking for a change.)
	$o = wc_get_order( $order->get_id() );
	$o->update_meta_data( '_jw_quote_state', 'sent' );
	$o->save();
	$o2 = JW_Quotes::save_quote( $rid, array( 'unit_price' => '650', 'shipping' => '40' ), wc_get_order( $order->get_id() ) );
	jw_eq( 2, (int) $o2->get_meta( '_jw_quote_version' ), 'version 2' );
	jw_assert( ! wc_get_order( $dep->get_id() )->needs_payment(), 'v1 deposit link can no longer be paid' );
	jw_eq( 'cancelled', wc_get_order( $dep->get_id() )->get_status(), 'v1 deposit request cancelled' );
	jw_assert( ! JW_Quotes::check_token( wc_get_order( $order->get_id() ), $old_token ), 'v1 quote link no longer valid' );
	JW_Quotes::send( wc_get_order( $order->get_id() ) );
	$new = jw_token_for( $order );
	jw_assert( JW_Quotes::check_token( wc_get_order( $order->get_id() ), $new ), 'v2 link valid' );
	jw_assert( is_wp_error( JW_Quotes::accept( wc_get_order( $order->get_id() ), $old_token ) ), 'old link cannot accept' );
	jw_assert( true === JW_Quotes::cancel( wc_get_order( $order->get_id() ) ), 'unpaid quote can be cancelled' );
	jw_assert( is_wp_error( JW_Quotes::accept( wc_get_order( $order->get_id() ), $new ) ), 'cancelled quote cannot be accepted' );
	[ $paid ] = jw_accepted_quote();
	jw_assert( is_wp_error( JW_Quotes::save_quote( (int) $paid->get_meta( '_jw_request_id' ), array( 'unit_price' => '1', 'shipping' => '0' ), $paid ) ), 'a quote with a payment cannot be silently revised' );
	jw_assert( is_wp_error( JW_Quotes::cancel( $paid ) ), 'a quote with a payment cannot be cancelled without a refund' );
} );

jw_test( 'Quote expiry', function () {
	$order = JW_Quotes::save_quote( jw_band_request(), array( 'unit_price' => '600', 'shipping' => '40' ) );
	JW_Quotes::send( $order );
	$token = jw_token_for( $order );
	$o     = wc_get_order( $order->get_id() );
	$o->update_meta_data( '_jw_valid_until', time() - 60 );
	$o->save();
	jw_eq( 'expired', JW_Quotes::state( wc_get_order( $order->get_id() ) ), 'state reads expired' );
	jw_assert( is_wp_error( JW_Quotes::accept( wc_get_order( $order->get_id() ), $token ) ), 'expired quote cannot be accepted' );
} );

jw_test( 'Refunds keep paid and outstanding amounts consistent', function () {
	[ $p, $dep ] = jw_accepted_quote();
	$refund = wc_create_refund( array( 'order_id' => $dep->get_id(), 'amount' => 100, 'reason' => 'test partial refund' ) );
	jw_assert( ! is_wp_error( $refund ), 'partial refund recorded on the deposit' );
	$p = wc_get_order( $p->get_id() );
	jw_eq( 220.0, JW_Payments::amounts( $p )['paid'], 'paid = 320 - 100' );
	jw_eq( 420.0, JW_Payments::amounts( $p )['balance'], 'outstanding = 640 - 220' );
	jw_eq( 'partially_refunded', JW_Payments::payment_state( $p ), 'state shows partial refund' );
	jw_eq( '420.00', $p->get_meta( '_jw_balance_due' ), 'stored outstanding amount recalculated' );
} );

jw_test( 'Concurrent actions: lock admits one holder', function () {
	jw_assert( JW_Lock::acquire( 'test_lock' ), 'first acquire' );
	jw_assert( ! JW_Lock::acquire( 'test_lock' ), 'second acquire refused' );
	JW_Lock::release( 'test_lock' );
	jw_assert( JW_Lock::acquire( 'test_lock' ), 'acquire after release' );
	JW_Lock::release( 'test_lock' );
} );

jw_test( 'Languages: one product, one stock, translated text', function () {
	$p = jw_product( 'demo-signet-ring' );
	$he = JW_I18n::with_lang( 'he', static fn() => array( wc_get_product( $p->get_id() )->get_name(), home_url( '/shop/' ), get_term( get_term_by( 'slug', 'rings', 'product_cat' )->term_id, 'product_cat' )->name, is_rtl() ) );
	jw_eq( 'טבעת חותם (הדגמה)', $he[0], 'Hebrew product name' );
	jw_assert( str_contains( $he[1], '/he/shop/' ), 'Hebrew URLs are prefixed' );
	jw_eq( 'טבעות', $he[2], 'Hebrew category name' );
	jw_assert( $he[3], 'Hebrew is right-to-left' );
	$fr = JW_I18n::with_lang( 'fr', static fn() => array( wc_get_product( $p->get_id() )->get_stock_quantity(), wc_get_product( $p->get_id() )->get_price() ) );
	jw_eq( array( wc_get_product( $p->get_id() )->get_stock_quantity(), wc_get_product( $p->get_id() )->get_price() ), $fr, 'same stock and price in every language' );
	$var = wc_get_product( jw_product( 'demo-band-ring' )->get_children()[0] );
	$vname = JW_I18n::with_lang( 'he', static fn() => wc_get_product( $var->get_id() )->get_name() );
	jw_assert( str_starts_with( $vname, 'טבעת פס בהזמנה (הדגמה)' ) && str_contains( $vname, 'מתכת הדגמה' ), "Hebrew variation name, no recursion ({$vname})" );
	jw_eq( 'en', JW_I18n::current(), 'language restored after the switch' );
} );

jw_test( 'Shipping: unconfigured destinations get no free shipping', function () {
	$rates = static function ( string $country ) {
		$pkg = array( 'contents' => array(), 'contents_cost' => 100, 'applied_coupons' => array(), 'destination' => array( 'country' => $country, 'state' => '', 'postcode' => '', 'city' => '', 'address' => '', 'address_2' => '' ) );
		WC()->shipping()->load_shipping_methods( $pkg );
		$r = WC()->shipping()->calculate_shipping_for_package( $pkg );
		return array_map( static fn( $rate ) => array( $rate->get_method_id(), (float) $rate->get_cost() ), $r['rates'] );
	};
	$il = array_values( $rates( 'IL' ) );
	jw_eq( array( array( 'flat_rate', 30.0 ) ), $il, 'Israel: the configured demo rate only' );
	jw_eq( array(), $rates( 'FR' ), 'France (unconfigured): no shipping method, so no checkout' );
} );

jw_test( 'Private uploads are outside the web root', function () {
	$dir = realpath( JW_Uploads::dir() ) ?: JW_Uploads::dir();
	jw_assert( ! str_starts_with( $dir, realpath( ABSPATH ) ), 'private dir is not under the WordPress web root' );
} );

// ------------------------------------------------------------------
$r = $GLOBALS['jw_results'];
WP_CLI::log( sprintf( "\n%d passed, %d failed", $r['pass'], $r['fail'] ) );
if ( $r['fail'] ) {
	WP_CLI::error( "Failures:\n- " . implode( "\n- ", $r['failures'] ) );
}
WP_CLI::success( 'All integration tests passed.' );
