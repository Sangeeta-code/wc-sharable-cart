<?php
/**
 * Plugin Name:       WC Share Cart
 * Description:       Let WooCommerce customers share their shopping cart through a unique link, WhatsApp, email or QR code. Recipients can preview the cart and merge it with, or replace, their own cart.
 * Version:           1.4.2
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Sangeeta Mondal
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wc-share-cart
 *
 * @package WC_Share_Cart
 */

defined( 'ABSPATH' ) || exit;

define( 'WCSC_VERSION', '1.0.0' );
define( 'WCSC_DB_VERSION', '1.0.0' );
define( 'WCSC_MIN_PHP', '7.4' );
define( 'WCSC_FILE', __FILE__ );
define( 'WCSC_PATH', plugin_dir_path( __FILE__ ) );
define( 'WCSC_URL', plugin_dir_url( __FILE__ ) );
define( 'WCSC_BASENAME', plugin_basename( __FILE__ ) );
define( 'WCSC_SLUG', 'shared-cart' );
define( 'WCSC_ACCOUNT_ENDPOINT', 'wcsc-shared-carts' );

if ( version_compare( PHP_VERSION, WCSC_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>' . esc_html(
				sprintf(
					/* translators: %s: minimum PHP version */
					__( 'WC Share Cart requires PHP %s or higher. The plugin has been paused.', 'wc-share-cart' ),
					WCSC_MIN_PHP
				)
			) . '</p></div>';
		}
	);
	return;
}

require_once WCSC_PATH . 'includes/class-wcsc-database.php';
require_once WCSC_PATH . 'includes/class-wcsc-activator.php';

register_activation_hook( __FILE__, array( 'WCSC_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WCSC_Activator', 'deactivate' ) );

/**
 * Register the shared-cart rewrite rule and the My Account endpoint.
 * Rewrite rules are only *flushed* on activation or when explicitly required.
 */
function wcsc_register_rewrites() {
	add_rewrite_rule(
		'^' . WCSC_SLUG . '/([A-Za-z0-9_-]{43})/?$',
		'index.php?wcsc_token=$matches[1]',
		'top'
	);
	add_rewrite_endpoint( WCSC_ACCOUNT_ENDPOINT, EP_ROOT | EP_PAGES );
}

/**
 * Admin notice shown when WooCommerce is not active.
 */
function wcsc_missing_woocommerce_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>' . esc_html__( 'WC Share Cart requires WooCommerce to be installed and active. The plugin has been paused until WooCommerce is available.', 'wc-share-cart' ) . '</p></div>';
}

/**
 * Boot the plugin once all plugins are loaded.
 */
function wcsc_bootstrap() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'wcsc_missing_woocommerce_notice' );
		return;
	}

	require_once WCSC_PATH . 'includes/class-wcsc-plugin.php';
	WCSC_Plugin::instance();
}
add_action( 'plugins_loaded', 'wcsc_bootstrap', 20 );
