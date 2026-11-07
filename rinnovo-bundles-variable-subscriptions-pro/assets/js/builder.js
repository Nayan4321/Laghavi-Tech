/* Rinnovo Bundles: box builder and item picker (counters, limits, live price). */
( function () {
	var cfg = window.rinnovobvBuilder || { currency: '', decimals: 2, count: '%1$d' };
	function money( n ) { return cfg.currency + Number( n ).toFixed( cfg.decimals ); }

	function setup( root ) {
		var picker = root.querySelector( '.rinnovobv-picker' ) || ( root.classList.contains( 'rinnovobv-picker' ) ? root : null );
		var counter = root.querySelector( '.rinnovobv-counter' );
		var total = root.querySelector( '.rinnovobv-total__value' );
		var submit = root.querySelector( '.rinnovobv-submit' ) || ( root.closest( 'form' ) && root.closest( 'form' ).querySelector( '.single_add_to_cart_button' ) );
		var attrs = root.querySelector( '.rinnovobv-attrs' );

		function limits() {
			var size = root.querySelector( 'input[name="variation_id"]:checked' );
			var min = picker ? parseInt( picker.dataset.min || '0', 10 ) : 0;
			var max = picker ? parseInt( picker.dataset.max || '0', 10 ) : 0;
			if ( size ) {
				min = parseInt( size.dataset.min || min, 10 ) || min;
				max = parseInt( size.dataset.max || max, 10 ) || max;
			}
			return [ min, max ];
		}
		function update() {
			var count = 0, sum = 0;
			if ( picker ) {
				picker.querySelectorAll( '.rinnovobv-item' ).forEach( function ( item ) {
					var input = item.querySelector( 'input' );
					var n = input ? parseInt( input.value || '0', 10 ) : 0;
					count += n;
					sum += n * parseFloat( item.dataset.price || '0' );
				} );
			}
			var l = limits();
			var plus = l[1] > 0 && count >= l[1];
			if ( picker ) {
				picker.querySelectorAll( '.rinnovobv-plus' ).forEach( function ( b ) { b.disabled = plus; } );
			}
			if ( counter ) {
				var want = l[0] && l[1] && l[0] !== l[1] ? l[0] + '–' + l[1] : String( l[1] || l[0] || '' );
				counter.textContent = cfg.count.replace( '%1$d', count ).replace( '%2$s', want );
			}
			var ok = ( ! l[0] || count >= l[0] ) && ( ! l[1] || count <= l[1] ) && ( ! picker || count > 0 );
			if ( submit ) { submit.disabled = ! ok; }
			if ( total ) {
				var size = root.querySelector( 'input[name="variation_id"]:checked' );
				var dyn = picker ? picker.dataset.dynamic : '';
				total.textContent = money( dyn ? sum * parseFloat( dyn ) : ( size ? parseFloat( size.dataset.price ) : sum ) );
			}
			if ( attrs ) {
				var s = root.querySelector( 'input[name="variation_id"]:checked' );
				attrs.innerHTML = '';
				if ( s && s.dataset.attrs ) {
					var a = JSON.parse( s.dataset.attrs );
					Object.keys( a ).forEach( function ( k ) {
						var h = document.createElement( 'input' );
						h.type = 'hidden'; h.name = k; h.value = a[ k ];
						attrs.appendChild( h );
					} );
				}
			}
		}
		root.addEventListener( 'click', function ( e ) {
			var b = e.target.closest( '.rinnovobv-plus, .rinnovobv-minus' );
			if ( ! b ) { return; }
			var input = b.parentNode.querySelector( 'input' );
			input.value = Math.max( 0, parseInt( input.value || '0', 10 ) + ( b.classList.contains( 'rinnovobv-plus' ) ? 1 : -1 ) );
			update();
		} );
		root.addEventListener( 'change', update );
		update();
	}
	document.querySelectorAll( '.rinnovobv-builder' ).forEach( setup );
	document.querySelectorAll( 'form.cart .rinnovobv-picker' ).forEach( function ( p ) { setup( p.closest( 'form' ) ); } );
} )();
