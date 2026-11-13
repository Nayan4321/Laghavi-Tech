/* Show the WordPress-only license fields (Requires WordPress, the license file) for plugins and themes. */
jQuery( function ( $ ) {
	var $type = $( '#_rinnovo_license_type' );
	function toggle() {
		$( '.rinnovo-license-wp-only' ).toggle( 0 === String( $type.val() ).indexOf( 'wp_' ) );
	}
	$type.on( 'change', toggle );
	toggle();
} );
