/* Manohara Menu Design Pro – "Menu links" block in the editor (rendered on the server). */
( function ( wp ) {
	'use strict';
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var C = wp.components;
	var ServerSideRender = wp.serverSideRender;
	var menus = ( window.mnhpMenus || [] ).slice();
	menus.unshift( { value: 0, label: __( 'First menu', 'manohara-menu-design-pro' ) } );

	wp.blocks.registerBlockType( 'manohara/menu-links', {
		apiVersion: 3,
		title: __( 'Menu links', 'manohara-menu-design-pro' ),
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						C.PanelBody,
						{ title: __( 'Menu', 'manohara-menu-design-pro' ) },
						el( C.SelectControl, {
							label: __( 'Menu to show', 'manohara-menu-design-pro' ),
							help: __( 'Top-level items become headings, their sub-items the links. Edit them in Appearance › Menus.', 'manohara-menu-design-pro' ),
							value: a.menu,
							options: menus,
							onChange: function ( v ) {
								set( { menu: parseInt( v, 10 ) || 0 } );
							},
						} ),
						el( C.SelectControl, {
							label: __( 'Look', 'manohara-menu-design-pro' ),
							value: a.style,
							options: [
								{ value: 'columns', label: __( 'Columns', 'manohara-menu-design-pro' ) },
								{ value: 'cards', label: __( 'Cards', 'manohara-menu-design-pro' ) },
								{ value: 'list', label: __( 'Small headings', 'manohara-menu-design-pro' ) },
							],
							onChange: function ( v ) {
								set( { style: v } );
							},
						} ),
						el( C.RangeControl, {
							label: __( 'Columns', 'manohara-menu-design-pro' ),
							value: a.columns,
							min: 1,
							max: 6,
							onChange: function ( v ) {
								set( { columns: v || 4 } );
							},
						} ),
						el( C.ToggleControl, {
							label: __( 'Show descriptions', 'manohara-menu-design-pro' ),
							checked: !! a.showDesc,
							onChange: function ( v ) {
								set( { showDesc: v } );
							},
						} )
					)
				),
				el( ServerSideRender, { block: 'manohara/menu-links', attributes: a } )
			);
		},
		save: function () {
			return null;
		},
	} );
}( window.wp ) );
