/* Rinnovo Session Membership Pro: draw check-in QR codes. */
( function () {
	if ( typeof window.qrcode !== 'function' ) {
		return;
	}
	document.querySelectorAll( '[data-qr]' ).forEach( function ( el ) {
		var qr = window.qrcode( 0, 'M' );
		qr.addData( el.getAttribute( 'data-qr' ) );
		qr.make();
		el.innerHTML = qr.createSvgTag( { cellSize: 4, margin: 2, scalable: true } );
		var svg = el.querySelector( 'svg' );
		if ( svg ) {
			svg.style.width = '180px';
			svg.style.height = '180px';
		}
	} );
}() );
