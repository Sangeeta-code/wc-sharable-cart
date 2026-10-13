<?php
/**
 * Page wrapper for the shared cart view in classic (non-block) themes.
 *
 * @package WC_Share_Cart
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="primary" class="site-main wcsc-page-main">
	<?php echo do_shortcode( '[wcsc_shared_cart_page]' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
</main>
<?php
get_footer();
