<?php
/**
 * Requests: made-to-order requests (from a product page), bespoke commission
 * briefs (from the commission page) and manual entries by the owner (for
 * conversations by email, WhatsApp or in person). A request is not an order.
 * The owner turns it into a quote (a WooCommerce order) in the admin.
 */
defined( 'ABSPATH' ) || exit;

class JW_Requests {
	const CPT = 'jw_request';

	const STATES = array(
		'new'                 => 'New',
		'needs_clarification' => 'Needs clarification',
		'quote_sent'          => 'Quote sent',
		'accepted'            => 'Accepted',
		'declined'            => 'Declined',
		'expired'             => 'Expired',
		'closed'              => 'Closed',
	);

	/** Form errors and old values for the current page view. */
	public static array $errors = array();
	public static array $old    = array();

	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'handle_post' ) );
		add_shortcode( 'jw_commission_form', array( __CLASS__, 'commission_form_shortcode' ) );
		add_action( 'admin_post_jw_attachment', array( 'JW_Uploads', 'serve' ) );
	}

	public static function state_label( string $state ): string {
		$labels = array(
			'new'                 => __( 'New', 'jewelry-workflows' ),
			'needs_clarification' => __( 'Needs clarification', 'jewelry-workflows' ),
			'quote_sent'          => __( 'Quote sent', 'jewelry-workflows' ),
			'accepted'            => __( 'Accepted', 'jewelry-workflows' ),
			'declined'            => __( 'Declined', 'jewelry-workflows' ),
			'expired'             => __( 'Expired', 'jewelry-workflows' ),
			'closed'              => __( 'Closed', 'jewelry-workflows' ),
		);
		return $labels[ $state ] ?? $state;
	}

	public static function register_post_type(): void {
		register_post_type(
			self::CPT,
			array(
				'labels'              => array(
					'name'          => __( 'Requests', 'jewelry-workflows' ),
					'singular_name' => __( 'Request', 'jewelry-workflows' ),
					'add_new_item'  => __( 'Record a request (email, WhatsApp, in person)', 'jewelry-workflows' ),
					'add_new'       => __( 'Record a request', 'jewelry-workflows' ),
					'edit_item'     => __( 'Request', 'jewelry-workflows' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => 'jw-atelier',
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'show_in_rest'        => false,
				'supports'            => array( 'title' ),
				'capability_type'     => 'shop_order',
				'map_meta_cap'        => true,
			)
		);
	}

	/**
	 * Create a request. Used by the public forms, the admin manual entry and tests.
	 *
	 * @return int|WP_Error
	 */
	public static function create( array $d ) {
		$type = in_array( $d['type'] ?? '', array( 'made_to_order', 'bespoke' ), true ) ? $d['type'] : 'bespoke';
		$name = sanitize_text_field( $d['name'] ?? '' );
		$mail = sanitize_email( $d['email'] ?? '' );
		if ( '' === $name ) {
			return new WP_Error( 'name', __( 'Please enter your name.', 'jewelry-workflows' ) );
		}
		if ( ! is_email( $mail ) && 'manual' !== ( $d['source'] ?? '' ) ) {
			return new WP_Error( 'email', __( 'Please enter a valid email address.', 'jewelry-workflows' ) );
		}
		$meta = array(
			'_jw_type'         => $type,
			'_jw_state'        => 'new',
			'_jw_name'         => $name,
			'_jw_email'        => $mail,
			'_jw_phone'        => sanitize_text_field( $d['phone'] ?? '' ),
			'_jw_contact_pref' => sanitize_key( $d['contact_pref'] ?? 'email' ),
			'_jw_brief'        => sanitize_textarea_field( $d['brief'] ?? '' ),
			'_jw_prefs'        => sanitize_textarea_field( $d['prefs'] ?? '' ),
			'_jw_delivery'     => 'pickup' === ( $d['delivery'] ?? '' ) ? 'pickup' : 'ship',
			'_jw_country'      => strtoupper( substr( sanitize_key( $d['country'] ?? '' ), 0, 2 ) ),
			'_jw_city'         => sanitize_text_field( $d['city'] ?? '' ),
			'_jw_address'      => sanitize_textarea_field( $d['address'] ?? '' ),
			'_jw_postcode'     => sanitize_text_field( $d['postcode'] ?? '' ),
			'_jw_desired_date' => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d['desired_date'] ?? '' ) ? $d['desired_date'] : '',
			'_jw_lang'         => isset( JW_I18n::LANGS[ $d['lang'] ?? '' ] ) ? $d['lang'] : JW_I18n::current(),
			'_jw_source'       => 'manual' === ( $d['source'] ?? '' ) ? 'manual' : 'form',
			'_jw_channel'      => sanitize_key( $d['channel'] ?? 'website' ),
			'_jw_attachments'  => $d['attachments'] ?? array(),
		);

		if ( 'made_to_order' === $type ) {
			$product = wc_get_product( absint( $d['product_id'] ?? 0 ) );
			if ( ! $product || 'made_to_order' !== JW_Sale_Types::type( $product ) || 'publish' !== $product->get_status() ) {
				return new WP_Error( 'product', __( 'This piece is not available for requests.', 'jewelry-workflows' ) );
			}
			$variation_id = 0;
			$attributes   = array();
			if ( $product->is_type( 'variable' ) ) {
				foreach ( array_keys( $product->get_variation_attributes() ) as $attr ) {
					$key   = 'attribute_' . sanitize_title( $attr );
					$value = wc_clean( wp_unslash( $d['attributes'][ $key ] ?? '' ) );
					if ( '' === $value ) {
						/* translators: %s: option name, for example Size */
						return new WP_Error( 'attr', sprintf( __( 'Please choose: %s', 'jewelry-workflows' ), wc_attribute_label( $attr, $product ) ) );
					}
					$attributes[ $key ] = $value;
				}
				$variation_id = ( new WC_Product_Data_Store_CPT() )->find_matching_product_variation( $product, $attributes );
				if ( ! $variation_id ) {
					return new WP_Error( 'attr', __( 'This combination of options is not available.', 'jewelry-workflows' ) );
				}
			}
			if ( 'ship' === $meta['_jw_delivery'] && '' === $meta['_jw_country'] ) {
				return new WP_Error( 'country', __( 'Please choose the delivery country so that delivery can be quoted.', 'jewelry-workflows' ) );
			}
			$meta['_jw_product_id']   = $product->get_id();
			$meta['_jw_variation_id'] = $variation_id;
			$meta['_jw_attributes']   = $attributes;
			$meta['_jw_qty']          = max( 1, min( 20, absint( $d['qty'] ?? 1 ) ) );
			// Price at the time of the request, for the owner's reference. The quote sets the binding price.
			$priced                  = $variation_id ? wc_get_product( $variation_id ) : $product;
			$meta['_jw_price_shown'] = (string) $priced->get_price();
		} elseif ( '' === $meta['_jw_brief'] && 'manual' !== $meta['_jw_source'] ) {
			return new WP_Error( 'brief', __( 'Please describe the piece you have in mind.', 'jewelry-workflows' ) );
		}
		if ( 'pickup' === $meta['_jw_delivery'] && ! JW_Settings::get( 'pickup_enabled' ) && 'manual' !== $meta['_jw_source'] ) {
			$meta['_jw_delivery'] = 'ship';
		}

		$id = wp_insert_post(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'publish',
				'post_title'  => $name,
				'meta_input'  => $meta,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		wp_update_post( array( 'ID' => $id, 'post_title' => sprintf( '#%d %s', $id, $name ) ) );
		self::log( $id, 'manual' === $meta['_jw_source'] ? 'Recorded manually by the owner.' : 'Received from the website form.' );
		do_action( 'jw_request_created', $id );
		return $id;
	}

	public static function log( int $id, string $message ): void {
		$log   = get_post_meta( $id, '_jw_log', true );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array( 'time' => time(), 'msg' => $message );
		update_post_meta( $id, '_jw_log', $log );
	}

	public static function set_state( int $id, string $state, string $why = '' ): void {
		if ( isset( self::STATES[ $state ] ) ) {
			update_post_meta( $id, '_jw_state', $state );
			self::log( $id, 'State: ' . self::STATES[ $state ] . ( $why ? " ({$why})" : '' ) );
		}
	}

	// ----- Public form handling -----

	private static function throttled(): bool {
		$ip    = $_SERVER['REMOTE_ADDR'] ?? '';
		$key   = 'jw_rl_' . md5( $ip . wp_salt( 'nonce' ) );
		$count = (int) get_transient( $key );
		if ( $count >= (int) JW_Settings::get( 'form_limit_per_hour' ) ) {
			return true;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return false;
	}

	public static function handle_post(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['jw_form'] ) ) {
			return;
		}
		$form = sanitize_key( $_POST['jw_form'] );
		if ( ! in_array( $form, array( 'mto', 'commission' ), true ) ) {
			return;
		}
		// Fields are namespaced: plain names such as "name" are WordPress query variables and would turn the page into a 404.
		self::$old = (array) wp_unslash( $_POST['jw'] ?? array() );
		if ( ! wp_verify_nonce( $_POST['_jw_nonce'] ?? '', 'jw_form_' . $form ) ) {
			self::$errors[] = __( 'Your session expired. Please submit the form again.', 'jewelry-workflows' );
			return;
		}
		if ( ! empty( $_POST['jw_website'] ) ) { // Honeypot field, hidden from people.
			wp_safe_redirect( add_query_arg( 'jw_request', 'received', JW_I18n::url_for_lang( JW_I18n::current() ) ) );
			exit;
		}
		if ( self::throttled() ) {
			self::$errors[] = __( 'Too many requests from this connection. Please try again later or contact us directly.', 'jewelry-workflows' );
			return;
		}
		$data         = self::$old;
		$data['type'] = 'mto' === $form ? 'made_to_order' : 'bespoke';
		$data['lang'] = JW_I18n::current();
		unset( $data['source'] );

		$attachments = array();
		if ( 'commission' === $form ) {
			$attachments = JW_Uploads::store_from_request( 'jw_photos' );
			if ( is_wp_error( $attachments ) ) {
				self::$errors[] = $attachments->get_error_message();
				return;
			}
		}
		$data['attachments'] = $attachments;
		$id                  = self::create( $data );
		if ( is_wp_error( $id ) ) {
			JW_Uploads::delete( $attachments );
			self::$errors[] = $id->get_error_message();
			return;
		}
		// The page that showed the form. (wp_get_referer() is unusable here: with a language
		// prefix it equals home_url() . REQUEST_URI and WordPress then returns false.)
		$back = remove_query_arg( array( 'jw_request' ), JW_I18n::url_for_lang( JW_I18n::current() ) );
		wp_safe_redirect( add_query_arg( 'jw_request', 'received', $back ) . '#jw-request' );
		exit;
	}

	private static function old( string $key, string $default = '' ): string {
		return isset( self::$old[ $key ] ) && is_scalar( self::$old[ $key ] ) ? (string) self::$old[ $key ] : $default;
	}

	private static function notices(): string {
		$out = '';
		if ( isset( $_GET['jw_request'] ) && 'received' === $_GET['jw_request'] ) {
			$out .= '<div class="jw-notice jw-notice--ok" role="status"><p>' . esc_html__( 'Thank you. Your request has been received. It is not an order yet: you will receive a reply with a quote, or questions, by email. Nothing has been charged.', 'jewelry-workflows' ) . '</p></div>';
		}
		if ( self::$errors ) {
			$out .= '<div class="jw-notice jw-notice--error" role="alert" tabindex="-1"><ul>';
			foreach ( self::$errors as $e ) {
				$out .= '<li>' . esc_html( $e ) . '</li>';
			}
			$out .= '</ul></div>';
		}
		return $out;
	}

	private static function contact_fields(): string {
		$pref = self::old( 'contact_pref', 'email' );
		ob_start();
		?>
		<div class="jw-field"><label for="jw-name"><?php esc_html_e( 'Name', 'jewelry-workflows' ); ?> <span aria-hidden="true">*</span></label>
			<input id="jw-name" name="jw[name]" type="text" required autocomplete="name" value="<?php echo esc_attr( self::old( 'name' ) ); ?>"></div>
		<div class="jw-field"><label for="jw-email"><?php esc_html_e( 'Email', 'jewelry-workflows' ); ?> <span aria-hidden="true">*</span></label>
			<input id="jw-email" name="jw[email]" type="email" required autocomplete="email" dir="ltr" value="<?php echo esc_attr( self::old( 'email' ) ); ?>"></div>
		<div class="jw-field"><label for="jw-phone"><?php esc_html_e( 'Phone or WhatsApp (optional)', 'jewelry-workflows' ); ?></label>
			<input id="jw-phone" name="jw[phone]" type="tel" autocomplete="tel" dir="ltr" value="<?php echo esc_attr( self::old( 'phone' ) ); ?>"></div>
		<fieldset class="jw-field jw-field--inline"><legend><?php esc_html_e( 'Preferred contact', 'jewelry-workflows' ); ?></legend>
			<?php foreach ( array( 'email' => __( 'Email', 'jewelry-workflows' ), 'whatsapp' => __( 'WhatsApp', 'jewelry-workflows' ), 'phone' => __( 'Phone', 'jewelry-workflows' ) ) as $k => $label ) : ?>
				<label><input type="radio" name="jw[contact_pref]" value="<?php echo esc_attr( $k ); ?>" <?php checked( $pref, $k ); ?>> <?php echo esc_html( $label ); ?></label>
			<?php endforeach; ?>
		</fieldset>
		<?php
		return ob_get_clean();
	}

	private static function delivery_fields( bool $required ): string {
		$delivery = self::old( 'delivery', 'ship' );
		$country  = self::old( 'country' );
		ob_start();
		if ( JW_Settings::get( 'pickup_enabled' ) ) :
			?>
			<fieldset class="jw-field jw-field--inline"><legend><?php esc_html_e( 'Delivery', 'jewelry-workflows' ); ?></legend>
				<label><input type="radio" name="jw[delivery]" value="ship" <?php checked( $delivery, 'ship' ); ?>> <?php esc_html_e( 'Ship to me', 'jewelry-workflows' ); ?></label>
				<label><input type="radio" name="jw[delivery]" value="pickup" <?php checked( $delivery, 'pickup' ); ?>> <?php esc_html_e( 'Pickup', 'jewelry-workflows' ); ?></label>
			</fieldset>
		<?php else : ?>
			<input type="hidden" name="jw[delivery]" value="ship">
		<?php endif; ?>
		<div class="jw-field"><label for="jw-country"><?php esc_html_e( 'Delivery country', 'jewelry-workflows' ); ?><?php echo $required ? ' <span aria-hidden="true">*</span>' : ''; ?></label>
			<select id="jw-country" name="jw[country]" autocomplete="country" <?php echo $required ? 'required' : ''; ?>>
				<option value=""><?php esc_html_e( 'Choose a country', 'jewelry-workflows' ); ?></option>
				<?php foreach ( WC()->countries->get_countries() as $code => $label ) : ?>
					<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $country, $code ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select></div>
		<div class="jw-field-row">
			<div class="jw-field"><label for="jw-city"><?php esc_html_e( 'City', 'jewelry-workflows' ); ?></label>
				<input id="jw-city" name="jw[city]" type="text" autocomplete="address-level2" value="<?php echo esc_attr( self::old( 'city' ) ); ?>"></div>
			<div class="jw-field"><label for="jw-postcode"><?php esc_html_e( 'Postcode', 'jewelry-workflows' ); ?></label>
				<input id="jw-postcode" name="jw[postcode]" type="text" autocomplete="postal-code" dir="ltr" value="<?php echo esc_attr( self::old( 'postcode' ) ); ?>"></div>
		</div>
		<div class="jw-field"><label for="jw-address"><?php esc_html_e( 'Street address', 'jewelry-workflows' ); ?></label>
			<textarea id="jw-address" name="jw[address]" rows="2" autocomplete="street-address"><?php echo esc_textarea( self::old( 'address' ) ); ?></textarea></div>
		<?php
		return ob_get_clean();
	}

	private static function form_end( string $form, string $button ): string {
		ob_start();
		?>
		<div class="jw-hp" aria-hidden="true"><label>Website <input type="text" name="jw_website" tabindex="-1" autocomplete="off"></label></div>
		<input type="hidden" name="jw_form" value="<?php echo esc_attr( $form ); ?>">
		<?php wp_nonce_field( 'jw_form_' . $form, '_jw_nonce' ); ?>
		<button type="submit" class="button jw-button"><?php echo esc_html( $button ); ?></button>
		<?php
		return ob_get_clean();
	}

	/** Product page form for made-to-order pieces. Replaces the add-to-cart form. */
	public static function render_mto_form(): void {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		echo '<form id="jw-request" class="jw-form jw-form--mto" method="post" action="' . esc_url( get_permalink( $product->get_id() ) ) . '#jw-request" novalidate>';
		echo self::notices(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in notices().
		echo '<input type="hidden" name="jw[product_id]" value="' . esc_attr( $product->get_id() ) . '">';
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_variation_attributes() as $attr => $values ) {
				$key = 'attribute_' . sanitize_title( $attr );
				$cur = isset( self::$old['attributes'][ $key ] ) ? (string) self::$old['attributes'][ $key ] : '';
				echo '<div class="jw-field"><label for="jw-' . esc_attr( $key ) . '">' . esc_html( wc_attribute_label( $attr, $product ) ) . ' <span aria-hidden="true">*</span></label>';
				echo '<select id="jw-' . esc_attr( $key ) . '" name="jw[attributes][' . esc_attr( $key ) . ']" required><option value="">' . esc_html__( 'Choose an option', 'woocommerce' ) . '</option>';
				$terms = taxonomy_exists( $attr ) ? wc_get_product_terms( $product->get_id(), $attr, array( 'fields' => 'all' ) ) : array();
				if ( $terms ) {
					foreach ( $terms as $term ) {
						if ( in_array( $term->slug, $values, true ) ) {
							echo '<option value="' . esc_attr( $term->slug ) . '" ' . selected( $cur, $term->slug, false ) . '>' . esc_html( $term->name ) . '</option>';
						}
					}
				} else {
					foreach ( $values as $v ) {
						echo '<option value="' . esc_attr( $v ) . '" ' . selected( $cur, $v, false ) . '>' . esc_html( $v ) . '</option>';
					}
				}
				echo '</select></div>';
			}
		}
		echo '<div class="jw-field jw-field--qty"><label for="jw-qty">' . esc_html__( 'Quantity', 'woocommerce' ) . '</label><input id="jw-qty" name="jw[qty]" type="number" min="1" max="20" value="' . esc_attr( self::old( 'qty', '1' ) ) . '" inputmode="numeric"></div>';
		echo self::delivery_fields( true ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo self::contact_fields(); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div class="jw-field"><label for="jw-brief">' . esc_html__( 'Notes (optional)', 'jewelry-workflows' ) . '</label><textarea id="jw-brief" name="jw[brief]" rows="3">' . esc_textarea( self::old( 'brief' ) ) . '</textarea></div>';
		echo '<p class="jw-form__note">' . esc_html__( 'This sends a request, not a payment. You will receive a quote with delivery cost, the full total, the deposit due now and the balance due later.', 'jewelry-workflows' ) . '</p>';
		echo self::form_end( 'mto', __( 'Send request', 'jewelry-workflows' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</form>';
	}

	/** [jw_commission_form] for bespoke briefs with optional reference photos. */
	public static function commission_form_shortcode(): string {
		$max = (int) JW_Settings::get( 'upload_max_files' );
		$mb  = (int) JW_Settings::get( 'upload_max_mb' );
		ob_start();
		?>
		<form id="jw-request" class="jw-form jw-form--commission" method="post" enctype="multipart/form-data" novalidate>
			<?php echo self::notices(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<div class="jw-field"><label for="jw-brief"><?php esc_html_e( 'Describe the piece you have in mind', 'jewelry-workflows' ); ?> <span aria-hidden="true">*</span></label>
				<textarea id="jw-brief" name="jw[brief]" rows="6" required><?php echo esc_textarea( self::old( 'brief' ) ); ?></textarea></div>
			<div class="jw-field"><label for="jw-prefs"><?php esc_html_e( 'Size and material preferences (optional)', 'jewelry-workflows' ); ?></label>
				<textarea id="jw-prefs" name="jw[prefs]" rows="2"><?php echo esc_textarea( self::old( 'prefs' ) ); ?></textarea></div>
			<div class="jw-field"><label for="jw-photos"><?php esc_html_e( 'Reference photos (optional)', 'jewelry-workflows' ); ?></label>
				<input id="jw-photos" name="jw_photos[]" type="file" accept="image/jpeg,image/png,image/webp" multiple aria-describedby="jw-photos-help">
				<p id="jw-photos-help" class="jw-help">
					<?php /* translators: 1: number of photos, 2: megabytes */ echo esc_html( sprintf( __( 'Up to %1$d photos, JPEG, PNG or WebP, %2$d MB each. Photos are private and seen only by the maker.', 'jewelry-workflows' ), $max, $mb ) ); ?>
				</p></div>
			<div class="jw-field"><label for="jw-date"><?php esc_html_e( 'Desired date (optional)', 'jewelry-workflows' ); ?></label>
				<input id="jw-date" name="jw[desired_date]" type="date" value="<?php echo esc_attr( self::old( 'desired_date' ) ); ?>" aria-describedby="jw-date-help">
				<p id="jw-date-help" class="jw-help"><?php esc_html_e( 'A wish, not a promise. Timing is confirmed in the quote.', 'jewelry-workflows' ); ?></p></div>
			<?php echo self::delivery_fields( false ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php echo self::contact_fields(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<p class="jw-form__note"><?php esc_html_e( 'Sending this form does not create an order or a commitment. You will receive a reply to discuss the piece, then a quote.', 'jewelry-workflows' ); ?></p>
			<?php echo self::form_end( 'commission', __( 'Send commission request', 'jewelry-workflows' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</form>
		<?php
		return ob_get_clean();
	}
}
