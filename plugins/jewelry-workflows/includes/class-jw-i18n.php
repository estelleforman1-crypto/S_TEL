<?php
/**
 * Hebrew, English and French without a paid multilingual plugin.
 *
 * - URLs: the default language has no prefix; the others use /he/ and /fr/.
 * - One product, one stock record, one price. Only text is translated.
 * - Translated text for posts, pages and products is in post meta
 *   (_jw_{lang}_title, _jw_{lang}_excerpt, _jw_{lang}_content). Translated term
 *   names (categories, sizes, materials) are in term meta _jw_{lang}_name.
 *   The native WordPress fields hold the default-language text.
 * - Interface strings come from WordPress, WooCommerce and project .mo files.
 * See docs/decisions.md (D-007).
 */
defined( 'ABSPATH' ) || exit;

class JW_I18n {
	const LANGS = array(
		'he' => array( 'locale' => 'he_IL', 'name' => 'עברית', 'short' => 'HE', 'dir' => 'rtl' ),
		'en' => array( 'locale' => 'en_US', 'name' => 'English', 'short' => 'EN', 'dir' => 'ltr' ),
		'fr' => array( 'locale' => 'fr_FR', 'name' => 'Français', 'short' => 'FR', 'dir' => 'ltr' ),
	);
	const COOKIE = 'jw_lang';

	private static string $lang = 'en';
	private static bool $forced = false;
	private static string $unprefixed_uri = '/';

	public static function boot(): void {
		self::$lang = self::default_lang();
		$is_front    = ! is_admin() && ! ( defined( 'WP_CLI' ) && WP_CLI );

		if ( $is_front && isset( $_SERVER['REQUEST_URI'] ) ) {
			$uri       = (string) $_SERVER['REQUEST_URI'];
			$home_path = rtrim( (string) wp_parse_url( get_option( 'home' ), PHP_URL_PATH ), '/' );
			$rest      = substr( $uri, strlen( $home_path ) );
			if ( preg_match( '#^/(he|en|fr)(/|\?|$)#', $rest, $m ) && $m[1] !== self::default_lang() ) {
				self::$lang = $m[1];
				$rest       = substr( $rest, 3 );
				$rest       = ( '' === $rest || '?' === $rest[0] ) ? '/' . $rest : $rest;
				$_SERVER['REQUEST_URI'] = $home_path . $rest;
			} elseif ( self::is_background_request( $rest ) && isset( $_COOKIE[ self::COOKIE ] ) && isset( self::LANGS[ $_COOKIE[ self::COOKIE ] ] ) ) {
				// AJAX and REST calls carry no prefix: use the language of the page that made them.
				self::$lang = $_COOKIE[ self::COOKIE ];
			}
			self::$unprefixed_uri = $rest;
		} elseif ( wp_doing_ajax() && isset( $_COOKIE[ self::COOKIE ] ) && isset( self::LANGS[ $_COOKIE[ self::COOKIE ] ] ) ) {
			self::$lang = $_COOKIE[ self::COOKIE ];
		}

		add_filter( 'locale', array( __CLASS__, 'filter_locale' ) );
		add_filter( 'home_url', array( __CLASS__, 'filter_home_url' ), 10, 3 );
		add_filter( 'redirect_canonical', array( __CLASS__, 'filter_redirect_canonical' ) );
		add_action( 'after_setup_theme', array( __CLASS__, 'apply_direction' ), 0 );
		add_action( 'send_headers', array( __CLASS__, 'remember_lang' ) );
		add_filter( 'language_attributes', array( __CLASS__, 'filter_language_attributes' ) );
		add_action( 'wp_head', array( __CLASS__, 'hreflang_links' ) );

		// Translated content.
		add_filter( 'the_title', array( __CLASS__, 'filter_title' ), 5, 2 );
		add_filter( 'single_post_title', array( __CLASS__, 'filter_single_post_title' ), 5, 2 );
		add_filter( 'the_content', array( __CLASS__, 'filter_content' ), 1 );
		add_filter( 'get_the_excerpt', array( __CLASS__, 'filter_excerpt' ), 5, 2 );
		add_filter( 'woocommerce_product_get_name', array( __CLASS__, 'filter_product_name' ), 10, 2 );
		add_filter( 'woocommerce_product_variation_get_name', array( __CLASS__, 'filter_variation_name' ), 10, 2 );
		add_filter( 'woocommerce_product_get_description', array( __CLASS__, 'filter_product_description' ), 10, 2 );
		add_filter( 'woocommerce_product_get_short_description', array( __CLASS__, 'filter_product_short_description' ), 10, 2 );
		add_filter( 'get_term', array( __CLASS__, 'filter_term' ), 10, 2 );
		add_filter( 'woocommerce_variation_option_name', array( __CLASS__, 'filter_option_name' ), 10, 3 );
	}

	private static function is_background_request( string $rest ): bool {
		return wp_doing_ajax() || str_starts_with( $rest, '/wp-json' ) || isset( $_GET['wc-ajax'] ) || isset( $_GET['rest_route'] );
	}

	public static function default_lang(): string {
		$opt  = (array) get_option( JW_Settings::OPTION, array() );
		$lang = $opt['default_lang'] ?? 'en';
		return isset( self::LANGS[ $lang ] ) ? $lang : 'en';
	}

	public static function current(): string {
		return self::$lang;
	}

	public static function is_default(): bool {
		return self::$lang === self::default_lang();
	}

	public static function locale_for( string $lang ): string {
		return self::LANGS[ $lang ]['locale'] ?? 'en_US';
	}

	public static function is_rtl_lang( string $lang ): bool {
		return 'rtl' === ( self::LANGS[ $lang ]['dir'] ?? 'ltr' );
	}

	/** True while text should be translated for the visitor (front end or a forced switch). */
	private static function translating(): bool {
		if ( self::is_default() ) {
			return false;
		}
		return self::$forced || ! is_admin() || wp_doing_ajax();
	}

	/**
	 * Run $fn with another language active: URLs, locale and content.
	 * Used for customer emails and payment links in the customer's language.
	 */
	public static function with_lang( string $lang, callable $fn ) {
		if ( ! isset( self::LANGS[ $lang ] ) ) {
			$lang = self::default_lang();
		}
		$prev_lang   = self::$lang;
		$prev_forced = self::$forced;
		self::$lang   = $lang;
		self::$forced = true;
		$switched     = switch_to_locale( self::locale_for( $lang ) );
		self::apply_direction();
		try {
			return $fn();
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
			self::$lang   = $prev_lang;
			self::$forced = $prev_forced;
			self::apply_direction();
		}
	}

	public static function filter_locale( $locale ) {
		if ( self::$forced || ! is_admin() || wp_doing_ajax() ) {
			return self::locale_for( self::$lang );
		}
		return $locale;
	}

	/** Set the text direction from our language table, also when a language pack is missing. */
	public static function apply_direction(): void {
		if ( isset( $GLOBALS['wp_locale'] ) && ( self::$forced || ! is_admin() ) ) {
			$GLOBALS['wp_locale']->text_direction = self::LANGS[ self::$lang ]['dir'];
		}
	}

	public static function filter_language_attributes( $output ) {
		$attrs = 'lang="' . esc_attr( str_replace( '_', '-', self::locale_for( self::$lang ) ) ) . '"';
		if ( self::is_rtl_lang( self::$lang ) ) {
			$attrs = 'dir="rtl" ' . $attrs;
		}
		return $attrs;
	}

	public static function filter_home_url( $url, $path, $scheme ) {
		if ( self::is_default() || 'rest' === $scheme ) {
			return $url;
		}
		if ( is_admin() && ! wp_doing_ajax() && ! self::$forced ) {
			return $url;
		}
		$home = untrailingslashit( get_option( 'home' ) );
		$home = set_url_scheme( $home, $scheme && 'relative' !== $scheme ? $scheme : null );
		if ( ! str_starts_with( $url, $home ) ) {
			return $url;
		}
		$rest = substr( $url, strlen( $home ) );
		if ( str_starts_with( ltrim( $rest, '/' ), 'wp-json' ) || preg_match( '#^/(he|en|fr)(/|\?|$)#', $rest ) ) {
			return $url;
		}
		if ( '' === $rest || '?' === $rest[0] || '#' === $rest[0] ) {
			$rest = '/' . $rest;
		}
		return $home . '/' . self::$lang . $rest;
	}

	/** WordPress compares the unprefixed request with prefixed permalinks; that would loop. */
	public static function filter_redirect_canonical( $redirect ) {
		return self::is_default() ? $redirect : false;
	}

	public static function remember_lang(): void {
		if ( ! headers_sent() && ( $_COOKIE[ self::COOKIE ] ?? '' ) !== self::$lang ) {
			setcookie( self::COOKIE, self::$lang, array( 'expires' => time() + YEAR_IN_SECONDS, 'path' => '/', 'samesite' => 'Lax', 'secure' => is_ssl(), 'httponly' => false ) );
		}
	}

	/** The current page in another language. */
	public static function url_for_lang( string $lang ): string {
		$home = untrailingslashit( get_option( 'home' ) );
		$rest = self::$unprefixed_uri ?: '/';
		$home_path = rtrim( (string) wp_parse_url( $home, PHP_URL_PATH ), '/' );
		$origin    = substr( $home, 0, strlen( $home ) - strlen( $home_path ) );
		$base      = $origin . $home_path;
		if ( $lang === self::default_lang() ) {
			return $base . $rest;
		}
		return $base . '/' . $lang . $rest;
	}

	public static function hreflang_links(): void {
		if ( is_404() || is_search() ) {
			return;
		}
		foreach ( self::LANGS as $code => $info ) {
			printf( '<link rel="alternate" hreflang="%s" href="%s">' . "\n", esc_attr( $code ), esc_url( self::url_for_lang( $code ) ) );
		}
	}

	// ----- Content -----

	public static function post_text( int $post_id, string $field, ?string $lang = null ): string {
		$lang = $lang ?? self::$lang;
		return (string) get_post_meta( $post_id, "_jw_{$lang}_{$field}", true );
	}

	public static function filter_title( $title, $post_id = 0 ) {
		if ( $post_id && self::translating() ) {
			$t = self::post_text( (int) $post_id, 'title' );
			if ( '' !== $t ) {
				return $t;
			}
		}
		return $title;
	}

	public static function filter_single_post_title( $title, $post ) {
		return $post ? self::filter_title( $title, $post->ID ) : $title;
	}

	public static function filter_content( $content ) {
		$id = get_the_ID();
		if ( $id && self::translating() ) {
			$t = self::post_text( (int) $id, 'content' );
			if ( '' !== $t ) {
				return $t;
			}
		}
		return $content;
	}

	public static function filter_excerpt( $excerpt, $post = null ) {
		if ( $post && self::translating() ) {
			$t = self::post_text( (int) $post->ID, 'excerpt' );
			if ( '' !== $t ) {
				return $t;
			}
		}
		return $excerpt;
	}

	public static function filter_product_name( $name, $product ) {
		return self::translating() ? ( self::post_text( $product->get_id(), 'title' ) ?: $name ) : $name;
	}

	public static function filter_variation_name( $name, $variation ) {
		if ( ! self::translating() ) {
			return $name;
		}
		$parent = self::post_text( $variation->get_parent_id(), 'title' );
		if ( '' === $parent ) {
			return $name;
		}
		$attrs = wc_get_formatted_variation( $variation, true, false, true );
		return $attrs ? $parent . ' - ' . $attrs : $parent;
	}

	public static function filter_product_description( $text, $product ) {
		return self::translating() ? ( self::post_text( $product->get_id(), 'content' ) ?: $text ) : $text;
	}

	public static function filter_product_short_description( $text, $product ) {
		return self::translating() ? ( self::post_text( $product->get_id(), 'excerpt' ) ?: $text ) : $text;
	}

	public static function translatable_taxonomy( string $tax ): bool {
		return 'product_cat' === $tax || str_starts_with( $tax, 'pa_' );
	}

	public static function filter_term( $term, $taxonomy ) {
		if ( $term instanceof WP_Term && self::translating() && self::translatable_taxonomy( $taxonomy ) ) {
			$name = (string) get_term_meta( $term->term_id, '_jw_' . self::$lang . '_name', true );
			if ( '' !== $name ) {
				$term       = clone $term;
				$term->name = $name;
			}
		}
		return $term;
	}

	public static function filter_option_name( $name, $term = null, $attribute = null ) {
		if ( $term instanceof WP_Term ) {
			return $term->name; // Already translated by filter_term.
		}
		return $name;
	}
}
