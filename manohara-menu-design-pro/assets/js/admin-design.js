/* Manohara Menu Design Pro – choose an image for a link field on the design screen. */
( function ( $ ) {
	'use strict';
	$( document ).on( 'click', '.mnhp-pick-url', function () {
		var $input = $( this ).siblings( '.mnhp-url-field' );
		var frame = wp.media( { library: { type: 'image' }, multiple: false } );
		frame.on( 'select', function () {
			$input.val( frame.state().get( 'selection' ).first().toJSON().url ).trigger( 'change' );
		} );
		frame.open();
	} );
}( jQuery ) );
