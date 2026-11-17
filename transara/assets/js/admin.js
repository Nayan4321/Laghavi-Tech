/* Transara admin: highlight the section in view. */
( function () {
	var links = [].slice.call( document.querySelectorAll( '.transara-side a' ) );
	if ( ! links.length ) {
		return;
	}
	var secs = links.map( function ( a ) {
		return document.querySelector( a.getAttribute( 'href' ) );
	} );
	function mark() {
		var y = window.scrollY + 120;
		var cur = 0;
		secs.forEach( function ( s, i ) {
			if ( s && s.getBoundingClientRect().top + window.scrollY <= y ) {
				cur = i;
			}
		} );
		links.forEach( function ( a, i ) {
			a.classList.toggle( 'is-on', i === cur );
		} );
	}
	window.addEventListener( 'scroll', mark, { passive: true } );
	mark();
} )();

/* Confirm links, and show only the fields of the chosen monitor type. */
( function () {
	document.addEventListener( 'click', function ( e ) {
		var a = e.target.closest ? e.target.closest( '[data-transara-confirm]' ) : null;
		if ( a && ! window.confirm( a.getAttribute( 'data-transara-confirm' ) ) ) {
			e.preventDefault();
		}
	} );
	var type = document.getElementById( 'tm-type' );
	if ( ! type ) {
		return;
	}
	function sync() {
		var t = type.value;
		var show = {
			'tm-keyword': 'keyword' === t,
			'tm-http': 'http' === t || 'keyword' === t,
			'tm-ssl': 'ssl' === t,
			'tm-not-heartbeat': 'heartbeat' !== t,
			'tm-heartbeat': 'heartbeat' === t
		};
		Object.keys( show ).forEach( function ( cls ) {
			[].forEach.call( document.querySelectorAll( '.' + cls ), function ( el ) {
				el.style.display = show[ cls ] ? '' : 'none';
			} );
		} );
	}
	type.addEventListener( 'change', sync );
	sync();
} )();
