<?php
/**
 * Wrapper for WooCommerce shop, category and product pages.
 */
defined( 'ABSPATH' ) || exit;
get_header();
?>
<main id="main" class="jwt-page jwt-shop jwt-container">
	<?php woocommerce_content(); ?>
</main>
<?php
get_footer();
