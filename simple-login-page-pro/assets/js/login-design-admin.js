/* Simple Login Page Pro – login page design settings. */
( function ( $ ) {
	// Colour pickers.
	$( '.slpp-color' ).wpColorPicker();

	// Image pickers.
	$( '.slpp-image-field' ).each( function () {
		var field = $( this );
		var input = field.find( '.slpp-image-field__url' );
		var preview = field.find( '.slpp-image-field__preview' );
		var frame;

		function show( url ) {
			input.val( url );
			preview.find( 'img' ).attr( 'src', url );
			preview.prop( 'hidden', ! url );
		}

		field.on( 'click', '.slpp-image-field__choose', function ( e ) {
			e.preventDefault();
			if ( ! frame ) {
				frame = wp.media( {
					title: slppDesign.choose,
					button: { text: slppDesign.use },
					library: { type: 'image' },
					multiple: false,
				} );
				frame.on( 'select', function () {
					show( frame.state().get( 'selection' ).first().toJSON().url );
				} );
			}
			frame.open();
		} );
		field.on( 'click', '.slpp-image-field__clear', function ( e ) {
			e.preventDefault();
			show( '' );
		} );
		input.on( 'change', function () {
			show( input.val() );
		} );
	} );

	// Template cards.
	$( '.slpp-template input' ).on( 'change', function () {
		$( '.slpp-template' ).removeClass( 'is-selected' );
		$( this ).closest( '.slpp-template' ).addClass( 'is-selected' );
		var colors = slppDesign.templates[ this.value ];
		// Show the template's own colours as hints in empty colour fields.
		$( '.slpp-color' ).each( function () {
			var key = $( this ).data( 'key' );
			if ( colors && colors[ key ] ) {
				$( this ).attr( 'placeholder', colors[ key ] );
			}
		} );
	} );

	// Preview screens.
	$( '.slpp-preview-link' ).on( 'click', function ( e ) {
		e.preventDefault();
		var url = $( this ).data( 'url' );
		$( '.slpp-previews iframe' ).attr( 'src', url );
	} );
} )( jQuery );
