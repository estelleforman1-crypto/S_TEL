<?php
/**
 * Development seed data. Run with: wp eval-file scripts/seed-demo.php
 * Everything here is DEMO content: product names start with "Demo", prices and
 * the shipping rate are invented for testing, and policy pages are placeholders.
 * Idempotent: it does nothing when the seed already ran.
 */

if ( get_option( 'jw_demo_seeded' ) ) {
	WP_CLI::log( 'Demo data already present.' );
	return;
}

// ---------- WooCommerce settings (labeled development defaults) ----------
update_option( 'woocommerce_currency', 'ILS' );                 // Unresolved: development default.
update_option( 'woocommerce_default_country', 'IL' );
update_option( 'woocommerce_default_customer_address', 'base' ); // Checkout starts with the store country (Israel).
update_option( 'woocommerce_enable_guest_checkout', 'yes' );     // Proposed baseline: guest checkout.
update_option( 'woocommerce_enable_checkout_login_reminder', 'yes' );
update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'no' );
update_option( 'woocommerce_hold_stock_minutes', '15' );         // Unresolved: demo stock-hold time.
update_option( 'woocommerce_manage_stock', 'yes' );
update_option( 'woocommerce_hide_out_of_stock_items', 'no' );    // Unresolved: sold pieces stay visible as "Sold out".
update_option( 'woocommerce_calc_taxes', 'no' );                // Unresolved: tax rules not supplied.
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_ship_to_countries', '' );
update_option( 'woocommerce_shipping_cost_requires_address', 'yes' );
update_option( 'woocommerce_enable_reviews', 'no' );
update_option( 'woocommerce_email_from_name', 'Jewelry Store (development)' );
update_option( 'blogname', 'Atelier' ); // Placeholder wordmark. The brand name is not supplied.
update_option( 'blogdescription', 'Development build – placeholder brand' );
update_option( 'woocommerce_jw_demo_settings', array( 'enabled' => 'yes' ) );

// ---------- Categories ----------
$cats = array();
foreach ( array(
	'rings'     => array( 'Rings', 'טבעות', 'Bagues' ),
	'earrings'  => array( 'Earrings', 'עגילים', "Boucles d'oreilles" ),
	'necklaces' => array( 'Necklaces', 'שרשראות', 'Colliers' ),
) as $slug => [ $en, $he, $fr ] ) {
	$t = term_exists( $slug, 'product_cat' ) ?: wp_insert_term( $en, 'product_cat', array( 'slug' => $slug ) );
	$cats[ $slug ] = (int) $t['term_id'];
	update_term_meta( $cats[ $slug ], '_jw_he_name', $he );
	update_term_meta( $cats[ $slug ], '_jw_fr_name', $fr );
}

// ---------- Attributes ----------
function jw_seed_attribute( string $slug, string $label, array $terms ): string {
	$tax = wc_attribute_taxonomy_name( $slug );
	if ( ! wc_attribute_taxonomy_id_by_name( $slug ) ) {
		wc_create_attribute( array( 'name' => $label, 'slug' => $slug, 'type' => 'select', 'has_archives' => false ) );
	}
	register_taxonomy( $tax, array( 'product' ), array( 'hierarchical' => false ) );
	foreach ( $terms as $tslug => [ $en, $he, $fr ] ) {
		$t = term_exists( $tslug, $tax ) ?: wp_insert_term( $en, $tax, array( 'slug' => $tslug ) );
		update_term_meta( (int) $t['term_id'], '_jw_he_name', $he );
		update_term_meta( (int) $t['term_id'], '_jw_fr_name', $fr );
	}
	return $tax;
}
$size     = jw_seed_attribute( 'ring-size', 'Ring size', array( '50' => array( '50', '50', '50' ), '52' => array( '52', '52', '52' ), '54' => array( '54', '54', '54' ) ) );
$material = jw_seed_attribute( 'material', 'Material', array( 'demo-silver' => array( 'Demo metal A', 'מתכת הדגמה א', 'Métal démo A' ), 'demo-gold' => array( 'Demo metal B', 'מתכת הדגמה ב', 'Métal démo B' ) ) );

function jw_seed_translations( int $id, array $he, array $fr ): void {
	foreach ( array( 'he' => $he, 'fr' => $fr ) as $code => $f ) {
		update_post_meta( $id, "_jw_{$code}_title", $f[0] );
		update_post_meta( $id, "_jw_{$code}_excerpt", $f[1] );
	}
}
$demo_desc = 'DEMONSTRATION PRODUCT for development. Not a real piece and not for sale. The maker supplies real descriptions, materials and care information.';

function jw_seed_attr_obj( string $tax, array $slugs, bool $variation ): WC_Product_Attribute {
	$a = new WC_Product_Attribute();
	$a->set_id( wc_attribute_taxonomy_id_by_name( $tax ) );
	$a->set_name( $tax );
	$a->set_options( array_map( static fn( $s ) => (int) get_term_by( 'slug', $s, $tax )->term_id, $slugs ) );
	$a->set_visible( true );
	$a->set_variation( $variation );
	return $a;
}

// 1. Ready stock, simple, stock 3.
$p = new WC_Product_Simple();
$p->set_name( 'Demo Signet Ring' );
$p->set_slug( 'demo-signet-ring' );
$p->set_regular_price( '480' );
$p->set_manage_stock( true );
$p->set_stock_quantity( 3 );
$p->set_category_ids( array( $cats['rings'] ) );
$p->set_short_description( 'Demo ready-to-ship ring.' );
$p->set_description( $demo_desc );
$p->update_meta_data( '_jw_sale_type', 'ready' );
$signet = $p->save();
jw_seed_translations( $signet, array( 'טבעת חותם (הדגמה)', 'טבעת הדגמה מוכנה למשלוח.' ), array( 'Bague chevalière (démo)', 'Bague de démonstration prête à expédier.' ) );

// 2. One of a kind, stock 1 (used by the race test).
$p = new WC_Product_Simple();
$p->set_name( 'Demo One-of-a-kind Pendant' );
$p->set_slug( 'demo-one-of-a-kind-pendant' );
$p->set_regular_price( '920' );
$p->set_manage_stock( true );
$p->set_stock_quantity( 1 );
$p->set_sold_individually( true );
$p->set_category_ids( array( $cats['necklaces'] ) );
$p->set_short_description( 'Demo one-of-a-kind piece. Quantity 1.' );
$p->set_description( $demo_desc );
$p->update_meta_data( '_jw_sale_type', 'ready' );
$pendant = $p->save();
jw_seed_translations( $pendant, array( 'תליון יחיד במינו (הדגמה)', 'פריט הדגמה יחיד.' ), array( 'Pendentif unique (démo)', 'Pièce unique de démonstration.' ) );

// 3. Ready stock, variable by material.
$p = new WC_Product_Variable();
$p->set_name( 'Demo Hoop Earrings' );
$p->set_slug( 'demo-hoop-earrings' );
$p->set_category_ids( array( $cats['earrings'] ) );
$p->set_short_description( 'Demo ready-to-ship earrings in two demo metals.' );
$p->set_description( $demo_desc );
$p->set_attributes( array( jw_seed_attr_obj( $material, array( 'demo-silver', 'demo-gold' ), true ) ) );
$p->update_meta_data( '_jw_sale_type', 'ready' );
$hoops = $p->save();
foreach ( array( 'demo-silver' => '350', 'demo-gold' => '420' ) as $slug => $price ) {
	$v = new WC_Product_Variation();
	$v->set_parent_id( $hoops );
	$v->set_attributes( array( $material => $slug ) );
	$v->set_regular_price( $price );
	$v->set_manage_stock( true );
	$v->set_stock_quantity( 2 );
	$v->save();
}
jw_seed_translations( $hoops, array( 'עגילי חישוק (הדגמה)', 'עגילי הדגמה בשתי מתכות.' ), array( 'Créoles (démo)', "Boucles d'oreilles de démonstration." ) );

// 4. Made to order, variable by size and material.
$p = new WC_Product_Variable();
$p->set_name( 'Demo Band Ring (made to order)' );
$p->set_slug( 'demo-band-ring' );
$p->set_category_ids( array( $cats['rings'] ) );
$p->set_short_description( 'Demo made-to-order ring. Choose size and metal.' );
$p->set_description( $demo_desc );
$p->set_attributes( array( jw_seed_attr_obj( $size, array( '50', '52', '54' ), true ), jw_seed_attr_obj( $material, array( 'demo-silver', 'demo-gold' ), true ) ) );
$p->update_meta_data( '_jw_sale_type', 'made_to_order' );
$p->update_meta_data( '_jw_lead_weeks', 4 );
$band = $p->save();
foreach ( array( '50', '52', '54' ) as $s ) {
	foreach ( array( 'demo-silver' => '600', 'demo-gold' => '1800' ) as $m => $price ) {
		$v = new WC_Product_Variation();
		$v->set_parent_id( $band );
		$v->set_attributes( array( $size => $s, $material => $m ) );
		$v->set_regular_price( $price );
		$v->save();
	}
}
jw_seed_translations( $band, array( 'טבעת פס בהזמנה (הדגמה)', 'טבעת הדגמה בהכנה לפי הזמנה.' ), array( 'Alliance sur commande (démo)', 'Bague de démonstration fabriquée sur commande.' ) );

// 5. Made to order, simple.
$p = new WC_Product_Simple();
$p->set_name( 'Demo Chain Necklace (made to order)' );
$p->set_slug( 'demo-chain-necklace' );
$p->set_regular_price( '750' );
$p->set_category_ids( array( $cats['necklaces'] ) );
$p->set_short_description( 'Demo made-to-order necklace.' );
$p->set_description( $demo_desc );
$p->update_meta_data( '_jw_sale_type', 'made_to_order' );
$p->update_meta_data( '_jw_lead_weeks', 3 );
$chain = $p->save();
jw_seed_translations( $chain, array( 'שרשרת בהזמנה (הדגמה)', 'שרשרת הדגמה בהכנה לפי הזמנה.' ), array( 'Collier sur commande (démo)', 'Collier de démonstration sur commande.' ) );

// ---------- Shipping: one demo zone only. Other countries have no method, so they cannot check out. ----------
$zone = new WC_Shipping_Zone();
$zone->set_zone_name( 'Israel (DEMO RATE)' );
$zone->add_location( 'IL', 'country' );
$zone->save();
$instance = $zone->add_shipping_method( 'flat_rate' );
update_option( "woocommerce_flat_rate_{$instance}_settings", array( 'title' => 'Delivery in Israel (demo rate)', 'tax_status' => 'none', 'cost' => '30' ) );

// ---------- Pages ----------
function jw_seed_page( string $slug, string $title, string $content, array $he = array(), array $fr = array(), string $excerpt = '' ): int {
	$existing = get_page_by_path( $slug );
	$id       = $existing ? $existing->ID : wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $title ) );
	wp_update_post( array( 'ID' => $id, 'post_title' => $title, 'post_content' => $content, 'post_excerpt' => $excerpt ) );
	foreach ( array( 'he' => $he, 'fr' => $fr ) as $code => $f ) {
		if ( $f ) {
			update_post_meta( $id, "_jw_{$code}_title", $f[0] );
			update_post_meta( $id, "_jw_{$code}_excerpt", $f[1] ?? '' );
			update_post_meta( $id, "_jw_{$code}_content", $f[2] ?? '' );
		}
	}
	return $id;
}
$placeholder = static fn( string $what ) => "<p><strong>[Owner to supply: {$what}]</strong></p><p>This is a development placeholder. No policy, promise or business fact is stated here.</p>";

$home = jw_seed_page( 'home', 'Demo Collection', '', array( 'קולקציית הדגמה', 'מסך פתיחה להדגמה' ), array( 'Collection démo', "Image d'ouverture de démonstration" ), 'Opening image placeholder' );
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $home );

// Classic shortcode cart and checkout (decision D-008).
wp_update_post( array( 'ID' => wc_get_page_id( 'cart' ), 'post_content' => '[woocommerce_cart]' ) );
wp_update_post( array( 'ID' => wc_get_page_id( 'checkout' ), 'post_content' => '[woocommerce_checkout]' ) );
jw_seed_translations( wc_get_page_id( 'shop' ), array( 'חנות', '' ), array( 'Boutique', '' ) );
jw_seed_translations( wc_get_page_id( 'cart' ), array( 'סל קניות', '' ), array( 'Panier', '' ) );
jw_seed_translations( wc_get_page_id( 'checkout' ), array( 'תשלום', '' ), array( 'Paiement', '' ) );

$commissions = jw_seed_page(
	'commissions',
	'Commissions',
	"<p>Made-to-order designs are requested from their product page. For a piece that does not exist yet, describe it below.</p>\n[jw_commission_form]",
	array( 'הזמנות אישיות', '', "<p>[טקסט הדגמה – בעלת העסק תכתוב את הנוסח הסופי]</p>\n[jw_commission_form]" ),
	array( 'Commandes', '', "<p>[Texte de démonstration – la créatrice rédigera le texte final]</p>\n[jw_commission_form]" ),
	'Made-to-order pieces and bespoke commissions.'
);
$about = jw_seed_page( 'about', 'About', $placeholder( 'maker introduction, technique (wax and metal process), portrait if wanted' ), array( 'אודות', '', $placeholder( 'טקסט אודות' ) ), array( 'À propos', '', $placeholder( 'texte À propos' ) ), '[Owner to supply: short factual introduction]' );
$contact  = jw_seed_page( 'contact', 'Contact', $placeholder( 'contact email, WhatsApp number, location if wanted' ), array( 'צור קשר' ), array( 'Contact' ) );
$delivery = jw_seed_page( 'delivery', 'Delivery and pickup', $placeholder( 'shipping destinations, rates, times, pickup arrangements' ), array( 'משלוח ואיסוף' ), array( 'Livraison et retrait' ) );
$returns  = jw_seed_page( 'returns', 'Returns and cancellations', $placeholder( 'returns, cancellations, made-to-order and bespoke terms' ), array( 'החזרות וביטולים' ), array( 'Retours et annulations' ) );
$care     = jw_seed_page( 'care', 'Care', $placeholder( 'care instructions per material' ), array( 'טיפול בתכשיטים' ), array( 'Entretien' ) );
$privacy  = jw_seed_page( 'privacy', 'Privacy', $placeholder( 'privacy policy reviewed for Israeli law and the payment provider' ), array( 'פרטיות' ), array( 'Confidentialité' ) );
update_option( 'wp_page_for_privacy_policy', $privacy );
update_option( 'woocommerce_terms_page_id', $returns );

// ---------- Menus ----------
function jw_seed_menu( string $name, string $location, array $items ): void {
	$menu = wp_get_nav_menu_object( $name );
	$id   = $menu ? $menu->term_id : wp_create_nav_menu( $name );
	foreach ( $items as $item ) {
		wp_update_nav_menu_item( $id, 0, $item + array( 'menu-item-status' => 'publish' ) );
	}
	$locations              = get_theme_mod( 'nav_menu_locations', array() );
	$locations[ $location ] = $id;
	set_theme_mod( 'nav_menu_locations', $locations );
}
$page_item = static fn( int $id ) => array( 'menu-item-object-id' => $id, 'menu-item-object' => 'page', 'menu-item-type' => 'post_type' );
$cat_item  = static fn( int $id ) => array( 'menu-item-object-id' => $id, 'menu-item-object' => 'product_cat', 'menu-item-type' => 'taxonomy' );
jw_seed_menu( 'Primary', 'primary', array( $page_item( wc_get_page_id( 'shop' ) ), $cat_item( $cats['rings'] ), $cat_item( $cats['earrings'] ), $cat_item( $cats['necklaces'] ), $page_item( $commissions ), $page_item( $about ) ) );
jw_seed_menu( 'Footer', 'footer', array( $page_item( $contact ), $page_item( $delivery ), $page_item( $care ), $page_item( $returns ), $page_item( $privacy ) ) );

JW_Settings::update( array( 'contact_email' => 'owner@example.test' ) );
update_option( 'jw_attribute_labels', array( 'ring-size' => array( 'he' => 'מידת טבעת', 'fr' => 'Taille de bague' ), 'material' => array( 'he' => 'חומר', 'fr' => 'Matière' ) ) );
// Images: from the images/ folder, else generated DEMO placeholders. See scripts/images.php.
require __DIR__ . '/images.php';
update_option( 'jw_demo_seeded', time() );
flush_rewrite_rules();
WP_CLI::success( 'Demo data created. All products, prices, rates and policy text are placeholders.' );
