/**
 * WC Share Cart – admin behaviour: colour pickers and confirmations.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		if ( $.fn.wpColorPicker ) {
			$( '.wcsc-color-field' ).wpColorPicker();
		}

		var cfg = window.wcscAdmin || {};

		$( '.wcsc-admin' ).on( 'click', '.wcsc-confirm-delete', function ( e ) {
			if ( ! window.confirm( cfg.confirmDelete ) ) {
				e.preventDefault();
			}
		} );

		$( '#wcsc-carts-form' ).on( 'submit', function ( e ) {
			var submitter = e.originalEvent && e.originalEvent.submitter;
			// Only guard the bulk "Apply" buttons, not the Filter button.
			if ( submitter && 'filter_action' === submitter.name ) {
				return;
			}
			var action = $( 'select[name="action"]', this ).val();
			var action2 = $( 'select[name="action2"]', this ).val();
			var chosen = '-1' !== action ? action : action2;
			if ( 'bulk_delete' === chosen && ! window.confirm( cfg.confirmDelete ) ) {
				e.preventDefault();
			} else if ( 'bulk_revoke' === chosen && ! window.confirm( cfg.confirmRevoke ) ) {
				e.preventDefault();
			}
		} );
	} );
}( window.jQuery ) );
