/* Rinnovo Bundles: save the plan table of the variations. */
jQuery( function ( $ ) {
	$( document ).on( 'click', '.rinnovobv-matrix-save', function () {
		var btn = $( this ), rows = {};
		$( '.rinnovobv-matrix tr[data-id]' ).each( function () {
			var r = {};
			$( this ).find( '[data-k]' ).each( function () {
				r[ $( this ).data( 'k' ) ] = this.type === 'checkbox' ? ( this.checked ? 1 : '' ) : $( this ).val();
			} );
			rows[ $( this ).data( 'id' ) ] = r;
		} );
		btn.prop( 'disabled', true );
		$.post( window.ajaxurl, { action: 'rinnovobv_matrix', nonce: btn.data( 'nonce' ), rows: rows } ).done( function ( r ) {
			$( '.rinnovobv-matrix-done' ).text( r && r.data ? r.data.message : '' );
		} ).always( function () { btn.prop( 'disabled', false ); } );
	} );
} );
