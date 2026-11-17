/* Manohara Menu Design Pro – menu item settings: icon picker, media, mega menu options and the grid builder. */
( function ( $ ) {
	'use strict';
	var D = window.mnhpItems || {};
	var T = D.i18n || {};

	function svg( name ) {
		return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' + ( D.svgs[ name ] || '' ) + '</svg>';
	}
	function esc( s ) {
		return String( null == s ? '' : s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	/* Show the parts that apply. */
	function refresh( box ) {
		var $b = $( box );
		var on = $b.find( '.mnhp-mega-on input' ).is( ':checked' );
		$b.find( '.mnhp-mega-fields' ).toggle( on );
		var src = $b.find( '.mnhp-source' ).val();
		$b.find( '.mnhp-src' ).hide();
		$b.find( '.mnhp-src-' + src ).show();
		$b.find( '.mnhp-templates' ).toggle( 'menu' === src );
		$b.find( '.mnhp-extra' ).toggle( 'menu' === src );
		if ( 'builder' === src && on ) {
			builder( $b.find( '.mnhp-builder' )[ 0 ] );
		}
	}
	$( document ).on( 'change', '.mnhp-mega-on input, .mnhp-source', function () {
		refresh( $( this ).closest( '.mnhp-mega-box' ) );
	} );

	/* Icon picker. */
	$( document ).on( 'click', '.mnhp-icon-open', function () {
		var $pick = $( this ).closest( '.mnhp-icon-pick' );
		var $grid = $pick.next( '.mnhp-icon-grid' );
		if ( $grid.length ) {
			$grid.remove();
			return;
		}
		var html = '<div class="mnhp-icon-grid">';
		D.icons.forEach( function ( name ) {
			html += '<button type="button" class="mnhp-icon-btn" data-icon="svg:' + esc( name ) + '" title="' + esc( name ) + '">' + svg( name ) + '</button>';
		} );
		html += '<button type="button" class="mnhp-icon-btn mnhp-icon-img" data-icon="img">' + esc( T.choose ) + '</button>';
		html += '<button type="button" class="mnhp-icon-btn mnhp-icon-none" data-icon="">' + esc( T.remove ) + '</button></div>';
		$pick.after( html );
	} );
	$( document ).on( 'click', '.mnhp-icon-btn', function () {
		var $grid = $( this ).closest( '.mnhp-icon-grid' );
		var $input = $grid.prev( '.mnhp-icon-pick' ).find( '.mnhp-icon-value' );
		var icon = $( this ).data( 'icon' );
		if ( 'img' === icon ) {
			media( function ( a ) {
				$input.val( 'img:' + a.id ).trigger( 'change' );
			} );
		} else {
			$input.val( icon ).trigger( 'change' );
		}
		$grid.remove();
	} );

	/* Media. */
	function media( done ) {
		var frame = wp.media( { title: T.choose, button: { text: T.use }, library: { type: 'image' }, multiple: false } );
		frame.on( 'select', function () {
			done( frame.state().get( 'selection' ).first().toJSON() );
		} );
		frame.open();
	}
	function thumb( a ) {
		var url = a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url;
		return '<img src="' + esc( url ) + '" width="48" height="48" alt="" />';
	}
	$( document ).on( 'click', '.mnhp-media-open', function () {
		var $m = $( this ).closest( '.mnhp-media' );
		media( function ( a ) {
			$m.find( 'input[type=hidden]' ).val( a.id ).trigger( 'change' );
			$m.find( '.mnhp-thumb' ).html( thumb( a ) );
		} );
	} );
	$( document ).on( 'click', '.mnhp-media-clear', function () {
		var $m = $( this ).closest( '.mnhp-media' );
		$m.find( 'input[type=hidden]' ).val( '0' ).trigger( 'change' );
		$m.find( '.mnhp-thumb' ).empty();
	} );

	/*
	 * Grid builder: rows of up to 12 column units, columns with blocks. Rows and blocks move by
	 * dragging; the result is kept as JSON in the hidden field.
	 */
	function load( el ) {
		var raw = $( el ).closest( '.mnhp-src-builder' ).find( '.mnhp-builder-data' ).val();
		try {
			var data = JSON.parse( raw || '[]' );
			return Array.isArray( data ) ? data : [];
		} catch ( e ) {
			return [];
		}
	}
	function save( el ) {
		var rows = [];
		$( el ).children( '.mnhp-b-row' ).each( function () {
			var row = { bg: $( this ).find( '> .mnhp-b-row-head .mnhp-b-bg' ).val() || '', cols: [] };
			if ( ! $( this ).find( '> .mnhp-b-row-head .mnhp-b-bg-on' ).is( ':checked' ) ) {
				row.bg = '';
			}
			$( this ).find( '> .mnhp-b-cols > .mnhp-b-col' ).each( function () {
				var col = { span: parseInt( $( this ).find( '> .mnhp-b-col-head .mnhp-b-span' ).val(), 10 ) || 3, blocks: [] };
				$( this ).find( '> .mnhp-b-blocks > .mnhp-b-block' ).each( function () {
					var b = { type: $( this ).data( 'type' ) };
					$( this ).find( '[data-k]' ).each( function () {
						b[ $( this ).data( 'k' ) ] = $( this ).val();
					} );
					col.blocks.push( b );
				} );
				row.cols.push( col );
			} );
			rows.push( row );
		} );
		$( el ).closest( '.mnhp-src-builder' ).find( '.mnhp-builder-data' ).val( rows.length ? JSON.stringify( rows ) : '' ).trigger( 'change' );
	}
	function field( k, type, value ) {
		var v = null == value ? '' : value;
		if ( Array.isArray( type ) ) {
			return '<select data-k="' + esc( k ) + '">' + type.map( function ( o ) {
				return '<option value="' + esc( o ) + '"' + ( o === v ? ' selected' : '' ) + '>' + esc( o ) + '</option>';
			} ).join( '' ) + '</select>';
		}
		switch ( type ) {
			case 'textarea':
			case 'lines':
				return '<textarea rows="3" data-k="' + esc( k ) + '">' + esc( v ) + '</textarea>';
			case 'number':
				return '<input type="number" min="1" max="12" data-k="' + esc( k ) + '" value="' + esc( v || 4 ) + '" />';
			case 'image':
				return '<span class="mnhp-b-image"><input type="hidden" data-k="' + esc( k ) + '" value="' + esc( v || 0 ) + '" /><button type="button" class="button mnhp-b-pick">' + esc( T.choose ) + '</button> <span class="mnhp-b-id">' + ( v && '0' !== String( v ) ? '#' + esc( v ) : '' ) + '</span></span>';
			case 'menu':
			case 'template':
				var list = 'menu' === type ? D.menus : D.saved;
				var html = '<select data-k="' + esc( k ) + '"><option value="0">—</option>';
				Object.keys( list || {} ).forEach( function ( id ) {
					html += '<option value="' + esc( id ) + '"' + ( String( id ) === String( v ) ? ' selected' : '' ) + '>' + esc( list[ id ] ) + '</option>';
				} );
				return html + '</select>';
		}
		return '<input type="' + ( 'url' === type ? 'url' : 'text' ) + '" data-k="' + esc( k ) + '" value="' + esc( v ) + '" />';
	}
	function blockHtml( b ) {
		var def = D.blocks[ b.type ];
		if ( ! def ) {
			return '';
		}
		var html = '<div class="mnhp-b-block" draggable="true" data-type="' + esc( b.type ) + '"><div class="mnhp-b-block-head"><span class="mnhp-b-drag" title="' + esc( T.drag ) + '">⋮⋮</span><strong>' + esc( def.label ) + '</strong><button type="button" class="button-link mnhp-b-del" aria-label="' + esc( T['delete'] ) + '">×</button></div><div class="mnhp-b-fields">';
		Object.keys( def.fields || {} ).forEach( function ( k ) {
			html += '<label><span>' + esc( ( T.fields && T.fields[ k ] ) || k ) + '</span>' + field( k, def.fields[ k ], b[ k ] ) + '</label>';
		} );
		if ( def.help ) {
			html += '<p class="description">' + esc( def.help ) + '</p>';
		}
		return html + '</div></div>';
	}
	function addMenu() {
		var html = '<select class="mnhp-b-add-block"><option value="">+ ' + esc( T.block ) + '</option>';
		Object.keys( D.blocks ).forEach( function ( type ) {
			html += '<option value="' + esc( type ) + '">' + esc( D.blocks[ type ].label ) + '</option>';
		} );
		return html + '</select>';
	}
	function colHtml( c ) {
		var span = '<select class="mnhp-b-span" title="' + esc( T.span ) + '">';
		for ( var i = 1; i <= 12; i++ ) {
			span += '<option value="' + i + '"' + ( i === ( c.span || 3 ) ? ' selected' : '' ) + '>' + i + '/12</option>';
		}
		span += '</select>';
		return '<div class="mnhp-b-col" style="flex-grow:' + ( c.span || 3 ) + '"><div class="mnhp-b-col-head">' + span + '<button type="button" class="button-link mnhp-b-del-col" aria-label="' + esc( T['delete'] ) + '">×</button></div><div class="mnhp-b-blocks">' + ( c.blocks || [] ).map( blockHtml ).join( '' ) + '</div>' + addMenu() + '</div>';
	}
	function rowHtml( r ) {
		return '<div class="mnhp-b-row" draggable="true"><div class="mnhp-b-row-head"><span class="mnhp-b-drag" title="' + esc( T.drag ) + '">⋮⋮</span><label><input type="checkbox" class="mnhp-b-bg-on"' + ( r.bg ? ' checked' : '' ) + ' /> <input type="color" class="mnhp-b-bg" value="' + esc( r.bg || '#f3f4f6' ) + '" /></label><button type="button" class="button mnhp-b-add-col">+ ' + esc( T.column ) + '</button><button type="button" class="button-link mnhp-b-del-row" aria-label="' + esc( T['delete'] ) + '">×</button></div><div class="mnhp-b-cols">' + ( r.cols || [] ).map( colHtml ).join( '' ) + '</div></div>';
	}
	function builder( el ) {
		if ( ! el || el.getAttribute( 'data-ready' ) ) {
			return;
		}
		el.setAttribute( 'data-ready', '1' );
		var rows = load( el );
		$( el ).html( rows.map( rowHtml ).join( '' ) );
		$( el ).after( '<p class="mnhp-b-tools"><button type="button" class="button mnhp-b-add-row">+ ' + esc( T.row ) + '</button> <span class="description">' + esc( T.empty ) + '</span></p>' );
	}
	function root( node ) {
		return $( node ).closest( '.mnhp-src-builder' ).find( '.mnhp-builder' )[ 0 ];
	}
	$( document ).on( 'click', '.mnhp-b-add-row', function () {
		var el = root( this );
		$( el ).append( rowHtml( { cols: [ { span: 4, blocks: [] }, { span: 4, blocks: [] }, { span: 4, blocks: [] } ] } ) );
		save( el );
	} );
	$( document ).on( 'click', '.mnhp-b-add-col', function () {
		$( this ).closest( '.mnhp-b-row' ).find( '> .mnhp-b-cols' ).append( colHtml( { span: 3, blocks: [] } ) );
		save( root( this ) );
	} );
	$( document ).on( 'change', '.mnhp-b-add-block', function () {
		var type = $( this ).val();
		if ( type ) {
			$( this ).siblings( '.mnhp-b-blocks' ).append( blockHtml( { type: type } ) );
			$( this ).val( '' );
			save( root( this ) );
		}
	} );
	$( document ).on( 'click', '.mnhp-b-del, .mnhp-b-del-col, .mnhp-b-del-row', function () {
		var el = root( this );
		$( this ).closest( $( this ).hasClass( 'mnhp-b-del' ) ? '.mnhp-b-block' : ( $( this ).hasClass( 'mnhp-b-del-col' ) ? '.mnhp-b-col' : '.mnhp-b-row' ) ).remove();
		save( el );
	} );
	$( document ).on( 'change input', '.mnhp-builder :input', function () {
		if ( $( this ).hasClass( 'mnhp-b-span' ) ) {
			$( this ).closest( '.mnhp-b-col' ).css( 'flex-grow', $( this ).val() );
		}
		if ( ! $( this ).hasClass( 'mnhp-b-add-block' ) ) {
			save( root( this ) );
		}
	} );
	$( document ).on( 'click', '.mnhp-b-pick', function () {
		var $wrap = $( this ).closest( '.mnhp-b-image' );
		var node = this;
		media( function ( a ) {
			$wrap.find( 'input' ).val( a.id );
			$wrap.find( '.mnhp-b-id' ).text( '#' + a.id );
			save( root( node ) );
		} );
	} );

	/* Drag and drop: rows within a builder, blocks between columns. */
	var dragged = null;
	$( document ).on( 'dragstart', '.mnhp-b-row, .mnhp-b-block', function ( e ) {
		if ( $( e.target ).is( ':input' ) ) {
			return;
		}
		e.stopPropagation();
		dragged = this;
		$( this ).addClass( 'is-dragging' );
		e.originalEvent.dataTransfer.effectAllowed = 'move';
		e.originalEvent.dataTransfer.setData( 'text/plain', 'mnhp' );
	} );
	$( document ).on( 'dragend', '.mnhp-b-row, .mnhp-b-block', function () {
		$( this ).removeClass( 'is-dragging' );
		if ( dragged ) {
			save( root( dragged ) );
		}
		dragged = null;
	} );
	$( document ).on( 'dragover', '.mnhp-builder, .mnhp-b-blocks', function ( e ) {
		if ( ! dragged ) {
			return;
		}
		var isBlock = $( dragged ).hasClass( 'mnhp-b-block' );
		var $zone = $( this );
		if ( isBlock !== $zone.hasClass( 'mnhp-b-blocks' ) || root( this ) !== root( dragged ) ) {
			return;
		}
		e.preventDefault();
		e.stopPropagation();
		var y = e.originalEvent.clientY;
		var after = null;
		$zone.children( isBlock ? '.mnhp-b-block' : '.mnhp-b-row' ).not( dragged ).each( function () {
			var r = this.getBoundingClientRect();
			if ( null === after && y < r.top + r.height / 2 ) {
				after = this;
			}
		} );
		if ( after ) {
			$( dragged ).insertBefore( after );
		} else {
			$zone.append( dragged );
		}
	} );
	$( document ).on( 'drop', '.mnhp-builder, .mnhp-b-blocks', function ( e ) {
		if ( dragged ) {
			e.preventDefault();
		}
	} );

	function init( ctx ) {
		$( ctx ).find( '.mnhp-mega-box' ).each( function () {
			refresh( this );
		} );
	}
	$( function () {
		init( document );
	} );
	$( document ).on( 'menu-item-added', function ( e, markup ) {
		init( markup || document );
	} );
}( jQuery ) );
