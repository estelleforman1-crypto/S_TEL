<?php defined( 'ABSPATH' ) || exit; ?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="jwt-skip" href="#main"><?php esc_html_e( 'Skip to content', 'jewelry-store' ); ?></a>
<?php if ( 'production' !== wp_get_environment_type() ) : ?>
	<div class="jwt-devbar" role="note"><?php esc_html_e( 'Development build: demo products, demo prices and placeholder brand. Nothing here is for sale.', 'jewelry-store' ); ?></div>
<?php endif; ?>
<header class="jwt-header">
	<div class="jwt-header__bar">
		<button class="jwt-menu-toggle" type="button" aria-expanded="false" aria-controls="jwt-nav">
			<span class="jwt-menu-toggle__icon" aria-hidden="true"></span>
			<span class="jwt-menu-toggle__label"><?php esc_html_e( 'Menu', 'jewelry-store' ); ?></span>
		</button>
		<a class="jwt-wordmark" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home"><?php bloginfo( 'name' ); ?></a>
		<div class="jwt-header__tools">
			<?php jwt_language_switcher( 'jwt-langs--header' ); ?>
			<?php echo jwt_bag_link(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in function. ?>
		</div>
	</div>
	<nav id="jwt-nav" class="jwt-nav" aria-label="<?php esc_attr_e( 'Main navigation', 'jewelry-store' ); ?>">
		<?php
		wp_nav_menu(
			array(
				'theme_location' => 'primary',
				'container'      => false,
				'menu_class'     => 'jwt-nav__list',
				'depth'          => 1,
				'fallback_cb'    => false,
			)
		);
		jwt_language_switcher( 'jwt-langs--menu' );
		?>
	</nav>
</header>
