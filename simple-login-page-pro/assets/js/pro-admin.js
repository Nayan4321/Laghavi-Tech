/* Simple Login Page Pro – filter the block list on the Hide & restrict screen. */
( function () {
	var search = document.querySelector( '.slpp-block-search' );
	if ( ! search ) {
		return;
	}
	search.addEventListener( 'input', function () {
		var term = search.value.toLowerCase();
		document.querySelectorAll( '.slpp-block-group' ).forEach( function ( group ) {
			var visible = 0;
			group.querySelectorAll( '.slpp-item' ).forEach( function ( item ) {
				var match = item.textContent.toLowerCase().indexOf( term ) !== -1;
				item.style.display = match ? '' : 'none';
				visible += match ? 1 : 0;
			} );
			group.style.display = visible ? '' : 'none';
		} );
	} );
} )();
