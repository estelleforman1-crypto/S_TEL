<?php
defined( 'ABSPATH' ) || exit;
get_header();
$wide = is_cart() || is_checkout() || is_account_page();
?>
<main id="main" class="jwt-page jwt-container<?php echo $wide ? '' : ' jwt-container--narrow'; ?>">
	<?php while ( have_posts() ) : the_post(); ?>
		<article <?php post_class( 'jwt-entry' ); ?>>
			<h1 class="jwt-page__title"><?php the_title(); ?></h1>
			<?php if ( has_post_thumbnail() && ! $wide ) : ?>
				<?php echo jwt_image( (int) get_post_thumbnail_id(), 'jwt-portrait', '(min-width: 900px) 720px, 100vw', false, 'jwt-page__img' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php endif; ?>
			<div class="jwt-prose"><?php the_content(); ?></div>
		</article>
	<?php endwhile; ?>
</main>
<?php
get_footer();
