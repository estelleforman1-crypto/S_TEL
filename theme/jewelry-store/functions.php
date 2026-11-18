<?php
/**
 * Jewelry Store theme. Presentation only: business rules live in the
 * jewelry-workflows plugin, commerce in WooCommerce.
 */
defined( 'ABSPATH' ) || exit;

define( 'JWT_VERSION', '0.1.0' );

add_action(
	'after_setup_theme',
	static function () {
		load_theme_textdomain( 'jewelry-store', get_template_directory() . '/languages' );
		add_theme_support( 'title-tag' );
		add_theme_support( 'post-thumbnails' );
		add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script', 'navigation-widgets' ) );
		add_theme_support(
			'woocommerce',
			array(
				'thumbnail_image_width' => 800,
				'single_image_width'    => 1200,
				'product_grid'          => array( 'default_columns' => 3, 'default_rows' => 4 ),
			)
		);
		add_theme_support( 'wc-product-gallery-lightbox' );
		add_theme_support( 'wc-product-gallery-slider' );
		register_nav_menus(
			array(
				'primary' => __( 'Main navigation', 'jewelry-store' ),
				'footer'  => __( 'Footer links', 'jewelry-store' ),
			)
		);
		add_image_size( 'jwt-hero', 2400, 1350, true );
		add_image_size( 'jwt-panel', 1600, 1000, true );
		add_image_size( 'jwt-portrait', 900, 1125, true );
	}
);

add_action(
	'wp_enqueue_scripts',
	static function () {
		wp_enqueue_style( 'jwt-main', get_template_directory_uri() . '/assets/css/main.css', array(), JWT_VERSION );
		wp_enqueue_script( 'jwt-main', get_template_directory_uri() . '/assets/js/main.js', array(), JWT_VERSION, array( 'strategy' => 'defer', 'in_footer' => true ) );
	},
	20
);

// Remove the WooCommerce sidebar and breadcrumbs: the catalog is small and image-led.
remove_action( 'woocommerce_sidebar', 'woocommerce_get_sidebar', 10 );
remove_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20 );
remove_action( 'woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10 );
remove_action( 'woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10 );
// The shop toolbar ordering is noise for about 20 pieces.
remove_action( 'woocommerce_before_shop_loop', 'woocommerce_catalog_ordering', 30 );
add_action( 'woocommerce_before_shop_loop', 'jwt_shop_filters', 25 );
add_filter( 'woocommerce_output_related_products_args', static fn( $a ) => array_merge( $a, array( 'posts_per_page' => 4, 'columns' => 4 ) ) );
add_filter( 'loop_shop_columns', static fn() => 3 );

/** Availability label under each product tile. */
add_action(
	'woocommerce_after_shop_loop_item_title',
	static function () {
		global $product;
		$label = jwt_availability_label( $product );
		if ( $label ) {
			echo '<span class="jwt-tile__tag">' . esc_html( $label ) . '</span>';
		}
	},
	15
);

function jwt_availability_label( $product ): string {
	if ( ! $product instanceof WC_Product ) {
		return '';
	}
	if ( class_exists( 'JW_Sale_Types' ) && 'made_to_order' === JW_Sale_Types::type( $product ) ) {
		return __( 'Made to order', 'jewelry-store' );
	}
	if ( ! $product->is_in_stock() ) {
		return __( 'Sold', 'jewelry-store' );
	}
	if ( $product->managing_stock() && 1 === (int) $product->get_stock_quantity() && ! $product->is_type( 'variable' ) ) {
		return __( 'One of a kind', 'jewelry-store' );
	}
	return '';
}

/** Filters from real catalog data: categories that have products, and how a piece is sold. */
function jwt_shop_filters(): void {
	if ( ! is_shop() && ! is_product_category() ) {
		return;
	}
	$terms   = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'exclude' => array( (int) get_option( 'default_product_cat' ) ) ) );
	$current = is_product_category() ? get_queried_object_id() : 0;
	$type    = sanitize_key( $_GET['jw_type'] ?? '' );
	$base    = is_product_category() ? get_term_link( $current ) : get_permalink( wc_get_page_id( 'shop' ) );
	echo '<nav class="jwt-filters" aria-label="' . esc_attr__( 'Filter pieces', 'jewelry-store' ) . '"><ul class="jwt-filters__group">';
	echo '<li><a href="' . esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ) . '"' . ( ! $current ? ' aria-current="page"' : '' ) . '>' . esc_html__( 'All', 'jewelry-store' ) . '</a></li>';
	foreach ( is_array( $terms ) ? $terms : array() as $t ) {
		echo '<li><a href="' . esc_url( get_term_link( $t ) ) . '"' . ( $current === $t->term_id ? ' aria-current="page"' : '' ) . '>' . esc_html( $t->name ) . '</a></li>';
	}
	echo '</ul><ul class="jwt-filters__group">';
	foreach ( array( '' => __( 'Any availability', 'jewelry-store' ), 'ready' => __( 'Ready to ship', 'jewelry-store' ), 'made_to_order' => __( 'Made to order', 'jewelry-store' ) ) as $k => $label ) {
		echo '<li><a href="' . esc_url( $k ? add_query_arg( 'jw_type', $k, $base ) : $base ) . '"' . ( $type === $k ? ' aria-current="true"' : '' ) . '>' . esc_html( $label ) . '</a></li>';
	}
	echo '</ul></nav>';
}

/** Bag link with item count. The count refreshes through WooCommerce cart fragments. */
function jwt_bag_link(): string {
	$count = function_exists( 'WC' ) && WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
	return sprintf(
		'<a class="jwt-bag" href="%1$s"><span class="jwt-bag__label">%2$s</span> <span class="jwt-bag__count" aria-label="%3$s">%4$s</span></a>',
		esc_url( wc_get_cart_url() ),
		esc_html__( 'Bag', 'jewelry-store' ),
		/* translators: %d: number of items */
		esc_attr( sprintf( _n( '%d item', '%d items', $count, 'jewelry-store' ), $count ) ),
		$count ? '(' . (int) $count . ')' : ''
	);
}
add_filter(
	'woocommerce_add_to_cart_fragments',
	static function ( $fragments ) {
		$fragments['a.jwt-bag'] = jwt_bag_link();
		return $fragments;
	}
);

function jwt_language_switcher( string $class = '' ): void {
	if ( ! class_exists( 'JW_I18n' ) ) {
		return;
	}
	echo '<ul class="jwt-langs ' . esc_attr( $class ) . '">';
	foreach ( JW_I18n::LANGS as $code => $info ) {
		$current = JW_I18n::current() === $code;
		printf(
			'<li><a href="%1$s" hreflang="%2$s" lang="%2$s"%3$s title="%4$s">%5$s</a></li>',
			esc_url( JW_I18n::url_for_lang( $code ) ),
			esc_attr( $code ),
			$current ? ' aria-current="true"' : '',
			esc_attr( $info['name'] ),
			esc_html( $info['short'] )
		);
	}
	echo '</ul>';
}

/** Responsive image with explicit dimensions. $eager for the opening image only. */
function jwt_image( int $attachment_id, string $size, string $sizes, bool $eager = false, string $class = '' ): string {
	if ( ! $attachment_id ) {
		return '<div class="jwt-image-missing ' . esc_attr( $class ) . '" role="img" aria-label="' . esc_attr__( 'Image to be supplied', 'jewelry-store' ) . '"></div>';
	}
	return wp_get_attachment_image(
		$attachment_id,
		$size,
		false,
		array(
			'class'         => $class,
			'sizes'         => $sizes,
			'loading'       => $eager ? 'eager' : 'lazy',
			'fetchpriority' => $eager ? 'high' : 'auto',
			'decoding'      => $eager ? 'sync' : 'async',
		)
	);
}

function jwt_contact_links(): array {
	$links = array();
	if ( class_exists( 'JW_Settings' ) ) {
		$email = JW_Settings::get( 'contact_email' );
		$wa    = preg_replace( '/\D+/', '', (string) JW_Settings::get( 'contact_whatsapp' ) );
		if ( $email ) {
			$links[] = array( 'mailto:' . $email, $email );
		}
		if ( $wa ) {
			$links[] = array( 'https://wa.me/' . $wa, 'WhatsApp' );
		}
	}
	return $links;
}
