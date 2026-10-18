/* Laghavi Subscriptions – change quantities and remove items on the checkout page. */
( function ( $ ) {
	if ( ! window.lsubCheckout ) {
		return;
	}
	var timer = null;

	function send( key, qty ) {
		$( '.woocommerce-checkout-review-order-table' ).addClass( 'processing' ).css( 'opacity', 0.6 );
		$.post( lsubCheckout.url, { key: key, qty: qty, nonce: lsubCheckout.nonce } )
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

	$( document.body ).on( 'click', '.lsub-qty__minus, .lsub-qty__plus', function () {
		var wrap = $( this ).closest( '.lsub-qty' );
		var input = wrap.find( '.lsub-qty__input' );
		var max = parseInt( input.attr( 'max' ), 10 ) || Infinity;
		var qty = ( parseInt( input.val(), 10 ) || 0 ) + ( $( this ).hasClass( 'lsub-qty__plus' ) ? 1 : -1 );
		qty = Math.max( 0, Math.min( qty, max ) );
		input.val( qty );
		clearTimeout( timer );
		timer = setTimeout( function () {
			send( wrap.data( 'key' ), qty );
		}, 400 );
	} );

	$( document.body ).on( 'change', '.lsub-qty__input', function () {
		var wrap = $( this ).closest( '.lsub-qty' );
		send( wrap.data( 'key' ), Math.max( 0, parseInt( $( this ).val(), 10 ) || 0 ) );
	} );

	$( document.body ).on( 'click', '.lsub-qty__remove', function () {
		send( $( this ).closest( '.lsub-qty' ).data( 'key' ), 0 );
	} );
} )( jQuery );
