<?php defined( 'ABSPATH' ) || exit; ?>
<footer class="jwt-footer">
	<div class="jwt-container jwt-footer__grid">
		<div>
			<h2 class="jwt-footer__title"><?php esc_html_e( 'Information', 'jewelry-store' ); ?></h2>
			<?php
			wp_nav_menu(
				array(
					'theme_location' => 'footer',
					'container'      => false,
					'menu_class'     => 'jwt-footer__list',
					'depth'          => 1,
					'fallback_cb'    => false,
				)
			);
			?>
		</div>
		<div>
			<h2 class="jwt-footer__title"><?php esc_html_e( 'Contact', 'jewelry-store' ); ?></h2>
			<ul class="jwt-footer__list">
				<?php foreach ( jwt_contact_links() as [ $href, $label ] ) : ?>
					<li><a href="<?php echo esc_url( $href ); ?>" dir="ltr"><?php echo esc_html( $label ); ?></a></li>
				<?php endforeach; ?>
				<?php if ( ! jwt_contact_links() ) : ?>
					<li><?php esc_html_e( '[Contact details to be supplied]', 'jewelry-store' ); ?></li>
				<?php endif; ?>
			</ul>
		</div>
		<div>
			<h2 class="jwt-footer__title"><?php esc_html_e( 'Language', 'jewelry-store' ); ?></h2>
			<?php jwt_language_switcher( 'jwt-langs--footer' ); ?>
		</div>
	</div>
	<p class="jwt-container jwt-footer__legal">&copy; <?php echo esc_html( wp_date( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?></p>
</footer>
<?php wp_footer(); ?>
</body>
</html>
