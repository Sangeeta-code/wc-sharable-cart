/**
 * WC Share Cart – cart page behaviour.
 *
 * - Mounts the Share Cart button in the WooCommerce Cart Block (the classic cart button is rendered by PHP).
 * - Opens an accessible modal, creates the link through the REST API and offers copy / WhatsApp / email / QR.
 *
 * Vanilla JS, no dependencies besides the bundled QR generator (global `qrcode`).
 */
( function () {
	'use strict';

	var data = window.wcscData;
	if ( ! data ) {
		return;
	}

	var i18n = data.i18n || {};
	var overlay, modal, lastFocus, cache = null, busy = false, currentToken = '', revoked = false;
	var els = {};

	/* ------------------------------------------------------------------ helpers */

	function $( sel, root ) {
		return ( root || document ).querySelector( sel );
	}

	function show( el, visible ) {
		if ( el ) {
			el.hidden = ! visible;
		}
	}

	function announce( message ) {
		if ( ! els.live ) {
			return;
		}
		els.live.textContent = '';
		// Re-set on the next tick so repeated identical messages are announced again.
		window.setTimeout( function () {
			els.live.textContent = message;
		}, 30 );
	}

	/**
	 * A signature of what is currently in the cart, so a created link is only reused
	 * while the cart has not changed.
	 */
	function cartSignature() {
		var parts = [];
		document.querySelectorAll( '.woocommerce-cart-form input.qty, .wc-block-components-quantity-selector__input' ).forEach( function ( input ) {
			parts.push( ( input.name || input.getAttribute( 'aria-label' ) || '' ) + '=' + input.value );
		} );
		var blockItems = $( '.wc-block-cart-items' );
		if ( blockItems ) {
			parts.push( blockItems.textContent.replace( /\s+/g, ' ' ) );
		}
		// Cart drawers / mini-carts (shortcode button placed outside the cart page).
		document.querySelectorAll( '.widget_shopping_cart_content, .wc-block-mini-cart__drawer' ).forEach( function ( box ) {
			parts.push( box.textContent.replace( /\s+/g, ' ' ) );
		} );
		return parts.join( '|' );
	}

	/* ------------------------------------------------------------------ block cart mounting */

	function buildButton() {
		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = ( 'wcsc-share-btn wcsc-share-btn--block ' + ( data.button.class || '' ) ).trim();
		btn.setAttribute( 'style', data.button.style || '' );
		btn.setAttribute( 'aria-haspopup', 'dialog' );
		btn.setAttribute( 'aria-controls', 'wcsc-modal' );
		btn.setAttribute( 'data-wcsc-injected', '1' );
		btn.innerHTML = '<svg class="wcsc-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="currentColor" d="M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11c.54.5 1.25.81 2.04.81a3 3 0 1 0-3-3c0 .24.04.47.09.7L8.04 9.81A2.99 2.99 0 0 0 6 9a3 3 0 1 0 0 6c.79 0 1.5-.31 2.04-.81l7.12 4.16c-.05.21-.08.43-.08.65a2.92 2.92 0 1 0 2.92-2.92z"/></svg><span></span>';
		btn.lastChild.textContent = data.button.label;
		return btn;
	}

	function wrap( btn ) {
		var div = document.createElement( 'div' );
		div.className = 'wcsc-share-wrap wcsc-share-wrap--block';
		div.appendChild( btn );
		return div;
	}

	var mountTimer = null, mountStart = Date.now();

	function ensureBlockButton() {
		// Classic cart: PHP already printed the button.
		if ( document.querySelector( '.wcsc-share-btn--classic' ) ) {
			return;
		}

		var submit = $( '.wc-block-cart__submit-container' );
		var existing = $( '.wcsc-share-wrap--block' );

		// Empty cart (no checkout button rendered): remove our button if present.
		if ( ! submit ) {
			var blockRoot = $( '.wp-block-woocommerce-cart, .wc-block-cart' );
			if ( ! blockRoot && existing ) {
				return;
			}
			if ( existing && Date.now() - mountStart > 1500 ) {
				existing.remove();
			}
			return;
		}

		if ( existing && document.body.contains( existing ) && existing.dataset.wcscPlacement === data.button.placement ) {
			// Make sure it is still adjacent to where React left the anchor.
			if ( existing.parentNode && ( existing.nextElementSibling === submit || existing.previousElementSibling === submit || existing.parentNode === submit.parentNode || existing.closest( '.wc-block-cart' ) ) ) {
				return;
			}
		}
		if ( existing ) {
			existing.remove();
		}

		var wrapper = wrap( buildButton() );
		wrapper.dataset.wcscPlacement = data.button.placement;

		var placement = data.button.placement;
		var cartRoot = $( '.wp-block-woocommerce-cart' ) || $( '.wc-block-cart' );

		if ( 'above_checkout' === placement && submit.parentNode ) {
			submit.parentNode.insertBefore( wrapper, submit );
		} else if ( 'cart_top' === placement && cartRoot ) {
			cartRoot.insertBefore( wrapper, cartRoot.firstChild );
		} else if ( submit.parentNode ) {
			submit.parentNode.insertBefore( wrapper, submit.nextSibling );
		}
	}

	function ensureFallback() {
		// If, after a while, the block anchor still does not exist, use the server rendered mount point.
		if ( document.querySelector( '.wcsc-share-btn--classic, .wcsc-share-wrap--block' ) ) {
			return;
		}
		var fb = $( '[data-wcsc-fallback]' );
		if ( fb && Date.now() - mountStart > 6000 && ! $( '.wc-block-cart__submit-container' ) && $( '.wc-block-cart-items' ) ) {
			fb.hidden = false;
			fb.appendChild( wrap( buildButton() ) );
		}
	}

	function scheduleMount() {
		if ( mountTimer ) {
			return;
		}
		mountTimer = window.requestAnimationFrame( function () {
			mountTimer = null;
			ensureBlockButton();
			ensureFallback();
		} );
	}

	function initBlockMounting() {
		// The store owner places the button with the [wcsc_share_button] shortcode instead.
		if ( 'none' === data.button.placement ) {
			return;
		}
		if ( ! ( $( '.wp-block-woocommerce-cart' ) || $( '.wc-block-cart' ) || $( '[data-wcsc-fallback]' ) ) ) {
			return;
		}
		scheduleMount();
		if ( 'MutationObserver' in window ) {
			new MutationObserver( scheduleMount ).observe( document.body, { childList: true, subtree: true } );
		}
		// Fallback evaluation even without DOM changes.
		window.setTimeout( scheduleMount, 6500 );
	}

	/* ------------------------------------------------------------------ modal */

	function collectEls() {
		overlay = $( '#wcsc-modal-overlay' );
		modal = $( '#wcsc-modal' );
		if ( ! overlay || ! modal ) {
			return false;
		}
		els = {
			loading: $( '[data-wcsc-loading]', modal ),
			loadingText: $( '[data-wcsc-loading-text]', modal ),
			error: $( '[data-wcsc-error]', modal ),
			unsupported: $( '[data-wcsc-unsupported]', modal ),
			unsupportedTitle: $( '[data-wcsc-unsupported-title]', modal ),
			unsupportedList: $( '[data-wcsc-unsupported-list]', modal ),
			sharePartial: $( '[data-wcsc-share-partial]', modal ),
			body: $( '[data-wcsc-body]', modal ),
			excluded: $( '[data-wcsc-excluded]', modal ),
			link: $( '[data-wcsc-link]', modal ),
			copy: $( '[data-wcsc-copy]', modal ),
			copyLabel: $( '[data-wcsc-copy-label]', modal ),
			whatsapp: $( '[data-wcsc-whatsapp]', modal ),
			email: $( '[data-wcsc-email]', modal ),
			qr: $( '[data-wcsc-qr]', modal ),
			qrDownload: $( '[data-wcsc-qr-download]', modal ),
			expiry: $( '[data-wcsc-expiry]', modal ),
			revoke: $( '[data-wcsc-revoke]', modal ),
			live: $( '[data-wcsc-live]', modal )
		};
		// Move the overlay to <body> so theme containers (transforms, overflow) cannot clip it.
		if ( overlay.parentNode !== document.body ) {
			document.body.appendChild( overlay );
		}
		return true;
	}

	function focusables() {
		return Array.prototype.filter.call(
			modal.querySelectorAll( 'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])' ),
			function ( el ) {
				return ! el.closest( '[hidden]' ) && el.offsetParent !== null;
			}
		);
	}

	function openModal( trigger ) {
		if ( ! overlay && ! collectEls() ) {
			return;
		}
		lastFocus = trigger || document.activeElement;
		overlay.hidden = false;
		document.documentElement.classList.add( 'wcsc-modal-open' );
		document.addEventListener( 'keydown', onKeydown, true );
		modal.focus();

		var sig = cartSignature();
		// Only reuse a link when we could actually observe the cart; otherwise always create a fresh one.
		if ( cache && '' !== sig && cache.signature === sig && ! revoked ) {
			renderResult( cache.result );
			return;
		}
		createLink( false, sig );
	}

	function closeModal() {
		if ( ! overlay || overlay.hidden ) {
			return;
		}
		overlay.hidden = true;
		document.documentElement.classList.remove( 'wcsc-modal-open' );
		document.removeEventListener( 'keydown', onKeydown, true );
		if ( lastFocus && typeof lastFocus.focus === 'function' && document.body.contains( lastFocus ) ) {
			lastFocus.focus();
		}
	}

	function onKeydown( e ) {
		if ( 'Escape' === e.key || 'Esc' === e.key ) {
			e.preventDefault();
			e.stopPropagation();
			closeModal();
			return;
		}
		if ( 'Tab' !== e.key ) {
			return;
		}
		var items = focusables();
		if ( ! items.length ) {
			e.preventDefault();
			modal.focus();
			return;
		}
		var first = items[ 0 ], last = items[ items.length - 1 ];
		if ( ! modal.contains( document.activeElement ) || document.activeElement === modal ) {
			e.preventDefault();
			( e.shiftKey ? last : first ).focus();
		} else if ( e.shiftKey && document.activeElement === first ) {
			e.preventDefault();
			last.focus();
		} else if ( ! e.shiftKey && document.activeElement === last ) {
			e.preventDefault();
			first.focus();
		}
	}

	function resetPanels() {
		show( els.loading, false );
		show( els.error, false );
		show( els.unsupported, false );
		show( els.body, false );
	}

	function showError( message ) {
		resetPanels();
		els.error.textContent = message;
		show( els.error, true );
		announce( message );
	}

	/* ------------------------------------------------------------------ API */

	function rawPost( url, body ) {
		return window.fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': data.restNonce,
				'X-WCSC-Nonce': data.nonce
			},
			body: JSON.stringify( body || {} )
		} ).then( function ( res ) {
			return res.json().catch( function () {
				return {};
			} ).then( function ( json ) {
				return { ok: res.ok, status: res.status, json: json };
			} );
		} );
	}

	/**
	 * Fetch fresh nonces. A guest's nonce depends on the shopping session, which may have been created
	 * after this page loaded (first add to cart from a drawer).
	 */
	function refreshNonces() {
		return window.fetch( data.noncesUrl, {
			method: 'GET',
			credentials: 'same-origin',
			cache: 'no-store',
			headers: { 'X-WCSC-Refresh': '1' }
		} ).then( function ( res ) {
			return res.json().then( function ( json ) {
				if ( res.ok && json && json.restNonce && json.nonce ) {
					data.restNonce = json.restNonce;
					data.nonce = json.nonce;
					return true;
				}
				return false;
			} );
		} ).catch( function () {
			return false;
		} );
	}

	function post( url, body ) {
		return rawPost( url, body ).then( function ( r ) {
			// Stale nonce: refresh once and retry.
			if ( 403 === r.status && r.json && 'wcsc_bad_nonce' === r.json.code && data.noncesUrl ) {
				return refreshNonces().then( function ( ok ) {
					return ok ? rawPost( url, body ) : r;
				} );
			}
			return r;
		} );
	}

	function createLink( confirmPartial, signature ) {
		if ( busy ) {
			return;
		}
		busy = true;
		revoked = false;
		resetPanels();
		els.loadingText.textContent = i18n.creating;
		show( els.loading, true );
		announce( i18n.creating );

		post( data.shareUrl, { confirm_partial: !! confirmPartial } )
			.then( function ( r ) {
				busy = false;
				if ( r.ok && r.json && r.json.success ) {
					cache = { signature: signature || cartSignature(), result: r.json };
					renderResult( r.json );
					return;
				}
				var json = r.json || {};
				if ( 'wcsc_unsupported' === json.code && json.data && json.data.excluded ) {
					renderUnsupported( json.data.excluded, json.message );
					return;
				}
				if ( 'wcsc_unsupported_all' === json.code && json.data && json.data.excluded ) {
					renderUnsupported( json.data.excluded, json.message, true );
					return;
				}
				showError( json.message || i18n.genericError );
			} )
			.catch( function () {
				busy = false;
				showError( i18n.genericError );
			} );
	}

	function renderUnsupported( excluded, message, noShare ) {
		resetPanels();
		els.unsupportedTitle.textContent = message || i18n.unsupportedHead;
		els.unsupportedList.innerHTML = '';
		excluded.forEach( function ( item ) {
			var li = document.createElement( 'li' );
			li.textContent = item.name + ( item.reason ? ' – ' + item.reason : '' ) + ( item.keys && item.keys.length ? ' [' + item.keys.join( ', ' ) + ']' : '' ) + ( item.detail ? ' ' + item.detail : '' );
			els.unsupportedList.appendChild( li );
		} );
		els.sharePartial.textContent = i18n.shareSupported;
		show( els.sharePartial, ! noShare );
		show( els.unsupported, true );
		announce( message || i18n.unsupportedHead );
		if ( ! noShare ) {
			els.sharePartial.focus();
		}
	}

	/* ------------------------------------------------------------------ result rendering */

	function drawQr( url ) {
		if ( ! els.qr || ! data.options.qr ) {
			return;
		}
		if ( 'function' !== typeof window.qrcode ) {
			show( els.qr.parentNode, false );
			return;
		}
		try {
			var qr = window.qrcode( 0, 'M' );
			qr.addData( url );
			qr.make();
			var count = qr.getModuleCount();
			var margin = 4;
			var size = 256;
			var cell = Math.floor( size / ( count + margin * 2 ) ) || 1;
			var px = cell * ( count + margin * 2 );
			els.qr.width = px;
			els.qr.height = px;
			var ctx = els.qr.getContext( '2d' );
			ctx.fillStyle = '#ffffff';
			ctx.fillRect( 0, 0, px, px );
			ctx.fillStyle = '#000000';
			for ( var r = 0; r < count; r++ ) {
				for ( var c = 0; c < count; c++ ) {
					if ( qr.isDark( r, c ) ) {
						ctx.fillRect( ( c + margin ) * cell, ( r + margin ) * cell, cell, cell );
					}
				}
			}
			if ( els.qrDownload ) {
				els.qrDownload.href = els.qr.toDataURL( 'image/png' );
			}
			show( els.qr.parentNode, true );
		} catch ( err ) {
			show( els.qr.parentNode, false );
		}
	}

	function renderResult( result ) {
		resetPanels();
		currentToken = result.token || '';
		var url = result.url;

		els.link.value = url;
		if ( els.copyLabel ) {
			els.copyLabel.textContent = i18n.copyLink;
		}
		if ( els.whatsapp ) {
			els.whatsapp.href = 'https://wa.me/?text=' + encodeURIComponent( i18n.waText + ' ' + url );
		}
		if ( els.email ) {
			els.email.href = 'mailto:?subject=' + encodeURIComponent( i18n.mailSubject ) + '&body=' + encodeURIComponent( i18n.mailBody + ' ' + url );
		}
		els.expiry.textContent = result.expires_label || '';

		// Items left out of the link (customer already confirmed).
		els.excluded.innerHTML = '';
		if ( result.excluded && result.excluded.length ) {
			var p = document.createElement( 'p' );
			p.textContent = i18n.excludedNote + ' ' + result.excluded.map( function ( i ) {
				return i.name;
			} ).join( ', ' );
			els.excluded.appendChild( p );
			show( els.excluded, true );
		} else {
			show( els.excluded, false );
		}

		drawQr( url );
		show( els.body, true );
		announce( result.expires_label || '' );

		if ( els.copy ) {
			els.copy.focus();
		} else {
			els.link.focus();
			els.link.select();
		}
	}

	/* ------------------------------------------------------------------ actions */

	function copyText( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}
		return new Promise( function ( resolve, reject ) {
			try {
				els.link.focus();
				els.link.select();
				els.link.setSelectionRange( 0, text.length );
				if ( document.execCommand( 'copy' ) ) {
					resolve();
				} else {
					reject( new Error( 'copy failed' ) );
				}
			} catch ( err ) {
				reject( err );
			}
		} );
	}

	var copyTimer = null;

	function onCopy() {
		var url = els.link.value;
		copyText( url ).then( function () {
			els.copyLabel.textContent = i18n.copied;
			announce( i18n.copied );
			window.clearTimeout( copyTimer );
			copyTimer = window.setTimeout( function () {
				els.copyLabel.textContent = i18n.copyLink;
			}, 2200 );
		} ).catch( function () {
			els.link.focus();
			els.link.select();
			announce( i18n.copyFailed );
			els.error.textContent = i18n.copyFailed;
			show( els.error, true );
		} );
	}

	function onRevoke() {
		if ( ! currentToken || ! window.confirm( i18n.revokeConfirm ) ) {
			return;
		}
		post( data.revokeUrl, { token: currentToken } ).then( function ( r ) {
			if ( r.ok && r.json && r.json.success ) {
				revoked = true;
				cache = null;
				currentToken = '';
				resetPanels();
				els.error.textContent = i18n.revoked;
				show( els.error, true );
				announce( i18n.revoked );
			} else {
				showError( ( r.json && r.json.message ) || i18n.genericError );
			}
		} ).catch( function () {
			showError( i18n.genericError );
		} );
	}

	/* ------------------------------------------------------------------ init */

	function init() {
		collectEls();

		document.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest ? e.target.closest( '.wcsc-share-btn' ) : null;
			if ( btn ) {
				e.preventDefault();
				openModal( btn );
				return;
			}
			if ( ! modal ) {
				return;
			}
			if ( e.target === overlay ) {
				closeModal();
				return;
			}
			if ( e.target.closest && e.target.closest( '[data-wcsc-close]' ) ) {
				closeModal();
				return;
			}
			if ( els.copy && e.target.closest && e.target.closest( '[data-wcsc-copy]' ) ) {
				onCopy();
				return;
			}
			if ( e.target.closest && e.target.closest( '[data-wcsc-share-partial]' ) ) {
				createLink( true, cartSignature() );
				return;
			}
			if ( e.target.closest && e.target.closest( '[data-wcsc-revoke]' ) ) {
				onRevoke();
			}
		} );

		if ( els.link ) {
			els.link.addEventListener( 'focus', function () {
				els.link.select();
			} );
		}

		// Classic cart AJAX updates change the cart: forget the cached link.
		if ( window.jQuery ) {
			window.jQuery( document.body ).on( 'updated_cart_totals removed_from_cart updated_wc_div added_to_cart wc_fragments_refreshed wc_fragments_loaded', function () {
				cache = null;
			} );
		}
		// WooCommerce blocks (Mini-Cart, Add to Cart) announce cart changes with DOM events.
		[ 'wc-blocks_added_to_cart', 'wc-blocks_removed_from_cart' ].forEach( function ( name ) {
			document.body.addEventListener( name, function () {
				cache = null;
			} );
		} );

		initBlockMounting();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
