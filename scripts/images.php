<?php
/**
 * Development shop images come from the images/ folder (see images/README.md).
 * The dev sandbox plugin runs this automatically when a file in images/ changes,
 * so replacing a file and refreshing the browser is enough. By hand: npm run images
 *
 * A file's name (without extension) says where it goes, checked in this order:
 *   a page slug (home, about) > a product category slug (rings) >
 *   a product slug, with or without "demo-" (band-ring) > name-2, name-3: extra product photos.
 */

if ( ! function_exists( 'jw_images_sync' ) ) {

	function jw_images_dir(): string {
		return dirname( __DIR__ ) . '/images';
	}

	function jw_images_log( string $msg ): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::log( $msg );
		}
	}

	/** name => path for every image file in the folder. */
	function jw_images_files(): array {
		$files = array();
		foreach ( glob( jw_images_dir() . '/*' ) ?: array() as $path ) {
			if ( in_array( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ), array( 'jpg', 'jpeg', 'png', 'webp' ), true ) ) {
				$files[ strtolower( pathinfo( $path, PATHINFO_FILENAME ) ) ] = $path;
			}
		}
		return $files;
	}

	/** Changes whenever a file is added, removed or replaced. */
	function jw_images_signature(): string {
		$sig = '';
		foreach ( jw_images_files() as $name => $path ) {
			$sig .= $name . filemtime( $path ) . filesize( $path );
		}
		return md5( $sig );
	}

	function jw_images_product( string $slug ): ?WP_Post {
		foreach ( array( $slug, 'demo-' . $slug ) as $s ) {
			$posts = get_posts( array( 'post_type' => 'product', 'name' => $s, 'post_status' => 'any', 'numberposts' => 1 ) );
			if ( $posts ) {
				return $posts[0];
			}
		}
		return null;
	}

	/** Where a name goes: [ type, object, extra photo position or 0 ], or null. */
	function jw_images_target( string $name ): ?array {
		$page = get_page_by_path( $name, OBJECT, 'page' );
		if ( $page ) {
			return array( 'page', $page, 0 );
		}
		$term = get_term_by( 'slug', $name, 'product_cat' );
		if ( $term ) {
			return array( 'category', $term, 0 );
		}
		$product = jw_images_product( $name );
		if ( $product ) {
			return array( 'product', $product, 0 );
		}
		if ( preg_match( '/^(.+)-(\d+)$/', $name, $m ) ) {
			$product = jw_images_product( $m[1] );
			if ( $product ) {
				return array( 'product', $product, (int) $m[2] );
			}
		}
		return null;
	}

	/** The attachment for $name, created or updated from $path. Unchanged files are skipped. */
	function jw_images_attachment( string $name, string $path, string $alt ): int {
		$ids  = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'fields' => 'ids', 'numberposts' => 1, 'meta_key' => '_jw_image_name', 'meta_value' => $name ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$id   = $ids ? (int) $ids[0] : 0;
		$hash = sha1_file( $path );
		if ( $id && get_post_meta( $id, '_jw_image_hash', true ) === $hash ) {
			return $id;
		}
		$upload = wp_upload_dir();
		$ext    = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$file   = $upload['path'] . "/{$name}-" . substr( $hash, 0, 8 ) . ".{$ext}"; // New name, so browsers do not show the old copy.
		copy( $path, $file );
		$mime = wp_check_filetype( $file )['type'] ? wp_check_filetype( $file )['type'] : 'image/jpeg';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		if ( $id ) {
			$meta = wp_get_attachment_metadata( $id );
			wp_delete_attachment_files( $id, is_array( $meta ) ? $meta : array(), array(), get_attached_file( $id ) );
			update_attached_file( $id, $file );
			wp_update_post( array( 'ID' => $id, 'post_mime_type' => $mime ) );
		} else {
			$id = wp_insert_attachment( array( 'post_mime_type' => $mime, 'post_title' => $alt, 'post_name' => "jw-image-{$name}", 'post_status' => 'inherit' ), $file );
			update_post_meta( $id, '_jw_image_name', $name );
		}
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file ) );
		update_post_meta( $id, '_jw_image_hash', $hash );
		update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		jw_images_log( 'Image: ' . basename( $path ) );
		return $id;
	}

	/** Make the shop match the images/ folder. */
	function jw_images_sync(): void {
		$files     = jw_images_files();
		$galleries = array();
		foreach ( $files as $name => $path ) {
			$target = jw_images_target( $name );
			if ( ! $target ) {
				jw_images_log( 'Skipped ' . basename( $path ) . ': no page, category or product has that name. See images/README.md.' );
				continue;
			}
			[ $type, $obj, $position ] = $target;
			if ( 'page' === $type ) {
				set_post_thumbnail( $obj, jw_images_attachment( $name, $path, get_the_title( $obj ) ) );
			} elseif ( 'category' === $type ) {
				update_term_meta( $obj->term_id, 'thumbnail_id', jw_images_attachment( $name, $path, $obj->name ) );
			} else {
				$id = jw_images_attachment( $name, $path, get_the_title( $obj ) );
				if ( $position ) {
					$galleries[ $obj->ID ][ $position ] = $id;
				} else {
					$product = wc_get_product( $obj->ID );
					$product->set_image_id( $id );
					$product->save();
				}
			}
		}
		// Images whose file was deleted: remove them (the theme then shows an empty image area).
		$atts = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_jw_image_name' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		foreach ( $atts as $att ) {
			$name = get_post_meta( $att->ID, '_jw_image_name', true );
			if ( isset( $files[ $name ] ) ) {
				continue;
			}
			$target = jw_images_target( $name );
			if ( $target && 'product' === $target[0] && $target[2] ) {
				$galleries[ $target[1]->ID ] ??= array();
			}
			wp_delete_attachment( $att->ID, true ); // Also clears it as page, category or product image.
			jw_images_log( "Removed: $name" );
		}
		foreach ( $galleries as $product_id => $ids ) {
			ksort( $ids );
			$product = wc_get_product( $product_id );
			$product->set_gallery_image_ids( array_values( $ids ) );
			$product->save();
		}
		update_option( 'jw_images_signature', jw_images_signature(), false );
	}
}

// Run when called from WP-CLI (npm run images, or the demo seed).
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	jw_images_sync();
	WP_CLI::success( 'The shop images match the images/ folder.' );
}
