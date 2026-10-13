<?php
/**
 * WooCommerce Cart Block integration.
 *
 * PHP hooks of the classic cart (woocommerce_after_cart_table, ...) do NOT run inside the Cart Block,
 * because its inner content is rendered client-side by React. Integration therefore has two parts:
 *
 *  1. public.js watches the DOM and mounts the button next to the block's checkout button
 *     (placement configurable), re-mounting it whenever React re-renders that area.
 *  2. A server-rendered, hidden fallback mount point is appended after the block, so the button
 *     still appears (below the block) if the expected block markup changes in a future version.
 *
 * @package WC_Share_Cart
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cart block integration.
 */
class WCSC_Cart_Block {

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_filter( 'render_block', array( $this, 'append_fallback_mount' ), 10, 2 );
	}

	/**
	 * Append the fallback mount point after the Cart Block.
	 *
	 * @param string $content Block HTML.
	 * @param array  $block   Parsed block.
	 * @return string
	 */
	public function append_fallback_mount( $content, $block ) {
		if ( empty( $block['blockName'] ) || 'woocommerce/cart' !== $block['blockName'] ) {
			return $content;
		}
		if ( ! WCSC_Public::sharing_available() || 'none' === (string) WCSC_Settings::get( 'block_placement' ) ) {
			return $content;
		}
		return $content . '<div class="wcsc-block-fallback" data-wcsc-fallback hidden></div>';
	}
}
