<?php
/**
 * Sale type per product:
 * - ready: normal WooCommerce cart and checkout (in stock and one-of-a-kind pieces).
 * - made_to_order: not purchasable through the cart. The product page shows a
 *   request form. Shipping is quoted, then a deposit is paid.
 * Bespoke work has no product: it starts on the commission page.
 */
defined( 'ABSPATH' ) || exit;

class JW_Sale_Types {
	const META      = '_jw_sale_type';
	const LEAD_META = '_jw_lead_weeks';

	public static function init(): void {
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'is_purchasable' ), 20, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', array( __CLASS__, 'is_purchasable' ), 20, 2 );
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'admin_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'admin_save' ) );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'purchase_terms' ), 25 );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'maybe_swap_add_to_cart' ), 1 );
		add_filter( 'woocommerce_loop_add_to_cart_link', array( __CLASS__, 'loop_link' ), 10, 2 );
		add_action( 'woocommerce_product_query', array( __CLASS__, 'filter_catalog' ) );
	}

	/** Shop filter ?jw_type=ready|made_to_order. Products without the meta count as ready. */
	public static function filter_catalog( WP_Query $q ): void {
		$type = sanitize_key( $_GET['jw_type'] ?? '' );
		if ( ! in_array( $type, array( 'ready', 'made_to_order' ), true ) ) {
			return;
		}
		$meta   = (array) $q->get( 'meta_query' );
		$meta[] = 'made_to_order' === $type
			? array( 'key' => self::META, 'value' => 'made_to_order' )
			: array(
				'relation' => 'OR',
				array( 'key' => self::META, 'value' => 'ready' ),
				array( 'key' => self::META, 'compare' => 'NOT EXISTS' ),
			);
		$q->set( 'meta_query', $meta );
	}

	public static function type( $product ): string {
		$product = is_numeric( $product ) ? wc_get_product( $product ) : $product;
		if ( ! $product ) {
			return 'ready';
		}
		$id = $product->get_parent_id() ?: $product->get_id();
		return 'made_to_order' === get_post_meta( $id, self::META, true ) ? 'made_to_order' : 'ready';
	}

	public static function lead_weeks( $product ): int {
		$id = $product->get_parent_id() ?: $product->get_id();
		return (int) get_post_meta( $id, self::LEAD_META, true );
	}

	public static function is_purchasable( $purchasable, $product ) {
		return 'made_to_order' === self::type( $product ) ? false : $purchasable;
	}

	public static function admin_fields(): void {
		echo '<div class="options_group">';
		woocommerce_wp_select(
			array(
				'id'          => self::META,
				'label'       => __( 'How this piece is sold', 'jewelry-workflows' ),
				'options'     => array(
					'ready'         => __( 'Ready to ship (cart and full payment)', 'jewelry-workflows' ),
					'made_to_order' => __( 'Made to order (request, shipping quote, deposit)', 'jewelry-workflows' ),
				),
				'desc_tip'    => true,
				'description' => __( 'One-of-a-kind pieces are "Ready to ship" with stock quantity 1.', 'jewelry-workflows' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => self::LEAD_META,
				'label'             => __( 'Production time (weeks)', 'jewelry-workflows' ),
				'type'              => 'number',
				'custom_attributes' => array( 'min' => 0, 'step' => 1 ),
				'desc_tip'          => true,
				'description'       => __( 'Made-to-order only. Leave empty if the time is given in each quote.', 'jewelry-workflows' ),
			)
		);
		echo '</div>';
	}

	public static function admin_save( WC_Product $product ): void {
		$type = sanitize_key( $_POST[ self::META ] ?? 'ready' );
		$product->update_meta_data( self::META, 'made_to_order' === $type ? 'made_to_order' : 'ready' );
		$weeks = absint( $_POST[ self::LEAD_META ] ?? 0 );
		$weeks ? $product->update_meta_data( self::LEAD_META, $weeks ) : $product->delete_meta_data( self::LEAD_META );
	}

	/** Payment and delivery expectations, shown above the purchase action. */
	public static function purchase_terms(): void {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		echo '<div class="jw-terms">';
		if ( 'made_to_order' === self::type( $product ) ) {
			$weeks = self::lead_weeks( $product );
			echo '<p class="jw-terms__type">' . esc_html__( 'Made to order', 'jewelry-workflows' ) . '</p><ul>';
			if ( $weeks ) {
				/* translators: %d: number of weeks */
				echo '<li>' . esc_html( sprintf( _n( 'Production time: about %d week after the deposit.', 'Production time: about %d weeks after the deposit.', $weeks, 'jewelry-workflows' ), $weeks ) ) . '</li>';
			} else {
				echo '<li>' . esc_html__( 'Production time is confirmed in your quote.', 'jewelry-workflows' ) . '</li>';
			}
			echo '<li>' . esc_html__( 'Send a request. Delivery is quoted for your address before you pay anything.', 'jewelry-workflows' ) . '</li>';
			/* translators: %s: deposit percentage */
			echo '<li>' . esc_html( sprintf( __( 'A %s%% deposit starts production. The balance is due when the piece is ready, before shipping or pickup.', 'jewelry-workflows' ), (int) JW_Settings::get( 'mto_deposit_percent' ) ) ) . '</li>';
			echo '</ul>';
		} else {
			$one = $product->managing_stock() && 1 === (int) $product->get_stock_quantity() && ! $product->is_type( 'variable' );
			echo '<p class="jw-terms__type">' . esc_html( $one ? __( 'One of a kind, ready to ship', 'jewelry-workflows' ) : __( 'Ready to ship', 'jewelry-workflows' ) ) . '</p><ul>';
			echo '<li>' . esc_html__( 'Full payment at checkout. Delivery cost is shown before you pay.', 'jewelry-workflows' ) . '</li>';
			echo '</ul>';
		}
		echo '</div>';
	}

	public static function maybe_swap_add_to_cart(): void {
		global $product;
		if ( $product instanceof WC_Product && 'made_to_order' === self::type( $product ) ) {
			remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
			add_action( 'woocommerce_single_product_summary', array( 'JW_Requests', 'render_mto_form' ), 30 );
		}
	}

	public static function loop_link( $html, $product ) {
		if ( 'made_to_order' === self::type( $product ) ) {
			return sprintf( '<a class="button jw-loop-request" href="%s">%s</a>', esc_url( $product->get_permalink() . '#jw-request' ), esc_html__( 'Request this piece', 'jewelry-workflows' ) );
		}
		return $html;
	}
}
