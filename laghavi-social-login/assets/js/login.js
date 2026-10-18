/* Laghavi Social Login – on wp-login.php, move the buttons below the form's submit button. */
( function () {
	document.querySelectorAll( '.lsl-context-wp-login' ).forEach( function ( block ) {
		var form = block.closest( 'form' );
		if ( form ) {
			form.appendChild( block );
		}
	} );
} )();
