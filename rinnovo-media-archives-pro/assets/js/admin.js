/* Rinnovo Media Archives Pro: plan editor. */
jQuery( function ( $ ) {
	var next = 1000;
	$( '.rinnovoma-add' ).on( 'click', function () {
		$( '.rinnovoma-plans' ).append( $( '#tmpl-rinnovoma-plan' ).html().replace( /__i__/g, String( next++ ) ).replace( /wc-product-search-new/g, 'wc-product-search' ) );
		$( document.body ).trigger( 'wc-enhanced-select-init' );
	} );
	$( '.rinnovoma-plans' ).on( 'click', '.rinnovoma-remove', function () {
		$( this ).closest( '.rinnovoma-plan-edit' ).remove();
	} );
} );
