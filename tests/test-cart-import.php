<?php
/**
 * Import validation and planning tests (the part of merge / replace that does not need a live cart).
 *
 * @package WC_Share_Cart
 */

function wcsc_stored( array $o = array() ) {
	return $o + array(
		'product_id'   => 100,
		'variation_id' => 0,
		'variation'    => array(),
		'quantity'     => 1,
		'type'         => 'simple',
		'name'         => 'Widget',
		'sku'          => '',
		'adapter'      => '',
		'options'      => array(),
	);
}

wcsc_test(
	'a valid simple product is prepared for import',
	function () {
		wcsc_add_product( 100, array( 'name' => 'Widget' ) );
		$plan = WCSC_Cart_Manager::plan_import( array( wcsc_stored( array( 'quantity' => 3 ) ) ) );
		wcsc_assert_same( 1, count( $plan['prepared'] ) );
		wcsc_assert_same( 0, count( $plan['skipped'] ) );
		wcsc_assert_same( 3, $plan['prepared'][0]['quantity'] );
	}
);

wcsc_test(
	'stored snapshot prices are never trusted or carried into the import',
	function () {
		wcsc_add_product( 100 );
		$plan = WCSC_Cart_Manager::plan_import( array( wcsc_stored( array( 'price' => 0.01, 'line_total' => 0.01 ) ) ) );
		$json = json_encode( array_map( function ( $r ) {
			unset( $r['product'], $r['parent'] );
			return $r;
		}, $plan['prepared'] ) );
		wcsc_assert( false === strpos( $json, 'price' ) && false === strpos( $json, '0.01' ) );
	}
);

wcsc_test(
	'a product deleted after sharing is skipped with its name and a clear reason',
	function () {
		$plan = WCSC_Cart_Manager::plan_import( array( wcsc_stored( array( 'product_id' => 999, 'name' => 'Gone Gadget' ) ) ) );
		wcsc_assert_same( 0, count( $plan['prepared'] ) );
		wcsc_assert_same( 'Gone Gadget', $plan['skipped'][0]['name'] );
		wcsc_assert( '' !== $plan['skipped'][0]['reason'] );
	}
);

wcsc_test(
	'unpublished, unpurchasable and type-changed products are skipped',
	function () {
		wcsc_add_product( 101, array( 'status' => 'draft' ) );
		wcsc_add_product( 102, array( 'purchasable' => false ) );
		wcsc_add_product( 103, array( 'type' => 'variable' ) );
		$plan = WCSC_Cart_Manager::plan_import(
			array(
				wcsc_stored( array( 'product_id' => 101 ) ),
				wcsc_stored( array( 'product_id' => 102 ) ),
				wcsc_stored( array( 'product_id' => 103 ) ), // Was simple when shared.
			)
		);
		wcsc_assert_same( 0, count( $plan['prepared'] ) );
		wcsc_assert_same( 3, count( $plan['skipped'] ) );
	}
);

wcsc_test(
	'out-of-stock and insufficient stock are reported, never silently reduced',
	function () {
		wcsc_add_product( 110, array( 'in_stock' => false ) );
		wcsc_add_product( 111, array( 'stock' => 2 ) );
		$plan = WCSC_Cart_Manager::plan_import(
			array(
				wcsc_stored( array( 'product_id' => 110, 'name' => 'Sold Out' ) ),
				wcsc_stored( array( 'product_id' => 111, 'name' => 'Scarce', 'quantity' => 5 ) ),
			)
		);
		wcsc_assert_same( 0, count( $plan['prepared'] ) );
		wcsc_assert_same( 'Out of stock', $plan['skipped'][0]['reason'] );
		wcsc_assert( false !== strpos( $plan['skipped'][1]['reason'], '2' ), 'Shows how many are available' );
	}
);

wcsc_test(
	'merge counts what is already in the cart against stock',
	function () {
		wcsc_add_product( 120, array( 'stock' => 5 ) );
		$already = array( 120 => 4 ); // Recipient already holds 4.
		$plan    = WCSC_Cart_Manager::plan_import( array( wcsc_stored( array( 'product_id' => 120, 'quantity' => 2 ) ) ), $already );
		wcsc_assert_same( 0, count( $plan['prepared'] ), '4 in cart + 2 shared > 5 in stock' );

		$plan = WCSC_Cart_Manager::plan_import( array( wcsc_stored( array( 'product_id' => 120, 'quantity' => 1 ) ) ), $already );
		wcsc_assert_same( 1, count( $plan['prepared'] ), '4 + 1 fits exactly' );
	}
);

wcsc_test(
	'two shared lines that draw from the same stock are checked cumulatively',
	function () {
		wcsc_add_product( 130, array( 'stock' => 3 ) );
		$plan = WCSC_Cart_Manager::plan_import(
			array(
				wcsc_stored( array( 'product_id' => 130, 'quantity' => 2 ) ),
				wcsc_stored( array( 'product_id' => 130, 'quantity' => 2 ) ),
			)
		);
		wcsc_assert_same( 1, count( $plan['prepared'] ) );
		wcsc_assert_same( 1, count( $plan['skipped'] ) );
	}
);

wcsc_test(
	'variations: valid selection passes, changed or missing attributes fail, nothing is substituted',
	function () {
		wcsc_add_product(
			200,
			array(
				'type'              => 'variable',
				'variation_options' => array( 'pa_color' => array( 'red', 'blue' ), 'Size' => array( 'S', 'M' ) ),
			)
		);
		// Variation 201: colour fixed to red, size is "any".
		wcsc_add_product( 201, array( 'parent_id' => 200, 'attrs' => array( 'attribute_pa_color' => 'red', 'attribute_size' => '' ) ), 'WC_Product_Variation' );

		$base = array( 'product_id' => 200, 'variation_id' => 201, 'type' => 'variable' );

		$ok = WCSC_Cart_Manager::plan_import( array( wcsc_stored( $base + array( 'variation' => array( 'attribute_pa_color' => 'red', 'attribute_size' => 'M' ) ) ) ) );
		wcsc_assert_same( 1, count( $ok['prepared'] ), 'Valid variation is importable' );
		wcsc_assert_same( 'M', $ok['prepared'][0]['variation']['attribute_size'] );

		$wrong_fixed = WCSC_Cart_Manager::plan_import( array( wcsc_stored( $base + array( 'variation' => array( 'attribute_pa_color' => 'blue', 'attribute_size' => 'M' ) ) ) ) );
		wcsc_assert_same( 0, count( $wrong_fixed['prepared'] ), 'Fixed attribute changed' );

		$missing_any = WCSC_Cart_Manager::plan_import( array( wcsc_stored( $base + array( 'variation' => array( 'attribute_pa_color' => 'red' ) ) ) ) );
		wcsc_assert_same( 0, count( $missing_any['prepared'] ), 'An "any" attribute without a value cannot be guessed' );

		$gone_option = WCSC_Cart_Manager::plan_import( array( wcsc_stored( $base + array( 'variation' => array( 'attribute_pa_color' => 'red', 'attribute_size' => 'XXL' ) ) ) ) );
		wcsc_assert_same( 0, count( $gone_option['prepared'] ), 'Option no longer offered' );

		$wrong_parent = WCSC_Cart_Manager::plan_import( array( wcsc_stored( array_merge( $base, array( 'product_id' => 999, 'variation' => array( 'attribute_pa_color' => 'red', 'attribute_size' => 'M' ) ) ) ) ) );
		wcsc_assert_same( 0, count( $wrong_parent['prepared'] ) );
	}
);

wcsc_test(
	'a variable product without a variation id is not importable',
	function () {
		wcsc_add_product( 210, array( 'type' => 'variable' ) );
		$plan = WCSC_Cart_Manager::plan_import( array( wcsc_stored( array( 'product_id' => 210, 'type' => 'variable' ) ) ) );
		wcsc_assert_same( 0, count( $plan['prepared'] ) );
	}
);

wcsc_test(
	'custom options without a matching adapter are reported as unsupported',
	function () {
		wcsc_add_product( 300, array( 'type' => 'flavor_box' ) );
		$plan = WCSC_Cart_Manager::plan_import( array( wcsc_stored( array( 'product_id' => 300, 'type' => 'flavor_box', 'adapter' => 'test-flavors', 'options' => array( 'flavors' => array( 'mango' ) ) ) ) ) );
		wcsc_assert_same( 0, count( $plan['prepared'] ) );
		wcsc_assert_same( 1, count( $plan['skipped'] ) );
	}
);

wcsc_test(
	'with an adapter, selections are re-validated and restored as cart item data',
	function () {
		WCSC_Cart_Serializer::register_adapter( new WCSC_Test_Flavor_Adapter() );
		wcsc_add_product( 301, array( 'type' => 'flavor_box' ) );

		$good = WCSC_Cart_Manager::plan_import( array( wcsc_stored( array( 'product_id' => 301, 'type' => 'flavor_box', 'adapter' => 'test-flavors', 'options' => array( 'flavors' => array( 'mango', 'lime' ), 'tier' => '12-pack' ) ) ) ) );
		wcsc_assert_same( 1, count( $good['prepared'] ) );
		wcsc_assert_same( array( 'flavors' => array( 'mango', 'lime' ), 'tier' => '12-pack' ), $good['prepared'][0]['cart_item_data'] );

		// A flavor that was discontinued after sharing must NOT be substituted.
		$bad = WCSC_Cart_Manager::plan_import( array( wcsc_stored( array( 'product_id' => 301, 'type' => 'flavor_box', 'adapter' => 'test-flavors', 'options' => array( 'flavors' => array( 'durian' ), 'tier' => '12-pack' ) ) ) ) );
		wcsc_assert_same( 0, count( $bad['prepared'] ) );
		wcsc_assert_same( 'That flavor no longer exists.', $bad['skipped'][0]['reason'] );
	}
);

wcsc_test(
	'good items are still imported when others are unavailable, and each skipped item is named',
	function () {
		wcsc_add_product( 400, array( 'name' => 'Available' ) );
		$plan = WCSC_Cart_Manager::plan_import(
			array(
				wcsc_stored( array( 'product_id' => 400, 'name' => 'Available' ) ),
				wcsc_stored( array( 'product_id' => 401, 'name' => 'Deleted One' ) ),
			)
		);
		wcsc_assert_same( 1, count( $plan['prepared'] ) );
		wcsc_assert_same( 'Deleted One', $plan['skipped'][0]['name'] );
	}
);

wcsc_test(
	'the restore filter can veto an item',
	function () {
		wcsc_add_product( 500 );
		add_filter( 'wcsc_restore_cart_item', function () {
			return new WP_Error( 'veto', 'Vetoed.', array( 'name' => 'Widget' ) );
		} );
		$plan = WCSC_Cart_Manager::plan_import( array( wcsc_stored( array( 'product_id' => 500 ) ) ) );
		wcsc_assert_same( 0, count( $plan['prepared'] ) );
		wcsc_assert_same( 'Vetoed.', $plan['skipped'][0]['reason'] );
	}
);

wcsc_test(
	'repeated identical lines are kept as separate prepared items so WooCommerce can merge identical configurations',
	function () {
		wcsc_add_product( 600 );
		$plan = WCSC_Cart_Manager::plan_import( array( wcsc_stored( array( 'product_id' => 600, 'quantity' => 1 ) ), wcsc_stored( array( 'product_id' => 600, 'quantity' => 2 ) ) ) );
		wcsc_assert_same( 2, count( $plan['prepared'] ) );
	}
);

wcsc_test(
	'wcsatt adapter: plan is re-validated on import, verified after adding, and request fields are cleaned up',
	function () {
		require_once WCSC_PATH . 'includes/integrations/class-wcsc-adapter-wcsatt.php';
		$adapter = new WCSC_Adapter_WCSATT();
		WCSC_Cart_Serializer::register_adapter( $adapter );
		wcsc_add_product( 80, array( 'name' => 'Gummy' ) );

		$stored = wcsc_stored( array( 'product_id' => 80, 'adapter' => 'wcsatt', 'options' => array( 'scheme' => '6_week' ) ) );

		// Add-on state cannot be determined -> refused, never guessed.
		$plan = WCSC_Cart_Manager::plan_import( array( $stored ) );
		wcsc_assert_same( 0, count( $plan['prepared'] ) );

		// Plan still offered -> restored.
		add_filter( 'wcsc_wcsatt_available_schemes', function () {
			return array( '1_month', '6_week' );
		} );
		$plan = WCSC_Cart_Manager::plan_import( array( $stored ) );
		wcsc_assert_same( 1, count( $plan['prepared'] ) );
		$r = $plan['prepared'][0];
		wcsc_assert_same( array( 'wcsatt_data' => array( 'active_subscription_scheme' => '6_week' ) ), $r['cart_item_data'] );

		// Lifecycle: field present during add, removed afterwards.
		$adapter->before_add( $r );
		wcsc_assert_same( '6_week', $_POST['convert_to_sub_80'] );
		$adapter->after_add( $r );
		wcsc_assert( ! isset( $_POST['convert_to_sub_80'] ) && ! isset( $_REQUEST['convert_to_sub_80'] ) );

		// Verification of the resulting cart item.
		wcsc_assert( $adapter->verify_added( array( 'wcsatt_data' => array( 'active_subscription_scheme' => '6_week' ) ), $r ) );
		wcsc_assert( ! $adapter->verify_added( array( 'wcsatt_data' => array( 'active_subscription_scheme' => null ) ), $r ), 'A cart item without the plan must fail verification' );
		wcsc_assert( ! $adapter->verify_added( array(), $r ) );

		// Plan withdrawn after sharing -> skipped, not substituted.
		$GLOBALS['wcsc_filters'] = array();
		add_filter( 'wcsc_wcsatt_available_schemes', function () {
			return array( '1_month' );
		} );
		$plan = WCSC_Cart_Manager::plan_import( array( $stored ) );
		wcsc_assert_same( 0, count( $plan['prepared'] ) );
		wcsc_assert( false !== strpos( $plan['skipped'][0]['reason'], 'no longer offered' ) );

		// Preview text.
		$lines = $adapter->describe( array( 'scheme' => '6_week' ), wc_get_product( 80 ) );
		wcsc_assert_same( 'Subscription: every 6 weeks', $lines[0] );
		$lines = $adapter->describe( array( 'scheme' => '1_month' ), wc_get_product( 80 ) );
		wcsc_assert_same( 'Subscription: every month', $lines[0] );
	}
);
