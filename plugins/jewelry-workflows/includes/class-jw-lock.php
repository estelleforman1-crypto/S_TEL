<?php
/**
 * Short database lock. An INSERT IGNORE on the unique option_name key succeeds
 * for one request only. (add_option() is not usable here: it runs
 * INSERT ... ON DUPLICATE KEY UPDATE, which succeeds for both requests.)
 * Used around actions that create payable records, so a double click or two
 * parallel requests cannot create two.
 */
defined( 'ABSPATH' ) || exit;

class JW_Lock {
	public static function acquire( string $name, int $ttl = 30 ): bool {
		global $wpdb;
		$key = 'jw_lock_' . $name;
		// Remove a lock left by a request that died. The expiry check is in the statement.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d", $key, time() ) );
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $key, (string) ( time() + $ttl ) ) );
		return 1 === (int) $wpdb->rows_affected;
	}

	public static function release( string $name ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => 'jw_lock_' . $name ) );
	}

	/** Run $fn while holding the lock. Returns WP_Error when the lock is busy. */
	public static function with( string $name, callable $fn ) {
		if ( ! self::acquire( $name ) ) {
			return new WP_Error( 'jw_busy', __( 'This action is already in progress. Please wait a moment and reload.', 'jewelry-workflows' ) );
		}
		try {
			return $fn();
		} finally {
			self::release( $name );
		}
	}
}
