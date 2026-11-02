/* Rinnovo Subscriptions & Licenses – copy license keys. */
( function () {
	document.addEventListener( 'click', function ( e ) {
		var button = e.target.closest( '.rinnovo-lic-copy' );
		if ( ! button ) {
			return;
		}
		var text = button.getAttribute( 'data-copy' );
		var label = button.textContent;
		var done = function () {
			button.textContent = ( window.rinnovoLicenses && window.rinnovoLicenses.copied ) || 'Copied!';
			setTimeout( function () {
				button.textContent = label;
			}, 1500 );
		};
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( text ).then( done );
		} else {
			var input = document.createElement( 'textarea' );
			input.value = text;
			document.body.appendChild( input );
			input.select();
			document.execCommand( 'copy' );
			document.body.removeChild( input );
			done();
		}
	} );
} )();
