/* Aarye Marketing Addon for Forms – "Copy" buttons in the admin. */
( function () {
	'use strict';
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '.aarye-copy__btn' ) : null;
		if ( ! btn ) {
			return;
		}
		var text = btn.getAttribute( 'data-copy' ) || '';
		var done = function () {
			var label = btn.textContent;
			btn.textContent = ( window.aaryeAdmin && window.aaryeAdmin.copied ) || 'Copied';
			setTimeout( function () { btn.textContent = label; }, 1500 );
		};
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( text ).then( done );
		} else {
			var area = document.createElement( 'textarea' );
			area.value = text;
			document.body.appendChild( area );
			area.select();
			document.execCommand( 'copy' );
			document.body.removeChild( area );
			done();
		}
	} );
}() );
