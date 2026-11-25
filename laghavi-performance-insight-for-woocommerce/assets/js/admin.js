/* Laghavi Performance Insight for WooCommerce — sortable tables and quick filter. */
( function () {
	'use strict';

	function cellValue( td ) {
		if ( ! td ) {
			return '';
		}
		if ( td.hasAttribute( 'data-v' ) ) {
			return parseFloat( td.getAttribute( 'data-v' ) );
		}
		var t = td.textContent.trim();
		var n = parseFloat( t.replace( /[^0-9.\-]/g, '' ) );
		return /^[^a-zA-Z]*[0-9]/.test( t ) && ! isNaN( n ) ? n : t.toLowerCase();
	}

	document.querySelectorAll( 'table.lgpi-sortable' ).forEach( function ( table ) {
		var heads = table.querySelectorAll( 'thead th' );
		heads.forEach( function ( th, col ) {
			th.addEventListener( 'click', function () {
				var desc = ! th.classList.contains( 'desc' );
				heads.forEach( function ( h ) {
					h.classList.remove( 'asc', 'desc' );
				} );
				th.classList.add( desc ? 'desc' : 'asc' );
				var body = table.tBodies[ 0 ];
				var rows = Array.prototype.slice.call( body.rows );
				rows.sort( function ( a, b ) {
					var x = cellValue( a.cells[ col ] );
					var y = cellValue( b.cells[ col ] );
					var r = typeof x === 'number' && typeof y === 'number' ? x - y : String( x ).localeCompare( String( y ) );
					return desc ? -r : r;
				} );
				rows.forEach( function ( r ) {
					body.appendChild( r );
				} );
			} );
		} );
	} );

	document.querySelectorAll( 'input.lgpi-search' ).forEach( function ( input ) {
		var table = document.querySelector( input.getAttribute( 'data-table' ) );
		if ( ! table ) {
			return;
		}
		input.addEventListener( 'input', function () {
			var q = input.value.trim().toLowerCase();
			Array.prototype.forEach.call( table.tBodies[ 0 ].rows, function ( r ) {
				r.style.display = ! q || r.textContent.toLowerCase().indexOf( q ) !== -1 ? '' : 'none';
			} );
		} );
	} );
} )();
