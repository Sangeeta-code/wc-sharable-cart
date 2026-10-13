<?php
/**
 * Activation and deactivation routines.
 *
 * @package WC_Share_Cart
 */

defined( 'ABSPATH' ) || exit;

/**
 * Activator.
 */
class WCSC_Activator {

	/**
	 * Activate (supports network activation on multisite).
	 *
	 * @param bool $network_wide Whether this is a network activation.
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $blog_id ) {
				switch_to_blog( $blog_id );
				self::activate_single_site();
				restore_current_blog();
			}
			return;
		}

		self::activate_single_site();
	}

	/**
	 * Per-site activation.
	 */
	private static function activate_single_site() {
		WCSC_Database::install();

		// Default settings (only if not present yet). Loaded lazily so activation stays light.
		require_once WCSC_PATH . 'admin/class-wcsc-settings.php';
		WCSC_Settings::ensure_defaults();

		if ( function_exists( 'wcsc_register_rewrites' ) ) {
			wcsc_register_rewrites();
		}
		flush_rewrite_rules( false );
		delete_option( 'wcsc_needs_flush' );

		if ( ! wp_next_scheduled( 'wcsc_cleanup_event' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wcsc_cleanup_event' );
		}
	}

	/**
	 * Deactivate. Customer data is intentionally kept.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'wcsc_cleanup_event' );
		flush_rewrite_rules( false );
	}
}
