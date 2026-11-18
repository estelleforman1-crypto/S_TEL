<?php
/**
 * Owner settings. Every value that the GDD lists as unresolved has an explicit,
 * labeled default here. Stock hold time and sold-item visibility use the native
 * WooCommerce settings (Products > Inventory) and are not duplicated.
 */
defined( 'ABSPATH' ) || exit;

class JW_Settings {
	const OPTION = 'jw_settings';

	public static function defaults(): array {
		return array(
			// Made-to-order deposit. Basis is unresolved: 'total' is a labeled demo assumption.
			'mto_deposit_percent'   => 50,
			'mto_deposit_basis'     => 'total', // total | merchandise
			'mto_deposit_confirmed' => 0,       // Owner must confirm the basis before live deposits.
			'quote_valid_days'      => 14,
			'default_lang'          => 'en',
			'contact_email'         => '',
			'contact_whatsapp'      => '',      // International format digits, for example 9725XXXXXXXX.
			'upload_max_files'      => 5,
			'upload_max_mb'         => 8,
			'form_limit_per_hour'   => 5,
			'pickup_enabled'        => 0,
			'pickup_details'        => '',
		);
	}

	public static function get( string $key ) {
		$all = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		return $all[ $key ] ?? null;
	}

	public static function all(): array {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
	}

	public static function update( array $values ): void {
		$clean = self::all();
		foreach ( self::defaults() as $key => $default ) {
			if ( ! array_key_exists( $key, $values ) ) {
				continue;
			}
			$v = $values[ $key ];
			if ( is_int( $default ) ) {
				$clean[ $key ] = max( 0, (int) $v );
			} elseif ( 'contact_email' === $key ) {
				$clean[ $key ] = sanitize_email( $v );
			} elseif ( 'pickup_details' === $key ) {
				$clean[ $key ] = sanitize_textarea_field( $v );
			} else {
				$clean[ $key ] = sanitize_text_field( $v );
			}
		}
		if ( ! in_array( $clean['mto_deposit_basis'], array( 'total', 'merchandise' ), true ) ) {
			$clean['mto_deposit_basis'] = 'total';
		}
		$clean['mto_deposit_percent'] = min( 100, $clean['mto_deposit_percent'] );
		if ( ! array_key_exists( $clean['default_lang'], JW_I18n::LANGS ) ) {
			$clean['default_lang'] = 'en';
		}
		update_option( self::OPTION, $clean );
	}

	/** Live deposits stay off until the owner confirms the deposit basis. */
	public static function mto_deposits_allowed(): bool {
		return (bool) self::get( 'mto_deposit_confirmed' ) || 'production' !== wp_get_environment_type();
	}
}
