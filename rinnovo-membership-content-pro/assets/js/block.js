/* Rinnovo Membership Content Pro: the "Members only" block (inner blocks are shown only to members). */
( function ( blocks, element, blockEditor, components ) {
	var el = element.createElement;
	var data = window.rinnovomemBlock || { tiers: [], title: 'Members only' };

	function tierLabel( value ) {
		for ( var i = 0; i < data.tiers.length; i++ ) {
			if ( data.tiers[ i ].value === value ) {
				return data.tiers[ i ].label;
			}
		}
		return value;
	}

	blocks.registerBlockType( 'rinnovomem/members-only', {
		apiVersion: 2,
		title: data.title,
		icon: 'lock',
		category: 'design',
		description: data.note,
		supports: { html: false },
		attributes: {
			tier: { type: 'string', default: '' },
			drip: { type: 'number', default: 0 },
			date: { type: 'string', default: '' }
		},
		edit: function ( props ) {
			var a = props.attributes;
			var blockProps = blockEditor.useBlockProps( { className: 'rinnovomem-block-editor' } );
			return el(
				'div',
				blockProps,
				el(
					blockEditor.InspectorControls,
					null,
					el(
						components.PanelBody,
						{ title: data.title },
						el( components.SelectControl, {
							label: data.tier,
							value: a.tier,
							options: data.tiers,
							onChange: function ( v ) { props.setAttributes( { tier: v } ); }
						} ),
						el( components.TextControl, {
							label: data.drip,
							type: 'number',
							min: 0,
							value: a.drip || '',
							onChange: function ( v ) { props.setAttributes( { drip: parseInt( v, 10 ) || 0 } ); }
						} ),
						el( components.TextControl, {
							label: data.date,
							value: a.date,
							placeholder: '2026-12-01',
							onChange: function ( v ) { props.setAttributes( { date: v } ); }
						} )
					)
				),
				el( 'div', { className: 'rinnovomem-block-editor__label' }, '🔒 ' + data.title + ': ' + tierLabel( a.tier ) + ( a.drip ? ' · +' + a.drip + 'd' : '' ) ),
				el( blockEditor.InnerBlocks, null )
			);
		},
		save: function () {
			return el( blockEditor.InnerBlocks.Content, null );
		}
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components );
