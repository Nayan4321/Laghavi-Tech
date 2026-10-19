/* Simple Login Page Pro – small touches on the designed login page. */
( function () {
	var form = document.getElementById( 'loginform' );
	var nav = document.getElementById( 'nav' );

	// "Remember me" and "Forgot password?" side by side, like most modern login pages.
	if ( form && nav ) {
		var lost = nav.querySelector( 'a[href*="action=lostpassword"]' );
		var remember = form.querySelector( '.forgetmenot' );
		if ( lost ) {
			var row = document.createElement( 'div' );
			row.className = 'slpp-row';
			var submit = form.querySelector( '.submit' );
			if ( remember ) {
				row.appendChild( remember );
			} else {
				row.appendChild( document.createElement( 'span' ) );
			}
			lost.textContent = lost.textContent.replace( /\?$/, '' ) + '?';
			row.appendChild( lost );
			form.insertBefore( row, submit );
		}
	}

	// Remove the " | " separators WordPress puts between links; spacing comes from CSS.
	if ( nav ) {
		Array.prototype.slice.call( nav.childNodes ).forEach( function ( node ) {
			if ( 3 === node.nodeType && /^\s*\|?\s*$/.test( node.textContent ) ) {
				nav.removeChild( node );
			}
		} );
		if ( ! nav.querySelector( 'a' ) ) {
			nav.style.display = 'none';
		}
	}
} )();
