/* Aarye Marketing Addon – review request */
( function () {
	var box = document.getElementById( 'aarye-review' );
	if ( ! box ) {
		return;
	}
	box.addEventListener( 'click', function ( e ) {
		var el = e.target.closest( '[data-answer]' );
		if ( ! el ) {
			return;
		}
		var body = new FormData();
		body.append( 'action', 'aarye_review' );
		body.append( 'answer', el.getAttribute( 'data-answer' ) );
		body.append( '_ajax_nonce', box.getAttribute( 'data-nonce' ) );
		fetch( box.getAttribute( 'data-ajax' ), { method: 'POST', credentials: 'same-origin', body: body } );
		box.classList.add( 'aarye-review--gone' );
	} );
} )();
