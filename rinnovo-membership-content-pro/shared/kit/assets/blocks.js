/* Rinnovo kit: one server-rendered block per Rinnovo shortcode. */
( function ( wp ) {
	if ( ! wp || ! wp.blocks || ! window.rinnovoKitBlocks ) {
		return;
	}
	var el = wp.element.createElement;
	var be = wp.blockEditor;
	var c = wp.components;
	var SSR = wp.serverSideRender;
	window.rinnovoKitBlocks.forEach( function ( b ) {
		if ( wp.blocks.getBlockType( b.name ) ) {
			return;
		}
		var attrs = {};
		Object.keys( b.attrs || {} ).forEach( function ( k ) {
			attrs[ k ] = { type: 'string', default: '' };
		} );
		wp.blocks.registerBlockType( b.name, {
			apiVersion: 3,
			title: b.title,
			description: b.description,
			category: 'rinnovo',
			icon: 'update',
			attributes: attrs,
			edit: function ( props ) {
				var fields = Object.keys( b.attrs || {} ).map( function ( k ) {
					return el( c.TextControl, {
						key: k,
						label: b.attrs[ k ],
						value: props.attributes[ k ] || '',
						onChange: function ( v ) {
							var o = {};
							o[ k ] = v;
							props.setAttributes( o );
						},
					} );
				} );
				return el(
					'div',
					be.useBlockProps(),
					fields.length ? el( be.InspectorControls, null, el( c.PanelBody, { title: b.title }, fields ) ) : null,
					el( SSR, { block: b.name, attributes: props.attributes } )
				);
			},
			save: function () {
				return null;
			},
		} );
	} );
} )( window.wp );
