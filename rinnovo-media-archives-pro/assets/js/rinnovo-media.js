/* Rinnovo Media Archives Pro: watermark, copy protection, stream limits, gallery viewer. */
( function () {
	var cfg = window.rinnovomaPlayer || {};
	document.querySelectorAll( '.rinnovoma-player' ).forEach( function ( box ) {
		if ( box.classList.contains( 'rinnovoma-protect' ) ) {
			[ 'contextmenu', 'dragstart', 'copy' ].forEach( function ( ev ) {
				box.addEventListener( ev, function ( e ) { e.preventDefault(); } );
			} );
		}
		var text = box.getAttribute( 'data-rinnovoma-watermark' );
		if ( text && 'art' !== box.getAttribute( 'data-rinnovoma-kind' ) ) {
			var mark = document.createElement( 'div' );
			mark.className = 'rinnovoma-watermark';
			mark.textContent = text;
			box.appendChild( mark );
			var move = function () {
				mark.style.left = ( 5 + Math.random() * 55 ) + '%';
				mark.style.top = ( 8 + Math.random() * 70 ) + '%';
			};
			move();
			setInterval( move, 12000 );
		}
		var id = box.getAttribute( 'data-rinnovoma-stream' );
		if ( id && cfg.ajax ) {
			var timer;
			var send = function ( stop ) {
				var body = new FormData();
				body.append( 'action', 'rinnovoma_stream' );
				body.append( 'nonce', cfg.nonce );
				body.append( 'stream', id );
				if ( stop ) {
					body.append( 'stop', '1' );
					if ( navigator.sendBeacon ) {
						navigator.sendBeacon( cfg.ajax, body );
						return;
					}
				}
				fetch( cfg.ajax, { method: 'POST', body: body, credentials: 'same-origin' } ).then( function ( r ) { return r.json(); } ).then( function ( r ) {
					if ( r && r.success && ! r.data.allowed ) {
						box.innerHTML = '<div class="rinnovoma-locked"><p>' + String( cfg.message ).replace( /</g, '&lt;' ) + '</p></div>';
						clearInterval( timer );
					}
				} ).catch( function () {} );
			};
			send( false );
			timer = setInterval( function () { send( false ); }, 45000 );
			window.addEventListener( 'pagehide', function () { send( true ); } );
		}
		box.querySelectorAll( '.rinnovoma-gallery img' ).forEach( function ( img ) {
			img.addEventListener( 'click', function () {
				var view = document.createElement( 'div' );
				view.className = 'rinnovoma-lightbox rinnovoma-protect';
				view.innerHTML = '<img alt="" draggable="false" />';
				view.firstChild.src = img.getAttribute( 'data-full' ) || img.src;
				view.addEventListener( 'click', function () { view.remove(); } );
				view.addEventListener( 'contextmenu', function ( e ) { e.preventDefault(); } );
				document.body.appendChild( view );
			} );
		} );
	} );
} )();
