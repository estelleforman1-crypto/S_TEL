<?php
/**
 * Customer emails in the customer's recorded language, with the WooCommerce
 * email layout. Text is in gettext strings: the owner's final wording for each
 * language goes into languages/jewelry-workflows-{locale}.po.
 * Every method returns the result of the mail call (true = handed to the mail system).
 */
defined( 'ABSPATH' ) || exit;

class JW_Emails {
	public static function init(): void {
		add_action( 'jw_request_created', array( __CLASS__, 'request_received' ) );
	}

	private static function send( string $to, string $lang, callable $build ): bool {
		if ( ! is_email( $to ) ) {
			return false;
		}
		return (bool) JW_I18n::with_lang(
			$lang,
			static function () use ( $to, $build ) {
				[ $subject, $heading, $body ] = $build();
				$mailer = WC()->mailer();
				return $mailer->send( $to, $subject, $mailer->wrap_message( $heading, $body ), "Content-Type: text/html\r\n" );
			}
		);
	}

	private static function shop_name(): string {
		return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}

	private static function button( string $url, string $label ): string {
		return '<p style="margin:24px 0"><a href="' . esc_url( $url ) . '" style="display:inline-block;padding:12px 24px;background:#000;color:#fff;text-decoration:none;letter-spacing:.05em">' . esc_html( $label ) . '</a></p><p style="font-size:12px;word-break:break-all">' . esc_html( $url ) . '</p>';
	}

	private static function money( float $v, WC_Order $o ): string {
		return wc_price( $v, array( 'currency' => $o->get_currency() ) );
	}

	public static function request_received( int $request_id ): void {
		$email = (string) get_post_meta( $request_id, '_jw_email', true );
		$lang  = (string) get_post_meta( $request_id, '_jw_lang', true );
		if ( 'manual' !== get_post_meta( $request_id, '_jw_source', true ) ) {
			$ok = self::send(
				$email,
				$lang,
				static fn() => array(
					/* translators: %s: shop name */
					sprintf( __( 'We received your request – %s', 'jewelry-workflows' ), self::shop_name() ),
					__( 'Request received', 'jewelry-workflows' ),
					'<p>' . esc_html__( 'Thank you for your request. This is a confirmation that we received it. It is not an accepted order and nothing has been charged.', 'jewelry-workflows' ) . '</p><p>' .
					esc_html__( 'You will receive a reply with questions or a quote. The quote shows the full price, delivery cost, production time and the deposit.', 'jewelry-workflows' ) . '</p>' .
					/* translators: %d: request number */
					'<p>' . esc_html( sprintf( __( 'Request number: %d', 'jewelry-workflows' ), $request_id ) ) . '</p>',
				)
			);
			JW_Requests::log( $request_id, $ok ? 'Receipt email sent to customer.' : 'Receipt email FAILED.' );
		}
		$owner = JW_Settings::get( 'contact_email' ) ?: get_option( 'admin_email' );
		$ok    = self::send(
			$owner,
			JW_I18n::default_lang(),
			static fn() => array(
				sprintf( 'New request #%d', $request_id ),
				'New request',
				'<p>' . esc_html( get_the_title( $request_id ) ) . '</p>' . self::button( admin_url( 'post.php?post=' . $request_id . '&action=edit' ), 'Open request' ),
			)
		);
		JW_Requests::log( $request_id, $ok ? 'Owner notification sent.' : 'Owner notification FAILED.' );
	}

	public static function quote( WC_Order $order, string $url ): bool {
		return self::send(
			$order->get_billing_email(),
			(string) $order->get_meta( '_jw_lang' ),
			static function () use ( $order, $url ) {
				$a     = JW_Payments::amounts( $order );
				$until = (int) $order->get_meta( '_jw_valid_until' );
				$body  = '<p>' . esc_html__( 'Thank you for your patience. Your quote is ready.', 'jewelry-workflows' ) . '</p>';
				$body .= '<table cellpadding="6" style="width:100%;border-collapse:collapse">';
				$body .= '<tr><td>' . esc_html__( 'Full total, including delivery', 'jewelry-workflows' ) . '</td><td style="text-align:end"><strong>' . self::money( $a['total'], $order ) . '</strong></td></tr>';
				$body .= '<tr><td>' . esc_html__( 'Deposit due now', 'jewelry-workflows' ) . '</td><td style="text-align:end">' . self::money( $a['deposit'], $order ) . '</td></tr>';
				$body .= '<tr><td>' . esc_html__( 'Balance due when the piece is ready', 'jewelry-workflows' ) . '</td><td style="text-align:end">' . self::money( max( 0, $a['total'] - $a['deposit'] ), $order ) . '</td></tr></table>';
				if ( $until ) {
					/* translators: %s: date */
					$body .= '<p>' . esc_html( sprintf( __( 'This quote is valid until %s.', 'jewelry-workflows' ), wp_date( get_option( 'date_format' ), $until ) ) ) . '</p>';
				}
				$body .= self::button( $url, __( 'View the quote', 'jewelry-workflows' ) );
				return array(
					/* translators: 1: order number, 2: shop name */
					sprintf( __( 'Your quote #%1$d – %2$s', 'jewelry-workflows' ), $order->get_id(), self::shop_name() ),
					__( 'Your quote', 'jewelry-workflows' ),
					$body,
				);
			}
		);
	}

	public static function balance_request( WC_Order $req, WC_Order $parent ): bool {
		return self::send(
			$parent->get_billing_email(),
			(string) $req->get_meta( '_jw_lang' ),
			static function () use ( $req, $parent ) {
				$a    = JW_Payments::amounts( $parent );
				$body = '<p>' . esc_html__( 'Your piece is ready.', 'jewelry-workflows' ) . '</p>';
				$body .= '<table cellpadding="6" style="width:100%;border-collapse:collapse">';
				$body .= '<tr><td>' . esc_html__( 'Full total', 'jewelry-workflows' ) . '</td><td style="text-align:end">' . self::money( $a['total'], $parent ) . '</td></tr>';
				$body .= '<tr><td>' . esc_html__( 'Already paid', 'jewelry-workflows' ) . '</td><td style="text-align:end">' . self::money( $a['paid'], $parent ) . '</td></tr>';
				$body .= '<tr><td><strong>' . esc_html__( 'Balance due now', 'jewelry-workflows' ) . '</strong></td><td style="text-align:end"><strong>' . self::money( (float) $req->get_total(), $req ) . '</strong></td></tr></table>';
				$body .= '<p>' . esc_html__( 'We ship or hand over the piece after the balance is paid.', 'jewelry-workflows' ) . '</p>';
				$body .= self::button( JW_Payments::pay_url( $req ), __( 'Pay the balance', 'jewelry-workflows' ) );
				return array(
					/* translators: 1: order number, 2: shop name */
					sprintf( __( 'Your piece is ready – balance for order #%1$d – %2$s', 'jewelry-workflows' ), $parent->get_id(), self::shop_name() ),
					__( 'Your piece is ready', 'jewelry-workflows' ),
					$body,
				);
			}
		);
	}

	public static function payment_received( WC_Order $req, WC_Order $parent ): bool {
		return self::send(
			$parent->get_billing_email(),
			(string) $req->get_meta( '_jw_lang' ),
			static function () use ( $req, $parent ) {
				$a        = JW_Payments::amounts( $parent );
				$deposit  = 'deposit' === JW_Payments::kind( $req );
				$heading  = $deposit ? __( 'Deposit received', 'jewelry-workflows' ) : __( 'Payment received', 'jewelry-workflows' );
				/* translators: %s: amount */
				$body     = '<p>' . sprintf( esc_html__( 'We received your payment of %s. Thank you.', 'jewelry-workflows' ), self::money( (float) $req->get_total(), $req ) ) . '</p>';
				if ( $a['balance'] > 0 ) {
					/* translators: %s: amount */
					$body .= '<p>' . sprintf( esc_html__( 'Remaining balance: %s. We will email you a payment link when the piece is ready.', 'jewelry-workflows' ), self::money( $a['balance'], $parent ) ) . '</p>';
				} else {
					$body .= '<p>' . esc_html__( 'Your order is paid in full. We will contact you about shipping or pickup.', 'jewelry-workflows' ) . '</p>';
				}
				/* translators: 1: heading, 2: order number */
				return array( sprintf( __( '%1$s – order #%2$d', 'jewelry-workflows' ), $heading, $parent->get_id() ), $heading, $body );
			}
		);
	}
}
