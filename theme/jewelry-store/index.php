<?php
defined( 'ABSPATH' ) || exit;
get_header();
?>
<main id="main" class="jwt-page jwt-container jwt-container--narrow">
	<?php if ( have_posts() ) : ?>
		<?php while ( have_posts() ) : the_post(); ?>
			<article <?php post_class( 'jwt-entry' ); ?>>
				<h1 class="jwt-page__title"><?php the_title(); ?></h1>
				<div class="jwt-prose"><?php the_content(); ?></div>
			</article>
		<?php endwhile; ?>
	<?php else : ?>
		<h1 class="jwt-page__title"><?php esc_html_e( 'Nothing found', 'jewelry-store' ); ?></h1>
	<?php endif; ?>
</main>
<?php
get_footer();
