/* Nyasah Social Feed – block. */
( function ( wp ) {
	'use strict';
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var cfg = window.nyasahBlock || { feeds: [], add: '' };
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var Placeholder = wp.components.Placeholder;
	var ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType( 'nyasah/feed', {
		edit: function ( props ) {
			var options = [ { value: '', label: __( '— Choose a feed —', 'nyasah-social-feed' ) } ].concat( cfg.feeds );
			var picker = el( SelectControl, {
				label: __( 'Feed', 'nyasah-social-feed' ),
				value: props.attributes.feed,
				options: options,
				onChange: function ( v ) { props.setAttributes( { feed: v } ); },
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true
			} );
			var link = el( 'a', { href: cfg.add, target: '_blank', rel: 'noopener' }, __( 'Create a feed', 'nyasah-social-feed' ) );
			var body = props.attributes.feed
				? el( ServerSideRender, { block: 'nyasah/feed', attributes: props.attributes } )
				: el( Placeholder, { icon: 'share', label: __( 'Social Feed', 'nyasah-social-feed' ), instructions: cfg.feeds.length ? __( 'Choose which feed to show.', 'nyasah-social-feed' ) : __( 'You have no feeds yet.', 'nyasah-social-feed' ) }, cfg.feeds.length ? picker : link );
			return el(
				'div',
				useBlockProps(),
				el( InspectorControls, null, el( PanelBody, { title: __( 'Feed', 'nyasah-social-feed' ) }, picker, el( 'p', null, link ) ) ),
				body
			);
		},
		save: function () {
			return null;
		}
	} );
}( window.wp ) );
