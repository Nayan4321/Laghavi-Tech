/* Aarye Marketing Addon for Forms – "Copy" buttons, confirmations and the past entries import. */
( function () {
	'use strict';
	document.addEventListener( 'click', function ( e ) {
		var ask = e.target.closest ? e.target.closest( '[data-confirm]' ) : null;
		if ( ask && ! window.confirm( ask.getAttribute( 'data-confirm' ) ) ) {
			e.preventDefault();
		}
	} );
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

	// Import past entries, one batch after another.
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '.aarye-import__go' ) : null;
		if ( ! btn || ! window.aaryeAdmin ) {
			return;
		}
		var cfg = window.aaryeAdmin;
		var status = btn.parentNode.querySelector( '.aarye-import__status' );
		var total = parseInt( btn.getAttribute( 'data-total' ), 10 ) || 0;
		var imported = 0;
		var existing = 0;
		var fmt = function ( text, a, b ) {
			return text.replace( '%1$s', a ).replace( '%2$s', b );
		};
		btn.disabled = true;
		var step = function ( offset ) {
			status.textContent = fmt( cfg.progress, Math.min( offset, total ), total );
			var body = new FormData();
			body.append( 'action', 'aarye_import' );
			body.append( 'nonce', cfg.nonce );
			body.append( 'source', btn.getAttribute( 'data-source' ) );
			body.append( 'offset', offset );
			fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( r ) {
					if ( ! r || ! r.success ) {
						throw new Error( r && r.data && r.data.message ? r.data.message : 'error' );
					}
					imported += r.data.imported;
					existing += r.data.existing;
					if ( r.data.done ) {
						status.textContent = fmt( cfg.done, imported, existing );
						btn.disabled = false;
					} else {
						step( r.data.next );
					}
				} )
				.catch( function ( err ) {
					status.textContent = cfg.error + ' ' + err.message;
					btn.disabled = false;
				} );
		};
		step( 0 );
	} );
}() );
