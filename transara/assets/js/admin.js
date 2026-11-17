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
