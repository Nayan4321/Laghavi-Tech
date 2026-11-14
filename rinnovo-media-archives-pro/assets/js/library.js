/* Rinnovo Media Archives: resume where the member stopped and save progress (continue watching). */
( function () {
	var cfg = window.rinnovomaLib;
	if ( ! cfg ) {
		return;
	}
	document.querySelectorAll( 'video[data-media], audio[data-media]' ).forEach( function ( m ) {
		var id = m.getAttribute( 'data-media' );
		var resume = parseInt( m.getAttribute( 'data-resume' ), 10 ) || 0;
		var last = 0;
		function save() {
			var b = new FormData();
			b.append( 'action', 'rinnovoma_progress' );
			b.append( 'nonce', cfg.nonce );
			b.append( 'media', id );
			b.append( 't', Math.floor( m.currentTime || 0 ) );
			b.append( 'd', Math.floor( m.duration || 0 ) );
			fetch( cfg.ajax, { method: 'POST', body: b, credentials: 'same-origin' } ).catch( function () {} );
		}
		m.addEventListener( 'loadedmetadata', function () {
			if ( resume > 5 && resume < m.duration - 10 ) {
				m.currentTime = resume;
			}
		} );
		m.addEventListener( 'timeupdate', function () {
			if ( Math.abs( m.currentTime - last ) >= 15 ) {
				last = m.currentTime;
				save();
			}
		} );
		m.addEventListener( 'pause', save );
		m.addEventListener( 'ended', save );
	} );
}() );
