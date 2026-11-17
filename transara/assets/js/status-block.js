/* Transara: "Status page" block (shown as on the website). */
( function ( blocks, element, ServerSideRender ) {
	blocks.registerBlockType( 'transara/status', {
		edit: function () {
			return element.createElement( ServerSideRender, { block: 'transara/status' } );
		},
		save: function () {
			return null;
		}
	} );
} )( window.wp.blocks, window.wp.element, window.wp.serverSideRender );
