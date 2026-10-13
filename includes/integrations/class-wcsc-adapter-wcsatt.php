<?php
/**
 * Adapter for "All Products for WooCommerce Subscriptions" (cart item key `wcsatt_data`).
 *
 * What is shared: only the chosen subscription scheme key (e.g. "6_week"), as observed in real cart data
 * `wcsatt_data = { active_subscription_scheme: "6_week" }`. Nothing else is stored, and prices are never stored.
 *
 * Safety:
 *  - On restore the scheme must still be offered by the live product (checked through the add-on when available).
 *  - After add_to_cart() the new cart item must carry exactly that scheme, otherwise the import is rolled back.
 *  - Any other non-empty value inside `wcsatt_data` makes the item unsupported instead of being dropped.
 *
 * @package WC_Share_Cart
 */

defined( 'ABSPATH' ) || exit;

/**
 * WCS_ATT adapter.
 */
class WCSC_Adapter_WCSATT implements WCSC_Cart_Item_Adapter, WCSC_Cart_Item_Lifecycle {

	const KEY = 'wcsatt_data';

	/**
	 * Request keys set during add_to_cart() so the add-on sees the selection the same way as on a product page.
	 *
	 * @var string[]
	 */
	private $request_keys = array();

	/**
	 * Adapter id.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'wcsatt';
	}

	/**
	 * Applies to any product; it only contributes options when a plan was actually chosen.
	 *
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	public function supports_product( WC_Product $product ): bool {
		return true;
	}

	/**
	 * Claimed cart item keys.
	 *
	 * @return string[]
	 */
	public function get_handled_keys(): array {
		return array( self::KEY );
	}

	/**
	 * Whether a scheme key has the expected shape (e.g. "6_week", "1_month_0").
	 *
	 * @param mixed $key Candidate.
	 * @return bool
	 */
	public static function is_valid_scheme_key( $key ) {
		return is_string( $key ) && 1 === preg_match( '/^[A-Za-z0-9]+(_[A-Za-z0-9]+){0,3}$/', $key );
	}

	/**
	 * Serialize the chosen plan.
	 *
	 * @param array      $cart_item Cart item.
	 * @param WC_Product $product   Product.
	 * @return array|WP_Error
	 */
	public function serialize( array $cart_item, WC_Product $product ) {
		$data = isset( $cart_item[ self::KEY ] ) ? $cart_item[ self::KEY ] : array();

		// One-time purchase: nothing to share.
		if ( ! is_array( $data ) || WCSC_Cart_Serializer::is_empty_deep( $data ) ) {
			return array();
		}

		$scheme = isset( $data['active_subscription_scheme'] ) ? $data['active_subscription_scheme'] : '';

		$extra = array();
		foreach ( $data as $key => $value ) {
			if ( 'active_subscription_scheme' !== $key && ! WCSC_Cart_Serializer::is_empty_deep( $value ) ) {
				$extra[] = $key;
			}
		}

		if ( ! empty( $extra ) || ! self::is_valid_scheme_key( $scheme ) ) {
			return new WP_Error( 'wcsc_wcsatt', __( 'This subscription option cannot be shared yet.', 'wc-share-cart' ) );
		}

		return array( 'scheme' => $scheme );
	}

	/**
	 * Scheme keys the live product currently offers, or null when they cannot be determined.
	 *
	 * @param WC_Product $product Parent product.
	 * @return string[]|null
	 */
	protected function available_scheme_keys( WC_Product $product ) {
		/**
		 * Lets a site supply the offered scheme keys itself (also used by the tests).
		 *
		 * @param string[]|null $keys    Keys, or null to use the add-on's API.
		 * @param WC_Product    $product Parent product.
		 */
		$keys = apply_filters( 'wcsc_wcsatt_available_schemes', null, $product );
		if ( is_array( $keys ) ) {
			return array_map( 'strval', $keys );
		}

		if ( class_exists( 'WCS_ATT_Product_Schemes' ) && method_exists( 'WCS_ATT_Product_Schemes', 'get_subscription_schemes' ) ) {
			try {
				$schemes = WCS_ATT_Product_Schemes::get_subscription_schemes( $product );
				if ( is_array( $schemes ) ) {
					return array_map( 'strval', array_keys( $schemes ) );
				}
			} catch ( Throwable $e ) {
				WCSC_Security::log( 'Could not read subscription schemes: ' . $e->getMessage(), 'warning' );
			}
		}

		return null;
	}

	/**
	 * Validate the stored plan against the live product and build the cart item data.
	 *
	 * @param array      $options  Stored options.
	 * @param WC_Product $product  Parent product.
	 * @param int        $quantity Quantity.
	 * @return array|WP_Error
	 */
	public function restore( array $options, WC_Product $product, int $quantity ) {
		$scheme = isset( $options['scheme'] ) ? $options['scheme'] : '';
		if ( ! self::is_valid_scheme_key( $scheme ) ) {
			return new WP_Error( 'wcsc_wcsatt', __( 'The subscription option is not valid.', 'wc-share-cart' ) );
		}

		$available = $this->available_scheme_keys( $product );
		if ( null === $available ) {
			return new WP_Error( 'wcsc_wcsatt', __( 'Subscription options cannot be verified on this store right now.', 'wc-share-cart' ) );
		}
		if ( ! in_array( $scheme, $available, true ) ) {
			return new WP_Error( 'wcsc_wcsatt', __( 'This subscription option is no longer offered for this product.', 'wc-share-cart' ) );
		}

		return array( self::KEY => array( 'active_subscription_scheme' => $scheme ) );
	}

	/**
	 * Preview text, e.g. "Subscription: every 6 weeks".
	 *
	 * @param array      $options Stored options.
	 * @param WC_Product $product Parent product.
	 * @return string[]
	 */
	public function describe( array $options, WC_Product $product ): array {
		$scheme = isset( $options['scheme'] ) ? (string) $options['scheme'] : '';
		$lines  = array();

		if ( 1 === preg_match( '/^(\d+)_(day|week|month|year)/', $scheme, $m ) ) {
			$n      = (int) $m[1];
			$labels = array(
				'day'   => array( __( 'day', 'wc-share-cart' ), __( 'days', 'wc-share-cart' ) ),
				'week'  => array( __( 'week', 'wc-share-cart' ), __( 'weeks', 'wc-share-cart' ) ),
				'month' => array( __( 'month', 'wc-share-cart' ), __( 'months', 'wc-share-cart' ) ),
				'year'  => array( __( 'year', 'wc-share-cart' ), __( 'years', 'wc-share-cart' ) ),
			);
			$label  = $labels[ $m[2] ][ 1 === $n ? 0 : 1 ];
			$lines[] = 1 === $n
				/* translators: %s: period, e.g. "month" */
				? sprintf( __( 'Subscription: every %s', 'wc-share-cart' ), $label )
				/* translators: 1: number, 2: period, e.g. "6 weeks" */
				: sprintf( __( 'Subscription: every %1$d %2$s', 'wc-share-cart' ), $n, $label );
		} elseif ( '' !== $scheme ) {
			/* translators: %s: subscription plan key */
			$lines[] = sprintf( __( 'Subscription plan: %s', 'wc-share-cart' ), $scheme );
		}

		$lines[] = __( 'Subscription pricing is confirmed in your cart.', 'wc-share-cart' );
		return $lines;
	}

	/**
	 * Expose the selection as the add-on's own request field while WooCommerce adds the item.
	 *
	 * @param array $restored Restored item.
	 */
	public function before_add( array $restored ): void {
		$scheme = $this->scheme_of( $restored );
		if ( '' === $scheme ) {
			return;
		}
		$field = 'convert_to_sub_' . (int) $restored['product_id'];
		// Our own import request already passed its nonce check; this only mimics the product page field.
		$_POST[ $field ]    = $scheme; // phpcs:ignore WordPress.Security.NonceVerification
		$_REQUEST[ $field ] = $scheme;
		$this->request_keys[] = $field;
	}

	/**
	 * Remove the temporary request fields.
	 *
	 * @param array $restored Restored item.
	 */
	public function after_add( array $restored ): void {
		foreach ( $this->request_keys as $field ) {
			unset( $_POST[ $field ], $_REQUEST[ $field ] );
		}
		$this->request_keys = array();
	}

	/**
	 * The cart item must carry exactly the requested plan.
	 *
	 * @param array $cart_item WooCommerce cart item.
	 * @param array $restored  Restored item.
	 * @return bool
	 */
	public function verify_added( array $cart_item, array $restored ): bool {
		$expected = $this->scheme_of( $restored );
		if ( '' === $expected ) {
			return true;
		}
		return isset( $cart_item[ self::KEY ]['active_subscription_scheme'] ) && $expected === (string) $cart_item[ self::KEY ]['active_subscription_scheme'];
	}

	/**
	 * Requested scheme of a restored item.
	 *
	 * @param array $restored Restored item.
	 * @return string
	 */
	private function scheme_of( array $restored ) {
		$scheme = isset( $restored['cart_item_data'][ self::KEY ]['active_subscription_scheme'] ) ? $restored['cart_item_data'][ self::KEY ]['active_subscription_scheme'] : '';
		return is_string( $scheme ) ? $scheme : '';
	}
}
