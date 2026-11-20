/* Manohara Menu Design Pro – menu item settings: mega menu choice, icon picker and media. */
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

	/* Mega menu: the Edit link follows the choice. */
	$( document ).on( 'change', '.mnhp-panel-select', function () {
		var edit = $( this ).find( 'option:selected' ).data( 'edit' ) || '';
		var $a = $( this ).siblings( '.mnhp-panel-edit' );
		$a.attr( 'href', edit ).prop( 'hidden', ! edit );
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

}( jQuery ) );
