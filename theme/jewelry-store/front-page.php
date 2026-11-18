<?php
/**
 * Home: opening image, available pieces, collection panels, commissions, maker.
 * Sections only render when real data exists, so the page never shows empty
 * collections or invented content.
 */
defined( 'ABSPATH' ) || exit;
get_header();

$front   = (int) get_option( 'page_on_front' );
$shop    = wc_get_page_permalink( 'shop' );
$ready   = wc_get_products(
	array(
		'status'       => 'publish',
		'limit'        => 4,
		'stock_status' => 'instock',
		'orderby'      => 'date',
		'order'        => 'DESC',
		'meta_key'     => '_jw_sale_type', // phpcs:ignore WordPress.DB.SlowDBQuery
		'meta_value'   => 'ready', // phpcs:ignore WordPress.DB.SlowDBQuery
	)
);
$panels  = get_terms(
	array(
		'taxonomy'   => 'product_cat',
		'hide_empty' => true,
		'exclude'    => array( (int) get_option( 'default_product_cat' ) ),
		'number'     => 3,
		'meta_query' => array( array( 'key' => 'thumbnail_id', 'compare' => 'EXISTS' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
	)
);
$commissions = get_page_by_path( 'commissions' );
$about       = get_page_by_path( 'about' );
?>
<main id="main" class="jwt-home">
	<section class="jwt-hero" aria-labelledby="jwt-hero-title">
		<a class="jwt-hero__media" href="<?php echo esc_url( $shop ); ?>" tabindex="-1" aria-hidden="true">
			<?php echo jwt_image( (int) get_post_thumbnail_id( $front ), 'jwt-hero', '100vw', true, 'jwt-hero__img' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</a>
		<div class="jwt-caption">
			<?php if ( has_excerpt( $front ) ) : ?>
				<p class="jwt-eyebrow"><?php echo esc_html( get_the_excerpt( $front ) ); ?></p>
			<?php endif; ?>
			<h1 id="jwt-hero-title" class="jwt-caption__title"><?php echo esc_html( get_the_title( $front ) ); ?></h1>
			<a class="jwt-link" href="<?php echo esc_url( $shop ); ?>"><?php esc_html_e( 'Discover the pieces', 'jewelry-store' ); ?></a>
		</div>
	</section>

	<?php if ( $ready ) : ?>
		<section class="jwt-section jwt-container" aria-labelledby="jwt-available">
			<h2 id="jwt-available" class="jwt-section__title"><?php esc_html_e( 'Available now', 'jewelry-store' ); ?></h2>
			<ul class="jwt-tiles">
				<?php foreach ( $ready as $p ) : ?>
					<li class="jwt-tile">
						<a class="jwt-tile__link" href="<?php echo esc_url( $p->get_permalink() ); ?>">
							<?php echo jwt_image( (int) $p->get_image_id(), 'woocommerce_thumbnail', '(min-width: 900px) 25vw, 50vw', false, 'jwt-tile__img' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<span class="jwt-tile__name"><?php echo esc_html( $p->get_name() ); ?></span>
						</a>
						<span class="jwt-tile__price"><?php echo wp_kses_post( $p->get_price_html() ); ?></span>
						<?php if ( jwt_availability_label( $p ) ) : ?>
							<span class="jwt-tile__tag"><?php echo esc_html( jwt_availability_label( $p ) ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="jwt-center"><a class="jwt-link" href="<?php echo esc_url( add_query_arg( 'jw_type', 'ready', $shop ) ); ?>"><?php esc_html_e( 'All available pieces', 'jewelry-store' ); ?></a></p>
		</section>
	<?php endif; ?>

	<?php foreach ( is_array( $panels ) ? $panels : array() as $i => $term ) : ?>
		<section class="jwt-panel" aria-labelledby="jwt-panel-<?php echo (int) $term->term_id; ?>">
			<a class="jwt-panel__media" href="<?php echo esc_url( get_term_link( $term ) ); ?>" tabindex="-1" aria-hidden="true">
				<?php echo jwt_image( (int) get_term_meta( $term->term_id, 'thumbnail_id', true ), 'jwt-panel', '100vw', false, 'jwt-panel__img' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</a>
			<div class="jwt-caption">
				<p class="jwt-eyebrow"><?php esc_html_e( 'Collection', 'jewelry-store' ); ?></p>
				<h2 id="jwt-panel-<?php echo (int) $term->term_id; ?>" class="jwt-caption__title"><?php echo esc_html( $term->name ); ?></h2>
				<a class="jwt-link" href="<?php echo esc_url( get_term_link( $term ) ); ?>"><?php esc_html_e( 'See more', 'jewelry-store' ); ?><span class="screen-reader-text">: <?php echo esc_html( $term->name ); ?></span></a>
			</div>
		</section>
	<?php endforeach; ?>

	<?php if ( $commissions ) : ?>
		<section class="jwt-section jwt-container jwt-split" aria-labelledby="jwt-commissions">
			<div>
				<p class="jwt-eyebrow"><?php esc_html_e( 'Made for you', 'jewelry-store' ); ?></p>
				<h2 id="jwt-commissions" class="jwt-section__title"><?php echo esc_html( get_the_title( $commissions ) ); ?></h2>
			</div>
			<div class="jwt-split__body">
				<p><?php esc_html_e( 'Made-to-order designs: choose the size and material on the piece, send a request, and receive a quote with delivery before paying a deposit.', 'jewelry-store' ); ?></p>
				<p><?php esc_html_e( 'Bespoke pieces: describe your idea and add reference photos. The design and price are agreed with you first.', 'jewelry-store' ); ?></p>
				<p class="jwt-actions">
					<a class="jwt-button" href="<?php echo esc_url( add_query_arg( 'jw_type', 'made_to_order', $shop ) ); ?>"><?php esc_html_e( 'Made-to-order designs', 'jewelry-store' ); ?></a>
					<a class="jwt-button jwt-button--ghost" href="<?php echo esc_url( get_permalink( $commissions ) ); ?>"><?php esc_html_e( 'Request a commission', 'jewelry-store' ); ?></a>
				</p>
			</div>
		</section>
	<?php endif; ?>

	<?php if ( $about ) : ?>
		<section class="jwt-section jwt-container jwt-maker" aria-labelledby="jwt-maker">
			<?php if ( has_post_thumbnail( $about ) ) : ?>
				<?php echo jwt_image( (int) get_post_thumbnail_id( $about ), 'jwt-portrait', '(min-width: 900px) 40vw, 100vw', false, 'jwt-maker__img' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php endif; ?>
			<div>
				<h2 id="jwt-maker" class="jwt-section__title"><?php echo esc_html( get_the_title( $about ) ); ?></h2>
				<?php if ( has_excerpt( $about ) ) : ?>
					<p><?php echo esc_html( get_the_excerpt( $about ) ); ?></p>
				<?php endif; ?>
				<a class="jwt-link" href="<?php echo esc_url( get_permalink( $about ) ); ?>"><?php esc_html_e( 'Read more', 'jewelry-store' ); ?></a>
			</div>
		</section>
	<?php endif; ?>
</main>
<?php
get_footer();
