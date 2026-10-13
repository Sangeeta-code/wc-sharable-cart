<?php
/**
 * WP-Cron cleanup, user data removal and privacy integration.
 *
 * @package WC_Share_Cart
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cleanup.
 */
class WCSC_Cleanup {

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_action( 'wcsc_cleanup_event', array( $this, 'run' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
		add_action( 'delete_user', array( $this, 'delete_user_records' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_action( 'admin_init', array( $this, 'privacy_policy_content' ) );
	}

	/**
	 * Re-schedule the cron event if it was lost (e.g. after a migration).
	 */
	public function ensure_scheduled() {
		if ( ! wp_next_scheduled( 'wcsc_cleanup_event' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wcsc_cleanup_event' );
		}
	}

	/**
	 * Daily job. Marks overdue links as expired and, if enabled, deletes old inactive records.
	 * Active links are never deleted before they expire.
	 */
	public function run() {
		global $wpdb;
		$table = WCSC_Database::table();
		$now   = WCSC_Share_Links::mysql_utc();

		$marked = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'expired' WHERE status = 'active' AND expires_at <= %s", $now ) ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $marked ) {
			WCSC_Analytics::increment( 'expired', (int) $marked );
		}

		if ( ! WCSC_Settings::get_bool( 'auto_cleanup' ) ) {
			return;
		}

		$days   = WCSC_Settings::get_int( 'cleanup_retention_days' );
		$cutoff = WCSC_Share_Links::mysql_utc( time() - ( $days * DAY_IN_SECONDS ) );

		// Batched to keep the query light on large tables.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE status <> 'active' AND expires_at < %s LIMIT 1000", $cutoff ) ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Remove a deleted user's shared carts.
	 *
	 * @param int $user_id User id.
	 */
	public function delete_user_records( $user_id ) {
		global $wpdb;
		$table = WCSC_Database::table();
		$wpdb->delete( $table, array( 'user_id' => (int) $user_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Register the personal data eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public function register_eraser( $erasers ) {
		$erasers['wc-share-cart'] = array(
			'eraser_friendly_name' => __( 'WC Share Cart shared carts', 'wc-share-cart' ),
			'callback'             => array( $this, 'erase_personal_data' ),
		);
		return $erasers;
	}

	/**
	 * Eraser callback: deletes shared carts owned by the given email's user account.
	 *
	 * @param string $email Email address.
	 * @return array
	 */
	public function erase_personal_data( $email ) {
		$user    = get_user_by( 'email', $email );
		$removed = false;
		if ( $user ) {
			global $wpdb;
			$removed = (bool) $wpdb->delete( WCSC_Database::table(), array( 'user_id' => (int) $user->ID ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Suggested privacy policy text.
	 */
	public function privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			'WC Share Cart',
			wp_kses_post(
				'<p class="privacy-policy-tutorial">' . __( 'WC Share Cart stores a snapshot of the products, variations and quantities a customer chooses to share, together with the creation/expiry dates and view/import counters. Logged-in customers\' links are linked to their user id. Guests\' links are linked to an irreversible hash derived from their shopping session. No names, emails, addresses or payment details are stored in a shared cart. Anyone who has the link can view the shared products.', 'wc-share-cart' ) . '</p>'
			)
		);
	}
}
