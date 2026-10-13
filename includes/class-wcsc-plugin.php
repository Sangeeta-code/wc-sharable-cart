<?php
/**
 * Main plugin controller.
 *
 * @package WC_Share_Cart
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap.
 */
final class WCSC_Plugin {

	/**
	 * Singleton.
	 *
	 * @var WCSC_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Instance.
	 *
	 * @return WCSC_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->includes();

		WCSC_Database::maybe_upgrade();

		add_action( 'init', array( $this, 'init' ), 5 );
		add_action( 'init', array( $this, 'maybe_flush_rewrites' ), 99 );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		// Built-in adapters run last so that adapters registered by product plugins win for their own product types.
		add_filter( 'wcsc_cart_item_adapters', array( $this, 'register_builtin_adapters' ), 99 );
		add_action( 'before_woocommerce_init', array( $this, 'declare_compatibility' ) );
		add_filter( 'plugin_action_links_' . WCSC_BASENAME, array( $this, 'action_links' ) );

		new WCSC_REST_API();
		new WCSC_Analytics();
		new WCSC_Cleanup();
		new WCSC_Public();
		new WCSC_Cart_Block();
		new WCSC_Account();

		if ( is_admin() ) {
			new WCSC_Admin();
		}
	}

	/**
	 * Load class files.
	 */
	private function includes() {
		require_once WCSC_PATH . 'admin/class-wcsc-settings.php';
		require_once WCSC_PATH . 'includes/class-wcsc-security.php';
		require_once WCSC_PATH . 'includes/class-wcsc-cart-serializer.php';
		require_once WCSC_PATH . 'includes/class-wcsc-cart-manager.php';
		require_once WCSC_PATH . 'includes/integrations/class-wcsc-adapter-wcsatt.php';
		require_once WCSC_PATH . 'includes/class-wcsc-share-links.php';
		require_once WCSC_PATH . 'includes/class-wcsc-analytics.php';
		require_once WCSC_PATH . 'includes/class-wcsc-cleanup.php';
		require_once WCSC_PATH . 'includes/class-wcsc-rest-api.php';
		require_once WCSC_PATH . 'public/class-wcsc-public.php';
		require_once WCSC_PATH . 'public/class-wcsc-cart-block.php';
		require_once WCSC_PATH . 'public/class-wcsc-account.php';

		if ( is_admin() ) {
			require_once WCSC_PATH . 'admin/class-wcsc-admin.php';
		}
	}

	/**
	 * Init: text domain and rewrite registration (no flushing here).
	 */
	public function init() {
		load_plugin_textdomain( 'wc-share-cart', false, dirname( WCSC_BASENAME ) . '/languages' );
		wcsc_register_rewrites();
	}

	/**
	 * Flush rewrite rules once, only when explicitly flagged (settings change, upgrade).
	 */
	public function maybe_flush_rewrites() {
		if ( get_option( 'wcsc_needs_flush' ) ) {
			flush_rewrite_rules( false );
			delete_option( 'wcsc_needs_flush' );
		}
	}

	/**
	 * Built-in adapters.
	 *
	 * @param array $adapters Adapters.
	 * @return array
	 */
	public function register_builtin_adapters( $adapters ) {
		$adapters[] = new WCSC_Adapter_WCSATT();
		return $adapters;
	}

	/**
	 * Register the public query variable.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public function query_vars( $vars ) {
		$vars[] = 'wcsc_token';
		return $vars;
	}

	/**
	 * WooCommerce feature compatibility declarations.
	 *
	 * HPOS / Cart & Checkout Blocks compatibility is NOT declared by default: the declaration is a
	 * claim that must only be made after the plugin has been tested on a real store. After testing,
	 * enable it with: add_filter( 'wcsc_declare_hpos_compatibility', '__return_true' );
	 * and add_filter( 'wcsc_declare_blocks_compatibility', '__return_true' );
	 */
	public function declare_compatibility() {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			return;
		}
		if ( apply_filters( 'wcsc_declare_hpos_compatibility', false ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WCSC_FILE, true );
		}
		if ( apply_filters( 'wcsc_declare_blocks_compatibility', false ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WCSC_FILE, true );
		}
	}

	/**
	 * Plugins list quick links.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=wc-share-cart&tab=settings' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'wc-share-cart' ) . '</a>' );
		return $links;
	}
}
