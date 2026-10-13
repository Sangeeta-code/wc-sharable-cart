<?php
/**
 * Adapter interface for custom product types.
 *
 * @package WC_Share_Cart
 */

defined( 'ABSPATH' ) || exit;

/**
 * A custom product plugin implements this interface to tell WC Share Cart how to
 * safely serialize and restore its own *public* product selections (flavors, quantity
 * tiers, split-flavor combinations, ...).
 *
 * WC Share Cart never guesses private meta keys: only keys an adapter explicitly
 * claims through get_handled_keys() are ever read from a cart item.
 *
 * Register an adapter:
 *
 *     add_filter( 'wcsc_cart_item_adapters', function ( $adapters ) {
 *         $adapters[] = new My_Flavor_Adapter();
 *         return $adapters;
 *     } );
 */
interface WCSC_Cart_Item_Adapter {

	/**
	 * Unique id stored with the snapshot, e.g. "my-plugin-flavors".
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Whether this adapter handles the given product (usually by product type).
	 *
	 * @param WC_Product $product Parent product (never the variation).
	 * @return bool
	 */
	public function supports_product( WC_Product $product ): bool;

	/**
	 * Cart item keys this adapter owns. Any other unknown key makes the item "unsupported".
	 *
	 * @return string[]
	 */
	public function get_handled_keys(): array;

	/**
	 * Extract the public selections from a cart item. Return scalars / arrays of scalars only.
	 *
	 * @param array      $cart_item WooCommerce cart item.
	 * @param WC_Product $product   Cart item product object.
	 * @return array|WP_Error Selections, or a WP_Error to mark the item as not shareable.
	 */
	public function serialize( array $cart_item, WC_Product $product );

	/**
	 * Validate stored selections against the live product and return the extra cart item data
	 * to pass to WC()->cart->add_to_cart(). Must not trust the stored values.
	 *
	 * @param array      $options  Stored selections.
	 * @param WC_Product $product  Parent product.
	 * @param int        $quantity Requested quantity.
	 * @return array|WP_Error Cart item data, or WP_Error when the selection is no longer valid.
	 */
	public function restore( array $options, WC_Product $product, int $quantity );

	/**
	 * Human readable lines describing the selections (shown in the preview).
	 *
	 * @param array      $options Stored selections.
	 * @param WC_Product $product Parent product.
	 * @return string[]
	 */
	public function describe( array $options, WC_Product $product ): array;
}

/**
 * Optional companion interface for adapters that must prepare the environment around
 * WC()->cart->add_to_cart() and confirm that the result matches what was requested.
 *
 * If verify_added() returns false the whole import is rolled back (the visitor's cart is restored).
 */
interface WCSC_Cart_Item_Lifecycle {

	/**
	 * Called right before add_to_cart().
	 *
	 * @param array $restored Restored item (see WCSC_Cart_Serializer::restore_item()).
	 */
	public function before_add( array $restored ): void;

	/**
	 * Called right after add_to_cart() (also when it failed).
	 *
	 * @param array $restored Restored item.
	 */
	public function after_add( array $restored ): void;

	/**
	 * Confirm that the cart item created by WooCommerce carries the requested selection.
	 *
	 * @param array $cart_item WooCommerce cart item that was just added or merged.
	 * @param array $restored  Restored item.
	 * @return bool
	 */
	public function verify_added( array $cart_item, array $restored ): bool;
}
