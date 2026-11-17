/* Transara: load the YouTube player when the visitor clicks the preview. */
( function () {
	function play( box ) {
		var f = document.createElement( 'iframe' );
		f.src = box.getAttribute( 'data-src' );
		f.title = box.getAttribute( 'data-title' ) || 'YouTube video';
		f.setAttribute( 'allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share' );
		f.setAttribute( 'allowfullscreen', '' );
		box.innerHTML = '';
		box.appendChild( f );
	}
	document.addEventListener( 'click', function ( e ) {
		var box = e.target.closest ? e.target.closest( '.transara-yt' ) : null;
		if ( box && ! box.querySelector( 'iframe' ) ) {
			e.preventDefault();
			play( box );
		}
	} );
} )();
