/* Rinnovo Membership Content Pro: resume videos where the member stopped, and mark them watched. */
( function () {
	var cfg = window.rinnovomemProgress;
	if ( ! cfg ) {
		return;
	}
	function save( key, t, d, done ) {
		var body = new FormData();
		body.append( 'action', 'rinnovomem_progress' );
		body.append( 'nonce', cfg.nonce );
		body.append( 'key', key );
		body.append( 't', t || 0 );
		body.append( 'd', d || 0 );
		if ( done ) {
			body.append( 'done', '1' );
		}
		return fetch( cfg.ajax, { method: 'POST', body: body, credentials: 'same-origin' } ).then( function ( r ) { return r.json(); } ).catch( function () {} );
	}
	document.querySelectorAll( '[data-rinnovomem-video]' ).forEach( function ( box ) {
		var key = box.getAttribute( 'data-rinnovomem-video' );
		var video = box.querySelector( 'video' );
		var badge = document.createElement( 'p' );
		badge.className = 'rinnovomem-video__state';
		box.parentNode.insertBefore( badge, box.nextSibling );
		function mark( done ) {
			badge.innerHTML = '';
			if ( done ) {
				badge.textContent = '✓ ' + cfg.done;
				return;
			}
			if ( ! video ) {
				var b = document.createElement( 'button' );
				b.type = 'button';
				b.className = 'button';
				b.textContent = cfg.mark;
				b.addEventListener( 'click', function () { save( key, 0, 0, true ).then( function () { mark( true ); } ); } );
				badge.appendChild( b );
			}
		}
		mark( box.getAttribute( 'data-done' ) === '1' );
		if ( ! video ) {
			return;
		}
		var resume = parseFloat( box.getAttribute( 'data-resume' ) ) || 0;
		video.addEventListener( 'loadedmetadata', function () {
			if ( resume > 5 && resume < video.duration - 10 ) {
				video.currentTime = resume;
			}
		} );
		var last = 0;
		video.addEventListener( 'timeupdate', function () {
			if ( Math.abs( video.currentTime - last ) >= 10 ) {
				last = video.currentTime;
				save( key, video.currentTime, video.duration ).then( function ( r ) {
					if ( r && r.success && r.data.done ) {
						mark( true );
					}
				} );
			}
		} );
		video.addEventListener( 'pause', function () { save( key, video.currentTime, video.duration ); } );
		video.addEventListener( 'ended', function () { save( key, video.duration, video.duration, true ).then( function () { mark( true ); } ); } );
	} );
}() );
