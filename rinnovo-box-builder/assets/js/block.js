( function ( blocks, element, blockEditor, components, i18n, ServerSideRender ) {
	var el = element.createElement;
	var __ = i18n.__;
	blocks.registerBlockType( 'rinnovo/box-builder', {
		apiVersion: 2,
		title: __( 'Box builder', 'rinnovo-box-builder' ),
		description: __( 'The box builder of a product, with its add to cart button.', 'rinnovo-box-builder' ),
		icon: 'screenoptions',
		category: 'widgets',
		keywords: [ 'box', 'builder', 'subscription' ],
		attributes: {
			product: { type: 'number', default: 0 },
			layout: { type: 'string', default: '' }
		},
		edit: function ( props ) {
			var a = props.attributes;
			return el(
				'div',
				blockEditor.useBlockProps(),
				el(
					blockEditor.InspectorControls,
					null,
					el(
						components.PanelBody,
						{ title: __( 'Box builder', 'rinnovo-box-builder' ) },
						el( components.TextControl, {
							label: __( 'Product ID (empty = this product)', 'rinnovo-box-builder' ),
							type: 'number',
							value: a.product || '',
							onChange: function ( v ) { props.setAttributes( { product: parseInt( v, 10 ) || 0 } ); }
						} ),
						el( components.SelectControl, {
							label: __( 'Layout', 'rinnovo-box-builder' ),
							value: a.layout,
							options: [
								{ label: __( 'As set on the product', 'rinnovo-box-builder' ), value: '' },
								{ label: __( 'Grid of cards', 'rinnovo-box-builder' ), value: 'grid' },
								{ label: __( 'List', 'rinnovo-box-builder' ), value: 'list' },
								{ label: __( 'Day-wise planner', 'rinnovo-box-builder' ), value: 'planner' },
								{ label: __( 'Step-by-step wizard', 'rinnovo-box-builder' ), value: 'wizard' },
								{ label: __( 'Preference form', 'rinnovo-box-builder' ), value: 'form' }
							],
							onChange: function ( v ) { props.setAttributes( { layout: v } ); }
						} )
					)
				),
				el( ServerSideRender, { block: 'rinnovo/box-builder', attributes: a } )
			);
		},
		save: function () { return null; }
	} );
}( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n, window.wp.serverSideRender ) );
