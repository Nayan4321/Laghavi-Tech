/* Rinnovo Boxes: show only the options of the chosen product path. */
( function () {
	var tab = document.getElementById( 'rinnovobox_data' );
	if ( ! tab ) {
		return;
	}
	function update() {
		var checked = tab.querySelector( 'input[name="_rinnovobox_path"]:checked' );
		var path = checked ? checked.value : 'box';
		tab.querySelectorAll( '[data-paths]' ).forEach( function ( group ) {
			group.style.display = group.getAttribute( 'data-paths' ).split( ' ' ).indexOf( path ) !== -1 ? '' : 'none';
		} );
		// The path switches its own feature on.
		var map = { box: '_rinnovobox_enabled', sns: '_rinnovobox_sns' };
		Object.keys( map ).forEach( function ( p ) {
			var box = tab.querySelector( '#' + map[ p ] );
			if ( box && p === path ) {
				box.checked = true;
			}
		} );
	}
	tab.addEventListener( 'change', function ( e ) {
		if ( e.target.name === '_rinnovobox_path' ) {
			update();
		}
	} );
	update();
}() );
