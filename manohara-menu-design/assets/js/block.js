/* Manohara Menu Design – "Manohara menu" block. No build step. */
( function ( wp ) {
	'use strict';
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var cfg = window.manoharaBlock || { designs: [], admin: '' };
	var be = wp.blockEditor;
	var c = wp.components;
	var SSR = wp.serverSideRender;

	wp.blocks.registerBlockType( 'manohara/menu', {
		apiVersion: 3,
		title: __( 'Manohara menu', 'manohara-menu-design' ),
		description: __( 'A menu design from Appearance > Manohara Menu.', 'manohara-menu-design' ),
		category: 'theme',
		icon: 'menu-alt',
		keywords: [ 'menu', 'navigation', 'header' ],
		supports: { html: false },
		attributes: {
			design: { type: 'number', default: 0 },
		},
		edit: function ( props ) {
			var blockProps = be.useBlockProps();
			var options = [ { value: 0, label: __( 'Choose a design', 'manohara-menu-design' ) } ].concat(
				cfg.designs.map( function ( d ) {
					return { value: d.value, label: d.label || '#' + d.value };
				} )
			);
			var select = el( c.SelectControl, {
				label: __( 'Menu design', 'manohara-menu-design' ),
				value: props.attributes.design,
				options: options,
				onChange: function ( v ) {
					props.setAttributes( { design: parseInt( v, 10 ) || 0 } );
				},
				__nextHasNoMarginBottom: true,
			} );
			var link = el( c.ExternalLink, { href: cfg.admin }, __( 'Make or edit designs', 'manohara-menu-design' ) );
			var body;
			if ( ! props.attributes.design ) {
				body = el( c.Placeholder, {
					icon: 'menu-alt',
					label: __( 'Manohara menu', 'manohara-menu-design' ),
					instructions: cfg.designs.length ? __( 'Choose which menu design to show here.', 'manohara-menu-design' ) : __( 'Make a menu design first.', 'manohara-menu-design' ),
				}, cfg.designs.length ? select : null, el( 'p', null, link ) );
			} else {
				body = el( 'div', { className: 'mnh-block-preview' }, el( SSR, { block: 'manohara/menu', attributes: props.attributes } ) );
			}
			return el(
				'div',
				blockProps,
				el( be.InspectorControls, null, el( c.PanelBody, { title: __( 'Menu design', 'manohara-menu-design' ) }, select, el( 'p', null, link ), el( 'p', { className: 'description' }, __( 'Devices set to "theme design" show nothing here. The preview shows the device of the editor\'s width.', 'manohara-menu-design' ) ) ) ),
				body
			);
		},
		save: function () {
			return null;
		},
	} );
}( window.wp ) );
