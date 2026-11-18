<?php
/**
 * Owner workspace in WordPress admin: Atelier menu (overview, requests, record
 * a request, settings), a workflow panel on quote orders, and translation
 * fields on products, pages and terms.
 */
defined( 'ABSPATH' ) || exit;

class JW_Admin {
	const CAP = 'edit_shop_orders';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'save_post', array( __CLASS__, 'save_translations' ), 10, 2 );
		add_action( 'save_post_' . JW_Requests::CPT, array( __CLASS__, 'save_request_state' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		foreach ( array( 'create_quote', 'save_quote', 'send_quote', 'cancel_quote', 'production', 'mark_ready', 'retry_email', 'record_request', 'settings' ) as $a ) {
			add_action( 'admin_post_jw_' . $a, array( __CLASS__, 'action_' . $a ) );
		}
		add_action( 'admin_init', array( __CLASS__, 'term_fields' ) );
		add_filter( 'manage_' . JW_Requests::CPT . '_posts_columns', array( __CLASS__, 'request_columns' ) );
		add_action( 'manage_' . JW_Requests::CPT . '_posts_custom_column', array( __CLASS__, 'request_column' ), 10, 2 );
	}

	public static function assets(): void {
		wp_add_inline_style( 'common', '.jw-box table{width:100%;border-collapse:collapse}.jw-box td,.jw-box th{padding:4px 6px;text-align:start;border-bottom:1px solid #eee;vertical-align:top}.jw-box .jw-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px 16px}.jw-box label{display:block;font-weight:600;margin-top:6px}.jw-box input[type=text],.jw-box input[type=number],.jw-box textarea{width:100%}.jw-warn{color:#b32d2e;font-weight:600}.jw-pill{display:inline-block;padding:1px 8px;border-radius:9px;background:#f0f0f1}.jw-copy{width:100%;font-family:monospace;font-size:11px}' );
	}

	// ----- Menu pages -----

	public static function menu(): void {
		add_menu_page( __( 'Atelier', 'jewelry-workflows' ), __( 'Atelier', 'jewelry-workflows' ), self::CAP, 'jw-atelier', array( __CLASS__, 'page_overview' ), 'dashicons-admin-customizer', 56 );
		add_submenu_page( 'jw-atelier', __( 'Overview', 'jewelry-workflows' ), __( 'Overview', 'jewelry-workflows' ), self::CAP, 'jw-atelier', array( __CLASS__, 'page_overview' ) );
		add_submenu_page( 'jw-atelier', __( 'Record a request', 'jewelry-workflows' ), __( 'Record a request', 'jewelry-workflows' ), self::CAP, 'jw-record', array( __CLASS__, 'page_record' ) );
		add_submenu_page( 'jw-atelier', __( 'Settings', 'jewelry-workflows' ), __( 'Settings', 'jewelry-workflows' ), 'manage_woocommerce', 'jw-settings', array( __CLASS__, 'page_settings' ) );
		add_submenu_page( 'options.php', __( 'Quote', 'jewelry-workflows' ), __( 'Quote', 'jewelry-workflows' ), self::CAP, 'jw-quote', array( __CLASS__, 'page_quote' ) );
	}

	private static function post_url( string $action, array $args = array() ): string {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'jw_' . $action ), $args ), admin_url( 'admin-post.php' ) ), 'jw_' . $action );
	}

	private static function guard( string $action, string $cap = self::CAP ): void {
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'jewelry-workflows' ), 403 );
		}
		check_admin_referer( 'jw_' . $action );
	}

	private static function back( string $url, string $notice, bool $error = false ): void {
		set_transient( 'jw_admin_notice_' . get_current_user_id(), ( $error ? '!' : '' ) . $notice, 120 );
		wp_safe_redirect( $url );
		exit;
	}

	public static function notices(): void {
		$key = 'jw_admin_notice_' . get_current_user_id();
		$msg = get_transient( $key );
		if ( $msg ) {
			delete_transient( $key );
			$error = str_starts_with( $msg, '!' );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $error ? 'error' : 'success', esc_html( ltrim( $msg, '!' ) ) );
		}
		$screen = get_current_screen();
		if ( $screen && in_array( $screen->id, array( 'toplevel_page_jw-atelier', 'atelier_page_jw-settings' ), true ) && ! JW_Settings::get( 'mto_deposit_confirmed' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Made-to-order deposit basis is not confirmed. Development uses the demo assumption "50% of the full quoted total". Live made-to-order deposits are disabled on a production site until you confirm the basis in Settings.', 'jewelry-workflows' ) . '</p></div>';
		}
	}

	/** @return WC_Order[] */
	private static function quote_orders( array $statuses ): array {
		return JW_Payments::find_orders( $statuses, '_jw_kind', 'quote' );
	}

	public static function page_overview(): void {
		$requests = get_posts(
			array(
				'post_type'   => JW_Requests::CPT,
				'numberposts' => 50,
				'meta_query'  => array( array( 'key' => '_jw_state', 'value' => array( 'new', 'needs_clarification' ), 'compare' => 'IN' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		$open        = self::quote_orders( array( 'wc-' . JW_Payments::STATUS ) );
		$to_ship     = self::quote_orders( array( 'wc-processing' ) );
		$reconcile   = JW_Payments::find_orders( array_keys( wc_get_order_statuses() ), '_jw_needs_reconciliation' );
		$groups      = array( 'sent' => array(), 'production' => array(), 'balance' => array(), 'other' => array() );
		foreach ( $open as $o ) {
			$state = JW_Quotes::state( $o );
			if ( in_array( $state, array( 'draft', 'sent', 'expired', 'declined' ), true ) ) {
				$groups['sent'][] = $o;
			} elseif ( 'accepted' === $state && 'ready' === $o->get_meta( '_jw_production' ) ) {
				$groups['balance'][] = $o;
			} elseif ( 'accepted' === $state ) {
				$groups['production'][] = $o;
			} else {
				$groups['other'][] = $o;
			}
		}
		echo '<div class="wrap jw-box"><h1>' . esc_html__( 'Atelier', 'jewelry-workflows' ) . '</h1>';
		echo '<p><a class="button" href="' . esc_url( admin_url( 'edit.php?post_type=' . JW_Requests::CPT ) ) . '">' . esc_html__( 'All requests', 'jewelry-workflows' ) . '</a> <a class="button" href="' . esc_url( admin_url( 'admin.php?page=jw-record' ) ) . '">' . esc_html__( 'Record a request', 'jewelry-workflows' ) . '</a> <a class="button" href="' . esc_url( admin_url( 'edit.php?post_type=product' ) ) . '">' . esc_html__( 'Products', 'jewelry-workflows' ) . '</a> <a class="button" href="' . esc_url( admin_url( 'admin.php?page=wc-orders' ) ) . '">' . esc_html__( 'All orders', 'jewelry-workflows' ) . '</a></p>';

		if ( $reconcile ) {
			echo '<h2 class="jw-warn">' . esc_html__( 'Needs attention: payment received after cancellation', 'jewelry-workflows' ) . '</h2>';
			self::order_table( $reconcile );
		}
		echo '<h2>' . esc_html__( 'New requests', 'jewelry-workflows' ) . '</h2>';
		if ( ! $requests ) {
			echo '<p>' . esc_html__( 'None.', 'jewelry-workflows' ) . '</p>';
		} else {
			echo '<table><thead><tr><th>' . esc_html__( 'Request', 'jewelry-workflows' ) . '</th><th>' . esc_html__( 'Type', 'jewelry-workflows' ) . '</th><th>' . esc_html__( 'State', 'jewelry-workflows' ) . '</th><th>' . esc_html__( 'Received', 'jewelry-workflows' ) . '</th></tr></thead><tbody>';
			foreach ( $requests as $r ) {
				printf(
					'<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td>%s</td></tr>',
					esc_url( get_edit_post_link( $r->ID ) ),
					esc_html( $r->post_title ),
					esc_html( 'made_to_order' === get_post_meta( $r->ID, '_jw_type', true ) ? __( 'Made to order', 'jewelry-workflows' ) : __( 'Bespoke', 'jewelry-workflows' ) ),
					esc_html( JW_Requests::state_label( (string) get_post_meta( $r->ID, '_jw_state', true ) ) ),
					esc_html( get_the_date( '', $r ) )
				);
			}
			echo '</tbody></table>';
		}
		$titles = array(
			'sent'       => __( 'Quotes waiting for the customer', 'jewelry-workflows' ),
			'production' => __( 'Accepted: in production', 'jewelry-workflows' ),
			'balance'    => __( 'Ready: balance due', 'jewelry-workflows' ),
		);
		foreach ( $titles as $k => $title ) {
			echo '<h2>' . esc_html( $title ) . '</h2>';
			$groups[ $k ] ? self::order_table( $groups[ $k ] ) : print( '<p>' . esc_html__( 'None.', 'jewelry-workflows' ) . '</p>' );
		}
		echo '<h2>' . esc_html__( 'Paid in full: ship or hand over', 'jewelry-workflows' ) . '</h2>';
		$to_ship ? self::order_table( $to_ship ) : print( '<p>' . esc_html__( 'None.', 'jewelry-workflows' ) . '</p>' );
		echo '<p class="description">' . esc_html__( 'Ready-to-ship orders from the normal checkout are in WooCommerce > Orders.', 'jewelry-workflows' ) . '</p></div>';
	}

	private static function order_table( array $orders ): void {
		echo '<table><thead><tr><th>' . esc_html__( 'Order', 'jewelry-workflows' ) . '</th><th>' . esc_html__( 'Customer', 'jewelry-workflows' ) . '</th><th>' . esc_html__( 'Quote', 'jewelry-workflows' ) . '</th><th>' . esc_html__( 'Production', 'jewelry-workflows' ) . '</th><th>' . esc_html__( 'Payment', 'jewelry-workflows' ) . '</th><th>' . esc_html__( 'Outstanding', 'jewelry-workflows' ) . '</th></tr></thead><tbody>';
		foreach ( $orders as $o ) {
			$is_q  = JW_Quotes::is_quote( $o );
			$a     = $is_q ? JW_Payments::amounts( $o ) : null;
			$email = '';
			if ( $is_q && ( $req = JW_Payments::open_request( $o, 'balance' ) ) && 'failed' === $req->get_meta( '_jw_email_status' ) ) {
				$email = ' <span class="jw-warn">' . esc_html__( 'email failed', 'jewelry-workflows' ) . '</span>';
			}
			printf(
				'<tr><td><a href="%s">#%d</a></td><td>%s</td><td>%s</td><td>%s</td><td>%s%s</td><td>%s</td></tr>',
				esc_url( $o->get_edit_order_url() ),
				(int) $o->get_id(),
				esc_html( $o->get_formatted_billing_full_name() ),
				esc_html( $is_q ? JW_Quotes::state_label( JW_Quotes::state( $o ) ) . ' v' . (int) $o->get_meta( '_jw_quote_version' ) : wc_get_order_status_name( $o->get_status() ) ),
				esc_html( $is_q ? JW_Payments::production_label( (string) $o->get_meta( '_jw_production' ) ) : '' ),
				esc_html( $is_q ? JW_Payments::payment_state_label( JW_Payments::payment_state( $o ) ) : '' ),
				$email, // phpcs:ignore WordPress.Security.EscapeOutput -- built above with esc_html.
				$a ? wp_kses_post( wc_price( $a['balance'], array( 'currency' => $o->get_currency() ) ) ) : ''
			);
		}
		echo '</tbody></table>';
	}

	public static function page_record(): void {
		echo '<div class="wrap jw-box"><h1>' . esc_html__( 'Record a request', 'jewelry-workflows' ) . '</h1>';
		echo '<p>' . esc_html__( 'For requests that arrived by email, WhatsApp, phone or in person. Conversations in those channels are not synchronized automatically: copy the relevant details here.', 'jewelry-workflows' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'jw_record_request' );
		echo '<input type="hidden" name="action" value="jw_record_request"><div class="jw-grid">';
		self::select( 'type', __( 'Type', 'jewelry-workflows' ), array( 'bespoke' => __( 'Bespoke', 'jewelry-workflows' ), 'made_to_order' => __( 'Made to order (existing product)', 'jewelry-workflows' ) ) );
		self::select( 'channel', __( 'Channel', 'jewelry-workflows' ), array( 'email' => 'Email', 'whatsapp' => 'WhatsApp', 'phone' => __( 'Phone', 'jewelry-workflows' ), 'in_person' => __( 'In person', 'jewelry-workflows' ) ) );
		self::select( 'lang', __( 'Customer language', 'jewelry-workflows' ), wp_list_pluck( JW_I18n::LANGS, 'name' ) );
		foreach ( array( 'name' => __( 'Name', 'jewelry-workflows' ), 'email' => __( 'Email', 'jewelry-workflows' ), 'phone' => __( 'Phone / WhatsApp', 'jewelry-workflows' ), 'country' => __( 'Country code (for example IL)', 'jewelry-workflows' ), 'city' => __( 'City', 'jewelry-workflows' ), 'postcode' => __( 'Postcode', 'jewelry-workflows' ), 'product_id' => __( 'Product ID (made to order)', 'jewelry-workflows' ), 'qty' => __( 'Quantity', 'jewelry-workflows' ) ) as $k => $label ) {
			echo '<div><label for="jw-' . esc_attr( $k ) . '">' . esc_html( $label ) . '</label><input type="text" id="jw-' . esc_attr( $k ) . '" name="' . esc_attr( $k ) . '"></div>';
		}
		self::select( 'delivery', __( 'Delivery', 'jewelry-workflows' ), array( 'ship' => __( 'Ship', 'jewelry-workflows' ), 'pickup' => __( 'Pickup', 'jewelry-workflows' ) ) );
		echo '</div><label for="jw-address">' . esc_html__( 'Street address', 'jewelry-workflows' ) . '</label><textarea id="jw-address" name="address" rows="2"></textarea>';
		echo '<label for="jw-brief">' . esc_html__( 'Brief / conversation summary', 'jewelry-workflows' ) . '</label><textarea id="jw-brief" name="brief" rows="5"></textarea>';
		echo '<p class="description">' . esc_html__( 'For a made-to-order variation, record the request and choose the options in the quote, or use the product page form on the customer\'s behalf.', 'jewelry-workflows' ) . '</p>';
		submit_button( __( 'Save request', 'jewelry-workflows' ) );
		echo '</form></div>';
	}

	private static function select( string $name, string $label, array $options, string $current = '' ): void {
		echo '<div><label for="jw-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label><select id="jw-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">';
		foreach ( $options as $k => $v ) {
			echo '<option value="' . esc_attr( $k ) . '" ' . selected( $current, $k, false ) . '>' . esc_html( $v ) . '</option>';
		}
		echo '</select></div>';
	}

	public static function page_settings(): void {
		$s = JW_Settings::all();
		echo '<div class="wrap jw-box"><h1>' . esc_html__( 'Atelier settings', 'jewelry-workflows' ) . '</h1><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'jw_settings' );
		echo '<input type="hidden" name="action" value="jw_settings"><h2>' . esc_html__( 'Made-to-order deposit', 'jewelry-workflows' ) . '</h2><div class="jw-grid">';
		echo '<div><label for="jw-pct">' . esc_html__( 'Deposit percentage', 'jewelry-workflows' ) . '</label><input type="number" id="jw-pct" name="mto_deposit_percent" min="0" max="100" value="' . esc_attr( $s['mto_deposit_percent'] ) . '"></div>';
		self::select( 'mto_deposit_basis', __( 'Percentage of', 'jewelry-workflows' ), array( 'total' => __( 'Full quoted total (items + delivery)', 'jewelry-workflows' ), 'merchandise' => __( 'Items only (without delivery)', 'jewelry-workflows' ) ), $s['mto_deposit_basis'] );
		echo '<div><label><input type="checkbox" name="mto_deposit_confirmed" value="1" ' . checked( $s['mto_deposit_confirmed'], 1, false ) . '> ' . esc_html__( 'I confirm this basis for live deposits', 'jewelry-workflows' ) . '</label></div></div>';
		echo '<p class="description">' . esc_html__( 'Bespoke quotes never use this rule: each bespoke quote has its own deposit amount.', 'jewelry-workflows' ) . '</p>';
		echo '<h2>' . esc_html__( 'Quotes, languages and contact', 'jewelry-workflows' ) . '</h2><div class="jw-grid">';
		echo '<div><label for="jw-valid">' . esc_html__( 'Quote valid for (days)', 'jewelry-workflows' ) . '</label><input type="number" id="jw-valid" name="quote_valid_days" min="1" value="' . esc_attr( $s['quote_valid_days'] ) . '"></div>';
		self::select( 'default_lang', __( 'Default language (no URL prefix)', 'jewelry-workflows' ), wp_list_pluck( JW_I18n::LANGS, 'name' ), $s['default_lang'] );
		echo '<div><label for="jw-ce">' . esc_html__( 'Contact email', 'jewelry-workflows' ) . '</label><input type="text" id="jw-ce" name="contact_email" value="' . esc_attr( $s['contact_email'] ) . '"></div>';
		echo '<div><label for="jw-wa">' . esc_html__( 'WhatsApp number (digits, with country code)', 'jewelry-workflows' ) . '</label><input type="text" id="jw-wa" name="contact_whatsapp" value="' . esc_attr( $s['contact_whatsapp'] ) . '"></div></div>';
		echo '<p class="description">' . esc_html__( 'The default language is the language of the normal WordPress title and text fields. Choose it before you write content.', 'jewelry-workflows' ) . '</p>';
		$attr_labels = (array) get_option( 'jw_attribute_labels', array() );
		if ( wc_get_attribute_taxonomies() ) {
			echo '<h2>' . esc_html__( 'Product option names in other languages', 'jewelry-workflows' ) . '</h2><div class="jw-grid">';
			foreach ( wc_get_attribute_taxonomies() as $attr ) {
				foreach ( JW_I18n::LANGS as $code => $info ) {
					if ( $code === JW_I18n::default_lang() ) {
						continue;
					}
					$id = 'jw-attr-' . $attr->attribute_name . '-' . $code;
					echo '<div><label for="' . esc_attr( $id ) . '">' . esc_html( $attr->attribute_label . ' (' . $info['name'] . ')' ) . '</label><input type="text" dir="' . esc_attr( $info['dir'] ) . '" id="' . esc_attr( $id ) . '" name="jw_attr[' . esc_attr( $attr->attribute_name ) . '][' . esc_attr( $code ) . ']" value="' . esc_attr( $attr_labels[ $attr->attribute_name ][ $code ] ?? '' ) . '"></div>';
				}
			}
			echo '</div><p class="description">' . esc_html__( 'Option values (sizes, materials) are translated on each term: Products > Attributes > Configure terms.', 'jewelry-workflows' ) . '</p>';
		}
		echo '<h2>' . esc_html__( 'Commission form', 'jewelry-workflows' ) . '</h2><div class="jw-grid">';
		echo '<div><label for="jw-mf">' . esc_html__( 'Max photos', 'jewelry-workflows' ) . '</label><input type="number" id="jw-mf" name="upload_max_files" min="0" value="' . esc_attr( $s['upload_max_files'] ) . '"></div>';
		echo '<div><label for="jw-mb">' . esc_html__( 'Max MB per photo', 'jewelry-workflows' ) . '</label><input type="number" id="jw-mb" name="upload_max_mb" min="1" value="' . esc_attr( $s['upload_max_mb'] ) . '"></div>';
		echo '<div><label for="jw-rl">' . esc_html__( 'Max submissions per hour per visitor', 'jewelry-workflows' ) . '</label><input type="number" id="jw-rl" name="form_limit_per_hour" min="1" value="' . esc_attr( $s['form_limit_per_hour'] ) . '"></div></div>';
		echo '<h2>' . esc_html__( 'Pickup', 'jewelry-workflows' ) . '</h2>';
		echo '<label><input type="checkbox" name="pickup_enabled" value="1" ' . checked( $s['pickup_enabled'], 1, false ) . '> ' . esc_html__( 'Offer pickup on request forms', 'jewelry-workflows' ) . '</label>';
		echo '<label for="jw-pd">' . esc_html__( 'Pickup location and arrangements', 'jewelry-workflows' ) . '</label><textarea id="jw-pd" name="pickup_details" rows="3">' . esc_textarea( $s['pickup_details'] ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Stock hold time and the visibility of sold pieces are WooCommerce settings: WooCommerce > Settings > Products > Inventory ("Hold stock" and "Out of stock visibility"). Pickup at checkout for ready-stock items is a WooCommerce shipping method (Local pickup).', 'jewelry-workflows' ) . '</p>';
		submit_button();
		echo '</form></div>';
	}

	// ----- admin-post actions -----

	public static function action_settings(): void {
		self::guard( 'settings', 'manage_woocommerce' );
		$in                          = wp_unslash( $_POST );
		$in['mto_deposit_confirmed'] = empty( $in['mto_deposit_confirmed'] ) ? 0 : 1;
		$in['pickup_enabled']        = empty( $in['pickup_enabled'] ) ? 0 : 1;
		if ( $in['pickup_enabled'] && '' === trim( (string) ( $in['pickup_details'] ?? '' ) ) ) {
			$in['pickup_enabled'] = 0;
			JW_Settings::update( $in );
			self::back( admin_url( 'admin.php?page=jw-settings' ), __( 'Saved, but pickup stays off until you enter the pickup location and arrangements.', 'jewelry-workflows' ), true );
		}
		$labels = array();
		foreach ( (array) ( $in['jw_attr'] ?? array() ) as $slug => $by_lang ) {
			foreach ( (array) $by_lang as $code => $label ) {
				if ( isset( JW_I18n::LANGS[ $code ] ) && '' !== trim( $label ) ) {
					$labels[ sanitize_title( $slug ) ][ $code ] = sanitize_text_field( $label );
				}
			}
		}
		update_option( 'jw_attribute_labels', $labels );
		JW_Settings::update( $in );
		self::back( admin_url( 'admin.php?page=jw-settings' ), __( 'Settings saved.', 'jewelry-workflows' ) );
	}

	public static function action_record_request(): void {
		self::guard( 'record_request' );
		$d           = wp_unslash( $_POST );
		$d['source'] = 'manual';
		$id          = JW_Requests::create( $d );
		if ( is_wp_error( $id ) ) {
			self::back( admin_url( 'admin.php?page=jw-record' ), $id->get_error_message(), true );
		}
		self::back( get_edit_post_link( $id, 'raw' ), __( 'Request recorded.', 'jewelry-workflows' ) );
	}

	private static function quote_input(): array {
		$in = wp_unslash( $_POST );
		return array_intersect_key( $in, array_flip( array( 'unit_price', 'qty', 'description', 'price', 'shipping', 'shipping_label', 'scope', 'lead_time', 'deposit', 'valid_days' ) ) );
	}

	public static function action_create_quote(): void {
		self::guard( 'create_quote' );
		$rid = absint( $_POST['request_id'] ?? 0 );
		if ( get_post_meta( $rid, '_jw_order_id', true ) ) {
			self::back( get_edit_post_link( $rid, 'raw' ), __( 'This request already has a quote.', 'jewelry-workflows' ), true );
		}
		$order = JW_Quotes::save_quote( $rid, self::quote_input() );
		if ( is_wp_error( $order ) ) {
			self::back( get_edit_post_link( $rid, 'raw' ), $order->get_error_message(), true );
		}
		self::back( $order->get_edit_order_url(), __( 'Quote saved as draft. Check it, then send it to the customer.', 'jewelry-workflows' ) );
	}

	private static function order_from_request(): WC_Order {
		$order = wc_get_order( absint( $_REQUEST['order_id'] ?? 0 ) );
		if ( ! JW_Quotes::is_quote( $order ) ) {
			wp_die( esc_html__( 'Quote order not found.', 'jewelry-workflows' ), 404 );
		}
		return $order;
	}

	public static function action_save_quote(): void {
		self::guard( 'save_quote' );
		$order = self::order_from_request();
		$res   = JW_Quotes::save_quote( (int) $order->get_meta( '_jw_request_id' ), self::quote_input(), $order );
		is_wp_error( $res ) ? self::back( $order->get_edit_order_url(), $res->get_error_message(), true ) : self::back( $order->get_edit_order_url(), __( 'Quote revised. Send it to issue a new link; the old link no longer works.', 'jewelry-workflows' ) );
	}

	public static function action_send_quote(): void {
		self::guard( 'send_quote' );
		$order = self::order_from_request();
		$res   = JW_Quotes::send( $order );
		is_wp_error( $res ) ? self::back( $order->get_edit_order_url(), $res->get_error_message(), true ) : self::back( $order->get_edit_order_url(), __( 'Quote sent. See the order notes for the email result.', 'jewelry-workflows' ) );
	}

	public static function action_cancel_quote(): void {
		self::guard( 'cancel_quote' );
		$order = self::order_from_request();
		$res   = JW_Quotes::cancel( $order, 'Cancelled by the owner.' );
		is_wp_error( $res ) ? self::back( $order->get_edit_order_url(), $res->get_error_message(), true ) : self::back( $order->get_edit_order_url(), __( 'Quote cancelled. Its payment links no longer work.', 'jewelry-workflows' ) );
	}

	public static function action_production(): void {
		self::guard( 'production' );
		$order = self::order_from_request();
		$state = sanitize_key( $_REQUEST['state'] ?? '' );
		if ( 'ready' === $state ) {
			self::back( $order->get_edit_order_url(), 'Use "Mark ready and request balance".', true );
		}
		JW_Payments::set_production( $order, $state );
		self::back( $order->get_edit_order_url(), __( 'Production status updated.', 'jewelry-workflows' ) );
	}

	public static function action_mark_ready(): void {
		self::guard( 'mark_ready' );
		$order = self::order_from_request();
		$res   = JW_Payments::mark_ready( $order );
		if ( is_wp_error( $res ) ) {
			self::back( $order->get_edit_order_url(), $res->get_error_message(), true );
		}
		$msgs = array(
			'sent'    => __( 'Marked ready. The balance request email was handed to the mail system.', 'jewelry-workflows' ),
			'failed'  => __( 'Marked ready, but the balance email FAILED. Use "Retry email" or copy the payment link.', 'jewelry-workflows' ),
			'skipped' => __( 'Already ready. The existing balance request was kept and no second email was sent. Use "Retry email" to send it again.', 'jewelry-workflows' ),
			'none'    => __( 'Marked ready. Nothing is outstanding.', 'jewelry-workflows' ),
		);
		self::back( $order->get_edit_order_url(), $msgs[ $res['email'] ], 'failed' === $res['email'] );
	}

	public static function action_retry_email(): void {
		self::guard( 'retry_email' );
		$order = self::order_from_request();
		$req   = JW_Payments::open_request( $order, 'balance' );
		if ( ! $req ) {
			self::back( $order->get_edit_order_url(), __( 'There is no open balance request.', 'jewelry-workflows' ), true );
		}
		$ok = JW_Payments::send_balance_email( $req );
		self::back( $order->get_edit_order_url(), $ok ? __( 'Email handed to the mail system.', 'jewelry-workflows' ) : __( 'Email FAILED again. Copy the payment link and send it yourself.', 'jewelry-workflows' ), ! $ok );
	}

	// ----- Request screen -----

	public static function meta_boxes(): void {
		add_meta_box( 'jw-request-details', __( 'Request', 'jewelry-workflows' ), array( __CLASS__, 'box_request' ), JW_Requests::CPT, 'normal', 'high' );
		add_meta_box( 'jw-request-quote', __( 'Quote', 'jewelry-workflows' ), array( __CLASS__, 'box_request_quote' ), JW_Requests::CPT, 'normal', 'default' );
		add_meta_box( 'jw-request-log', __( 'Timeline', 'jewelry-workflows' ), array( __CLASS__, 'box_request_log' ), JW_Requests::CPT, 'side', 'default' );
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'jw-workflow', __( 'Commission workflow', 'jewelry-workflows' ), array( __CLASS__, 'box_order' ), $screen, 'normal', 'high' );
		foreach ( array( 'product', 'page', 'post' ) as $type ) {
			add_meta_box( 'jw-translations', __( 'Translations', 'jewelry-workflows' ), array( __CLASS__, 'box_translations' ), $type, 'normal', 'default' );
		}
	}

	public static function request_columns( $cols ): array {
		return array(
			'cb'       => $cols['cb'] ?? '',
			'title'    => __( 'Request', 'jewelry-workflows' ),
			'jw_type'  => __( 'Type', 'jewelry-workflows' ),
			'jw_state' => __( 'State', 'jewelry-workflows' ),
			'jw_order' => __( 'Quote order', 'jewelry-workflows' ),
			'date'     => $cols['date'] ?? __( 'Date', 'jewelry-workflows' ),
		);
	}

	public static function request_column( $col, $id ): void {
		if ( 'jw_type' === $col ) {
			echo esc_html( 'made_to_order' === get_post_meta( $id, '_jw_type', true ) ? __( 'Made to order', 'jewelry-workflows' ) : __( 'Bespoke', 'jewelry-workflows' ) );
		} elseif ( 'jw_state' === $col ) {
			echo esc_html( JW_Requests::state_label( (string) get_post_meta( $id, '_jw_state', true ) ) );
		} elseif ( 'jw_order' === $col && ( $oid = (int) get_post_meta( $id, '_jw_order_id', true ) ) && ( $o = wc_get_order( $oid ) ) ) {
			echo '<a href="' . esc_url( $o->get_edit_order_url() ) . '">#' . (int) $oid . '</a>';
		}
	}

	public static function box_request( WP_Post $post ): void {
		$m    = static fn( $k ) => get_post_meta( $post->ID, $k, true );
		$rows = array(
			__( 'Type', 'jewelry-workflows' )           => 'made_to_order' === $m( '_jw_type' ) ? __( 'Made to order', 'jewelry-workflows' ) : __( 'Bespoke', 'jewelry-workflows' ),
			__( 'Source', 'jewelry-workflows' )         => 'manual' === $m( '_jw_source' ) ? __( 'Recorded manually', 'jewelry-workflows' ) . ' (' . $m( '_jw_channel' ) . ')' : __( 'Website form', 'jewelry-workflows' ),
			__( 'Name', 'jewelry-workflows' )           => $m( '_jw_name' ),
			__( 'Email', 'jewelry-workflows' )          => $m( '_jw_email' ),
			__( 'Phone', 'jewelry-workflows' )          => $m( '_jw_phone' ),
			__( 'Preferred contact', 'jewelry-workflows' ) => $m( '_jw_contact_pref' ),
			__( 'Language', 'jewelry-workflows' )       => JW_I18n::LANGS[ $m( '_jw_lang' ) ]['name'] ?? $m( '_jw_lang' ),
			__( 'Delivery', 'jewelry-workflows' )       => 'pickup' === $m( '_jw_delivery' ) ? __( 'Pickup', 'jewelry-workflows' ) : trim( implode( ', ', array_filter( array( $m( '_jw_address' ), $m( '_jw_city' ), $m( '_jw_postcode' ), $m( '_jw_country' ) ) ) ) ),
			__( 'Desired date', 'jewelry-workflows' )   => $m( '_jw_desired_date' ),
		);
		if ( $m( '_jw_product_id' ) ) {
			$p = wc_get_product( (int) ( $m( '_jw_variation_id' ) ?: $m( '_jw_product_id' ) ) );
			$rows[ __( 'Product', 'jewelry-workflows' ) ]  = $p ? $p->get_name() . ' (#' . $p->get_id() . ')' : __( '(deleted product)', 'jewelry-workflows' );
			$rows[ __( 'Quantity', 'jewelry-workflows' ) ] = $m( '_jw_qty' );
			$rows[ __( 'Price shown at request time', 'jewelry-workflows' ) ] = wp_strip_all_tags( wc_price( (float) $m( '_jw_price_shown' ) ) );
		}
		echo '<div class="jw-box"><table>';
		foreach ( $rows as $k => $v ) {
			if ( '' !== (string) $v ) {
				echo '<tr><th>' . esc_html( $k ) . '</th><td>' . esc_html( (string) $v ) . '</td></tr>';
			}
		}
		foreach ( array( '_jw_brief' => __( 'Brief / notes', 'jewelry-workflows' ), '_jw_prefs' => __( 'Size and material preferences', 'jewelry-workflows' ) ) as $k => $label ) {
			if ( $m( $k ) ) {
				echo '<tr><th>' . esc_html( $label ) . '</th><td>' . nl2br( esc_html( $m( $k ) ) ) . '</td></tr>';
			}
		}
		$files = $m( '_jw_attachments' );
		if ( is_array( $files ) && $files ) {
			echo '<tr><th>' . esc_html__( 'Reference photos (private)', 'jewelry-workflows' ) . '</th><td>';
			foreach ( $files as $i => $f ) {
				echo '<a href="' . esc_url( JW_Uploads::view_url( $post->ID, (int) $i ) ) . '" target="_blank" rel="noopener">' . esc_html( $f['name'] ?: 'photo ' . ( $i + 1 ) ) . '</a><br>';
			}
			echo '</td></tr>';
		}
		echo '</table>';
		wp_nonce_field( 'jw_request_state', '_jw_state_nonce' );
		self::select( '_jw_state', __( 'Request state', 'jewelry-workflows' ), array_combine( array_keys( JW_Requests::STATES ), array_map( array( 'JW_Requests', 'state_label' ), array_keys( JW_Requests::STATES ) ) ), (string) $m( '_jw_state' ) );
		$contact = array();
		if ( $m( '_jw_email' ) ) {
			$contact[] = '<a class="button" href="mailto:' . esc_attr( $m( '_jw_email' ) ) . '">' . esc_html__( 'Email customer', 'jewelry-workflows' ) . '</a>';
		}
		$digits = preg_replace( '/\D+/', '', (string) $m( '_jw_phone' ) );
		if ( $digits ) {
			$contact[] = '<a class="button" target="_blank" rel="noopener" href="https://wa.me/' . esc_attr( $digits ) . '">' . esc_html__( 'Open WhatsApp', 'jewelry-workflows' ) . '</a>';
		}
		echo '<p>' . implode( ' ', $contact ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public static function save_request_state( $post_id ): void {
		if ( ! isset( $_POST['_jw_state_nonce'] ) || ! wp_verify_nonce( $_POST['_jw_state_nonce'], 'jw_request_state' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$state = sanitize_key( $_POST['_jw_state'] ?? '' );
		if ( $state && $state !== get_post_meta( $post_id, '_jw_state', true ) ) {
			JW_Requests::set_state( (int) $post_id, $state, 'set by owner' );
		}
	}

	public static function box_request_log( WP_Post $post ): void {
		$log = get_post_meta( $post->ID, '_jw_log', true );
		$log = array_reverse( is_array( $log ) ? $log : array() );
		echo '<ul>';
		foreach ( array_filter( $log ) as $entry ) {
			echo '<li><small>' . esc_html( wp_date( 'Y-m-d H:i', $entry['time'] ) ) . '</small><br>' . esc_html( $entry['msg'] ) . '</li>';
		}
		echo '</ul>';
	}

	public static function box_request_quote( WP_Post $post ): void {
		$oid = (int) get_post_meta( $post->ID, '_jw_order_id', true );
		if ( $oid && ( $o = wc_get_order( $oid ) ) ) {
			echo '<p><a class="button button-primary" href="' . esc_url( $o->get_edit_order_url() ) . '">' . esc_html( sprintf( /* translators: %d: order id */ __( 'Open quote order #%d', 'jewelry-workflows' ), $oid ) ) . '</a></p>';
			return;
		}
		echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=jw-quote&request_id=' . (int) $post->ID ) ) . '">' . esc_html__( 'Prepare quote', 'jewelry-workflows' ) . '</a></p>';
	}

	/** Quote editor page (own form; metaboxes sit inside other forms). */
	public static function page_quote(): void {
		$order = isset( $_GET['order_id'] ) ? wc_get_order( absint( $_GET['order_id'] ) ) : null;
		$rid   = $order ? (int) $order->get_meta( '_jw_request_id' ) : absint( $_GET['request_id'] ?? 0 );
		if ( ! $rid || JW_Requests::CPT !== get_post_type( $rid ) ) {
			wp_die( esc_html__( 'Request not found.', 'jewelry-workflows' ), 404 );
		}
		$action = $order ? 'save_quote' : 'create_quote';
		echo '<div class="wrap jw-box"><h1>' . esc_html( $order ? sprintf( /* translators: %d: order id */ __( 'Revise quote #%d', 'jewelry-workflows' ), $order->get_id() ) : __( 'Prepare quote', 'jewelry-workflows' ) ) . '</h1>';
		echo '<p><a href="' . esc_url( get_edit_post_link( $rid ) ) . '">' . esc_html( get_the_title( $rid ) ) . '</a></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'jw_' . $action );
		echo '<input type="hidden" name="action" value="jw_' . esc_attr( $action ) . '"><input type="hidden" name="request_id" value="' . (int) $rid . '">';
		if ( $order ) {
			echo '<input type="hidden" name="order_id" value="' . (int) $order->get_id() . '">';
			echo '<p class="description">' . esc_html__( 'Saving a revision makes the previous quote link and any unpaid deposit link stop working. Send the quote again afterwards.', 'jewelry-workflows' ) . '</p>';
		}
		self::quote_fields( $rid, $order );
		submit_button( $order ? __( 'Save revision', 'jewelry-workflows' ) : __( 'Save quote as draft', 'jewelry-workflows' ) );
		echo '</form></div>';
	}

	/** Shared quote input fields (new quote and revision). */
	private static function quote_fields( int $request_id, ?WC_Order $order ): void {
		$type   = get_post_meta( $request_id, '_jw_type', true );
		$pickup = 'pickup' === get_post_meta( $request_id, '_jw_delivery', true );
		$v      = array( 'qty' => get_post_meta( $request_id, '_jw_qty', true ) ?: 1, 'valid_days' => JW_Settings::get( 'quote_valid_days' ) );
		if ( 'made_to_order' === $type ) {
			$p               = wc_get_product( (int) ( get_post_meta( $request_id, '_jw_variation_id', true ) ?: get_post_meta( $request_id, '_jw_product_id', true ) ) );
			$v['unit_price'] = $p ? $p->get_price() : '';
		}
		if ( $order ) {
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				$v['unit_price'] = (float) $item->get_total() / max( 1, $item->get_quantity() );
				$v['qty']        = $item->get_quantity();
			}
			foreach ( $order->get_items( 'fee' ) as $item ) {
				$v['description'] = $item->get_name();
				$v['price']       = $item->get_total();
			}
			foreach ( $order->get_items( 'shipping' ) as $item ) {
				$v['shipping']       = $item->get_total();
				$v['shipping_label'] = $item->get_name();
			}
			$v['scope']      = $order->get_meta( '_jw_scope' );
			$v['lead_time']  = $order->get_meta( '_jw_lead_time' );
			$v['deposit']    = 'bespoke' === $type ? $order->get_meta( '_jw_deposit_amount' ) : '';
			$v['valid_days'] = $order->get_meta( '_jw_valid_days' );
		}
		$field = static function ( string $k, string $label, string $type = 'text', string $help = '' ) use ( $v ) {
			echo '<div><label for="jwq-' . esc_attr( $k ) . '">' . esc_html( $label ) . '</label>';
			if ( 'textarea' === $type ) {
				echo '<textarea id="jwq-' . esc_attr( $k ) . '" name="' . esc_attr( $k ) . '" rows="3">' . esc_textarea( (string) ( $v[ $k ] ?? '' ) ) . '</textarea>';
			} else {
				echo '<input type="' . esc_attr( $type ) . '" step="any" id="jwq-' . esc_attr( $k ) . '" name="' . esc_attr( $k ) . '" value="' . esc_attr( (string) ( $v[ $k ] ?? '' ) ) . '">';
			}
			echo $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '';
			echo '</div>';
		};
		echo '<div class="jw-grid">';
		if ( 'made_to_order' === $type ) {
			$field( 'unit_price', __( 'Unit price', 'jewelry-workflows' ), 'number', __( 'Fixed in the quote. Later product price changes do not affect it.', 'jewelry-workflows' ) );
			$field( 'qty', __( 'Quantity', 'jewelry-workflows' ), 'number' );
		} else {
			$field( 'description', __( 'Piece description (shown to customer)', 'jewelry-workflows' ) );
			$field( 'price', __( 'Price', 'jewelry-workflows' ), 'number' );
			$field( 'deposit', __( 'Deposit amount (required)', 'jewelry-workflows' ), 'number', __( 'Bespoke deposits are set per quote. 0 means no deposit.', 'jewelry-workflows' ) );
		}
		if ( ! $pickup ) {
			$field( 'shipping', __( 'Quoted delivery cost', 'jewelry-workflows' ), 'number' );
			$field( 'shipping_label', __( 'Delivery method (shown to customer)', 'jewelry-workflows' ) );
		}
		$field( 'lead_time', __( 'Production time (shown to customer)', 'jewelry-workflows' ) );
		$field( 'valid_days', __( 'Quote valid for (days)', 'jewelry-workflows' ), 'number' );
		echo '</div>';
		$field( 'scope', __( 'Scope and terms (shown to customer)', 'jewelry-workflows' ), 'textarea' );
		if ( 'made_to_order' === $type ) {
			$pct = (int) JW_Settings::get( 'mto_deposit_percent' );
			echo '<p class="description">' . esc_html( sprintf( /* translators: 1: percent, 2: basis */ __( 'Deposit: %1$d%% of the %2$s (shared made-to-order rule).', 'jewelry-workflows' ), $pct, 'merchandise' === JW_Settings::get( 'mto_deposit_basis' ) ? __( 'item price', 'jewelry-workflows' ) : __( 'full total including delivery', 'jewelry-workflows' ) ) ) . ( JW_Settings::get( 'mto_deposit_confirmed' ) ? '' : ' <span class="jw-warn">' . esc_html__( 'Demo assumption, not confirmed.', 'jewelry-workflows' ) . '</span>' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	// ----- Order screen -----

	public static function box_order( $post_or_order ): void {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}
		echo '<div class="jw-box">';
		if ( JW_Payments::is_payment_request( $order ) ) {
			$parent = wc_get_order( (int) $order->get_parent_id() );
			echo '<p>' . esc_html( 'deposit' === JW_Payments::kind( $order ) ? __( 'This order is a deposit payment request.', 'jewelry-workflows' ) : __( 'This order is a balance payment request.', 'jewelry-workflows' ) ) . ' ';
			if ( $parent ) {
				echo '<a href="' . esc_url( $parent->get_edit_order_url() ) . '">' . esc_html( sprintf( /* translators: %d: order id */ __( 'Open commission order #%d', 'jewelry-workflows' ), $parent->get_id() ) ) . '</a>';
			}
			echo '</p><p class="description">' . esc_html__( 'Refund payments here, in this order. The commission order recalculates paid and outstanding amounts automatically.', 'jewelry-workflows' ) . '</p></div>';
			return;
		}
		if ( ! JW_Quotes::is_quote( $order ) ) {
			if ( $order->get_meta( '_jw_needs_reconciliation' ) ) {
				echo '<p class="jw-warn">' . esc_html__( 'Payment arrived after this order was cancelled. Stock was not reduced again. Refund the payment, or check stock and restore the order.', 'jewelry-workflows' ) . '</p>';
			} else {
				echo '<p>' . esc_html__( 'Ready-to-ship order from the normal checkout. No commission workflow.', 'jewelry-workflows' ) . '</p>';
			}
			echo '</div>';
			return;
		}

		$state = JW_Quotes::state( $order );
		$a     = JW_Payments::amounts( $order );
		$prod  = (string) $order->get_meta( '_jw_production' );
		$oid   = $order->get_id();
		$rid   = (int) $order->get_meta( '_jw_request_id' );
		$cur   = array( 'currency' => $order->get_currency() );
		echo '<table>';
		echo '<tr><th>' . esc_html__( 'Request', 'jewelry-workflows' ) . '</th><td>' . ( $rid ? '<a href="' . esc_url( get_edit_post_link( $rid ) ) . '">' . esc_html( get_the_title( $rid ) ) . '</a>' : '' ) . ' · ' . esc_html( 'made_to_order' === $order->get_meta( '_jw_sale_type' ) ? __( 'Made to order', 'jewelry-workflows' ) : __( 'Bespoke', 'jewelry-workflows' ) ) . ' · ' . esc_html( JW_I18n::LANGS[ $order->get_meta( '_jw_lang' ) ]['name'] ?? '' ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Quote', 'jewelry-workflows' ) . '</th><td><span class="jw-pill">' . esc_html( JW_Quotes::state_label( $state ) ) . '</span> v' . (int) $order->get_meta( '_jw_quote_version' ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Production', 'jewelry-workflows' ) . '</th><td><span class="jw-pill">' . esc_html( JW_Payments::production_label( $prod ) ) . '</span></td></tr>';
		echo '<tr><th>' . esc_html__( 'Payment', 'jewelry-workflows' ) . '</th><td><span class="jw-pill">' . esc_html( JW_Payments::payment_state_label( JW_Payments::payment_state( $order ) ) ) . '</span></td></tr>';
		echo '<tr><th>' . esc_html__( 'Fulfillment', 'jewelry-workflows' ) . '</th><td><span class="jw-pill">' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . '</span></td></tr>';
		echo '<tr><th>' . esc_html__( 'Total / deposit / paid / outstanding', 'jewelry-workflows' ) . '</th><td>' . wp_kses_post( wc_price( $a['total'], $cur ) . ' / ' . wc_price( $a['deposit'], $cur ) . ' / ' . wc_price( $a['paid'], $cur ) . ' / <strong>' . wc_price( $a['balance'], $cur ) . '</strong>' ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Deposit rule', 'jewelry-workflows' ) . '</th><td>' . esc_html( $order->get_meta( '_jw_deposit_rule' ) ) . '</td></tr>';
		if ( 'sent' === $state && $order->get_meta( '_jw_quote_link' ) ) {
			echo '<tr><th>' . esc_html__( 'Quote link', 'jewelry-workflows' ) . '</th><td><input class="jw-copy" readonly onclick="this.select()" value="' . esc_attr( $order->get_meta( '_jw_quote_link' ) ) . '"></td></tr>';
		}
		echo '</table><h4>' . esc_html__( 'Payment requests', 'jewelry-workflows' ) . '</h4><table>';
		foreach ( JW_Payments::requests( $order ) as $c ) {
			$link = $c->needs_payment() ? '<input class="jw-copy" readonly onclick="this.select()" value="' . esc_attr( JW_Payments::pay_url( $c ) ) . '">' : '';
			$mail = $c->get_meta( '_jw_email_status' ) ? ' · email: ' . esc_html( $c->get_meta( '_jw_email_status' ) ) : '';
			echo '<tr><td><a href="' . esc_url( $c->get_edit_order_url() ) . '">#' . (int) $c->get_id() . '</a> ' . esc_html( JW_Payments::kind( $c ) ) . ' v' . (int) $c->get_meta( '_jw_quote_version' ) . '</td><td>' . wp_kses_post( wc_price( $c->get_total(), $cur ) ) . '</td><td>' . esc_html( wc_get_order_status_name( $c->get_status() ) ) . $mail . '</td><td>' . $link . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</table><p>';

		$btn = static function ( string $action, string $label, array $args = array(), string $class = 'button' ) use ( $oid ) {
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( self::post_url( $action, array_merge( array( 'order_id' => $oid ), $args ) ) ) . '">' . esc_html( $label ) . '</a> ';
		};
		if ( in_array( $state, array( 'draft', 'sent', 'expired' ), true ) && ! $order->has_status( 'cancelled' ) ) {
			$btn( 'send_quote', 'draft' === $state ? __( 'Send quote to customer', 'jewelry-workflows' ) : __( 'Re-send quote (new link)', 'jewelry-workflows' ), array(), 'button button-primary' );
		}
		if ( 'accepted' === $state && ! $order->has_status( array( 'completed', 'cancelled', 'refunded' ) ) ) {
			if ( 'not_started' === $prod ) {
				$btn( 'production', __( 'Set: in production', 'jewelry-workflows' ), array( 'state' => 'in_production' ) );
			}
			if ( 'ready' !== $prod ) {
				$btn( 'mark_ready', __( 'Mark ready and request balance', 'jewelry-workflows' ), array(), 'button button-primary' );
			} elseif ( $a['balance'] > 0 ) {
				$btn( 'mark_ready', __( 'Check balance request', 'jewelry-workflows' ) );
				$btn( 'retry_email', __( 'Retry email', 'jewelry-workflows' ) );
			}
		}
		if ( ! in_array( $state, array( 'cancelled' ), true ) && $a['paid'] <= 0 && ! $order->has_status( 'cancelled' ) ) {
			$btn( 'cancel_quote', __( 'Cancel quote', 'jewelry-workflows' ), array(), 'button-link-delete' );
		}
		echo '</p>';
		if ( in_array( $state, array( 'draft', 'sent', 'expired', 'declined' ), true ) && $a['paid'] <= 0 && $rid ) {
			echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=jw-quote&order_id=' . $oid ) ) . '">' . esc_html__( 'Revise quote', 'jewelry-workflows' ) . '</a></p>';
		}
		echo '<p class="description">' . esc_html__( 'Fulfillment: when the order is paid in full it moves to "Processing". Set it to "Completed" after shipping or pickup. Completing is blocked while a balance is unpaid.', 'jewelry-workflows' ) . '</p></div>';
	}

	// ----- Translations -----

	public static function box_translations( WP_Post $post ): void {
		wp_nonce_field( 'jw_translations', '_jw_tr_nonce' );
		$default = JW_I18n::default_lang();
		echo '<div class="jw-box"><p class="description">' . esc_html( sprintf( /* translators: %s: language */ __( 'The main title and text above are in %s (the default language). Write the other languages here. Empty fields show the default language.', 'jewelry-workflows' ), JW_I18n::LANGS[ $default ]['name'] ) ) . '</p>';
		foreach ( JW_I18n::LANGS as $code => $info ) {
			if ( $code === $default ) {
				continue;
			}
			$dir = esc_attr( $info['dir'] );
			echo '<h3>' . esc_html( $info['name'] ) . '</h3>';
			echo '<label for="jw-' . esc_attr( $code ) . '-title">' . esc_html__( 'Title', 'jewelry-workflows' ) . '</label><input type="text" dir="' . $dir . '" id="jw-' . esc_attr( $code ) . '-title" name="jw_tr[' . esc_attr( $code ) . '][title]" value="' . esc_attr( JW_I18n::post_text( $post->ID, 'title', $code ) ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<label for="jw-' . esc_attr( $code ) . '-excerpt">' . esc_html( 'product' === $post->post_type ? __( 'Short description', 'jewelry-workflows' ) : __( 'Excerpt', 'jewelry-workflows' ) ) . '</label><textarea dir="' . $dir . '" id="jw-' . esc_attr( $code ) . '-excerpt" name="jw_tr[' . esc_attr( $code ) . '][excerpt]" rows="2">' . esc_textarea( JW_I18n::post_text( $post->ID, 'excerpt', $code ) ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<label for="jw-' . esc_attr( $code ) . '-content">' . esc_html__( 'Text', 'jewelry-workflows' ) . '</label><textarea dir="' . $dir . '" id="jw-' . esc_attr( $code ) . '-content" name="jw_tr[' . esc_attr( $code ) . '][content]" rows="8">' . esc_textarea( JW_I18n::post_text( $post->ID, 'content', $code ) ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</div>';
	}

	public static function save_translations( $post_id, $post ): void {
		if ( ! isset( $_POST['_jw_tr_nonce'] ) || ! wp_verify_nonce( $_POST['_jw_tr_nonce'], 'jw_translations' ) || ! current_user_can( 'edit_post', $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		foreach ( (array) wp_unslash( $_POST['jw_tr'] ?? array() ) as $code => $fields ) {
			if ( ! isset( JW_I18n::LANGS[ $code ] ) ) {
				continue;
			}
			update_post_meta( $post_id, "_jw_{$code}_title", sanitize_text_field( $fields['title'] ?? '' ) );
			update_post_meta( $post_id, "_jw_{$code}_excerpt", wp_kses_post( $fields['excerpt'] ?? '' ) );
			update_post_meta( $post_id, "_jw_{$code}_content", wp_kses_post( $fields['content'] ?? '' ) );
		}
	}

	public static function term_fields(): void {
		$taxes = array_merge( array( 'product_cat' ), function_exists( 'wc_get_attribute_taxonomy_names' ) ? wc_get_attribute_taxonomy_names() : array() );
		foreach ( $taxes as $tax ) {
			add_action( "{$tax}_edit_form_fields", array( __CLASS__, 'term_edit_fields' ) );
			add_action( "edited_{$tax}", array( __CLASS__, 'term_save' ) );
		}
	}

	public static function term_edit_fields( $term ): void {
		wp_nonce_field( 'jw_term_tr', '_jw_term_nonce' );
		foreach ( JW_I18n::LANGS as $code => $info ) {
			if ( $code === JW_I18n::default_lang() ) {
				continue;
			}
			$val = (string) get_term_meta( $term->term_id, "_jw_{$code}_name", true );
			echo '<tr class="form-field"><th scope="row"><label for="jw-term-' . esc_attr( $code ) . '">' . esc_html( sprintf( /* translators: %s: language */ __( 'Name (%s)', 'jewelry-workflows' ), $info['name'] ) ) . '</label></th><td><input type="text" dir="' . esc_attr( $info['dir'] ) . '" id="jw-term-' . esc_attr( $code ) . '" name="jw_term_tr[' . esc_attr( $code ) . ']" value="' . esc_attr( $val ) . '"></td></tr>';
		}
	}

	public static function term_save( $term_id ): void {
		if ( ! isset( $_POST['_jw_term_nonce'] ) || ! wp_verify_nonce( $_POST['_jw_term_nonce'], 'jw_term_tr' ) || ! current_user_can( 'manage_product_terms' ) ) {
			return;
		}
		foreach ( (array) wp_unslash( $_POST['jw_term_tr'] ?? array() ) as $code => $name ) {
			if ( isset( JW_I18n::LANGS[ $code ] ) ) {
				update_term_meta( $term_id, "_jw_{$code}_name", sanitize_text_field( $name ) );
			}
		}
	}
}
