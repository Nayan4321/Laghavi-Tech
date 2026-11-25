/* Laghavi Performance Insight for WooCommerce — counts one page view on this site (no cookies, nothing sent elsewhere). */
( function () {
	var c = window.lgpiTrack;
	if ( ! c || ! c.url ) { return; }
	if ( c.dnt && ( navigator.doNotTrack === '1' || window.doNotTrack === '1' ) ) { return; }
	if ( navigator.webdriver ) { return; }
	function send( url, body ) {
		try {
			if ( navigator.sendBeacon && navigator.sendBeacon( url, new Blob( [ body ], { type: 'application/json' } ) ) ) { return; }
		} catch ( e ) {}
		try {
			fetch( url, { method: 'POST', body: body, headers: { 'Content-Type': 'application/json' }, keepalive: true, credentials: 'omit' } );
		} catch ( e ) {}
	}
	var tz = '';
	try { tz = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch ( e ) {}
	// A random id for this one page view, so the time spent on it can be added when the visitor leaves.
	var pv = Math.random().toString( 36 ).slice( 2, 12 );
	send( c.url, JSON.stringify( {
		t: c.t, i: c.i, r: document.referrer || '', u: location.search || '', pv: pv,
		w: window.screen ? screen.width : 0, h: window.screen ? screen.height : 0,
		l: navigator.language || '', z: tz, co: c.co ? 1 : 0, s: c.s || '', nf: c.nf || ''
	} ) );
	if ( ! c.leave ) { return; }
	// Time on page: only the time the tab was visible.
	var shown = document.visibilityState === 'visible' ? Date.now() : 0;
	var total = 0;
	function leave() {
		if ( shown ) { total += Date.now() - shown; shown = 0; }
		var s = Math.round( total / 1000 );
		if ( s > 0 ) { send( c.leave, JSON.stringify( { pv: pv, d: s } ) ); }
	}
	document.addEventListener( 'visibilitychange', function () {
		if ( document.visibilityState === 'hidden' ) { leave(); } else if ( ! shown ) { shown = Date.now(); }
	} );
	window.addEventListener( 'pagehide', leave );
}() );
