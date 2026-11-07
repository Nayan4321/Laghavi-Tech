/* Rinnovo Membership Content Pro: limit simultaneous video streams per account. */
( function () {
	var cfg = window.rinnovomemStreams;
	if ( ! cfg ) {
		return;
	}
	document.querySelectorAll( '[data-rinnovomem-stream]' ).forEach( function ( box ) {
		var id = box.getAttribute( 'data-rinnovomem-stream' );
		function send( stop ) {
			var body = new FormData();
			body.append( 'action', 'rinnovomem_stream' );
			body.append( 'nonce', cfg.nonce );
			body.append( 'stream', id );
			if ( stop ) {
				body.append( 'stop', '1' );
				if ( navigator.sendBeacon ) {
					navigator.sendBeacon( cfg.ajax, body );
					return;
				}
			}
			fetch( cfg.ajax, { method: 'POST', body: body, credentials: 'same-origin' } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( r ) {
					if ( r && r.success && ! r.data.play ) {
						box.innerHTML = '<div class="rinnovomem-paywall rinnovomem-paywall--video"><p>' + cfg.message.replace( /</g, '&lt;' ) + '</p></div>';
						clearInterval( timer );
					}
				} )
				.catch( function () {} );
		}
		send( false );
		var timer = setInterval( function () { send( false ); }, 45000 );
		window.addEventListener( 'pagehide', function () { send( true ); } );
	} );
} )();
