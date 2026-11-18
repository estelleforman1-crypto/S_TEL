<?php
/**
 * Quotes for made-to-order and bespoke work.
 *
 * A quote is a WooCommerce order in status "jw-quote" that holds the agreed
 * items, the quoted delivery line and the full total. The customer cannot pay
 * it directly. The customer pays separate payment-request orders (deposit,
 * balance) that are children of the quote order. See JW_Payments.
 *
 * Quote states (meta _jw_quote_state): draft, sent, accepted, declined, expired, cancelled.
 */
defined( 'ABSPATH' ) || exit;

class JW_Quotes {
	const STATES = array( 'draft', 'sent', 'accepted', 'declined', 'expired', 'cancelled' );

	public static function init(): void {
		add_filter( 'query_vars', static fn( $v ) => array_merge( $v, array( 'jw-quote', 'k' ) ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_customer_action' ), 5 );
		add_filter( 'template_include', array( __CLASS__, 'template' ), 50 );
	}

	public static function state_label( string $state ): string {
		$labels = array(
			'draft'     => __( 'Draft (not sent)', 'jewelry-workflows' ),
			'sent'      => __( 'Quote sent', 'jewelry-workflows' ),
			'accepted'  => __( 'Accepted', 'jewelry-workflows' ),
			'declined'  => __( 'Declined', 'jewelry-workflows' ),
			'expired'   => __( 'Expired', 'jewelry-workflows' ),
			'cancelled' => __( 'Cancelled', 'jewelry-workflows' ),
		);
		return $labels[ $state ] ?? $state;
	}

	public static function is_quote( $order ): bool {
		return $order instanceof WC_Order && 'quote' === $order->get_meta( '_jw_kind' );
	}

	public static function state( WC_Order $order ): string {
		$state = (string) $order->get_meta( '_jw_quote_state' );
		if ( 'sent' === $state && self::is_past_expiry( $order ) ) {
			return 'expired';
		}
		return $state ?: 'draft';
	}

	private static function is_past_expiry( WC_Order $order ): bool {
		$until = (int) $order->get_meta( '_jw_valid_until' );
		return $until && time() > $until;
	}

	/**
	 * Deposit for a quote.
	 * Made to order: the shared percentage rule, on the configured basis.
	 * Bespoke: the explicit amount the owner entered. Never an assumed 50%.
	 */
	public static function compute_deposit( string $sale_type, float $merchandise, float $total, $explicit ): array {
		$dp = wc_get_price_decimals();
		if ( 'made_to_order' === $sale_type ) {
			$pct   = (float) JW_Settings::get( 'mto_deposit_percent' );
			$basis = JW_Settings::get( 'mto_deposit_basis' );
			$base  = 'merchandise' === $basis ? $merchandise : $total;
			$rule  = sprintf( 'made_to_order: %s%% of %s', $pct, 'merchandise' === $basis ? 'merchandise subtotal' : 'full quoted total incl. delivery' );
			if ( ! JW_Settings::get( 'mto_deposit_confirmed' ) ) {
				$rule .= ' [DEMO ASSUMPTION: basis not confirmed by owner]';
			}
			return array( round( $base * $pct / 100, $dp ), $rule );
		}
		if ( '' === $explicit || null === $explicit || ! is_numeric( $explicit ) ) {
			return array( null, 'bespoke: deposit amount required' );
		}
		return array( round( (float) $explicit, $dp ), 'bespoke: explicit amount per quote' );
	}

	/**
	 * Build or rebuild the quote order from a request and the owner's quote input.
	 *
	 * $q keys: unit_price (made to order), description + price (bespoke), shipping (cost),
	 * shipping_label, scope, lead_time, deposit (bespoke amount), valid_days.
	 *
	 * @return WC_Order|WP_Error
	 */
	public static function save_quote( int $request_id, array $q, ?WC_Order $order = null ) {
		$req = get_post( $request_id );
		if ( ! $req || JW_Requests::CPT !== $req->post_type ) {
			return new WP_Error( 'jw_request', 'Request not found.' );
		}
		$m         = static fn( $k ) => get_post_meta( $request_id, $k, true );
		$sale_type = 'made_to_order' === $m( '_jw_type' ) ? 'made_to_order' : 'bespoke';
		$pickup    = 'pickup' === $m( '_jw_delivery' );

		if ( $order ) {
			if ( (float) $order->get_meta( '_jw_paid_total' ) > 0 || JW_Payments::paid_total( $order ) > 0 ) {
				return new WP_Error( 'jw_paid', __( 'A payment was already received for this quote. Refund or reconcile it before you change the quote.', 'jewelry-workflows' ) );
			}
			if ( ! in_array( self::state( $order ), array( 'draft', 'sent', 'expired', 'declined' ), true ) ) {
				return new WP_Error( 'jw_state', __( 'This quote can no longer be revised.', 'jewelry-workflows' ) );
			}
		}

		$shipping = isset( $q['shipping'] ) && '' !== $q['shipping'] ? (float) wc_format_decimal( $q['shipping'] ) : null;
		if ( ! $pickup && ( null === $shipping || $shipping < 0 ) ) {
			return new WP_Error( 'jw_shipping', __( 'Enter the quoted delivery cost (0 is allowed only if you mean free delivery).', 'jewelry-workflows' ) );
		}

		$product = null;
		if ( 'made_to_order' === $sale_type ) {
			$product = wc_get_product( (int) ( $m( '_jw_variation_id' ) ?: $m( '_jw_product_id' ) ) );
			if ( ! $product ) {
				return new WP_Error( 'jw_product', __( 'The requested product no longer exists.', 'jewelry-workflows' ) );
			}
			$unit = isset( $q['unit_price'] ) && '' !== $q['unit_price'] ? (float) wc_format_decimal( $q['unit_price'] ) : (float) $product->get_price();
			$qty  = max( 1, (int) ( $q['qty'] ?? $m( '_jw_qty' ) ?: 1 ) );
			if ( $unit <= 0 ) {
				return new WP_Error( 'jw_price', __( 'Enter a price for the piece.', 'jewelry-workflows' ) );
			}
		} else {
			$desc  = sanitize_text_field( $q['description'] ?? '' );
			$price = (float) wc_format_decimal( $q['price'] ?? 0 );
			if ( '' === $desc || $price <= 0 ) {
				return new WP_Error( 'jw_price', __( 'Enter a description and a price for the commission.', 'jewelry-workflows' ) );
			}
		}

		$is_new = ! $order;
		if ( $is_new ) {
			$order = wc_create_order( array( 'status' => 'jw-quote', 'created_via' => 'jw_quote' ) );
			if ( is_wp_error( $order ) ) {
				return $order;
			}
		} else {
			foreach ( $order->get_items( array( 'line_item', 'fee', 'shipping' ) ) as $item_id => $item ) {
				$order->remove_item( $item_id );
			}
		}

		// Snapshot of the agreed price. Later product price edits do not change this quote.
		if ( $product ) {
			$order->add_product( $product, $qty, array( 'subtotal' => $unit * $qty, 'total' => $unit * $qty ) );
			$merch = $unit * $qty;
		} else {
			$fee = new WC_Order_Item_Fee();
			$fee->set_name( $desc );
			$fee->set_tax_status( 'none' );
			$fee->set_total( $price );
			$order->add_item( $fee );
			$merch = $price;
		}
		$ship = new WC_Order_Item_Shipping();
		$ship->set_method_title( $pickup ? __( 'Pickup', 'jewelry-workflows' ) : ( sanitize_text_field( $q['shipping_label'] ?? '' ) ?: __( 'Quoted delivery', 'jewelry-workflows' ) ) );
		$ship->set_method_id( $pickup ? 'local_pickup' : 'jw_quoted' );
		$ship->set_total( $pickup ? 0 : $shipping );
		$order->add_item( $ship );

		// Customer details from the request.
		$names = explode( ' ', (string) $m( '_jw_name' ), 2 );
		$order->set_billing_first_name( $names[0] );
		$order->set_billing_last_name( $names[1] ?? '' );
		$order->set_billing_email( (string) $m( '_jw_email' ) );
		$order->set_billing_phone( (string) $m( '_jw_phone' ) );
		$order->set_billing_country( (string) $m( '_jw_country' ) );
		$order->set_billing_city( (string) $m( '_jw_city' ) );
		$order->set_billing_postcode( (string) $m( '_jw_postcode' ) );
		$order->set_billing_address_1( (string) $m( '_jw_address' ) );
		if ( ! $pickup ) {
			$order->set_shipping_first_name( $names[0] );
			$order->set_shipping_last_name( $names[1] ?? '' );
			$order->set_shipping_country( (string) $m( '_jw_country' ) );
			$order->set_shipping_city( (string) $m( '_jw_city' ) );
			$order->set_shipping_postcode( (string) $m( '_jw_postcode' ) );
			$order->set_shipping_address_1( (string) $m( '_jw_address' ) );
		}
		// Taxes are unresolved (see launch checklist): no automatic tax on quotes.
		$order->calculate_totals( false );

		[ $deposit, $rule ] = self::compute_deposit( $sale_type, $merch, (float) $order->get_total(), $q['deposit'] ?? null );
		if ( null === $deposit ) {
			if ( $is_new ) {
				$order->delete( true );
			}
			return new WP_Error( 'jw_deposit', __( 'Enter the deposit amount for this bespoke quote.', 'jewelry-workflows' ) );
		}
		if ( $deposit < 0 || $deposit > (float) $order->get_total() ) {
			if ( $is_new ) {
				$order->delete( true );
			}
			return new WP_Error( 'jw_deposit', __( 'The deposit must be between 0 and the full total.', 'jewelry-workflows' ) );
		}

		$version = $is_new ? 1 : (int) $order->get_meta( '_jw_quote_version' ) + 1;
		$order->update_meta_data( '_jw_kind', 'quote' );
		$order->update_meta_data( '_jw_request_id', $request_id );
		$order->update_meta_data( '_jw_sale_type', $sale_type );
		$order->update_meta_data( '_jw_lang', (string) $m( '_jw_lang' ) );
		$order->update_meta_data( '_jw_quote_version', $version );
		$order->update_meta_data( '_jw_quote_state', 'draft' );
		$order->update_meta_data( '_jw_scope', sanitize_textarea_field( $q['scope'] ?? '' ) );
		$order->update_meta_data( '_jw_lead_time', sanitize_text_field( $q['lead_time'] ?? '' ) );
		$order->update_meta_data( '_jw_valid_days', max( 1, (int) ( $q['valid_days'] ?? JW_Settings::get( 'quote_valid_days' ) ) ) );
		$order->update_meta_data( '_jw_deposit_amount', wc_format_decimal( $deposit, wc_get_price_decimals() ) );
		$order->update_meta_data( '_jw_deposit_rule', $rule );
		if ( $is_new ) {
			$order->update_meta_data( '_jw_production', 'not_started' );
		}
		$order->delete_meta_data( '_jw_quote_token' );
		$order->save();

		// A revision makes earlier unpaid payment links unusable.
		JW_Payments::cancel_open_requests( $order, 'Quote revised to version ' . $version . '.' );
		$order->add_order_note( sprintf( 'Quote version %d saved: total %s, deposit %s (%s).', $version, wc_price( $order->get_total() ), wc_price( $deposit ), $rule ) );

		update_post_meta( $request_id, '_jw_order_id', $order->get_id() );
		JW_Requests::log( $request_id, sprintf( 'Quote v%d saved on order #%d.', $version, $order->get_id() ) );
		return $order;
	}

	/**
	 * Send (or re-send) the current quote version. A new link is issued, so the
	 * link of an earlier version stops working.
	 *
	 * @return true|WP_Error
	 */
	public static function send( WC_Order $order ) {
		if ( ! in_array( self::state( $order ), array( 'draft', 'sent', 'expired' ), true ) ) {
			return new WP_Error( 'jw_state', __( 'Only a draft or sent quote can be sent.', 'jewelry-workflows' ) );
		}
		if ( 'made_to_order' === $order->get_meta( '_jw_sale_type' ) && ! JW_Settings::mto_deposits_allowed() ) {
			return new WP_Error( 'jw_deposit_basis', __( 'Made-to-order deposits are disabled until you confirm the deposit basis in Atelier > Settings.', 'jewelry-workflows' ) );
		}
		$token = wp_generate_password( 32, false );
		$order->update_meta_data( '_jw_quote_token', self::hash_token( $token, (int) $order->get_meta( '_jw_quote_version' ) ) );
		$order->update_meta_data( '_jw_quote_state', 'sent' );
		$order->update_meta_data( '_jw_valid_until', time() + DAY_IN_SECONDS * (int) $order->get_meta( '_jw_valid_days' ) );
		$order->save();
		$url = self::customer_url( $order, $token );
		$ok  = JW_Emails::quote( $order, $url );
		$order->add_order_note( $ok ? 'Quote email sent to the customer.' : 'Quote email FAILED. Copy the quote link from the workflow panel and send it yourself.' );
		$order->update_meta_data( '_jw_quote_link', $url ); // Shown to the owner for copy and paste.
		$order->save();
		$rid = (int) $order->get_meta( '_jw_request_id' );
		if ( $rid ) {
			JW_Requests::set_state( $rid, 'quote_sent', 'v' . $order->get_meta( '_jw_quote_version' ) );
		}
		return true;
	}

	public static function cancel( WC_Order $order, string $why = '' ) {
		if ( JW_Payments::paid_total( $order ) > 0 ) {
			return new WP_Error( 'jw_paid', __( 'A payment was received. Refund it in the payment order before you cancel.', 'jewelry-workflows' ) );
		}
		JW_Payments::cancel_open_requests( $order, 'Quote cancelled.' );
		$order->update_meta_data( '_jw_quote_state', 'cancelled' );
		$order->delete_meta_data( '_jw_quote_token' );
		$order->set_status( 'cancelled', 'Quote cancelled. ' . $why );
		$order->save();
		$rid = (int) $order->get_meta( '_jw_request_id' );
		if ( $rid ) {
			JW_Requests::set_state( $rid, 'closed', 'quote cancelled' );
		}
		return true;
	}

	private static function hash_token( string $token, int $version ): string {
		return hash_hmac( 'sha256', $version . '|' . $token, wp_salt( 'auth' ) );
	}

	public static function check_token( WC_Order $order, string $token ): bool {
		$stored = (string) $order->get_meta( '_jw_quote_token' );
		return '' !== $stored && '' !== $token && hash_equals( $stored, self::hash_token( $token, (int) $order->get_meta( '_jw_quote_version' ) ) );
	}

	public static function customer_url( WC_Order $order, string $token ): string {
		return JW_I18n::with_lang(
			(string) $order->get_meta( '_jw_lang' ),
			static fn() => add_query_arg( array( 'jw-quote' => $order->get_id(), 'k' => $token ), home_url( '/' ) )
		);
	}

	/**
	 * Customer accepts the quote. Creates (or reuses) the deposit payment request.
	 *
	 * @return WC_Order|WP_Error The deposit payment order.
	 */
	public static function accept( WC_Order $order, string $token ) {
		return JW_Lock::with(
			'quote_' . $order->get_id(),
			static function () use ( $order, $token ) {
				$order = wc_get_order( $order->get_id() ); // Fresh read inside the lock.
				if ( ! self::check_token( $order, $token ) ) {
					return new WP_Error( 'jw_link', __( 'This quote link is no longer valid. A newer quote may have been sent to you.', 'jewelry-workflows' ) );
				}
				$state = self::state( $order );
				if ( 'expired' === $state ) {
					$order->update_meta_data( '_jw_quote_state', 'expired' );
					$order->save();
					return new WP_Error( 'jw_expired', __( 'This quote has expired. Please contact us for an updated quote.', 'jewelry-workflows' ) );
				}
				if ( 'accepted' === $state ) {
					return JW_Payments::open_request( $order, 'deposit' ) ?: new WP_Error( 'jw_done', __( 'The deposit for this quote is already paid.', 'jewelry-workflows' ) );
				}
				if ( 'sent' !== $state ) {
					return new WP_Error( 'jw_state', __( 'This quote can no longer be accepted.', 'jewelry-workflows' ) );
				}
				if ( 'made_to_order' === $order->get_meta( '_jw_sale_type' ) && ! JW_Settings::mto_deposits_allowed() ) {
					return new WP_Error( 'jw_deposit_basis', __( 'Online deposits are not available yet. Please contact us.', 'jewelry-workflows' ) );
				}
				$order->update_meta_data( '_jw_quote_state', 'accepted' );
				$order->update_meta_data( '_jw_accepted_at', time() );
				$order->save();
				$order->add_order_note( sprintf( 'Customer accepted quote version %d.', $order->get_meta( '_jw_quote_version' ) ) );
				$rid = (int) $order->get_meta( '_jw_request_id' );
				if ( $rid ) {
					JW_Requests::set_state( $rid, 'accepted' );
				}
				$deposit = (float) $order->get_meta( '_jw_deposit_amount' );
				if ( $deposit <= 0 ) {
					JW_Payments::reconcile( $order );
					return new WP_Error( 'jw_no_deposit', __( 'Thank you. No deposit is due for this quote. We will contact you when the piece is ready.', 'jewelry-workflows' ) );
				}
				return JW_Payments::request( $order, 'deposit', $deposit );
			}
		);
	}

	public static function decline( WC_Order $order, string $token ) {
		if ( ! self::check_token( $order, $token ) || 'sent' !== self::state( $order ) ) {
			return new WP_Error( 'jw_state', __( 'This quote can no longer be changed.', 'jewelry-workflows' ) );
		}
		JW_Payments::cancel_open_requests( $order, 'Quote declined by customer.' );
		$order->update_meta_data( '_jw_quote_state', 'declined' );
		$order->save();
		$order->add_order_note( 'Customer declined the quote.' );
		$rid = (int) $order->get_meta( '_jw_request_id' );
		if ( $rid ) {
			JW_Requests::set_state( $rid, 'declined' );
		}
		return true;
	}

	// ----- Customer quote page -----

	/** @var array{order: ?WC_Order, token: string, error: string, notice: string} */
	public static array $view = array( 'order' => null, 'token' => '', 'error' => '', 'notice' => '' );

	public static function handle_customer_action(): void {
		$id = absint( get_query_var( 'jw-quote' ) ?: ( $_GET['jw-quote'] ?? 0 ) );
		if ( ! $id ) {
			return;
		}
		$token = sanitize_text_field( wp_unslash( $_REQUEST['k'] ?? '' ) );
		$order = wc_get_order( $id );
		self::$view['token'] = $token;
		if ( ! self::is_quote( $order ) || ! self::check_token( $order, $token ) ) {
			self::$view['error'] = __( 'This quote link is not valid. It may have been replaced by a newer quote. Please use the most recent email, or contact us.', 'jewelry-workflows' );
			status_header( 404 );
			return;
		}
		self::$view['order'] = $order;
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( $_POST['_jw_nonce'] ?? '', 'jw_quote_' . $id ) ) {
			self::$view['error'] = __( 'Your session expired. Please try again.', 'jewelry-workflows' );
			return;
		}
		if ( isset( $_POST['jw_accept'] ) ) {
			$res = self::accept( $order, $token );
			if ( $res instanceof WC_Order ) {
				wp_safe_redirect( $res->get_checkout_payment_url() );
				exit;
			}
			self::$view['error'] = $res->get_error_message();
		} elseif ( isset( $_POST['jw_decline'] ) ) {
			$res = self::decline( $order, $token );
			is_wp_error( $res ) ? self::$view['error'] = $res->get_error_message() : self::$view['notice'] = __( 'You declined this quote. Thank you for letting us know.', 'jewelry-workflows' );
		}
		self::$view['order'] = wc_get_order( $id );
	}

	public static function template( $template ) {
		if ( ! get_query_var( 'jw-quote' ) && empty( $_GET['jw-quote'] ) ) {
			return $template;
		}
		$theme = locate_template( 'jw-quote.php' );
		return $theme ?: JW_DIR . '/templates/quote.php';
	}
}
