<?php
/**
 * Analytics: counters, summary and verified purchase attribution.
 *
 * Attribution rules: an order is only attributed when the buyer imported a shared cart
 * in the same session AND the order contains at least one product from that cart.
 * It is only counted as a purchase once the order is paid (processing / completed).
 * An import alone is never a purchase.
 *
 * @package WC_Share_Cart
 */

defined( 'ABSPATH' ) || exit;

/**
 * Analytics.
 */
class WCSC_Analytics {

	const OPTION          = 'wcsc_stats';
	const SESSION_KEY     = 'wcsc_cart_id';
	const META_CART       = '_wcsc_cart_id';
	const META_TOTAL      = '_wcsc_attributed_total';
	const META_COUNTED    = '_wcsc_counted';

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_action( 'woocommerce_checkout_create_order', array( $this, 'attach_to_order' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'attach_to_order' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'order_status_changed' ), 20, 4 );
	}

	/**
	 * Increment a counter.
	 *
	 * @param string $key   Counter key.
	 * @param int    $by    Amount.
	 */
	public static function increment( $key, $by = 1 ) {
		$stats         = get_option( self::OPTION, array() );
		$stats         = is_array( $stats ) ? $stats : array();
		$stats[ $key ] = ( isset( $stats[ $key ] ) ? (float) $stats[ $key ] : 0 ) + $by;
		update_option( self::OPTION, $stats, false );
	}

	/**
	 * Summary for the admin dashboard.
	 *
	 * @return array
	 */
	public static function summary() {
		$stats = get_option( self::OPTION, array() );
		$stats = is_array( $stats ) ? $stats : array();
		$get   = static function ( $key ) use ( $stats ) {
			return isset( $stats[ $key ] ) ? $stats[ $key ] : 0;
		};

		return array(
			'created'   => (int) $get( 'created' ),
			'opened'    => (int) $get( 'opened' ),
			'imports'   => (int) $get( 'imports' ),
			'merge'     => (int) $get( 'merge' ),
			'replace'   => (int) $get( 'replace' ),
			'revoked'   => (int) $get( 'revoked' ),
			'expired'   => (int) $get( 'expired' ),
			'purchases' => (int) $get( 'purchases' ),
			'revenue'   => (float) $get( 'revenue' ),
		);
	}

	/**
	 * Most commonly shared products (from the latest records), cached for an hour.
	 *
	 * @param int $limit Number of products.
	 * @return array[] { product_id, name, count }
	 */
	public static function top_products( $limit = 5 ) {
		$cached = get_transient( 'wcsc_top_products' );
		if ( is_array( $cached ) ) {
			return array_slice( $cached, 0, $limit );
		}

		global $wpdb;
		$table = WCSC_Database::table();
		$rows  = $wpdb->get_col( "SELECT cart_data FROM {$table} ORDER BY id DESC LIMIT 500" ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$counts = array();
		foreach ( (array) $rows as $json ) {
			$data = json_decode( (string) $json, true );
			if ( empty( $data['items'] ) || ! is_array( $data['items'] ) ) {
				continue;
			}
			foreach ( $data['items'] as $item ) {
				if ( empty( $item['product_id'] ) ) {
					continue;
				}
				$pid = (int) $item['product_id'];
				if ( ! isset( $counts[ $pid ] ) ) {
					$counts[ $pid ] = array(
						'product_id' => $pid,
						'name'       => isset( $item['name'] ) ? (string) $item['name'] : '#' . $pid,
						'count'      => 0,
					);
				}
				++$counts[ $pid ]['count'];
			}
		}

		usort(
			$counts,
			static function ( $a, $b ) {
				return $b['count'] <=> $a['count'];
			}
		);
		$counts = array_slice( $counts, 0, 10 );
		set_transient( 'wcsc_top_products', $counts, HOUR_IN_SECONDS );

		return array_slice( $counts, 0, $limit );
	}

	/**
	 * Tag a new order with the shared cart the buyer imported (if it really contains its products).
	 *
	 * @param WC_Order $order Order being created.
	 */
	public function attach_to_order( $order ) {
		if ( ! $order instanceof WC_Order || ! WC()->session ) {
			return;
		}

		$cart_id = (int) WC()->session->get( self::SESSION_KEY );
		if ( $cart_id <= 0 ) {
			return;
		}

		$row = WCSC_Share_Links::get( $cart_id );
		if ( ! $row ) {
			return;
		}

		$shared_ids = array();
		foreach ( WCSC_Share_Links::get_items( $row ) as $item ) {
			if ( ! empty( $item['product_id'] ) ) {
				$shared_ids[ (int) $item['product_id'] ] = true;
			}
		}

		$attributed = 0.0;
		foreach ( $order->get_items() as $line ) {
			if ( $line instanceof WC_Order_Item_Product && isset( $shared_ids[ (int) $line->get_product_id() ] ) ) {
				$attributed += (float) $line->get_total();
			}
		}

		if ( $attributed <= 0 && ! $this->order_has_shared_product( $order, $shared_ids ) ) {
			return;
		}

		// CRUD meta API: compatible with both posts and HPOS order storage. WooCommerce saves the order afterwards.
		$order->update_meta_data( self::META_CART, $cart_id );
		$order->update_meta_data( self::META_TOTAL, wc_format_decimal( $attributed ) );
	}

	/**
	 * Whether an order contains at least one shared product.
	 *
	 * @param WC_Order $order      Order.
	 * @param array    $shared_ids Product ids as keys.
	 * @return bool
	 */
	private function order_has_shared_product( $order, array $shared_ids ) {
		foreach ( $order->get_items() as $line ) {
			if ( $line instanceof WC_Order_Item_Product && isset( $shared_ids[ (int) $line->get_product_id() ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Count a purchase only when the order is paid; reverse it when the order is refunded/cancelled/failed.
	 *
	 * @param int      $order_id Order id.
	 * @param string   $from     Old status.
	 * @param string   $to       New status.
	 * @param WC_Order $order    Order.
	 */
	public function order_status_changed( $order_id, $from, $to, $order ) {
		if ( ! $order instanceof WC_Order || ! $order->get_meta( self::META_CART ) ) {
			return;
		}

		$counted = (bool) $order->get_meta( self::META_COUNTED );
		$amount  = (float) $order->get_meta( self::META_TOTAL );

		if ( in_array( $to, array( 'processing', 'completed' ), true ) && ! $counted ) {
			self::increment( 'purchases' );
			self::increment( 'revenue', $amount );
			$order->update_meta_data( self::META_COUNTED, 1 );
			$order->save_meta_data();
		} elseif ( in_array( $to, array( 'cancelled', 'refunded', 'failed' ), true ) && $counted ) {
			self::increment( 'purchases', -1 );
			self::increment( 'revenue', -$amount );
			$order->delete_meta_data( self::META_COUNTED );
			$order->save_meta_data();
		}
	}
}
