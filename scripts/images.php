<?php
/**
 * Development shop images come from the images/ folder in this repository.
 * Run with: npm run images   (or: scripts/wp eval-file scripts/images.php)
 *
 *   images/pages/<page-slug>.jpg           page image: "home" is the opening image, "about" the maker portrait
 *   images/categories/<category-slug>.jpg  category image (rings, earrings, necklaces)
 *   images/products/<product-slug>.jpg     product main image
 *   images/products/<product-slug>-2.jpg   extra product images (-2, -3, ...), in order
 *
 * .jpg, .jpeg, .png and .webp work. A changed file replaces the old image; a removed file
 * brings back the generated DEMO placeholder (or no image, where there is no placeholder).
 * Only changed images are processed, so running it again is quick.
 */

const JW_IMAGE_EXTS = array( 'jpg', 'jpeg', 'png', 'webp' );

/** Generated placeholders for the demo content: key => [width, height, seed, label]. */
function jw_image_defaults(): array {
	return array(
		'pages/home'                          => array( 2400, 1350, 7, 'Opening image' ),
		'categories/rings'                    => array( 1600, 1000, crc32( 'rings' ), 'Rings' ),
		'categories/earrings'                 => array( 1600, 1000, crc32( 'earrings' ), 'Earrings' ),
		'categories/necklaces'                => array( 1600, 1000, crc32( 'necklaces' ), 'Necklaces' ),
		'products/demo-signet-ring'           => array( 1200, 1500, 11, 'Signet ring' ),
		'products/demo-signet-ring-2'         => array( 1200, 1500, 12, 'Signet ring detail' ),
		'products/demo-one-of-a-kind-pendant' => array( 1200, 1500, 21, 'Pendant' ),
		'products/demo-hoop-earrings'         => array( 1200, 1500, 31, 'Hoop earrings' ),
		'products/demo-band-ring'             => array( 1200, 1500, 41, 'Band ring' ),
		'products/demo-chain-necklace'        => array( 1200, 1500, 51, 'Chain necklace' ),
	);
}

/** Image files in the repository: key ("products/demo-signet-ring") => path. */
function jw_image_files( string $dir ): array {
	$files = array();
	foreach ( array( 'pages', 'categories', 'products' ) as $group ) {
		foreach ( glob( "$dir/$group/*" ) ?: array() as $path ) {
			$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			if ( in_array( $ext, JW_IMAGE_EXTS, true ) ) {
				$files[ $group . '/' . strtolower( pathinfo( $path, PATHINFO_FILENAME ) ) ] = $path;
			}
		}
	}
	return $files;
}

/** Placeholder JPEG in the style of the original demo images. Needs the PHP gd extension. */
function jw_image_placeholder( string $file, array $spec ): bool {
	if ( ! function_exists( 'imagecreatetruecolor' ) ) {
		return false;
	}
	[ $w, $h, $seed, $label ] = $spec;
	$img = imagecreatetruecolor( $w, $h );
	mt_srand( $seed );
	$base = 200 + mt_rand( 0, 40 );
	for ( $y = 0; $y < $h; $y++ ) {
		$c = (int) ( $base - 50 * ( $y / $h ) );
		imageline( $img, 0, $y, $w, $y, imagecolorallocate( $img, $c, $c, $c - 4 ) );
	}
	imagesetthickness( $img, max( 2, (int) ( $w / 260 ) ) );
	$dark = imagecolorallocate( $img, 40, 40, 40 );
	$lite = imagecolorallocate( $img, 250, 248, 244 );
	$cx   = (int) ( $w * ( 0.35 + mt_rand( 0, 30 ) / 100 ) );
	$cy   = (int) ( $h * 0.5 );
	$r    = (int) ( min( $w, $h ) * ( 0.18 + mt_rand( 0, 10 ) / 100 ) );
	imageellipse( $img, $cx, $cy, $r * 2, $r * 2, $dark );
	imageellipse( $img, $cx, $cy, (int) ( $r * 1.7 ), (int) ( $r * 1.7 ), $dark );
	imagefilledellipse( $img, $cx, $cy - $r, (int) ( $r * 0.28 ), (int) ( $r * 0.28 ), $lite );
	imageellipse( $img, $cx, $cy - $r, (int) ( $r * 0.28 ), (int) ( $r * 0.28 ), $dark );
	imagestring( $img, 5, 16, $h - 30, 'DEMO IMAGE - ' . $label, $dark );
	imagejpeg( $img, $file, 85 );
	imagedestroy( $img );
	return true;
}

/** The attachment this script made for $key, or 0. */
function jw_image_attachment( string $key ): int {
	$ids = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'fields' => 'ids', 'numberposts' => 1, 'meta_key' => '_jw_image_key', 'meta_value' => $key ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	return $ids ? (int) $ids[0] : 0;
}

/** Create or update the attachment for $key from a repo file or a placeholder. Returns its ID, or 0. */
function jw_image_sync_one( string $key, ?string $path, ?array $spec, string $alt ): int {
	$id     = jw_image_attachment( $key );
	$source = $path ? sha1_file( $path ) : ( $spec ? 'placeholder-' . md5( wp_json_encode( $spec ) ) : '' );
	if ( '' === $source ) {
		if ( $id ) {
			wp_delete_attachment( $id, true );
			WP_CLI::log( "Removed -> $key" );
		}
		return 0;
	}
	if ( $id && get_post_meta( $id, '_jw_image_source', true ) === $source ) {
		return $id; // Unchanged.
	}

	$upload = wp_upload_dir();
	$name   = str_replace( '/', '-', $key ) . '-' . substr( $source, -8 );
	if ( $path ) {
		$file = $upload['path'] . '/' . $name . '.' . strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		copy( $path, $file );
	} else {
		$file = $upload['path'] . '/demo-' . $name . '.jpg';
		if ( ! jw_image_placeholder( $file, $spec ) ) {
			return $id; // No gd: keep whatever is there (the theme shows an empty image area for 0).
		}
	}
	$mime = wp_check_filetype( $file )['type'] ?: 'image/jpeg';

	require_once ABSPATH . 'wp-admin/includes/image.php';
	if ( $id ) { // Replace the files of the existing attachment; everything that uses it keeps working.
		$old_meta = wp_get_attachment_metadata( $id );
		wp_delete_attachment_files( $id, is_array( $old_meta ) ? $old_meta : array(), array(), get_attached_file( $id ) );
		update_attached_file( $id, $file );
		wp_update_post( array( 'ID' => $id, 'post_mime_type' => $mime ) );
	} else {
		$id = wp_insert_attachment( array( 'post_mime_type' => $mime, 'post_title' => $alt, 'post_name' => 'jw-image-' . str_replace( '/', '-', $key ), 'post_status' => 'inherit' ), $file );
		update_post_meta( $id, '_jw_image_key', $key );
	}
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file ) );
	update_post_meta( $id, '_jw_image_source', $source );
	update_post_meta( $id, '_wp_attachment_image_alt', $path ? $alt : "Demo placeholder image ({$spec[3]}). Not a real product photo." );
	WP_CLI::log( ( $path ? 'Image: ' . basename( $path ) : 'Placeholder' ) . " -> $key" );
	return $id;
}

/** The product with this slug (products only; get_page_by_path also matches attachments). */
function jw_image_product( string $slug ): ?WP_Post {
	$posts = get_posts( array( 'post_type' => 'product', 'name' => $slug, 'post_status' => 'any', 'numberposts' => 1 ) );
	return $posts ? $posts[0] : null;
}

function jw_apply_images( string $dir ): void {
	$files    = jw_image_files( $dir );
	$defaults = jw_image_defaults();
	$keys     = array_unique( array_merge( array_keys( $defaults ), array_keys( $files ) ) );
	// Keys whose file was removed and that have no placeholder: their image goes away.
	foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_jw_image_key' ) ) as $att ) { // phpcs:ignore WordPress.DB.SlowDBQuery
		$keys[] = get_post_meta( $att->ID, '_jw_image_key', true );
	}
	$keys = array_unique( $keys );
	sort( $keys ); // Main product images ("x") before extra ones ("x-2").

	$galleries = array();
	foreach ( $keys as $key ) {
		[ $group, $slug ] = explode( '/', $key, 2 );
		$path = $files[ $key ] ?? null;
		$spec = $defaults[ $key ] ?? null;

		if ( 'pages' === $group ) {
			$page = get_page_by_path( $slug );
			if ( ! $page ) {
				WP_CLI::warning( "No page with the slug \"$slug\" for images/$key" );
				continue;
			}
			$id = jw_image_sync_one( $key, $path, $spec, get_the_title( $page ) );
			$id ? set_post_thumbnail( $page, $id ) : delete_post_thumbnail( $page );
		} elseif ( 'categories' === $group ) {
			$term = get_term_by( 'slug', $slug, 'product_cat' );
			if ( ! $term ) {
				WP_CLI::warning( "No product category with the slug \"$slug\" for images/$key" );
				continue;
			}
			$id = jw_image_sync_one( $key, $path, $spec, $term->name );
			$id ? update_term_meta( $term->term_id, 'thumbnail_id', $id ) : delete_term_meta( $term->term_id, 'thumbnail_id' );
		} elseif ( 'products' === $group ) {
			$product_slug = $slug;
			$extra        = false;
			if ( ! jw_image_product( $slug ) && preg_match( '/^(.+)-(\d+)$/', $slug, $m ) ) {
				[ , $product_slug, $extra ] = $m;
			}
			$post = jw_image_product( $product_slug );
			if ( ! $post ) {
				WP_CLI::warning( "No product with the slug \"$product_slug\" for images/$key" );
				continue;
			}
			$product = wc_get_product( $post->ID );
			$id      = jw_image_sync_one( $key, $path, $spec, $product->get_name() );
			if ( $extra ) {
				$galleries[ $post->ID ] ??= array();
				if ( $id ) {
					$galleries[ $post->ID ][ (int) $extra ] = $id;
				}
			} else {
				$product->set_image_id( $id );
				$product->save();
			}
		}
	}
	foreach ( $galleries as $product_id => $ids ) {
		ksort( $ids );
		$product = wc_get_product( $product_id );
		$product->set_gallery_image_ids( array_values( $ids ) );
		$product->save();
	}
}

jw_apply_images( dirname( __DIR__ ) . '/images' );
WP_CLI::success( 'Images are up to date with the images/ folder.' );
