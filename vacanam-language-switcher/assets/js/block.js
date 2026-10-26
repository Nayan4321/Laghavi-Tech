/* Vacanam Language Switcher – block (rendered by PHP, settings in the sidebar). */
( function ( wp, cfg ) {
	'use strict';
	if ( ! wp || ! wp.blocks || ! cfg ) {
		return;
	}
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var c = wp.components;
	var be = wp.blockEditor;
	var SSR = wp.serverSideRender;
	var d = cfg.defaults;

	function val( props, key ) {
		var v = props.attributes[ key ];
		return ( undefined === v || null === v || '' === v ) ? d[ key ] : v;
	}
	function options( map ) {
		return Object.keys( map ).map( function ( k ) {
			return { value: k, label: map[ k ] };
		} );
	}
	function color( props, key, label ) {
		return el( 'div', { style: { marginBottom: '16px' } },
			el( c.BaseControl, { label: label, id: 'vacanam-' + key },
				el( be.ColorPalette || c.ColorPalette, {
					value: props.attributes[ key ] || undefined,
					onChange: function ( v ) {
						var o = {};
						o[ key ] = v || '';
						props.setAttributes( o );
					}
				} )
			)
		);
	}
	function set( props, key ) {
		return function ( v ) {
			var o = {};
			o[ key ] = v;
			props.setAttributes( o );
		};
	}

	wp.blocks.registerBlockType( 'vacanam/language-switcher', {
		apiVersion: 3,
		title: __( 'Language switcher', 'vacanam-language-switcher' ),
		icon: 'translation',
		category: 'widgets',
		supports: { html: false, align: [ 'left', 'center', 'right' ] },
		edit: function ( props ) {
			var blockProps = be.useBlockProps ? be.useBlockProps() : {};
			return el( 'div', blockProps,
				el( be.InspectorControls, {},
					el( c.PanelBody, { title: __( 'Switcher', 'vacanam-language-switcher' ) },
						el( c.SelectControl, { label: __( 'Style', 'vacanam-language-switcher' ), value: val( props, 'style' ), options: options( cfg.styles ), onChange: set( props, 'style' ) } ),
						el( c.SelectControl, {
							label: __( 'Show', 'vacanam-language-switcher' ),
							value: val( props, 'names' ),
							options: [
								{ value: 'name', label: __( 'Language names', 'vacanam-language-switcher' ) },
								{ value: 'code', label: __( 'Codes (EN, AR)', 'vacanam-language-switcher' ) },
								{ value: 'none', label: __( 'No text', 'vacanam-language-switcher' ) }
							],
							onChange: set( props, 'names' )
						} ),
						el( c.ToggleControl, { label: __( 'Flags', 'vacanam-language-switcher' ), checked: !! Number( val( props, 'flags' ) ), onChange: function ( v ) { props.setAttributes( { flags: v ? 1 : 0 } ); } } ),
						el( c.SelectControl, {
							label: __( 'Flag shape', 'vacanam-language-switcher' ),
							value: val( props, 'flag_shape' ),
							options: [
								{ value: 'rect', label: __( 'Rectangle', 'vacanam-language-switcher' ) },
								{ value: 'round', label: __( 'Round', 'vacanam-language-switcher' ) },
								{ value: 'square', label: __( 'Square', 'vacanam-language-switcher' ) }
							],
							onChange: set( props, 'flag_shape' )
						} ),
						el( c.RangeControl, { label: __( 'Flag size', 'vacanam-language-switcher' ), min: 12, max: 48, value: Number( val( props, 'flag_size' ) ), onChange: set( props, 'flag_size' ) } ),
						el( c.ToggleControl, { label: __( 'Hide the current language', 'vacanam-language-switcher' ), checked: !! Number( val( props, 'hide_current' ) ), onChange: function ( v ) { props.setAttributes( { hide_current: v ? 1 : 0 } ); } } ),
						el( c.SelectControl, {
							label: __( 'Dropdown opens on', 'vacanam-language-switcher' ),
							value: val( props, 'open_on' ),
							options: [
								{ value: 'click', label: __( 'Click', 'vacanam-language-switcher' ) },
								{ value: 'hover', label: __( 'Hover', 'vacanam-language-switcher' ) }
							],
							onChange: set( props, 'open_on' )
						} ),
						el( c.TextControl, { label: __( 'Accessible label', 'vacanam-language-switcher' ), value: props.attributes.label || '', onChange: set( props, 'label' ) } )
					),
					el( c.PanelBody, { title: __( 'Colours and size', 'vacanam-language-switcher' ), initialOpen: false },
						color( props, 'text', __( 'Text', 'vacanam-language-switcher' ) ),
						color( props, 'background', __( 'Background', 'vacanam-language-switcher' ) ),
						color( props, 'hover_text', __( 'Text on hover', 'vacanam-language-switcher' ) ),
						color( props, 'hover_bg', __( 'Background on hover', 'vacanam-language-switcher' ) ),
						color( props, 'active_text', __( 'Current language text', 'vacanam-language-switcher' ) ),
						color( props, 'active_bg', __( 'Current language background', 'vacanam-language-switcher' ) ),
						color( props, 'border', __( 'Border', 'vacanam-language-switcher' ) ),
						el( c.RangeControl, { label: __( 'Corner radius', 'vacanam-language-switcher' ), min: 0, max: 30, value: Number( val( props, 'radius' ) ), onChange: set( props, 'radius' ) } ),
						el( c.RangeControl, { label: __( 'Text size (0 = theme)', 'vacanam-language-switcher' ), min: 0, max: 40, value: Number( val( props, 'font_size' ) ), onChange: set( props, 'font_size' ) } )
					)
				),
				el( SSR, { block: 'vacanam/language-switcher', attributes: props.attributes } )
			);
		},
		save: function () {
			return null;
		}
	} );
}( window.wp, window.vacanamBlock ) );
