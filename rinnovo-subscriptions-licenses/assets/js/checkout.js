/* Rinnovo Subscriptions & Licenses – change quantities and remove items on the checkout page. */
( function ( $ ) {
	if ( ! window.rinnovoCheckout ) {
		return;
	}
	var timer = null;

	function send( key, qty ) {
		$( '.woocommerce-checkout-review-order-table' ).addClass( 'processing' ).css( 'opacity', 0.6 );
		$.post( rinnovoCheckout.url, { key: key, qty: qty, nonce: rinnovoCheckout.nonce } )
			.done( function ( res ) {
				if ( res && res.success && res.data.empty ) {
					window.location = res.data.redirect;
					return;
				}
				$( document.body ).trigger( 'update_checkout' );
			} )
			.always( function () {
				$( '.woocommerce-checkout-review-order-table' ).removeClass( 'processing' ).css( 'opacity', '' );
			} );
	}

	$( document.body ).on( 'click', '.rinnovo-qty__minus, .rinnovo-qty__plus', function () {
		var wrap = $( this ).closest( '.rinnovo-qty' );
		var input = wrap.find( '.rinnovo-qty__input' );
		var max = parseInt( input.attr( 'max' ), 10 ) || Infinity;
		var qty = ( parseInt( input.val(), 10 ) || 0 ) + ( $( this ).hasClass( 'rinnovo-qty__plus' ) ? 1 : -1 );
		qty = Math.max( 0, Math.min( qty, max ) );
		input.val( qty );
		clearTimeout( timer );
		timer = setTimeout( function () {
			send( wrap.data( 'key' ), qty );
		}, 400 );
	} );

	$( document.body ).on( 'change', '.rinnovo-qty__input', function () {
		var wrap = $( this ).closest( '.rinnovo-qty' );
		send( wrap.data( 'key' ), Math.max( 0, parseInt( $( this ).val(), 10 ) || 0 ) );
	} );

	$( document.body ).on( 'click', '.rinnovo-qty__remove', function () {
		send( $( this ).closest( '.rinnovo-qty' ).data( 'key' ), 0 );
	} );
} )( jQuery );
