<?php
/**
 * Uninstall routine.
 *
 * Data is only removed when the administrator explicitly enabled
 * "Delete ALL plugin data when the plugin is deleted" in the settings.
 *
 * @package WC_Share_Cart
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove the data of the current site.
 */
function wcsc_uninstall_single_site() {
	global $wpdb;

	$settings = get_option( 'wcsc_settings', array() );
	if ( empty( $settings['delete_on_uninstall'] ) ) {
		return; // Keep everything.
	}

	$table = $wpdb->prefix . 'wcsc_shared_carts';
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	delete_option( 'wcsc_settings' );
	delete_option( 'wcsc_db_version' );
	delete_option( 'wcsc_stats' );
	delete_option( 'wcsc_needs_flush' );
	delete_transient( 'wcsc_top_products' );

	// Rate limit counters.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_wcsc\_rl\_%' OR option_name LIKE '\_transient\_timeout\_wcsc\_rl\_%'" ); // phpcs:ignore WordPress.DB

	wp_clear_scheduled_hook( 'wcsc_cleanup_event' );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $blog_id ) {
		switch_to_blog( $blog_id );
		wcsc_uninstall_single_site();
		restore_current_blog();
	}
} else {
	wcsc_uninstall_single_site();
}
