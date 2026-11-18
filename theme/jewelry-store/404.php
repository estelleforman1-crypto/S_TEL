<?php
defined( 'ABSPATH' ) || exit;
get_header();
?>
<main id="main" class="jwt-page jwt-container jwt-container--narrow jwt-center">
	<h1 class="jwt-page__title"><?php esc_html_e( 'Page not found', 'jewelry-store' ); ?></h1>
	<p><?php esc_html_e( 'This page does not exist, or the piece is no longer listed.', 'jewelry-store' ); ?></p>
	<p class="jwt-actions jwt-actions--center">
		<a class="jwt-button" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"><?php esc_html_e( 'See all pieces', 'jewelry-store' ); ?></a>
		<a class="jwt-button jwt-button--ghost" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'jewelry-store' ); ?></a>
	</p>
</main>
<?php
get_footer();
