<?php
/**
 * Customer quote page. A theme can override it with jw-quote.php.
 */
defined( 'ABSPATH' ) || exit;

$view  = JW_Quotes::$view;
$order = $view['order'];
get_header();
?>
<main id="main" class="jw-page jw-quote">
	<div class="jw-container jw-container--narrow">
		<?php if ( ! $order ) : ?>
			<h1 class="jw-page__title"><?php esc_html_e( 'Quote not available', 'jewelry-workflows' ); ?></h1>
			<p><?php echo esc_html( $view['error'] ); ?></p>
		<?php else :
			$state   = JW_Quotes::state( $order );
			$a       = JW_Payments::amounts( $order );
			$until   = (int) $order->get_meta( '_jw_valid_until' );
			$open    = JW_Payments::open_request( $order, 'deposit' );
			?>
			<p class="jw-eyebrow"><?php /* translators: 1: order number, 2: version */ echo esc_html( sprintf( __( 'Quote #%1$d · version %2$d', 'jewelry-workflows' ), $order->get_id(), (int) $order->get_meta( '_jw_quote_version' ) ) ); ?></p>
			<h1 class="jw-page__title"><?php esc_html_e( 'Your quote', 'jewelry-workflows' ); ?></h1>

			<?php if ( $view['error'] ) : ?>
				<div class="jw-notice jw-notice--error" role="alert"><p><?php echo esc_html( $view['error'] ); ?></p></div>
			<?php endif; ?>
			<?php if ( $view['notice'] ) : ?>
				<div class="jw-notice jw-notice--ok" role="status"><p><?php echo esc_html( $view['notice'] ); ?></p></div>
			<?php endif; ?>

			<?php if ( $order->get_meta( '_jw_scope' ) ) : ?>
				<h2 class="jw-h3"><?php esc_html_e( 'Scope', 'jewelry-workflows' ); ?></h2>
				<p><?php echo nl2br( esc_html( $order->get_meta( '_jw_scope' ) ) ); ?></p>
			<?php endif; ?>

			<table class="jw-table">
				<tbody>
				<?php foreach ( $order->get_items( array( 'line_item', 'fee' ) ) as $item ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $item->get_name() ); ?><?php echo $item instanceof WC_Order_Item_Product && $item->get_quantity() > 1 ? ' × ' . (int) $item->get_quantity() : ''; ?>
							<?php if ( $item instanceof WC_Order_Item_Product ) { echo '<div class="jw-meta">' . wp_kses_post( wc_display_item_meta( $item, array( 'echo' => false ) ) ) . '</div>'; } ?></th>
						<td><?php echo wp_kses_post( wc_price( $item->get_total(), array( 'currency' => $order->get_currency() ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php foreach ( $order->get_items( 'shipping' ) as $item ) : ?>
					<tr><th scope="row"><?php echo esc_html( $item->get_name() ); ?></th><td><?php echo wp_kses_post( wc_price( $item->get_total(), array( 'currency' => $order->get_currency() ) ) ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr class="jw-table__total"><th scope="row"><?php esc_html_e( 'Full total', 'jewelry-workflows' ); ?></th><td><?php echo wp_kses_post( wc_price( $a['total'], array( 'currency' => $order->get_currency() ) ) ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Deposit due now', 'jewelry-workflows' ); ?></th><td><?php echo wp_kses_post( wc_price( $a['deposit'], array( 'currency' => $order->get_currency() ) ) ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Balance due when the piece is ready, before shipping or pickup', 'jewelry-workflows' ); ?></th><td><?php echo wp_kses_post( wc_price( max( 0, $a['total'] - $a['deposit'] ), array( 'currency' => $order->get_currency() ) ) ); ?></td></tr>
					<?php if ( $a['paid'] > 0 ) : ?>
						<tr><th scope="row"><?php esc_html_e( 'Paid so far', 'jewelry-workflows' ); ?></th><td><?php echo wp_kses_post( wc_price( $a['paid'], array( 'currency' => $order->get_currency() ) ) ); ?></td></tr>
					<?php endif; ?>
				</tfoot>
			</table>

			<dl class="jw-dl">
				<?php if ( $order->get_meta( '_jw_lead_time' ) ) : ?>
					<dt><?php esc_html_e( 'Production time', 'jewelry-workflows' ); ?></dt><dd><?php echo esc_html( $order->get_meta( '_jw_lead_time' ) ); ?></dd>
				<?php endif; ?>
				<?php if ( $until ) : ?>
					<dt><?php esc_html_e( 'Valid until', 'jewelry-workflows' ); ?></dt><dd><?php echo esc_html( wp_date( get_option( 'date_format' ), $until ) ); ?></dd>
				<?php endif; ?>
				<dt><?php esc_html_e( 'Status', 'jewelry-workflows' ); ?></dt><dd><?php echo esc_html( JW_Quotes::state_label( $state ) ); ?></dd>
			</dl>

			<?php if ( 'sent' === $state || ( 'accepted' === $state && $open ) ) : ?>
				<form method="post" class="jw-quote__actions">
					<?php wp_nonce_field( 'jw_quote_' . $order->get_id(), '_jw_nonce' ); ?>
					<input type="hidden" name="k" value="<?php echo esc_attr( $view['token'] ); ?>">
					<button type="submit" name="jw_accept" value="1" class="button jw-button">
						<?php echo esc_html( 'accepted' === $state ? __( 'Pay the deposit', 'jewelry-workflows' ) : __( 'Accept and pay the deposit', 'jewelry-workflows' ) ); ?>
					</button>
					<?php if ( 'sent' === $state ) : ?>
						<button type="submit" name="jw_decline" value="1" class="button jw-button jw-button--ghost"><?php esc_html_e( 'Decline', 'jewelry-workflows' ); ?></button>
					<?php endif; ?>
				</form>
				<p class="jw-help"><?php esc_html_e( 'Payment is made on the secure payment page. The amount is fixed by this quote.', 'jewelry-workflows' ); ?></p>
			<?php elseif ( 'expired' === $state ) : ?>
				<p><?php esc_html_e( 'This quote has expired. Please contact us for an updated quote.', 'jewelry-workflows' ); ?></p>
			<?php endif; ?>
		<?php endif; ?>
	</div>
</main>
<?php
get_footer();
