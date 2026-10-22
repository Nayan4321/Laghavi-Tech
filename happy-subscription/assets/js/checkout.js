/* Happy Subscription – change quantities and remove items on the checkout page. */
( function ( $ ) {
	if ( ! window.happysubCheckout ) {
		return;
	}
	var timer = null;

	function send( key, qty ) {
		$( '.woocommerce-checkout-review-order-table' ).addClass( 'processing' ).css( 'opacity', 0.6 );
		$.post( happysubCheckout.url, { key: key, qty: qty, nonce: happysubCheckout.nonce } )
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

	$( document.body ).on( 'click', '.happysub-qty__minus, .happysub-qty__plus', function () {
		var wrap = $( this ).closest( '.happysub-qty' );
		var input = wrap.find( '.happysub-qty__input' );
		var max = parseInt( input.attr( 'max' ), 10 ) || Infinity;
		var qty = ( parseInt( input.val(), 10 ) || 0 ) + ( $( this ).hasClass( 'happysub-qty__plus' ) ? 1 : -1 );
		qty = Math.max( 0, Math.min( qty, max ) );
		input.val( qty );
		clearTimeout( timer );
		timer = setTimeout( function () {
			send( wrap.data( 'key' ), qty );
		}, 400 );
	} );

	$( document.body ).on( 'change', '.happysub-qty__input', function () {
		var wrap = $( this ).closest( '.happysub-qty' );
		send( wrap.data( 'key' ), Math.max( 0, parseInt( $( this ).val(), 10 ) || 0 ) );
	} );

	$( document.body ).on( 'click', '.happysub-qty__remove', function () {
		send( $( this ).closest( '.happysub-qty' ).data( 'key' ), 0 );
	} );
} )( jQuery );
