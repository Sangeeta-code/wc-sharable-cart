<?php
/**
 * Cart serialization tests.
 *
 * @package WC_Share_Cart
 */

/** Test adapter standing in for a custom "flavor" product plugin. */
class WCSC_Test_Flavor_Adapter implements WCSC_Cart_Item_Adapter {
	public function get_id(): string { return 'test-flavors'; }
	public function supports_product( WC_Product $product ): bool { return 'flavor_box' === $product->get_type(); }
	public function get_handled_keys(): array { return array( 'flavors', 'tier' ); }
	public function serialize( array $cart_item, WC_Product $product ) {
		if ( empty( $cart_item['flavors'] ) ) {
			return new WP_Error( 'x', 'Pick at least one flavor.' );
		}
		return array( 'flavors' => (array) $cart_item['flavors'], 'tier' => isset( $cart_item['tier'] ) ? $cart_item['tier'] : '' );
	}
	public function restore( array $options, WC_Product $product, int $quantity ) {
		$allowed = array( 'mango', 'lime', 'berry' );
		foreach ( (array) $options['flavors'] as $f ) {
			if ( ! in_array( $f, $allowed, true ) ) {
				return new WP_Error( 'bad', 'That flavor no longer exists.' );
			}
		}
		return array( 'flavors' => $options['flavors'], 'tier' => $options['tier'] );
	}
	public function describe( array $options, WC_Product $product ): array {
		return array( 'Flavors: ' . implode( ', ', $options['flavors'] ) );
	}
}

/** Build a cart item the way WooCommerce does. */
function wcsc_cart_item( WC_Product $product, array $extra = array() ) {
	return array_merge(
		array(
			'key'               => 'abc123',
			'product_id'        => $product->get_parent_id() ? $product->get_parent_id() : $product->get_id(),
			'variation_id'      => $product->get_parent_id() ? $product->get_id() : 0,
			'variation'         => array(),
			'quantity'          => 2,
			'data'              => $product,
			'data_hash'         => 'hash',
			'line_tax_data'     => array( 'subtotal' => array(), 'total' => array() ),
			'line_subtotal'     => 20,
			'line_subtotal_tax' => 0,
			'line_total'        => 20,
			'line_tax'          => 0,
		),
		$extra
	);
}

wcsc_test(
	'simple product is serialized with only whitelisted fields (no prices, no session data)',
	function () {
		$p    = wcsc_add_product( 10, array( 'name' => 'Mug', 'sku' => 'MUG-1' ) );
		$item = WCSC_Cart_Serializer::serialize_cart_item( wcsc_cart_item( $p ) );

		wcsc_assert( is_array( $item ), 'Simple product must serialize' );
		wcsc_assert_same( 10, $item['product_id'] );
		wcsc_assert_same( 0, $item['variation_id'] );
		wcsc_assert_same( 2, $item['quantity'] );
		wcsc_assert_same( 'simple', $item['type'] );
		wcsc_assert_same( 'Mug', $item['name'] );
		wcsc_assert_same( 'MUG-1', $item['sku'] );

		$json = json_encode( $item );
		foreach ( array( 'line_total', 'line_subtotal', 'data_hash', 'key', 'price' ) as $forbidden ) {
			wcsc_assert( false === strpos( $json, '"' . $forbidden . '"' ), "Snapshot must not contain {$forbidden}" );
		}
	}
);

wcsc_test(
	'variable product keeps its selected attributes',
	function () {
		wcsc_add_product( 20, array( 'type' => 'variable', 'name' => 'Shirt' ) );
		$var  = wcsc_add_product( 21, array( 'parent_id' => 20, 'name' => 'Shirt - Red, M' ), 'WC_Product_Variation' );
		$item = WCSC_Cart_Serializer::serialize_cart_item(
			wcsc_cart_item( $var, array( 'variation' => array( 'attribute_pa_color' => 'red', 'attribute_size' => 'M', 'junk' => 'drop me' ) ) )
		);

		wcsc_assert( is_array( $item ) );
		wcsc_assert_same( 20, $item['product_id'] );
		wcsc_assert_same( 21, $item['variation_id'] );
		wcsc_assert_same( array( 'attribute_pa_color' => 'red', 'attribute_size' => 'M' ), $item['variation'] );
		wcsc_assert_same( 'variable', $item['type'] );
	}
);

wcsc_test(
	'unknown custom cart data makes an item unsupported (never guessed)',
	function () {
		$p      = wcsc_add_product( 30, array( 'name' => 'Engraved Pen' ) );
		$result = WCSC_Cart_Serializer::serialize_cart_item( wcsc_cart_item( $p, array( 'engraving_text' => 'Hello' ) ) );

		wcsc_assert( is_wp_error( $result ), 'Unknown keys must be flagged' );
		wcsc_assert_same( 'Engraved Pen', WCSC_Cart_Serializer::error_name( $result ), 'The item is identified by name' );
	}
);

wcsc_test(
	'a registered adapter makes custom selections shareable',
	function () {
		WCSC_Cart_Serializer::register_adapter( new WCSC_Test_Flavor_Adapter() );
		$p    = wcsc_add_product( 40, array( 'type' => 'flavor_box', 'name' => 'Flavor Box' ) );
		$item = WCSC_Cart_Serializer::serialize_cart_item( wcsc_cart_item( $p, array( 'flavors' => array( 'mango', 'lime' ), 'tier' => '12-pack' ) ) );

		wcsc_assert( is_array( $item ), 'Adapter item must serialize' );
		wcsc_assert_same( 'test-flavors', $item['adapter'] );
		wcsc_assert_same( array( 'flavors' => array( 'mango', 'lime' ), 'tier' => '12-pack' ), $item['options'] );
	}
);

wcsc_test(
	'an adapter may only claim keys it declares; other unknown keys still block the item',
	function () {
		WCSC_Cart_Serializer::register_adapter( new WCSC_Test_Flavor_Adapter() );
		$p      = wcsc_add_product( 41, array( 'type' => 'flavor_box', 'name' => 'Flavor Box' ) );
		$result = WCSC_Cart_Serializer::serialize_cart_item( wcsc_cart_item( $p, array( 'flavors' => array( 'mango' ), 'secret_meta' => 'x' ) ) );
		wcsc_assert( is_wp_error( $result ) );
	}
);

wcsc_test(
	'an adapter can reject an item with a customer-friendly reason',
	function () {
		WCSC_Cart_Serializer::register_adapter( new WCSC_Test_Flavor_Adapter() );
		$p      = wcsc_add_product( 42, array( 'type' => 'flavor_box', 'name' => 'Flavor Box' ) );
		$result = WCSC_Cart_Serializer::serialize_cart_item( wcsc_cart_item( $p ) );
		wcsc_assert( is_wp_error( $result ) );
		wcsc_assert_same( 'Pick at least one flavor.', $result->get_error_message() );
	}
);

wcsc_test(
	'the ignored-keys filter lets a site whitelist harmless keys',
	function () {
		add_filter( 'wcsc_ignored_cart_item_keys', function ( $keys ) {
			$keys[] = 'harmless_flag';
			return $keys;
		} );
		$p = wcsc_add_product( 50, array( 'name' => 'Thing' ) );
		wcsc_assert( is_array( WCSC_Cart_Serializer::serialize_cart_item( wcsc_cart_item( $p, array( 'harmless_flag' => 1 ) ) ) ) );
	}
);

wcsc_test(
	'serialize filter can veto an item',
	function () {
		add_filter( 'wcsc_serialize_cart_item', function () {
			return new WP_Error( 'veto', 'Not shareable.' );
		} );
		$p = wcsc_add_product( 51, array( 'name' => 'Thing' ) );
		wcsc_assert( is_wp_error( WCSC_Cart_Serializer::serialize_cart_item( wcsc_cart_item( $p ) ) ) );
	}
);

wcsc_test(
	'invalid quantity or missing product data is unsupported',
	function () {
		$p = wcsc_add_product( 52, array( 'name' => 'Thing' ) );
		wcsc_assert( is_wp_error( WCSC_Cart_Serializer::serialize_cart_item( wcsc_cart_item( $p, array( 'quantity' => 0 ) ) ) ) );
		$no_data = wcsc_cart_item( $p );
		unset( $no_data['data'] );
		wcsc_assert( is_wp_error( WCSC_Cart_Serializer::serialize_cart_item( $no_data ) ) );
	}
);

wcsc_test(
	'option sanitization drops objects, nulls and over-deep or over-large structures',
	function () {
		$clean = WCSC_Cart_Serializer::sanitize_options(
			array(
				'text'   => '<b>Hi</b>',
				'n'      => 5,
				'flag'   => true,
				'object' => new stdClass(),
				'nothing' => null,
				'list'   => array( 'a', 'b' ),
				'Bad Key!' => 'x',
			)
		);
		wcsc_assert_same( 'Hi', $clean['text'] );
		wcsc_assert_same( 5, $clean['n'] );
		wcsc_assert( ! isset( $clean['object'] ) && ! isset( $clean['nothing'] ) );
		wcsc_assert_same( array( 'a', 'b' ), $clean['list'] );
		wcsc_assert( isset( $clean['badkey'] ), 'Keys are sanitized' );

		$deep = array( 'a' => array( 'b' => array( 'c' => array( 'd' => array( 'e' => array( 'f' => 1 ) ) ) ) ) );
		wcsc_assert_same( null, WCSC_Cart_Serializer::sanitize_options( $deep ), 'Too deep' );

		wcsc_assert_same( null, WCSC_Cart_Serializer::sanitize_options( array( 'big' => str_repeat( 'x', 5000 ) ) ), 'Too large' );
	}
);

wcsc_test(
	'stored items are normalized defensively',
	function () {
		wcsc_assert( is_wp_error( WCSC_Cart_Serializer::normalize_item( 'not an array' ) ) );
		wcsc_assert( is_wp_error( WCSC_Cart_Serializer::normalize_item( array( 'product_id' => 0, 'quantity' => 1 ) ) ) );
		wcsc_assert( is_wp_error( WCSC_Cart_Serializer::normalize_item( array( 'product_id' => 5, 'quantity' => 0 ) ) ) );

		$n = WCSC_Cart_Serializer::normalize_item( array( 'product_id' => '5', 'quantity' => '3', 'variation' => array( 'attribute_x' => '<i>y</i>', 'evil' => 'z' ), 'name' => '<b>N</b>' ) );
		wcsc_assert_same( 5, $n['product_id'] );
		wcsc_assert_same( 3, $n['quantity'] );
		wcsc_assert_same( array( 'attribute_x' => 'y' ), $n['variation'] );
		wcsc_assert_same( 'N', $n['name'] );

		$huge = WCSC_Cart_Serializer::normalize_item( array( 'product_id' => 5, 'quantity' => 99999999 ) );
		wcsc_assert_same( 999, $huge['quantity'], 'Quantity is capped' );
	}
);

wcsc_test(
	'wcsatt_data is ignored only when it holds no selection',
	function () {
		$p = wcsc_add_product( 60, array( 'name' => 'Syrup' ) );

		foreach ( array( array( 'active_subscription_scheme' => null ), array( 'active_subscription_scheme' => false ), array(), array( 'a' => array( 'b' => '' ) ) ) as $empty ) {
			wcsc_assert( is_array( WCSC_Cart_Serializer::serialize_cart_item( wcsc_cart_item( $p, array( 'wcsatt_data' => $empty ) ) ) ), 'Empty selection must be shareable' );
		}

		$chosen = WCSC_Cart_Serializer::serialize_cart_item( wcsc_cart_item( $p, array( 'wcsatt_data' => array( 'active_subscription_scheme' => '1_month_0' ) ) ) );
		wcsc_assert( is_wp_error( $chosen ), 'A chosen plan must stay unsupported' );
		wcsc_assert_same( array( 'wcsatt_data' ), WCSC_Cart_Serializer::error_keys( $chosen ) );
		wcsc_assert( false !== strpos( WCSC_Cart_Serializer::error_detail( $chosen ), '1_month_0' ), 'Admin detail shows the value' );
	}
);

wcsc_test(
	'wcsatt adapter: chosen plan is shared, one-time purchase is not, odd data is refused',
	function () {
		require_once WCSC_PATH . 'includes/integrations/class-wcsc-adapter-wcsatt.php';
		WCSC_Cart_Serializer::register_adapter( new WCSC_Adapter_WCSATT() );
		$p = wcsc_add_product( 70, array( 'name' => 'Gummy' ) );

		$sub = WCSC_Cart_Serializer::serialize_cart_item( wcsc_cart_item( $p, array( 'wcsatt_data' => array( 'active_subscription_scheme' => '6_week' ) ) ) );
		wcsc_assert( is_array( $sub ), 'Chosen plan must be shareable' );
		wcsc_assert_same( 'wcsatt', $sub['adapter'] );
		wcsc_assert_same( array( 'scheme' => '6_week' ), $sub['options'] );
		wcsc_assert( false === strpos( json_encode( $sub ), 'wcsatt_data' ), 'Raw add-on data is not stored' );

		$once = WCSC_Cart_Serializer::serialize_cart_item( wcsc_cart_item( $p, array( 'wcsatt_data' => array( 'active_subscription_scheme' => null ) ) ) );
		wcsc_assert_same( '', $once['adapter'], 'One-time purchases do not depend on the adapter' );
		wcsc_assert_same( array(), $once['options'] );

		$odd = WCSC_Cart_Serializer::serialize_cart_item( wcsc_cart_item( $p, array( 'wcsatt_data' => array( 'active_subscription_scheme' => '6_week', 'mystery' => 'x' ) ) ) );
		wcsc_assert( is_wp_error( $odd ), 'Unknown extra data must not be dropped silently' );

		$bad = WCSC_Cart_Serializer::serialize_cart_item( wcsc_cart_item( $p, array( 'wcsatt_data' => array( 'active_subscription_scheme' => '6 week; DROP' ) ) ) );
		wcsc_assert( is_wp_error( $bad ) );
	}
);
