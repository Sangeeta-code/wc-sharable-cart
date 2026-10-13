<?php
/**
 * Safe cart item serialization and restoration.
 *
 * Only an explicit whitelist is ever stored: product id, variation id, variation
 * attributes, quantity, and public selections claimed by a registered adapter.
 * The WooCommerce cart session is never serialized.
 *
 * @package WC_Share_Cart
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/interface-wcsc-cart-item-adapter.php';

/**
 * Serializer.
 */
class WCSC_Cart_Serializer {

	const SCHEMA_VERSION = 1;

	/**
	 * Keys WooCommerce itself puts on every cart item. They are known and safe to ignore.
	 */
	const CORE_KEYS = array(
		'key',
		'product_id',
		'variation_id',
		'variation',
		'quantity',
		'data',
		'data_hash',
		'line_tax_data',
		'line_subtotal',
		'line_subtotal_tax',
		'line_total',
		'line_tax',
	);

	/**
	 * Registered adapters keyed by id.
	 *
	 * @var WCSC_Cart_Item_Adapter[]|null
	 */
	private static $adapters = null;

	/**
	 * Registered adapters.
	 *
	 * Developers add adapters with the `wcsc_cart_item_adapters` filter or register_adapter().
	 *
	 * @return WCSC_Cart_Item_Adapter[]
	 */
	public static function get_adapters() {
		if ( null === self::$adapters ) {
			self::$adapters = array();
			$list           = apply_filters( 'wcsc_cart_item_adapters', array() );
			foreach ( (array) $list as $adapter ) {
				if ( $adapter instanceof WCSC_Cart_Item_Adapter ) {
					self::$adapters[ $adapter->get_id() ] = $adapter;
				}
			}
		}
		return self::$adapters;
	}

	/**
	 * Register an adapter at runtime.
	 *
	 * @param WCSC_Cart_Item_Adapter $adapter Adapter.
	 */
	public static function register_adapter( WCSC_Cart_Item_Adapter $adapter ) {
		self::get_adapters();
		self::$adapters[ $adapter->get_id() ] = $adapter;
	}

	/**
	 * Forget registered adapters (tests).
	 */
	public static function reset_adapters() {
		self::$adapters = null;
	}

	/**
	 * Adapter by id.
	 *
	 * @param string $id Adapter id.
	 * @return WCSC_Cart_Item_Adapter|null
	 */
	public static function get_adapter( $id ) {
		$adapters = self::get_adapters();
		return isset( $adapters[ $id ] ) ? $adapters[ $id ] : null;
	}

	/**
	 * First adapter that supports the product.
	 *
	 * @param WC_Product $product Parent product.
	 * @return WCSC_Cart_Item_Adapter|null
	 */
	public static function find_adapter( WC_Product $product ) {
		foreach ( self::get_adapters() as $adapter ) {
			if ( $adapter->supports_product( $product ) ) {
				return $adapter;
			}
		}
		return null;
	}

	/**
	 * Serialize the whole cart.
	 *
	 * @param WC_Cart $cart Cart.
	 * @return array { items: array[], unsupported: array[] }
	 */
	public static function serialize_cart( $cart ) {
		$items       = array();
		$unsupported = array();

		foreach ( $cart->get_cart() as $cart_item ) {
			$result = self::serialize_cart_item( $cart_item );
			if ( is_wp_error( $result ) ) {
				$unsupported[] = array(
					'name'   => self::error_name( $result ),
					'reason' => $result->get_error_message(),
					'keys'   => self::error_keys( $result ),
					'detail' => self::error_detail( $result ),
				);
				continue;
			}
			$items[] = $result;
		}

		return array(
			'items'       => $items,
			'unsupported' => $unsupported,
		);
	}

	/**
	 * Product name attached to an item error.
	 *
	 * @param WP_Error $error Error created by this class.
	 * @return string
	 */
	public static function error_name( $error ) {
		$data = $error->get_error_data();
		return is_array( $data ) && isset( $data['name'] ) ? (string) $data['name'] : '';
	}

	/**
	 * Unclaimed cart item key names attached to an item error (diagnostics).
	 *
	 * @param WP_Error $error Error created by this class.
	 * @return string[]
	 */
	public static function error_keys( $error ) {
		$data = $error->get_error_data();
		return is_array( $data ) && ! empty( $data['keys'] ) ? array_map( 'strval', (array) $data['keys'] ) : array();
	}

	/**
	 * Diagnostic detail (admins only) attached to an item error.
	 *
	 * @param WP_Error $error Error created by this class.
	 * @return string
	 */
	public static function error_detail( $error ) {
		$data = $error->get_error_data();
		return is_array( $data ) && ! empty( $data['detail'] ) ? (string) $data['detail'] : '';
	}

	/**
	 * Whether a value contains no selection at all (null, false, empty string, empty arrays only).
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function is_empty_deep( $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $v ) {
				if ( ! self::is_empty_deep( $v ) ) {
					return false;
				}
			}
			return true;
		}
		return null === $value || false === $value || '' === $value;
	}

	/**
	 * Reduce a value to scalars for diagnostics (objects become their class name).
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Depth.
	 * @return mixed
	 */
	public static function describe_value( $value, $depth = 0 ) {
		if ( is_array( $value ) ) {
			if ( $depth > 3 ) {
				return '[array]';
			}
			$out = array();
			foreach ( $value as $k => $v ) {
				$out[ $k ] = self::describe_value( $v, $depth + 1 );
			}
			return $out;
		}
		if ( is_object( $value ) ) {
			return '[' . get_class( $value ) . ']';
		}
		return is_scalar( $value ) || null === $value ? $value : '[unsupported]';
	}

	/**
	 * Serialize one cart item.
	 *
	 * @param array $cart_item WooCommerce cart item.
	 * @return array|WP_Error Snapshot item, or WP_Error (data "name") when unsupported.
	 */
	public static function serialize_cart_item( array $cart_item ) {
		$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
		$name    = $product instanceof WC_Product ? wp_strip_all_tags( $product->get_name() ) : __( 'Unknown item', 'wc-share-cart' );

		$fail = static function ( $message, array $keys = array(), $detail = '' ) use ( $name ) {
			return new WP_Error( 'wcsc_unsupported_item', $message, array( 'name' => $name, 'keys' => $keys, 'detail' => $detail ) );
		};

		if ( ! $product instanceof WC_Product ) {
			return $fail( __( 'The product could not be read.', 'wc-share-cart' ) );
		}

		$product_id   = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
		$variation_id = isset( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : 0;
		$quantity     = isset( $cart_item['quantity'] ) ? (int) wc_stock_amount( $cart_item['quantity'] ) : 0;

		if ( $product_id <= 0 || $quantity < 1 ) {
			return $fail( __( 'The product or quantity is not valid.', 'wc-share-cart' ) );
		}

		$parent = $variation_id > 0 ? wc_get_product( $product_id ) : $product;
		if ( ! $parent instanceof WC_Product ) {
			return $fail( __( 'The product could not be read.', 'wc-share-cart' ) );
		}

		// Variation attributes (public selections made on the product page).
		$variation = array();
		if ( $variation_id > 0 && ! empty( $cart_item['variation'] ) && is_array( $cart_item['variation'] ) ) {
			foreach ( $cart_item['variation'] as $key => $value ) {
				if ( is_string( $key ) && 0 === strpos( $key, 'attribute_' ) && is_scalar( $value ) ) {
					$variation[ sanitize_title( $key ) ] = sanitize_text_field( (string) $value );
				}
			}
		}

		// Custom selections: only through an adapter, only for keys it claims.
		$adapter = self::find_adapter( $parent );
		$options = array();
		$handled = array();

		if ( $adapter ) {
			$handled = $adapter->get_handled_keys();
			$options = $adapter->serialize( $cart_item, $product );
			if ( is_wp_error( $options ) ) {
				return $fail( $options->get_error_message() );
			}
			$options = self::sanitize_options( (array) $options );
			if ( null === $options ) {
				return $fail( __( 'The product options are too large or contain unsupported values.', 'wc-share-cart' ) );
			}
		}

		$ignored = (array) apply_filters( 'wcsc_ignored_cart_item_keys', array(), $cart_item );

		/*
		 * "All Products for WooCommerce Subscriptions" adds `wcsatt_data` to every eligible cart item, even when the
		 * customer chose the normal one-time price. We only ignore it when it holds no selection at all (nothing
		 * non-empty inside). A selected subscription plan stays unsupported until an adapter handles it.
		 */
		if ( isset( $cart_item['wcsatt_data'] ) && self::is_empty_deep( $cart_item['wcsatt_data'] ) ) {
			$ignored[] = 'wcsatt_data';
		}

		$unknown = array_diff( array_keys( $cart_item ), self::CORE_KEYS, $handled, $ignored );
		if ( ! empty( $unknown ) ) {
			$unknown = array_values( array_map( 'sanitize_key', $unknown ) );
			WCSC_Security::log( 'Cart item not shareable: unclaimed cart item keys.', 'notice', array( 'product_id' => $product_id, 'keys' => implode( ',', $unknown ) ) );
			$detail = array();
			foreach ( $unknown as $unknown_key ) {
				$raw      = isset( $cart_item[ $unknown_key ] ) ? $cart_item[ $unknown_key ] : ( isset( $cart_item[ str_replace( '-', '_', $unknown_key ) ] ) ? $cart_item[ str_replace( '-', '_', $unknown_key ) ] : null );
				$encoded  = wp_json_encode( self::describe_value( $raw ) );
				$detail[] = $unknown_key . '=' . ( false === $encoded ? '?' : substr( $encoded, 0, 300 ) );
			}
			return $fail( __( 'This item has custom options that cannot be shared yet.', 'wc-share-cart' ), $unknown, implode( '; ', $detail ) );
		}

		$item = array(
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'variation'    => $variation,
			'quantity'     => $quantity,
			'type'         => $parent->get_type(),
			'name'         => $name,
			'sku'          => (string) $product->get_sku(),
			'adapter'      => ( $adapter && ! empty( $options ) ) ? $adapter->get_id() : '',
			'options'      => $options,
		);

		/**
		 * Filter a serialized cart item. Return a WP_Error to mark it unsupported.
		 *
		 * @param array      $item      Snapshot item.
		 * @param array      $cart_item Original cart item.
		 * @param WC_Product $product   Product object.
		 */
		$item = apply_filters( 'wcsc_serialize_cart_item', $item, $cart_item, $product );
		if ( is_wp_error( $item ) ) {
			return $fail( $item->get_error_message() );
		}

		return is_array( $item ) ? $item : $fail( __( 'The product could not be read.', 'wc-share-cart' ) );
	}

	/**
	 * Recursively sanitize adapter options. Only scalars and nested arrays of scalars survive.
	 *
	 * @param array $options Raw options.
	 * @param int   $depth   Current depth.
	 * @return array|null Null when the options are unusable (too deep / too large).
	 */
	public static function sanitize_options( array $options, $depth = 0 ) {
		if ( $depth > 4 ) {
			return null;
		}

		$clean = array();
		foreach ( $options as $key => $value ) {
			$key = is_int( $key ) ? $key : sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$sub = self::sanitize_options( $value, $depth + 1 );
				if ( null === $sub ) {
					return null;
				}
				$clean[ $key ] = $sub;
			} elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$clean[ $key ] = $value;
			} elseif ( is_string( $value ) ) {
				$clean[ $key ] = sanitize_text_field( $value );
			}
			// Objects, resources and nulls are dropped.
		}

		if ( 0 === $depth ) {
			$json = wp_json_encode( $clean );
			if ( false === $json || strlen( $json ) > 4000 ) {
				return null;
			}
		}

		return $clean;
	}

	/**
	 * Normalize an item loaded from the database (never trust stored data).
	 *
	 * @param mixed $item Raw item.
	 * @return array|WP_Error
	 */
	public static function normalize_item( $item ) {
		if ( ! is_array( $item ) ) {
			return new WP_Error( 'wcsc_invalid_item', __( 'This item is not valid.', 'wc-share-cart' ) );
		}

		$variation = array();
		if ( isset( $item['variation'] ) && is_array( $item['variation'] ) ) {
			foreach ( $item['variation'] as $key => $value ) {
				if ( is_string( $key ) && 0 === strpos( $key, 'attribute_' ) && is_scalar( $value ) ) {
					$variation[ sanitize_title( $key ) ] = sanitize_text_field( (string) $value );
				}
			}
		}

		$options = isset( $item['options'] ) && is_array( $item['options'] ) ? self::sanitize_options( $item['options'] ) : array();

		$normalized = array(
			'product_id'   => isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0,
			'variation_id' => isset( $item['variation_id'] ) ? absint( $item['variation_id'] ) : 0,
			'variation'    => $variation,
			'quantity'     => isset( $item['quantity'] ) ? absint( $item['quantity'] ) : 0,
			'type'         => isset( $item['type'] ) ? sanitize_key( (string) $item['type'] ) : '',
			'name'         => isset( $item['name'] ) ? sanitize_text_field( (string) $item['name'] ) : '',
			'sku'          => isset( $item['sku'] ) ? sanitize_text_field( (string) $item['sku'] ) : '',
			'adapter'      => isset( $item['adapter'] ) ? sanitize_key( (string) $item['adapter'] ) : '',
			'options'      => null === $options ? array() : $options,
		);

		if ( $normalized['product_id'] <= 0 || $normalized['quantity'] < 1 ) {
			return new WP_Error( 'wcsc_invalid_item', __( 'This item is not valid.', 'wc-share-cart' ), array( 'name' => $normalized['name'] ) );
		}

		$max = (int) apply_filters( 'wcsc_max_item_quantity', 999 );
		if ( $normalized['quantity'] > $max ) {
			$normalized['quantity'] = $max;
		}

		return $normalized;
	}

	/**
	 * Validate a stored item against the live catalogue and prepare it for add_to_cart().
	 * Prices from the snapshot are never used.
	 *
	 * @param array $stored Stored item.
	 * @return array|WP_Error {
	 *     product, parent, product_id, variation_id, variation, quantity, cart_item_data, name, options, adapter
	 * }
	 */
	public static function restore_item( $stored ) {
		$item = self::normalize_item( $stored );
		if ( is_wp_error( $item ) ) {
			return $item;
		}

		$name = '' !== $item['name'] ? $item['name'] : __( 'A product', 'wc-share-cart' );
		$err  = static function ( $code, $message ) use ( $name ) {
			return new WP_Error( $code, $message, array( 'name' => $name ) );
		};

		$parent = wc_get_product( $item['product_id'] );
		if ( ! $parent instanceof WC_Product || 'publish' !== $parent->get_status() ) {
			return $err( 'wcsc_deleted_product', __( 'This product is no longer available.', 'wc-share-cart' ) );
		}

		if ( '' !== $item['type'] && $parent->get_type() !== $item['type'] ) {
			return $err( 'wcsc_type_changed', __( 'This product has changed and can no longer be added as shared.', 'wc-share-cart' ) );
		}

		$product   = $parent;
		$variation = array();

		if ( $item['variation_id'] > 0 ) {
			$product = wc_get_product( $item['variation_id'] );
			if ( ! $product instanceof WC_Product_Variation || (int) $product->get_parent_id() !== $item['product_id'] || 'publish' !== $product->get_status() ) {
				return $err( 'wcsc_invalid_variation', __( 'The selected variation is no longer available.', 'wc-share-cart' ) );
			}
			$variation = self::validate_variation( $parent, $product, $item['variation'] );
			if ( false === $variation ) {
				return $err( 'wcsc_invalid_variation', __( 'The selected options are no longer valid for this product.', 'wc-share-cart' ) );
			}
		} elseif ( $parent->is_type( 'variable' ) ) {
			return $err( 'wcsc_invalid_variation', __( 'A variation must be selected for this product.', 'wc-share-cart' ) );
		}

		if ( ! $product->is_purchasable() ) {
			return $err( 'wcsc_not_purchasable', __( 'This product cannot be purchased right now.', 'wc-share-cart' ) );
		}

		// Custom selections require a matching adapter; never guess.
		$cart_item_data = array();
		$has_options    = ! empty( $item['options'] ) || '' !== $item['adapter'];

		if ( $has_options ) {
			$adapter = '' !== $item['adapter'] ? self::get_adapter( $item['adapter'] ) : self::find_adapter( $parent );
			if ( ! $adapter || ! $adapter->supports_product( $parent ) ) {
				return $err( 'wcsc_unsupported_options', __( 'This item has custom options that this store cannot recreate.', 'wc-share-cart' ) );
			}
			$restored = $adapter->restore( $item['options'], $parent, $item['quantity'] );
			if ( is_wp_error( $restored ) ) {
				return $err( 'wcsc_invalid_options', $restored->get_error_message() );
			}
			$cart_item_data = (array) $restored;
		}

		$result = array(
			'product'        => $product,
			'parent'         => $parent,
			'product_id'     => $item['product_id'],
			'variation_id'   => $item['variation_id'],
			'variation'      => $variation,
			'quantity'       => $item['quantity'],
			'cart_item_data' => $cart_item_data,
			'name'           => $name,
			'options'        => $item['options'],
			'adapter'        => $item['adapter'],
		);

		/**
		 * Last chance for custom validation / adjustments. Return a WP_Error to reject the item.
		 *
		 * @param array $result Restored item.
		 * @param array $item   Normalized stored item.
		 */
		$result = apply_filters( 'wcsc_restore_cart_item', $result, $item );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return is_array( $result ) ? $result : $err( 'wcsc_invalid_item', __( 'This item is not valid.', 'wc-share-cart' ) );
	}

	/**
	 * Check stored variation attributes against the live variation.
	 *
	 * @param WC_Product           $parent    Variable parent.
	 * @param WC_Product_Variation $variation Variation.
	 * @param array                $stored    Stored attribute_* => value pairs.
	 * @return array|false Complete attribute set, or false when invalid.
	 */
	public static function validate_variation( $parent, $variation, array $stored ) {
		if ( ! $parent->is_type( 'variable' ) ) {
			return false;
		}

		$live     = (array) $variation->get_variation_attributes();
		$allowed  = (array) $parent->get_variation_attributes();
		$complete = array();

		foreach ( $live as $key => $live_value ) {
			$key = sanitize_title( $key );
			if ( '' !== $live_value ) {
				// The variation fixes this attribute: a stored value must not disagree.
				if ( isset( $stored[ $key ] ) && '' !== $stored[ $key ] && 0 !== strcasecmp( $stored[ $key ], $live_value ) ) {
					return false;
				}
				$complete[ $key ] = $live_value;
				continue;
			}

			// "Any" attribute: the customer must have picked a value that still exists.
			$value = isset( $stored[ $key ] ) ? $stored[ $key ] : '';
			if ( '' === $value ) {
				return false;
			}
			$found = false;
			foreach ( $allowed as $attr_name => $options ) {
				if ( 'attribute_' . sanitize_title( $attr_name ) !== $key ) {
					continue;
				}
				foreach ( (array) $options as $option ) {
					if ( 0 === strcasecmp( (string) $option, $value ) ) {
						$found = true;
						break 2;
					}
				}
			}
			if ( ! $found ) {
				return false;
			}
			$complete[ $key ] = $value;
		}

		return $complete;
	}
}
