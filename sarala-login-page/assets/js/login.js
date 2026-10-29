/* Sarala Login Page – on wp-login.php, move the buttons below the form's submit button. */
( function () {
	document.querySelectorAll( '.saralopa-context-wp-login' ).forEach( function ( block ) {
		var form = block.closest( 'form' );
		if ( form ) {
			form.appendChild( block );
		}
	} );
} )();
