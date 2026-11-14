/* Rinnovo Membership Content Pro: "Members only" panel on every block. */
( function ( hooks, compose, element, blockEditor, components ) {
	var el = element.createElement;
	var cfg = window.rinnovomemVis || { modes: [], tiers: [] };
	hooks.addFilter( 'blocks.registerBlockType', 'rinnovomem/vis-attr', function ( settings ) {
		settings.attributes = Object.assign( {}, settings.attributes, { rinnovomemVis: { type: 'object', default: {} } } );
		return settings;
	} );
	var withPanel = compose.createHigherOrderComponent( function ( BlockEdit ) {
		return function ( props ) {
			var v = props.attributes.rinnovomemVis || {};
			function set( key, value ) {
				var next = Object.assign( {}, v );
				next[ key ] = value;
				props.setAttributes( { rinnovomemVis: next } );
			}
			var fields = [
				el( components.SelectControl, { key: 'm', label: cfg.title, value: v.mode || '', options: cfg.modes, onChange: function ( x ) { set( 'mode', x ); } } )
			];
			if ( v.mode === 'tier' || v.mode === 'exact' ) {
				fields.push( el( components.SelectControl, { key: 't', label: cfg.tier, value: v.value || '', options: [ { value: '', label: '—' } ].concat( cfg.tiers ), onChange: function ( x ) { set( 'value', x ); } } ) );
			} else if ( v.mode === 'group' || v.mode === 'course' || v.mode === 'product' ) {
				fields.push( el( components.TextControl, { key: 'v', label: cfg.value, value: v.value || '', onChange: function ( x ) { set( 'value', x ); } } ) );
			}
			if ( v.mode ) {
				fields.push( el( components.TextareaControl, { key: 'x', label: cfg.tease, value: v.teaser || '', onChange: function ( x ) { set( 'teaser', x ); } } ) );
				fields.push( el( 'p', { key: 'n', className: 'components-base-control__help' }, cfg.note ) );
			}
			return el(
				element.Fragment,
				null,
				el( BlockEdit, props ),
				el( blockEditor.InspectorControls, null, el( components.PanelBody, { title: cfg.title, initialOpen: !! v.mode }, fields ) )
			);
		};
	}, 'withRinnovomemVis' );
	hooks.addFilter( 'editor.BlockEdit', 'rinnovomem/vis-panel', withPanel );
}( window.wp.hooks, window.wp.compose, window.wp.element, window.wp.blockEditor, window.wp.components ) );
