<?php
/**
 * Ready-stock rules on top of WooCommerce's own stock handling.
 *
 * WooCommerce already: reserves stock for a checkout with a conditional insert
 * (wc_reserved_stock), so two buyers cannot both hold the last unit; releases
 * the reservation when it expires ("Hold stock" minutes); cancels unpaid
 * checkout orders; reduces stock once per order; and ignores a repeated
 * payment_complete() on a paid order.
 *
 * Gap closed here: a payment can arrive after the reservation expired and the
 * piece was sold to someone else, or after the order was cancelled. Then:
 * - stock is not reduced below what is really there (no overselling), and
 * - the order is flagged _jw_needs_reconciliation for the owner (refund or resolve).
 * A cancelled order also stays cancelled instead of being marked paid.
 */
defined( 'ABSPATH' ) || exit;

class JW_Stock {
	public static function init(): void {
		add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', array( __CLASS__, 'no_auto_complete_after_cancel' ), 10, 2 );
		add_action( 'woocommerce_payment_complete_order_status_cancelled', array( __CLASS__, 'late_payment_on_cancelled' ), 10, 2 );
		add_filter( 'woocommerce_can_reduce_order_stock', array( __CLASS__, 'can_reduce' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'record_lang' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( __CLASS__, 'record_lang' ) );
	}

	public static function no_auto_complete_after_cancel( $statuses, $order ) {
		return array_values( array_diff( $statuses, array( 'cancelled' ) ) );
	}

	public static function late_payment_on_cancelled( $order_id, $transaction_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( $transaction_id && ! $order->get_transaction_id() ) {
			$order->set_transaction_id( $transaction_id );
			$order->save();
		}
		self::flag( $order, 'Payment received AFTER this order was cancelled. Stock was not reduced again and the order was not marked paid. Check availability, then refund the payment or restore and fulfil the order.' );
	}

	/** Never reduce stock below zero for a payment that arrived too late. */
	public static function can_reduce( $can, $order ) {
		if ( ! $can || ! $order instanceof WC_Order ) {
			return $can;
		}
		foreach ( $order->get_items() as $item ) {
			if ( ! $item->is_type( 'line_item' ) || $item->get_meta( '_reduced_stock', true ) ) {
				continue;
			}
			$product = $item->get_product();
			if ( ! $product || ! $product->managing_stock() || $product->backorders_allowed() ) {
				continue;
			}
			// Fresh read: the cached object can be older than a concurrent sale.
			$stock = wc_get_product( $product->get_id() )->get_stock_quantity();
			if ( null !== $stock && $stock < $item->get_quantity() ) {
				self::flag( $order, sprintf( 'Paid, but "%s" is no longer in stock (another order took it after this checkout\'s hold expired). Stock was not reduced. Refund the payment or arrange an alternative with the customer.', $product->get_name() ) );
				return false;
			}
		}
		return $can;
	}

	private static function flag( WC_Order $order, string $note ): void {
		if ( $order->get_meta( '_jw_needs_reconciliation' ) ) {
			return; // Already recorded: a repeated callback changes nothing.
		}
		$order->update_meta_data( '_jw_needs_reconciliation', time() );
		$order->save_meta_data();
		$order->add_order_note( $note );
	}

	/** Record the customer's language on every checkout order (for later emails). */
	public static function record_lang( $order ): void {
		$order->update_meta_data( '_jw_lang', JW_I18n::current() );
	}
}
