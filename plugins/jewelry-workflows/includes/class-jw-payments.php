<?php
/**
 * Deposits, balances, production readiness and the fulfillment gate.
 *
 * Money is never tracked in a second ledger. Each payment request (deposit or
 * balance) is a normal WooCommerce order with parent_id = quote order and one
 * fee line for the amount. The customer pays it on the native "order-pay"
 * page with the store's gateway. Paid, refunded and outstanding amounts are
 * always recomputed from these orders (see paid_total()).
 *
 * Four separate dimensions on the quote order:
 * - quote:       _jw_quote_state (JW_Quotes)
 * - production:  _jw_production = not_started | in_production | ready
 * - payment:     computed: unpaid | deposit_paid | balance_due | paid_in_full | refunded
 * - fulfillment: WooCommerce status: jw-quote (open) -> processing (paid, to ship) -> completed (shipped/collected) | cancelled
 */
defined( 'ABSPATH' ) || exit;

class JW_Payments {
	const STATUS = 'jw-quote';

	public static function init(): void {
		add_filter( 'wc_order_statuses', array( __CLASS__, 'add_status_label' ) );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 10, 4 );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'on_refund' ), 10, 1 );
		add_action( 'woocommerce_before_order_object_save', array( __CLASS__, 'fulfillment_gate' ), 10, 1 );
		foreach ( array( 'customer_processing_order', 'customer_completed_order', 'customer_on_hold_order', 'new_order', 'customer_invoice' ) as $email_id ) {
			add_filter( 'woocommerce_email_enabled_' . $email_id, array( __CLASS__, 'filter_wc_emails' ), 10, 3 );
		}
	}

	public static function register_statuses(): void {
		register_post_status(
			'wc-' . self::STATUS,
			array(
				'label'                     => _x( 'Commission open', 'Order status', 'jewelry-workflows' ),
				'public'                    => false,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of orders */
				'label_count'               => _n_noop( 'Commission open <span class="count">(%s)</span>', 'Commissions open <span class="count">(%s)</span>', 'jewelry-workflows' ),
			)
		);
	}

	public static function add_status_label( array $statuses ): array {
		$out = array();
		foreach ( $statuses as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'wc-pending' === $k ) {
				$out[ 'wc-' . self::STATUS ] = _x( 'Commission open', 'Order status', 'jewelry-workflows' );
			}
		}
		return $out;
	}

	public static function kind( $order ): string {
		return $order instanceof WC_Order ? (string) $order->get_meta( '_jw_kind' ) : '';
	}

	public static function is_payment_request( $order ): bool {
		return in_array( self::kind( $order ), array( 'deposit', 'balance' ), true );
	}

	/**
	 * Orders in $statuses whose meta $key is set (or equals $value).
	 * shortcut: filters in PHP because meta_query is not supported by the legacy
	 * (posts) order store; fine for a small shop. Use meta_query once HPOS is the only store.
	 *
	 * @return WC_Order[]
	 */
	public static function find_orders( array $statuses, string $key, ?string $value = null, int $limit = 300 ): array {
		$orders = wc_get_orders( array( 'status' => $statuses, 'limit' => $limit, 'orderby' => 'date', 'order' => 'DESC', 'type' => 'shop_order' ) );
		return array_values(
			array_filter(
				$orders,
				static fn( $o ) => null === $value ? '' !== (string) $o->get_meta( $key ) : $value === (string) $o->get_meta( $key )
			)
		);
	}

	/** @return WC_Order[] */
	public static function requests( WC_Order $parent, array $kinds = array( 'deposit', 'balance' ) ): array {
		$children = wc_get_orders(
			array(
				'parent'  => $parent->get_id(),
				'type'    => 'shop_order',
				'limit'   => -1,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'status'  => array_keys( wc_get_order_statuses() ),
			)
		);
		return array_values( array_filter( $children, static fn( $c ) => in_array( self::kind( $c ), $kinds, true ) ) );
	}

	/** Sum of verified payments minus refunds, from the payment-request orders. */
	public static function paid_total( WC_Order $parent ): float {
		$sum = 0.0;
		foreach ( self::requests( $parent ) as $child ) {
			if ( $child->is_paid() || $child->has_status( 'refunded' ) ) {
				$sum += (float) $child->get_total() - (float) $child->get_total_refunded();
			}
		}
		return round( $sum, wc_get_price_decimals() );
	}

	public static function amounts( WC_Order $parent ): array {
		$total   = round( (float) $parent->get_total() - (float) $parent->get_total_refunded(), wc_get_price_decimals() );
		$paid    = self::paid_total( $parent );
		$deposit = (float) $parent->get_meta( '_jw_deposit_amount' );
		return array(
			'total'   => $total,
			'paid'    => $paid,
			'deposit' => $deposit,
			'balance' => max( 0.0, round( $total - $paid, wc_get_price_decimals() ) ),
		);
	}

	public static function payment_state( WC_Order $parent ): string {
		$a        = self::amounts( $parent );
		$refunded = false;
		foreach ( self::requests( $parent ) as $child ) {
			if ( (float) $child->get_total_refunded() > 0 ) {
				$refunded = true;
			}
		}
		if ( $a['paid'] <= 0 ) {
			return $refunded ? 'refunded' : 'unpaid';
		}
		if ( $a['balance'] <= 0 ) {
			return 'paid_in_full';
		}
		if ( $refunded ) {
			return 'partially_refunded';
		}
		return 'ready' === $parent->get_meta( '_jw_production' ) ? 'balance_due' : 'deposit_paid';
	}

	public static function payment_state_label( string $s ): string {
		$labels = array(
			'unpaid'             => __( 'Unpaid', 'jewelry-workflows' ),
			'deposit_paid'       => __( 'Deposit paid', 'jewelry-workflows' ),
			'balance_due'        => __( 'Balance due', 'jewelry-workflows' ),
			'paid_in_full'       => __( 'Paid in full', 'jewelry-workflows' ),
			'refunded'           => __( 'Refunded', 'jewelry-workflows' ),
			'partially_refunded' => __( 'Partially refunded', 'jewelry-workflows' ),
		);
		return $labels[ $s ] ?? $s;
	}

	public static function production_label( string $s ): string {
		$labels = array(
			'not_started'   => __( 'Not started', 'jewelry-workflows' ),
			'in_production' => __( 'In production', 'jewelry-workflows' ),
			'ready'         => __( 'Ready', 'jewelry-workflows' ),
		);
		return $labels[ $s ] ?? $s;
	}

	/** The unpaid, payable request of this kind for the current quote version, if any. */
	public static function open_request( WC_Order $parent, string $kind ): ?WC_Order {
		$version = (int) $parent->get_meta( '_jw_quote_version' );
		foreach ( array_reverse( self::requests( $parent, array( $kind ) ) ) as $child ) {
			if ( $child->needs_payment() && $version === (int) $child->get_meta( '_jw_quote_version' ) ) {
				return $child;
			}
		}
		return null;
	}

	/**
	 * Create or reuse one payment request. Never creates a second payable
	 * request of the same kind: an open request with a different amount is
	 * cancelled first, so its link stops working.
	 *
	 * @return WC_Order|WP_Error
	 */
	public static function request( WC_Order $parent, string $kind, float $amount ) {
		$amount = round( $amount, wc_get_price_decimals() );
		if ( $amount <= 0 ) {
			return new WP_Error( 'jw_amount', 'Nothing to pay.' );
		}
		$open = self::open_request( $parent, $kind );
		if ( $open && abs( (float) $open->get_total() - $amount ) < 0.005 ) {
			return $open;
		}
		if ( $open ) {
			$open->update_status( 'cancelled', 'Replaced by a new payment request with a different amount.' );
		}
		$lang  = (string) $parent->get_meta( '_jw_lang' );
		$child = wc_create_order( array( 'parent' => $parent->get_id(), 'status' => 'pending', 'created_via' => 'jw_payment_request' ) );
		if ( is_wp_error( $child ) ) {
			return $child;
		}
		$label = JW_I18n::with_lang(
			$lang,
			static fn() => sprintf(
				/* translators: %d: order number */
				'deposit' === $kind ? __( 'Deposit for order #%d', 'jewelry-workflows' ) : __( 'Balance for order #%d', 'jewelry-workflows' ),
				$parent->get_id()
			)
		);
		$fee = new WC_Order_Item_Fee();
		$fee->set_name( $label );
		$fee->set_tax_status( 'none' );
		$fee->set_total( $amount );
		$child->add_item( $fee );
		$child->set_currency( $parent->get_currency() );
		$child->set_address( $parent->get_address( 'billing' ), 'billing' );
		$child->set_customer_id( 0 ); // Guest payment: link access via the order key.
		$child->update_meta_data( '_jw_kind', $kind );
		$child->update_meta_data( '_jw_parent_id', $parent->get_id() );
		$child->update_meta_data( '_jw_quote_version', (int) $parent->get_meta( '_jw_quote_version' ) );
		$child->update_meta_data( '_jw_lang', $lang );
		$child->calculate_totals( false );
		$child->save();
		$parent->add_order_note( sprintf( '%s request #%d created for %s.', ucfirst( $kind ), $child->get_id(), wc_price( $amount, array( 'currency' => $parent->get_currency() ) ) ) );
		return $child;
	}

	public static function cancel_open_requests( WC_Order $parent, string $why ): void {
		foreach ( self::requests( $parent ) as $child ) {
			if ( $child->has_status( array( 'pending', 'failed', 'on-hold' ) ) && ! $child->is_paid() ) {
				$child->update_status( 'cancelled', $why );
			}
		}
	}

	public static function pay_url( WC_Order $child ): string {
		return JW_I18n::with_lang( (string) $child->get_meta( '_jw_lang' ), static fn() => $child->get_checkout_payment_url() );
	}

	/** Recompute payment state from the payment orders. Safe to run any number of times. */
	public static function reconcile( WC_Order $parent ): void {
		$parent = wc_get_order( $parent->get_id() );
		$a      = self::amounts( $parent );
		$parent->update_meta_data( '_jw_paid_total', wc_format_decimal( $a['paid'], wc_get_price_decimals() ) );
		$parent->update_meta_data( '_jw_balance_due', wc_format_decimal( $a['balance'], wc_get_price_decimals() ) );
		$parent->update_meta_data( '_jw_payment_state', self::payment_state( $parent ) );
		if ( $a['balance'] <= 0 && $a['paid'] > 0 && $parent->has_status( self::STATUS ) ) {
			$parent->set_status( 'processing', 'Paid in full. Ready for shipping or pickup.' );
		}
		$parent->save();
	}

	public static function on_status_changed( $order_id, $from, $to, $order ): void {
		if ( ! self::is_payment_request( $order ) ) {
			return;
		}
		$parent = wc_get_order( (int) $order->get_parent_id() );
		if ( ! $parent ) {
			return;
		}
		$was_paid = in_array( $from, wc_get_is_paid_statuses(), true );
		$is_paid  = in_array( $to, wc_get_is_paid_statuses(), true );
		self::reconcile( $parent );
		if ( $is_paid && ! $was_paid && ! $order->get_meta( '_jw_receipt_sent' ) ) {
			$parent = wc_get_order( $parent->get_id() );
			$parent->add_order_note( sprintf( '%s payment #%d received: %s.', ucfirst( self::kind( $order ) ), $order->get_id(), wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ) );
			$order->update_meta_data( '_jw_receipt_sent', time() );
			$order->save_meta_data();
			JW_Emails::payment_received( $order, $parent );
		}
	}

	public static function on_refund( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( self::is_payment_request( $order ) && ( $parent = wc_get_order( (int) $order->get_parent_id() ) ) ) {
			self::reconcile( $parent );
			$parent->add_order_note( sprintf( 'Refund recorded on payment #%d. Paid and outstanding amounts recalculated.', $order_id ) );
		}
	}

	/**
	 * A quote order cannot be marked "processing" (to ship) or "completed"
	 * (shipped or collected) while a balance is unpaid.
	 */
	public static function fulfillment_gate( $order ): void {
		if ( ! JW_Quotes::is_quote( $order ) ) {
			return;
		}
		$changes = $order->get_changes();
		if ( empty( $changes['status'] ) || ! in_array( $changes['status'], array( 'processing', 'completed' ), true ) ) {
			return;
		}
		$a = self::amounts( $order );
		if ( $a['balance'] > 0 ) {
			$old = $order->get_data()['status'] ?: self::STATUS;
			$order->set_status( $old );
			$order->update_meta_data( '_jw_gate_blocked', time() );
			$order->add_order_note( sprintf( 'Fulfillment blocked: %s is still unpaid. Status kept as "%s".', wp_strip_all_tags( wc_price( $a['balance'], array( 'currency' => $order->get_currency() ) ) ), wc_get_order_status_name( $old ) ) );
			if ( is_admin() && function_exists( 'set_transient' ) ) {
				set_transient( 'jw_admin_notice_' . get_current_user_id(), __( 'Not changed: this order still has an unpaid balance. Mark it ready to send the balance request.', 'jewelry-workflows' ), 60 );
			}
		}
	}

	public static function set_production( WC_Order $parent, string $state ): void {
		if ( ! in_array( $state, array( 'not_started', 'in_production', 'ready' ), true ) ) {
			return;
		}
		$parent->update_meta_data( '_jw_production', $state );
		$parent->save();
		$parent->add_order_note( 'Production: ' . self::production_label( $state ) . '.' );
	}

	/**
	 * Mark the piece (the whole order is one fulfillment group) ready and
	 * request the balance. Repeating this reuses the same request and does not
	 * re-send the email unless $force_email is true.
	 *
	 * @return array|WP_Error ['request' => ?WC_Order, 'email' => 'sent'|'failed'|'skipped'|'none']
	 */
	public static function mark_ready( WC_Order $parent, bool $force_email = false ) {
		return JW_Lock::with(
			'ready_' . $parent->get_id(),
			static function () use ( $parent, $force_email ) {
				$parent = wc_get_order( $parent->get_id() );
				if ( ! JW_Quotes::is_quote( $parent ) || 'accepted' !== JW_Quotes::state( $parent ) ) {
					return new WP_Error( 'jw_state', __( 'Only an accepted quote can be marked ready.', 'jewelry-workflows' ) );
				}
				if ( $parent->has_status( array( 'cancelled', 'refunded', 'completed' ) ) ) {
					return new WP_Error( 'jw_state', __( 'This order is closed.', 'jewelry-workflows' ) );
				}
				$a = self::amounts( $parent );
				if ( $a['deposit'] > 0 && $a['paid'] + 0.005 < $a['deposit'] ) {
					return new WP_Error( 'jw_deposit', __( 'The deposit is not paid yet.', 'jewelry-workflows' ) );
				}
				if ( 'ready' !== $parent->get_meta( '_jw_production' ) ) {
					self::set_production( $parent, 'ready' );
					$parent = wc_get_order( $parent->get_id() );
				}
				if ( $a['balance'] <= 0 ) {
					self::reconcile( $parent );
					return array( 'request' => null, 'email' => 'none' );
				}
				$req = self::request( $parent, 'balance', $a['balance'] );
				if ( is_wp_error( $req ) ) {
					return $req;
				}
				self::reconcile( $parent );
				$already = 'sent' === $req->get_meta( '_jw_email_status' );
				if ( $already && ! $force_email ) {
					return array( 'request' => $req, 'email' => 'skipped' );
				}
				return array( 'request' => $req, 'email' => self::send_balance_email( $req ) ? 'sent' : 'failed' );
			}
		);
	}

	/** Send the balance email and record the attempt. A recorded send is not proof of delivery. */
	public static function send_balance_email( WC_Order $req ): bool {
		$parent = wc_get_order( (int) $req->get_parent_id() );
		$ok     = JW_Emails::balance_request( $req, $parent );
		$log    = $req->get_meta( '_jw_email_attempts' );
		$log    = is_array( $log ) ? $log : array();
		$log[]  = array( 'time' => time(), 'ok' => $ok );
		$req->update_meta_data( '_jw_email_attempts', $log );
		$req->update_meta_data( '_jw_email_status', $ok ? 'sent' : 'failed' );
		$req->save();
		$parent->add_order_note(
			$ok ? sprintf( 'Balance request email handed to the mail system for %s (request #%d).', $parent->get_billing_email(), $req->get_id() )
				: sprintf( 'Balance request email FAILED (request #%d). Use "Retry email" or copy the payment link.', $req->get_id() )
		);
		return $ok;
	}

	/** Keep WooCommerce's generic emails away from payment-request orders; ours explain them better. */
	public static function filter_wc_emails( $enabled, $order, $email = null ) {
		if ( self::is_payment_request( $order ) ) {
			return false;
		}
		if ( JW_Quotes::is_quote( $order ) && $email && in_array( $email->id, array( 'customer_processing_order', 'customer_on_hold_order', 'new_order', 'customer_invoice' ), true ) ) {
			return false;
		}
		return $enabled;
	}
}
