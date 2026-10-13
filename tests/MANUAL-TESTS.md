# WC Share Cart – test plan

`php tests/run.php` covers the pure logic with stubs (marked **Auto**). Everything marked **Manual** needs a real
WooCommerce site (staging recommended; use two browsers or one normal + one private window). None of the manual
cases has been run yet: please record results before declaring HPOS / Blocks compatibility.

| # | Scenario | How | Expected |
|---|----------|-----|----------|
| 1 | Share one simple product | Add a simple product, click Share Cart | Modal opens, link `/shared-cart/<43 chars>/` shown, expiry text shown. **Manual** |
| 2 | Share multiple products | Add 3 products | Preview lists all 3. **Manual** |
| 3 | Variable products | Add a variation with attributes | Preview shows the same attributes; import selects the same variation. Serialization **Auto**, end-to-end **Manual** |
| 4 | Guest session | Not logged in | Link created; Revoke button works only in the same session. **Manual** |
| 5 | Logged-in account | Log in, share | Appears in My Account → Shared Carts. **Manual** |
| 6 | Other browser | Open link in a private window | Preview shows; the sharer's cart/session is untouched. **Manual** |
| 7 | Import into empty cart | Add Shared Items (either mode) | Items in cart, success message, redirected to cart. **Manual** |
| 8 | Merge with non-empty cart | Choose Merge | Existing items kept; identical configuration quantities combine, different variations stay separate. **Manual** |
| 9 | Replace non-empty cart | Choose Replace | Old items gone, shared items present; valid coupons re-applied, invalid ones reported. **Manual** |
| 10 | Expired link | Set `expires_at` in the past (DB) or wait | "Expired" message, HTTP 410. Status logic **Auto**, page **Manual** |
| 11 | Revoked link | Revoke in admin / My Account / modal | "Revoked" message, HTTP 410. **Manual** |
| 12 | Product deleted after sharing | Trash the product | Item flagged unavailable by name; other items import; nothing substituted. Planning **Auto**, page **Manual** |
| 13 | Stock changes | Reduce stock below shared quantity | "Only N available" / "Out of stock" shown and skipped. Planning **Auto**, page **Manual** |
| 14 | Price changes | Change the price after sharing | Preview and cart show the NEW price. **Manual** |
| 15 | Coupons / discounts | Recipient has a coupon applied | Merge keeps it; Replace re-applies when valid, otherwise tells the customer. **Manual** |
| 16 | Unsupported custom options | Add a product with extra cart data (no adapter) | 409 warning listing the item BEFORE a link is created; "Share the other items" creates the link without it. Serialization **Auto**, UI **Manual** |
| 17 | Mobile modal | 360 px wide device/emulator | No horizontal scroll, full-width buttons, modal scrolls internally. **Manual** |
| 18 | WhatsApp & Email links | Click both | `wa.me` opens with encoded text + link; `mailto:` opens the mail app with subject/body. No server-sent email. **Manual** |
| 19 | QR code | Open modal, download PNG, scan with a phone | Scans to the link; PNG downloads. **Manual** |
| 20 | Cart Block | Use a page with the Cart Block | Button appears at the configured placement, survives quantity changes and re-renders; fallback below the block if the anchor is missing. **Manual** |
| 21 | Classic cart | Use `[woocommerce_cart]` | Button appears at the configured hook position. **Manual** |
| 22 | CSRF / unauthorized | POST `/wp-json/wcsc/v1/share` without `X-WCSC-Nonce`; POST import without `wcsc_nonce`; revoke someone else's token; open another user's My Account action | 403 / redirect with error; revoke returns the same "could not be revoked" for unknown and not-owned. Nonce logic **Manual** |
| 23 | Repeated imports | Import the same link twice (merge) | Quantities combine for identical configurations; import counter increments; stock limits respected. **Manual** |
| 24 | Concurrent stock | Two browsers import the last unit at the same time | The loser gets "could not be added… nothing was changed" (rollback). **Manual** |
| 25 | Warnings / console | `WP_DEBUG` + `WP_DEBUG_LOG` on, browser console open | No PHP notices, no JS errors on cart, shared page, My Account, admin. **Manual** |
| 26 | Rate limits | Create more links than the configured limit | 429 with a friendly message. Limiter **Auto**, REST wiring **Manual** |
| 27 | Pretty / plain permalinks | Switch to Plain | `?wcsc_token=` links work. **Manual** |
| 28 | Block theme and classic theme | Open a shared link on both | Page uses the theme header/footer. **Manual** |
| 29 | Checkout Block + HPOS | Complete an order after importing | Order meta `_wcsc_cart_id` set only if it contains shared products; purchase counted only when paid. **Manual** |
| 30 | Uninstall | Deactivate, then delete with and without the "delete data" setting | Deactivate keeps data; delete removes data only when enabled. **Manual** |
| 31 | Shortcode on a normal page | Add `[wcsc_share_button]` in a Shortcode block, set both placements to "Do not add automatically", open the page and the cart | Button on the page opens the modal; cart page shows no automatic button. **Manual** |
| 32 | Shortcode in a cart drawer | Print the shortcode in the theme's drawer / `woocommerce_after_mini_cart`; as a **guest** add an item from a product page, open the drawer, click the button | Modal opens, link is created (no "session expired" error); change the cart and click again: a NEW link is created. With AJAX-refreshed drawers enable "Load on every page". **Manual** |
| 33 | Shortcode attributes | `label`, `class`, `icon="no"`, `width="full"`; shortcode with sharing disabled for the visitor type | Attributes applied; nothing printed when sharing is unavailable. Escaping **Auto**, rest **Manual** |
| 34 | Shortcode + Cart Block default | Shortcode inside a drawer on the Cart Block page with default placement | Automatic Cart Block button is still added (drawer button does not suppress it). **Manual** |
