/* Laghavi Social Login – copy buttons on the settings page. */
( function () {
	document.querySelectorAll( '.lsl-copy__button' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var input = button.parentNode.querySelector( 'input' );
			var label = button.textContent;
			var done = function () {
				button.textContent = window.lslAdmin ? window.lslAdmin.copied : label;
				setTimeout( function () {
					button.textContent = label;
				}, 1500 );
			};
			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( input.value ).then( done );
			} else {
				input.select();
				document.execCommand( 'copy' );
				done();
			}
		} );
	} );
} )();
