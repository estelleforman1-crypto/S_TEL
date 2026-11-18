<?php
/**
 * Private reference photos for commission requests.
 * Files are stored with random names in a directory that should be outside the
 * web root (constant JW_PRIVATE_UPLOAD_DIR). Only shop managers can view them,
 * through an authenticated admin handler. They never enter the Media Library.
 */
defined( 'ABSPATH' ) || exit;

class JW_Uploads {
	const MIMES = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/webp' => 'webp',
	);

	public static function dir(): string {
		if ( defined( 'JW_PRIVATE_UPLOAD_DIR' ) && JW_PRIVATE_UPLOAD_DIR ) {
			return untrailingslashit( JW_PRIVATE_UPLOAD_DIR );
		}
		// Fallback inside uploads, protected by .htaccess (Apache) and random names.
		// On nginx or other servers, define JW_PRIVATE_UPLOAD_DIR outside the web root.
		return wp_upload_dir( null, false )['basedir'] . '/jw-private';
	}

	public static function ensure_dir(): void {
		$dir = self::dir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			@file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence.\n" );
		}
	}

	/**
	 * Validate and store uploaded images from $_FILES[$field].
	 *
	 * @return array|WP_Error List of stored file records.
	 */
	public static function store_from_request( string $field ) {
		if ( empty( $_FILES[ $field ] ) || ! is_array( $_FILES[ $field ]['name'] ) ) {
			return array();
		}
		$files     = $_FILES[ $field ];
		$max_files = (int) JW_Settings::get( 'upload_max_files' );
		$max_bytes = (int) JW_Settings::get( 'upload_max_mb' ) * MB_IN_BYTES;
		$indexes   = array();
		foreach ( $files['name'] as $i => $name ) {
			if ( UPLOAD_ERR_NO_FILE === (int) $files['error'][ $i ] ) {
				continue;
			}
			$indexes[] = $i;
		}
		if ( count( $indexes ) > $max_files ) {
			/* translators: %d: maximum number of photos */
			return new WP_Error( 'jw_too_many', sprintf( __( 'Please attach at most %d photos.', 'jewelry-workflows' ), $max_files ) );
		}
		$checked = array();
		foreach ( $indexes as $i ) {
			$tmp = $files['tmp_name'][ $i ];
			if ( UPLOAD_ERR_OK !== (int) $files['error'][ $i ] || ! is_uploaded_file( $tmp ) ) {
				return new WP_Error( 'jw_upload', __( 'A photo could not be uploaded. Please try again.', 'jewelry-workflows' ) );
			}
			if ( filesize( $tmp ) > $max_bytes ) {
				/* translators: %d: maximum size in megabytes */
				return new WP_Error( 'jw_too_big', sprintf( __( 'Each photo must be smaller than %d MB.', 'jewelry-workflows' ), (int) JW_Settings::get( 'upload_max_mb' ) ) );
			}
			$mime = ( new finfo( FILEINFO_MIME_TYPE ) )->file( $tmp );
			if ( ! isset( self::MIMES[ $mime ] ) || false === @getimagesize( $tmp ) ) {
				return new WP_Error( 'jw_type', __( 'Photos must be JPEG, PNG or WebP images.', 'jewelry-workflows' ) );
			}
			$checked[] = array( $tmp, $mime, $files['name'][ $i ] );
		}
		// Store only after every file passed, so a rejected request leaves nothing behind.
		self::ensure_dir();
		$stored = array();
		foreach ( $checked as [ $tmp, $mime, $orig ] ) {
			$name = bin2hex( random_bytes( 16 ) ) . '.' . self::MIMES[ $mime ];
			if ( ! move_uploaded_file( $tmp, self::dir() . '/' . $name ) ) {
				self::delete( $stored );
				return new WP_Error( 'jw_upload', __( 'A photo could not be saved. Please try again.', 'jewelry-workflows' ) );
			}
			$stored[] = array( 'file' => $name, 'mime' => $mime, 'name' => sanitize_file_name( $orig ) );
		}
		return $stored;
	}

	public static function delete( array $records ): void {
		foreach ( $records as $r ) {
			$path = self::dir() . '/' . basename( $r['file'] );
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}
	}

	public static function view_url( int $request_id, int $index ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=jw_attachment&request=' . $request_id . '&i=' . $index ), 'jw_attachment_' . $request_id );
	}

	/** admin-post handler. Only logged-in shop managers reach admin_post_* actions with this capability. */
	public static function serve(): void {
		$request_id = absint( $_GET['request'] ?? 0 );
		if ( ! current_user_can( 'edit_shop_orders' ) || ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'jw_attachment_' . $request_id ) ) {
			wp_die( esc_html__( 'You are not allowed to view this file.', 'jewelry-workflows' ), 403 );
		}
		$files = get_post_meta( $request_id, '_jw_attachments', true );
		$files = is_array( $files ) ? $files : array();
		$rec   = $files[ absint( $_GET['i'] ?? -1 ) ] ?? null;
		$path  = $rec ? self::dir() . '/' . basename( $rec['file'] ) : '';
		if ( ! $rec || ! is_file( $path ) ) {
			wp_die( esc_html__( 'File not found.', 'jewelry-workflows' ), 404 );
		}
		nocache_headers();
		header( 'Content-Type: ' . $rec['mime'] );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: inline; filename="' . rawurlencode( $rec['name'] ) . '"' );
		readfile( $path );
		exit;
	}
}
