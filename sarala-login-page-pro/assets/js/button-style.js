/* Sarala Login Page Pro – live preview of the button style. */
( function ( $ ) {
	var form = document.querySelector( '.slpp-button-style__form' );
	var box = document.getElementById( 'slpp-preview-buttons' );
	if ( ! form || ! box ) {
		return;
	}
	function val( name ) {
		var el = form.querySelector( '[name="slpp_button_style[' + name + ']"]:checked' ) || form.querySelector( 'select[name="slpp_button_style[' + name + ']"]' );
		return el ? el.value : '';
	}
	function update() {
		var classes = [ 'saralopa-buttons', 'saralopa-buttons--full' ];
		var style = val( 'style' ), shape = val( 'shape' ), colors = val( 'colors' );
		if ( style && 'default' !== style ) { classes.push( 'slpp-style-' + style ); }
		if ( shape && 'theme' !== shape ) { classes.push( 'slpp-shape-' + shape ); }
		if ( 'custom' === colors ) { classes.push( 'slpp-colors-custom' ); }
		box.className = classes.join( ' ' );
		form.querySelectorAll( '.slpp-color' ).forEach( function ( input ) {
			box.style.setProperty( '--slpp-' + input.dataset.var, input.value );
		} );
		var picker = form.querySelector( '.slpp-colors' );
		if ( picker ) { picker.style.display = 'custom' === colors ? '' : 'none'; }
	}
	form.addEventListener( 'change', update );
	if ( $ && $.fn.wpColorPicker ) {
		$( '.slpp-color' ).wpColorPicker( { change: function () { setTimeout( update, 0 ); }, clear: update } );
	}
	update();
}( window.jQuery ) );
