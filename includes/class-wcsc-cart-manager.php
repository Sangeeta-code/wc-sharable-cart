<?php
/**
 * Cart session helpers, preview building and the merge / replace import.
 *
 * @package WC_Share_Cart
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cart manager.
 */
class WCSC_Cart_Manager {

	/**
	 * Make sure the WooCommerce session, customer and cart exist. Required in REST requests,
	 * where WooCommerce does not load the cart by itself.
	 *
	 * @return bool Whether a cart is available.
	 */
	public static function ensure_cart() {
		if ( ! function_exists( 'WC' ) ) {
			return false;
		}
		if ( null === WC()->cart || null === WC()->session ) {
			if ( function_exists( 'wc_load_cart' ) ) {
				wc_load_cart();
			}
		}
		return null !== WC()->cart && null !== WC()->session;
	}

	/**
	 * Identity used for per-customer rate limiting.
	 *
	 * @return string
	 */
	public static function session_identity() {
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			return 'u' . $user_id;
		}
		if ( WC()->session ) {
			return 's' . WC()->session->get_customer_id();
		}
		return 'anon';
	}

	/**
	 * Ownership marker for the current guest session (never the raw session id).
	 *
	 * @return string|null
	 */
	public static function guest_owner_hash() {
		if ( get_current_user_id() > 0 || ! WC()->session ) {
			return null;
		}
		$id = WC()->session->get_customer_id();
		return $id ? WCSC_Security::hash_owner( $id ) : null;
	}

	/**
	 * Build the preview rows for a shared cart. Prices are always read live from the catalogue.
	 *
	 * @param array $stored_items Items from the snapshot.
	 * @return array { rows, importable, issues, total_html }
	 */
	public static function build_preview( array $stored_items ) {
		$rows       = array();
		$importable = 0;
		$issues     = 0;
		$total      = 0.0;
		$running    = array();

		foreach ( $stored_items as $stored ) {
			$normalized = WCSC_Cart_Serializer::normalize_item( $stored );
			$name       = is_wp_error( $normalized ) ? __( 'Unknown item', 'wc-share-cart' ) : ( '' !== $normalized['name'] ? $normalized['name'] : __( 'A product', 'wc-share-cart' ) );
			$qty        = is_wp_error( $normalized ) ? 0 : $normalized['quantity'];

			$restored = WCSC_Cart_Serializer::restore_item( $stored );

			if ( is_wp_error( $restored ) ) {
				++$issues;
				$rows[] = array(
					'ok'           => false,
					'status'       => 'unavailable',
					'status_label' => $restored->get_error_message(),
					'name'         => $name,
					'meta'         => array(),
					'quantity'     => $qty,
					'image'        => wc_placeholder_img( 'woocommerce_thumbnail' ),
					'price_html'   => '',
					'subtotal'     => '',
				);
				continue;
			}

			$product = $restored['product'];
			$stock   = self::stock_problem( $product, $restored['quantity'], $running );

			$meta = array();
			if ( $product instanceof WC_Product_Variation ) {
				$flat = wc_get_formatted_variation( $product, true, true, false );
				if ( '' !== $flat ) {
					$meta[] = wp_strip_all_tags( $flat );
				}
			}
			if ( '' !== $restored['adapter'] ) {
				$adapter = WCSC_Cart_Serializer::get_adapter( $restored['adapter'] );
				if ( $adapter ) {
					foreach ( $adapter->describe( $restored['options'], $restored['parent'] ) as $line ) {
						$meta[] = wp_strip_all_tags( (string) $line );
					}
				}
			}

			$unit = (float) wc_get_price_to_display( $product );
			$line = (float) wc_get_price_to_display( $product, array( 'qty' => $restored['quantity'] ) );

			$row = array(
				'ok'           => null === $stock,
				'status'       => null === $stock ? 'in_stock' : $stock['code'],
				'status_label' => null === $stock ? __( 'Available', 'wc-share-cart' ) : $stock['message'],
				'name'         => $restored['parent']->get_name(),
				'meta'         => $meta,
				'quantity'     => $restored['quantity'],
				'image'        => $product->get_image( 'woocommerce_thumbnail' ),
				'price_html'   => wc_price( $unit ),
				'subtotal'     => wc_price( $line ),
			);

			if ( null === $stock ) {
				$running[ $product->get_stock_managed_by_id() ] = ( isset( $running[ $product->get_stock_managed_by_id() ] ) ? $running[ $product->get_stock_managed_by_id() ] : 0 ) + $restored['quantity'];
				++$importable;
				$total += $line;
			} else {
				++$issues;
			}
			$rows[] = $row;
		}

		return array(
			'rows'       => $rows,
			'importable' => $importable,
			'issues'     => $issues,
			'total_html' => wc_price( $total ),
		);
	}

	/**
	 * Why a product cannot be added in the requested quantity, or null when it can.
	 *
	 * @param WC_Product $product  Product or variation.
	 * @param int        $quantity Quantity to add.
	 * @param array      $already  Quantities already planned/in cart, keyed by stock managed id.
	 * @return array|null { code, message }
	 */
	public static function stock_problem( $product, $quantity, array $already = array() ) {
		if ( ! $product->is_in_stock() ) {
			return array(
				'code'    => 'out_of_stock',
				/* translators: %s: product name */
				'message' => __( 'Out of stock', 'wc-share-cart' ),
			);
		}

		$id   = $product->get_stock_managed_by_id();
		$have = isset( $already[ $id ] ) ? (int) $already[ $id ] : 0;

		if ( ! $product->has_enough_stock( $have + $quantity ) ) {
			$available = $product->get_stock_quantity();
			return array(
				'code'    => 'insufficient_stock',
				'message' => null === $available
					? __( 'Not enough stock', 'wc-share-cart' )
					/* translators: %s: available quantity */
					: sprintf( __( 'Only %s available', 'wc-share-cart' ), wc_format_stock_quantity_for_display( max( 0, (int) $available - $have ), $product ) ),
			);
		}

		return null;
	}

	/**
	 * Import a shared cart into the current visitor's cart.
	 *
	 * Everything is validated before the cart is touched. Items that are unavailable are skipped and
	 * reported, never substituted. If WooCommerce rejects an item while adding, the original cart is
	 * restored. A visitor is never left with an unexpectedly empty cart.
	 *
	 * @param array  $stored_items Snapshot items.
	 * @param string $mode         merge|replace.
	 * @return array|WP_Error { added, skipped[], mode, dropped_coupons[] }
	 */
	public static function import( array $stored_items, $mode ) {
		if ( ! in_array( $mode, array( 'merge', 'replace' ), true ) ) {
			return new WP_Error( 'wcsc_bad_mode', __( 'Please choose how to add the shared items.', 'wc-share-cart' ) );
		}
		if ( ! self::ensure_cart() ) {
			return new WP_Error( 'wcsc_no_cart', __( 'Your cart is not available right now. Please try again.', 'wc-share-cart' ) );
		}

		$cart = WC()->cart;

		// 1. Validate everything before touching the cart.
		$existing = 'merge' === $mode ? $cart->get_cart_item_quantities() : array();
		$plan     = self::plan_import( $stored_items, $existing );

		if ( empty( $plan['prepared'] ) ) {
			return new WP_Error(
				'wcsc_nothing_to_import',
				__( 'None of the shared items can be added right now, so your cart was left unchanged.', 'wc-share-cart' ),
				array( 'skipped' => $plan['skipped'] )
			);
		}

		// 2. Snapshot the current cart and notices.
		$backup_contents = $cart->get_cart_contents();
		$backup_coupons  = $cart->get_applied_coupons();
		$notices_before  = wc_get_notices();

		if ( 'replace' === $mode ) {
			$cart->empty_cart( false );
		}

		// 3. Add the shared items through WooCommerce so all validation filters run.
		$failed = null;
		$added  = 0;
		foreach ( $plan['prepared'] as $r ) {
			$adapter   = '' !== $r['adapter'] ? WCSC_Cart_Serializer::get_adapter( $r['adapter'] ) : null;
			$lifecycle = $adapter instanceof WCSC_Cart_Item_Lifecycle ? $adapter : null;

			if ( $lifecycle ) {
				$lifecycle->before_add( $r );
			}
			$key = $cart->add_to_cart( $r['product_id'], $r['quantity'], $r['variation_id'], $r['variation'], $r['cart_item_data'] );
			if ( $lifecycle ) {
				$lifecycle->after_add( $r );
			}

			// Confirm the selection really arrived in the cart; otherwise treat it as a failure (rollback).
			if ( $key && $lifecycle ) {
				$added_item = $cart->get_cart_item( $key );
				if ( ! is_array( $added_item ) || ! $lifecycle->verify_added( $added_item, $r ) ) {
					$key = false;
				}
			}

			if ( ! $key ) {
				$failed = $r;
				break;
			}
			++$added;
		}

		if ( null !== $failed ) {
			// 4a. Roll back to the exact original cart.
			$cart->empty_cart( false );
			$cart->set_cart_contents( $backup_contents );
			$cart->set_applied_coupons( $backup_coupons );
			$cart->calculate_totals();
			$cart->set_session();
			wc_set_notices( $notices_before );

			WCSC_Security::log( 'Import rolled back because WooCommerce rejected an item.', 'notice', array( 'product_id' => $failed['product_id'] ) );

			return new WP_Error(
				'wcsc_import_failed',
				sprintf(
					/* translators: %s: product name */
					__( '"%s" could not be added to your cart, so nothing was changed. It may have just sold out or its options changed.', 'wc-share-cart' ),
					$failed['name']
				)
			);
		}

		// 4b. Replace: re-apply the visitor's own coupons where still valid.
		$dropped = array();
		if ( 'replace' === $mode ) {
			foreach ( $backup_coupons as $code ) {
				if ( ! $cart->apply_coupon( $code ) ) {
					$dropped[] = $code;
				}
			}
		}

		// Remove error notices produced while adding/applying (we report our own summary).
		wc_set_notices( $notices_before );

		$cart->calculate_totals();
		$cart->set_session();

		return array(
			'added'           => $added,
			'skipped'         => $plan['skipped'],
			'mode'            => $mode,
			'dropped_coupons' => $dropped,
		);
	}

	/**
	 * Pure planning step: restore every item, drop the unavailable ones and track stock use.
	 *
	 * @param array $stored_items Snapshot items.
	 * @param array $existing     Quantities already in the cart keyed by stock managed id.
	 * @return array { prepared[], skipped[] }
	 */
	public static function plan_import( array $stored_items, array $existing = array() ) {
		$prepared = array();
		$skipped  = array();
		$running  = $existing;

		foreach ( $stored_items as $stored ) {
			$restored = WCSC_Cart_Serializer::restore_item( $stored );
			if ( is_wp_error( $restored ) ) {
				$skipped[] = array(
					'name'   => WCSC_Cart_Serializer::error_name( $restored ),
					'reason' => $restored->get_error_message(),
				);
				continue;
			}

			$problem = self::stock_problem( $restored['product'], $restored['quantity'], $running );
			if ( $problem ) {
				$skipped[] = array(
					'name'   => $restored['name'],
					'reason' => $problem['message'],
				);
				continue;
			}

			$id             = $restored['product']->get_stock_managed_by_id();
			$running[ $id ] = ( isset( $running[ $id ] ) ? (int) $running[ $id ] : 0 ) + $restored['quantity'];
			$prepared[]     = $restored;
		}

		return array(
			'prepared' => $prepared,
			'skipped'  => $skipped,
		);
	}
}
