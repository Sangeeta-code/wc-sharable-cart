<?php
/**
 * Database installation and migrations.
 *
 * @package WC_Share_Cart
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles the dedicated shared carts table.
 */
class WCSC_Database {

	/**
	 * Full table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wcsc_shared_carts';
	}

	/**
	 * Create or update the table with dbDelta().
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		// dbDelta is picky: two spaces after PRIMARY KEY, one definition per line.
		$sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  token_hash char(64) NOT NULL,
  user_id bigint(20) unsigned DEFAULT NULL,
  owner_hash char(64) DEFAULT NULL,
  cart_data longtext NOT NULL,
  product_count smallint(5) unsigned NOT NULL DEFAULT 0,
  total_qty int(10) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  expires_at datetime NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  open_count int(10) unsigned NOT NULL DEFAULT 0,
  import_count int(10) unsigned NOT NULL DEFAULT 0,
  last_opened_at datetime DEFAULT NULL,
  last_imported_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token_hash (token_hash),
  KEY user_id (user_id),
  KEY owner_hash (owner_hash),
  KEY status_expires (status,expires_at),
  KEY created_at (created_at)
) {$charset};";

		dbDelta( $sql );

		update_option( 'wcsc_db_version', WCSC_DB_VERSION, false );
	}

	/**
	 * Run version based migrations when the stored schema is older than the code.
	 */
	public static function maybe_upgrade() {
		$installed = get_option( 'wcsc_db_version', '0' );

		if ( version_compare( (string) $installed, WCSC_DB_VERSION, '>=' ) ) {
			return;
		}

		self::install();

		/*
		 * Future migrations go here, each guarded by a version check, e.g.
		 * if ( version_compare( $installed, '1.1.0', '<' ) ) { ... }
		 */

		// New routes may have been introduced: ask for a single, explicit flush.
		update_option( 'wcsc_needs_flush', 1, false );
	}

	/**
	 * Drop the table (used by uninstall only, when the admin opted in).
	 */
	public static function drop() {
		global $wpdb;
		$table = self::table();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
