=== WC Share Cart ===
Contributors: sangeetamondal
Tags: woocommerce, cart, share cart, whatsapp, qr code
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let customers share their WooCommerce cart with a unique link, WhatsApp, email or QR code. Recipients can preview it and merge it with, or replace, their own cart.

== Description ==

WC Share Cart adds a **Share Cart** button to the WooCommerce cart (classic cart shortcode and Cart Block). Customers get a secure link they can copy, send through WhatsApp or email (opened in their own apps), or hand over as a QR code. Everything is generated locally: no paid services, no external APIs, no tracking.

**For customers**

* Share modal with Copy Link, WhatsApp, Email and QR code (PNG download).
* Recipients see a preview with images, selected options, quantities, live prices, availability and expiry, then choose **Merge With My Cart** or **Replace My Cart**. Nothing is imported until they click the button.
* My Account → Shared Carts: history, contents, revoke, remove.

**For store owners**

* WooCommerce → WC Share Cart: dashboard, shared carts list (filters, search, bulk revoke/delete) and settings.
* Link expiry, maximum validity, rate limits (per session and per IP), product limit, daily cleanup.
* Button label, colours, radius, placement (classic cart and Cart Block), custom class, all customer-facing messages.

**Safe by design**

* 256-bit random tokens; only a SHA-256 hash is stored, so links cannot be reconstructed from the database.
* Shared data contains only product id, variation id, variation attributes, quantity and public options claimed by an adapter. No personal data, no session data, **no prices**.
* Prices, taxes, stock and purchasability are re-validated from the live catalogue at preview and import. Unavailable items are reported by name and never substituted.
* Replace is protected: validation happens before the cart is touched, and the original cart (and coupons) is restored if WooCommerce rejects an item.
* Preview is read-only (GET). Importing, sharing and revoking are POST requests with nonces and rate limiting. Shared pages are sent with no-cache, `noindex` and `no-referrer` headers.

== Shortcode: place the button anywhere ==

`[wcsc_share_button]` prints the Share Cart button wherever you put it: a page, a widget, a page-builder shortcode widget, a theme template or a cart drawer / mini-cart.

Attributes (all optional): `label="Send my cart"`, `class="my-btn"`, `icon="no"`, `width="full"`.

* To use only the shortcode, set both placement options (Settings → Button Settings) to "Do not add automatically".
* Theme template: `<?php echo do_shortcode( '[wcsc_share_button]' ); ?>`
* Cart drawer built on WooCommerce's standard mini-cart template:

    add_action( 'woocommerce_after_mini_cart', function () {
        echo do_shortcode( '[wcsc_share_button]' );
    } );

* The scripts and the Share Cart window load automatically on pages that contain the button. For drawers that refresh by AJAX or print late in the page, enable "Load the Share Cart scripts on every page".
* The Mini-Cart block does not run PHP hooks. Add a Shortcode block to the Mini-Cart template part in the Site Editor if your WooCommerce version allows it.

These instructions are also shown on the Settings page.

== Custom product types ==

WC Share Cart never guesses private cart item keys. If a cart item carries extra data that no adapter claims, it is clearly reported as "cannot be shared" before the link is created, and is left out only after the customer confirms.

To support your own product type (flavors, quantity tiers, split-flavor combinations...), implement `WCSC_Cart_Item_Adapter` and register it:

    add_filter( 'wcsc_cart_item_adapters', function ( $adapters ) {
        $adapters[] = new My_Flavor_Adapter();
        return $adapters;
    } );

Your adapter declares which cart item keys it owns, serializes only public selections, and validates them again on restore (the stored values are never trusted).

Built in: an adapter for "All Products for WooCommerce Subscriptions" (`wcsatt_data`). The chosen plan is re-validated against the live product, applied through WooCommerce, and verified in the cart afterwards; if it cannot be applied the whole import is rolled back. This adapter has been written from observed cart data and has not yet been tested on a live store.

Other hooks: `wcsc_wcsatt_available_schemes`, `wcsc_ignored_cart_item_keys`, `wcsc_serialize_cart_item`, `wcsc_restore_cart_item`, `wcsc_max_item_quantity`, `wcsc_client_ip`, `wcsc_import_rate_limit`, `wcsc_template_file`.

Templates can be overridden in your theme under `wc-share-cart/` (`share-modal.php`, `shared-cart-preview.php`, `my-account-shared-carts.php`).

== Installation ==

1. Make sure WooCommerce is installed and active.
2. Upload the `wc-share-cart` folder to `/wp-content/plugins/` (or upload the ZIP under Plugins → Add New → Upload Plugin).
3. Activate the plugin. Pretty permalinks are recommended (links look like `/shared-cart/TOKEN/`); with plain permalinks a `?wcsc_token=` fallback is used automatically.
4. Review WooCommerce → WC Share Cart → Settings.

If shared links return a 404 after an unusual migration, visit Settings → Permalinks and press Save once. The plugin itself only flushes rewrite rules on activation or after a setting that needs it.

== Compatibility ==

Built for the WooCommerce classic cart, Cart Block, Checkout Block, guest and logged-in customers, variable products and AJAX cart updates. High-Performance Order Storage: the plugin only uses the WooCommerce order CRUD API, but it does **not** declare HPOS or Cart/Checkout Blocks compatibility until you have verified it on your store. After testing, declare it with:

    add_filter( 'wcsc_declare_hpos_compatibility', '__return_true' );
    add_filter( 'wcsc_declare_blocks_compatibility', '__return_true' );

== Testing ==

* Automated: `php tests/run.php` runs logic tests (tokens, hashing, expiry, rate limiting, serialization, import planning) with WordPress/WooCommerce stubs. No WordPress install needed.
* Manual: see `tests/MANUAL-TESTS.md` for the checklist that must be run on a real WooCommerce site (Cart Block, checkout, stock races, mobile, etc.).

== Privacy ==

Shared carts store product ids, variations, quantities, dates and view/import counters. Logged-in customers' records reference their user id; guests' records reference an irreversible hash derived from their shopping session. No names, emails, addresses or payment data are stored. Suggested privacy policy text is added automatically, and records are included in WordPress personal data erasure and user deletion.

Deactivating the plugin keeps all data. Permanent removal on uninstall happens only if you enable "Delete ALL plugin data when the plugin is deleted".

== Changelog ==

= 1.0.0 =
* Initial release.
* `[wcsc_share_button]` shortcode, "Do not add automatically" placement option and optional site-wide script loading for cart drawers.
