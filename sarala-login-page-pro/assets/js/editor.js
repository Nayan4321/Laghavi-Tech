/**
 * Sarala Login Page Pro – block editor.
 *
 * Hides blocks and sidebar panels for restricted users, and adds a
 * "Who can see this block" control to every block.
 */
( function ( wp, config ) {
	if ( ! wp || ! config ) {
		return;
	}

	var el = wp.element.createElement;
	var addFilter = wp.hooks.addFilter;
	var hiddenBlocks = config.hiddenBlocks || [];
	var hiddenPanels = config.hiddenPanels || [];

	// Hidden blocks leave the inserter; blocks already in a post keep working.
	addFilter( 'blocks.registerBlockType', 'slpp/hide-blocks', function ( settings, name ) {
		if ( hiddenBlocks.indexOf( name ) === -1 ) {
			return settings;
		}
		return Object.assign( {}, settings, {
			supports: Object.assign( {}, settings.supports, { inserter: false } ),
		} );
	} );

	wp.domReady( function () {
		// Blocks registered before this script loaded.
		hiddenBlocks.forEach( function ( name ) {
			var type = wp.blocks.getBlockType( name );
			if ( ! type || ( type.supports && type.supports.inserter === false ) ) {
				return;
			}
			try {
				wp.blocks.unregisterBlockType( name );
				wp.blocks.registerBlockType( name, Object.assign( {}, type, {
					supports: Object.assign( {}, type.supports, { inserter: false } ),
				} ) );
			} catch ( e ) {}
		} );

		hiddenPanels.forEach( function ( panel ) {
			var editor = wp.data.dispatch( 'core/editor' );
			if ( editor && editor.removeEditorPanel ) {
				editor.removeEditorPanel( panel );
			} else if ( wp.data.dispatch( 'core/edit-post' ) ) {
				wp.data.dispatch( 'core/edit-post' ).removeEditorPanel( panel );
			}
		} );
	} );

	if ( ! config.visibility ) {
		return;
	}

	var i18n = config.i18n;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var CheckboxControl = wp.components.CheckboxControl;

	addFilter( 'blocks.registerBlockType', 'slpp/visibility-attribute', function ( settings ) {
		settings.attributes = Object.assign( {}, settings.attributes, {
			slppVisibility: { type: 'object', default: {} },
		} );
		return settings;
	} );

	var withVisibility = wp.compose.createHigherOrderComponent( function ( BlockEdit ) {
		return function ( props ) {
			if ( ! props.isSelected ) {
				return el( BlockEdit, props );
			}
			var rules = props.attributes.slppVisibility || {};
			var mode = rules.mode || '';
			var roles = rules.roles || [];
			var save = function ( next ) {
				props.setAttributes( { slppVisibility: next.mode ? next : {} } );
			};

			return el(
				wp.element.Fragment,
				null,
				el( BlockEdit, props ),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: i18n.panel, initialOpen: !! mode },
						el( SelectControl, {
							label: i18n.panel,
							hideLabelFromVision: true,
							value: mode,
							options: [
								{ value: '', label: i18n.everyone },
								{ value: 'show', label: i18n.show },
								{ value: 'hide', label: i18n.hide },
							],
							onChange: function ( value ) {
								save( { mode: value, roles: roles } );
							},
						} ),
						mode
							? Object.keys( config.roles ).map( function ( slug ) {
									return el( CheckboxControl, {
										key: slug,
										label: config.roles[ slug ],
										checked: roles.indexOf( slug ) !== -1,
										onChange: function ( checked ) {
											save( {
												mode: mode,
												roles: checked
													? roles.concat( [ slug ] )
													: roles.filter( function ( r ) {
															return r !== slug;
													  } ),
											} );
										},
									} );
							  } )
							: null,
						el( 'p', { className: 'components-base-control__help' }, i18n.help )
					)
				)
			);
		};
	}, 'slppWithVisibility' );

	addFilter( 'editor.BlockEdit', 'slpp/visibility-control', withVisibility );
} )( window.wp, window.slppEditor );
