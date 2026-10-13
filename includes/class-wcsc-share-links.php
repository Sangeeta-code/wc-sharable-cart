<?php
/**
 * Shared cart records: creation, lookup, status, revocation, deletion.
 *
 * @package WC_Share_Cart
 */

defined( 'ABSPATH' ) || exit;

/**
 * Share links repository.
 */
class WCSC_Share_Links {

	const CACHE_GROUP = 'wcsc';

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		return WCSC_Database::table();
	}

	/**
	 * Current UTC time as MySQL datetime.
	 *
	 * @param int|null $timestamp Optional timestamp.
	 * @return string
	 */
	public static function mysql_utc( $timestamp = null ) {
		return gmdate( 'Y-m-d H:i:s', null === $timestamp ? time() : (int) $timestamp );
	}

	/**
	 * Convert a stored UTC datetime to a timestamp.
	 *
	 * @param string|null $mysql Datetime.
	 * @return int
	 */
	public static function to_timestamp( $mysql ) {
		if ( empty( $mysql ) ) {
			return 0;
		}
		$dt = date_create_immutable( $mysql . ' +0000' );
		return $dt ? $dt->getTimestamp() : 0;
	}

	/**
	 * Localised date/time for display.
	 *
	 * @param string|null $mysql Stored UTC datetime.
	 * @return string
	 */
	public static function format_date( $mysql ) {
		$ts = self::to_timestamp( $mysql );
		return $ts ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : '—';
	}

	/**
	 * Clamp an expiration (in days) to the configured limits.
	 *
	 * @param int|null $days Requested days.
	 * @return int
	 */
	public static function clamp_days( $days = null ) {
		$default = max( 1, WCSC_Settings::get_int( 'default_expiry_days' ) );
		$max     = max( 1, WCSC_Settings::get_int( 'max_validity_days' ) );
		$days    = null === $days ? $default : (int) $days;
		return max( 1, min( $max, $days ) );
	}

	/**
	 * Create a share link from an already serialized snapshot.
	 *
	 * @param array       $items      Serialized items (see WCSC_Cart_Serializer).
	 * @param int         $user_id    User id (0 for guests).
	 * @param string|null $owner_hash Guest ownership marker.
	 * @param int|null    $days       Expiration in days.
	 * @return array|WP_Error { id, token, url, expires_at }
	 */
	public static function create( array $items, $user_id = 0, $owner_hash = null, $days = null ) {
		global $wpdb;

		if ( empty( $items ) ) {
			return new WP_Error( 'wcsc_empty_cart', __( 'Your cart is empty.', 'wc-share-cart' ), array( 'status' => 400 ) );
		}

		try {
			$token = WCSC_Security::generate_token();
		} catch ( Exception $e ) {
			WCSC_Security::log( 'Secure random generator unavailable: ' . $e->getMessage(), 'error' );
			return new WP_Error( 'wcsc_token_failed', __( 'A secure link could not be generated. Please try again later.', 'wc-share-cart' ), array( 'status' => 500 ) );
		}

		$days    = self::clamp_days( $days );
		$now     = time();
		$expires = $now + ( $days * DAY_IN_SECONDS );
		$qty     = 0;
		foreach ( $items as $item ) {
			$qty += isset( $item['quantity'] ) ? (int) $item['quantity'] : 0;
		}

		$payload = wp_json_encode(
			array(
				'v'     => WCSC_Cart_Serializer::SCHEMA_VERSION,
				'items' => array_values( $items ),
			)
		);

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::table(),
			array(
				'token_hash'    => WCSC_Security::hash_token( $token ),
				'user_id'       => $user_id > 0 ? (int) $user_id : null,
				'owner_hash'    => $user_id > 0 ? null : $owner_hash,
				'cart_data'     => $payload,
				'product_count' => count( $items ),
				'total_qty'     => $qty,
				'created_at'    => self::mysql_utc( $now ),
				'expires_at'    => self::mysql_utc( $expires ),
				'status'        => 'active',
			),
			array( '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			WCSC_Security::log( 'Could not insert shared cart row.', 'error', array( 'db_error' => $wpdb->last_error ) );
			return new WP_Error( 'wcsc_db_error', __( 'Your cart could not be saved. Please try again.', 'wc-share-cart' ), array( 'status' => 500 ) );
		}

		WCSC_Analytics::increment( 'created' );

		return array(
			'id'         => (int) $wpdb->insert_id,
			'token'      => $token,
			'url'        => self::get_url( $token ),
			'expires_at' => self::mysql_utc( $expires ),
		);
	}

	/**
	 * Public URL for a token. Uses the rewrite rule with a query-string fallback.
	 *
	 * @param string $token Raw token.
	 * @return string
	 */
	public static function get_url( $token ) {
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( '/' . WCSC_SLUG . '/' . rawurlencode( $token ) . '/' );
		}
		return add_query_arg( 'wcsc_token', rawurlencode( $token ), home_url( '/' ) );
	}

	/**
	 * Look up a record by raw token.
	 *
	 * @param string $token Raw token.
	 * @return object|null
	 */
	public static function get_by_token( $token ) {
		global $wpdb;

		if ( ! WCSC_Security::is_valid_token_format( $token ) ) {
			return null;
		}

		$hash   = WCSC_Security::hash_token( $token );
		$cached = wp_cache_get( $hash, self::CACHE_GROUP );
		if ( is_object( $cached ) ) {
			return $cached;
		}

		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token_hash = %s", $hash ) ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $row ) {
			wp_cache_set( $hash, $row, self::CACHE_GROUP, 60 );
		}
		return $row ? $row : null;
	}

	/**
	 * Look up a record by internal id.
	 *
	 * @param int $id Id.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $row : null;
	}

	/**
	 * Effective status of a record: active, expired or revoked.
	 *
	 * @param object $row    Record.
	 * @param int    $now    Timestamp (tests).
	 * @return string
	 */
	public static function effective_status( $row, $now = null ) {
		$now = null === $now ? time() : (int) $now;

		if ( 'revoked' === $row->status ) {
			return 'revoked';
		}
		if ( 'expired' === $row->status || self::to_timestamp( $row->expires_at ) <= $now ) {
			return 'expired';
		}
		return 'active';
	}

	/**
	 * Decoded items of a record.
	 *
	 * @param object $row Record.
	 * @return array
	 */
	public static function get_items( $row ) {
		$data = json_decode( (string) $row->cart_data, true );
		if ( ! is_array( $data ) || empty( $data['items'] ) || ! is_array( $data['items'] ) ) {
			return array();
		}
		return array_values( $data['items'] );
	}

	/**
	 * Count an open of the preview page.
	 *
	 * @param object $row Record.
	 */
	public static function record_open( $row ) {
		global $wpdb;
		$table = self::table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET open_count = open_count + 1, last_opened_at = %s WHERE id = %d", self::mysql_utc(), (int) $row->id ) ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 0 === (int) $row->open_count ) {
			WCSC_Analytics::increment( 'opened' );
		}
		self::forget( $row );
	}

	/**
	 * Count a successful import.
	 *
	 * @param object $row  Record.
	 * @param string $mode merge|replace.
	 */
	public static function record_import( $row, $mode ) {
		global $wpdb;
		$table = self::table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET import_count = import_count + 1, last_imported_at = %s WHERE id = %d", self::mysql_utc(), (int) $row->id ) ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		WCSC_Analytics::increment( 'imports' );
		WCSC_Analytics::increment( 'replace' === $mode ? 'replace' : 'merge' );
		self::forget( $row );
	}

	/**
	 * Revoke a record.
	 *
	 * @param int $id Id.
	 * @return bool Whether a record was revoked.
	 */
	public static function revoke( $id ) {
		global $wpdb;
		$table   = self::table();
		$row     = self::get( $id );
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'revoked' WHERE id = %d AND status <> 'revoked'", (int) $id ) ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $changed ) {
			WCSC_Analytics::increment( 'revoked' );
		}
		if ( $row ) {
			self::forget( $row );
		}
		return (bool) $changed;
	}

	/**
	 * Delete records.
	 *
	 * @param int[] $ids Ids.
	 * @return int Deleted rows.
	 */
	public static function delete( array $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( empty( $ids ) ) {
			return 0;
		}
		$table        = self::table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		foreach ( $ids as $id ) {
			$row = self::get( $id );
			if ( $row ) {
				self::forget( $row );
			}
		}
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids ) ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
	}

	/**
	 * Drop the cached copy of a record.
	 *
	 * @param object $row Record.
	 */
	private static function forget( $row ) {
		wp_cache_delete( $row->token_hash, self::CACHE_GROUP );
	}

	/**
	 * Whether the visitor owns the record.
	 *
	 * @param object      $row        Record.
	 * @param int         $user_id    Current user id (0 for guests).
	 * @param string|null $owner_hash Guest ownership marker for the current session.
	 * @return bool
	 */
	public static function is_owner( $row, $user_id, $owner_hash = null ) {
		if ( $user_id > 0 && ! empty( $row->user_id ) ) {
			return (int) $row->user_id === (int) $user_id;
		}
		if ( empty( $row->user_id ) && ! empty( $row->owner_hash ) && ! empty( $owner_hash ) ) {
			return hash_equals( (string) $row->owner_hash, (string) $owner_hash );
		}
		return false;
	}

	/**
	 * Paginated query used by the admin list and the My Account page.
	 *
	 * @param array $args { status, search, date_from, date_to, user_id, orderby, order, per_page, page }
	 * @return array { rows, total }
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'status'    => '',
				'search'    => '',
				'date_from' => '',
				'date_to'   => '',
				'user_id'   => 0,
				'orderby'   => 'id',
				'order'     => 'DESC',
				'per_page'  => 20,
				'page'      => 1,
			)
		);

		$table = self::table();
		$where = array( '1=1' );
		$vals  = array();
		$now   = self::mysql_utc();

		switch ( $args['status'] ) {
			case 'active':
				$where[] = "status = 'active' AND expires_at > %s";
				$vals[]  = $now;
				break;
			case 'expired':
				$where[] = "status <> 'revoked' AND (status = 'expired' OR expires_at <= %s)";
				$vals[]  = $now;
				break;
			case 'revoked':
				$where[] = "status = 'revoked'";
				break;
		}

		if ( ! empty( $args['user_id'] ) ) {
			$where[] = 'user_id = %d';
			$vals[]  = (int) $args['user_id'];
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args['date_from'] ) ) {
			$where[] = 'created_at >= %s';
			$vals[]  = $args['date_from'] . ' 00:00:00';
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args['date_to'] ) ) {
			$where[] = 'created_at <= %s';
			$vals[]  = $args['date_to'] . ' 23:59:59';
		}

		$search = trim( (string) $args['search'] );
		if ( '' !== $search ) {
			if ( ctype_digit( $search ) ) {
				$where[] = '(id = %d OR cart_data LIKE %s)';
				$vals[]  = (int) $search;
				$vals[]  = '%' . $wpdb->esc_like( $search ) . '%';
			} else {
				$where[] = 'cart_data LIKE %s';
				$vals[]  = '%' . $wpdb->esc_like( wp_json_encode( $search ) ? trim( wp_json_encode( $search ), '"' ) : $search ) . '%';
			}
		}

		$allowed_order = array( 'id', 'created_at', 'expires_at', 'product_count', 'total_qty', 'open_count', 'import_count' );
		$orderby       = in_array( $args['orderby'], $allowed_order, true ) ? $args['orderby'] : 'id';
		$order         = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$per_page      = max( 1, min( 200, (int) $args['per_page'] ) );
		$offset        = max( 0, ( (int) $args['page'] - 1 ) * $per_page );
		$where_sql     = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( $vals ? $wpdb->get_var( $wpdb->prepare( $count_sql, $vals ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.NotPrepared

		$rows_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$rows     = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $vals, array( $per_page, $offset ) ) ) ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'rows'  => $rows ? $rows : array(),
			'total' => $total,
		);
	}
}
