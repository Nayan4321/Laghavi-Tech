/* Rinnovo Box Builder: quantities, limits, live total, conditions, day tabs and wizard steps. */
( function () {
	'use strict';
	var L = window.rinnovobbL10n || {};

	function money( n ) {
		var d = parseInt( L.decimals, 10 ) || 0;
		var parts = ( Math.round( n * Math.pow( 10, d ) ) / Math.pow( 10, d ) ).toFixed( d ).split( '.' );
		parts[ 0 ] = parts[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, L.thousand || ',' );
		var num = parts.join( L.dec || '.' );
		return ( L.format || '%1$s%2$s' ).replace( '%1$s', L.currency || '' ).replace( '%2$s', num ).replace( /&nbsp;/g, ' ' );
	}

	function num( v ) {
		var n = parseFloat( v );
		return isNaN( n ) ? 0 : n;
	}

	function qtyOf( item ) {
		var input = item.querySelector( 'input[type=number]' );
		if ( input ) {
			return Math.max( 0, parseInt( input.value, 10 ) || 0 );
		}
		var box = item.querySelector( 'input[type=checkbox]' );
		return box && box.checked ? 1 : 0;
	}

	function prefs( root ) {
		var out = {};
		root.querySelectorAll( '[data-field]' ).forEach( function ( f ) {
			var id = f.getAttribute( 'data-field' );
			var vals = [];
			f.querySelectorAll( 'input, select, textarea' ).forEach( function ( i ) {
				if ( ( i.type === 'checkbox' || i.type === 'radio' ) && ! i.checked ) {
					return;
				}
				if ( i.tagName === 'SELECT' && i.multiple ) {
					Array.prototype.forEach.call( i.selectedOptions, function ( o ) { vals.push( o.value ); } );
					return;
				}
				if ( i.value !== '' ) {
					vals.push( i.value );
				}
			} );
			out[ id ] = vals;
		} );
		return out;
	}

	function conditions( root ) {
		var answers = prefs( root );
		root.querySelectorAll( '[data-show-field]' ).forEach( function ( el ) {
			var v = answers[ el.getAttribute( 'data-show-field' ) ] || [];
			var show = v.indexOf( el.getAttribute( 'data-show-value' ) ) !== -1;
			el.hidden = ! show;
			el.querySelectorAll( 'input, select, textarea' ).forEach( function ( i ) { i.disabled = ! show; } );
		} );
	}

	function update( root ) {
		conditions( root );
		var pricing = root.getAttribute( 'data-pricing' );
		var total = pricing === 'items' ? 0 : num( root.getAttribute( 'data-base' ) );
		var lines = [];
		var count = 0;
		root.querySelectorAll( '[data-fixed], [data-scheduled]' ).forEach( function ( li ) {
			var q = parseInt( li.getAttribute( 'data-qty' ), 10 ) || 1;
			lines.push( q + ' × ' + li.getAttribute( 'data-name' ) );
			if ( pricing === 'items' ) {
				total += ( num( li.getAttribute( 'data-price' ) ) + num( li.getAttribute( 'data-extra' ) ) ) * q;
			} else if ( pricing === 'extras' ) {
				total += num( li.getAttribute( 'data-extra' ) ) * q;
			}
		} );
		root.querySelectorAll( '.rinnovobb-dayset' ).forEach( function ( set ) {
			var dayCount = 0;
			set.querySelectorAll( '.rinnovobb-group' ).forEach( function ( g ) {
				if ( g.hidden ) {
					return;
				}
				var gCount = 0;
				g.querySelectorAll( '.rinnovobb-item' ).forEach( function ( item ) {
					var q = qtyOf( item );
					item.classList.toggle( 'is-chosen', q > 0 );
					if ( ! q ) {
						return;
					}
					var price = num( item.getAttribute( 'data-price' ) );
					var sel = item.querySelector( '.rinnovobb-var' );
					if ( sel && sel.selectedOptions[ 0 ] && sel.selectedOptions[ 0 ].getAttribute( 'data-price' ) ) {
						price = num( sel.selectedOptions[ 0 ].getAttribute( 'data-price' ) );
					}
					var extra = num( item.getAttribute( 'data-extra' ) );
					total += pricing === 'items' ? ( price + extra ) * q : ( pricing === 'extras' ? extra * q : 0 );
					gCount += q;
					lines.push( q + ' × ' + item.getAttribute( 'data-name' ) );
				} );
				var max = parseInt( g.getAttribute( 'data-max' ), 10 ) || 0;
				var limit = g.querySelector( '.rinnovobb-limit' );
				if ( limit && max ) {
					limit.textContent = ( L.count || '%1$d of %2$d' ).replace( '%1$d', gCount ).replace( '%2$d', max );
				}
				g.classList.toggle( 'is-full', max > 0 && gCount >= max );
				g.querySelectorAll( '.rinnovobb-plus' ).forEach( function ( b ) { b.disabled = max > 0 && gCount >= max; } );
				g.querySelectorAll( '.rinnovobb-check input' ).forEach( function ( c ) { c.disabled = ! c.checked && max > 0 && gCount >= max; } );
				dayCount += gCount;
			} );
			count += dayCount;
			var tab = root.querySelector( '.rinnovobb-tab[data-day="' + set.getAttribute( 'data-day' ) + '"] .rinnovobb-tab-count' );
			if ( tab ) {
				tab.textContent = dayCount ? '(' + dayCount + ')' : '';
			}
		} );
		var list = root.querySelector( '.rinnovobb-summary-list' );
		if ( list ) {
			list.innerHTML = '';
			if ( ! lines.length ) {
				var li = document.createElement( 'li' );
				li.textContent = L.empty || '';
				list.appendChild( li );
			}
			lines.forEach( function ( t ) {
				var li = document.createElement( 'li' );
				li.textContent = t;
				list.appendChild( li );
			} );
		}
		var max = parseInt( root.getAttribute( 'data-max' ), 10 ) || 0;
		var c = root.querySelector( '.rinnovobb-count' );
		if ( c && max ) {
			c.textContent = ( L.count || '%1$d of %2$d' ).replace( '%1$d', count ).replace( '%2$d', max );
		}
		var t = root.querySelector( '.rinnovobb-total-amount' );
		if ( t ) {
			t.textContent = money( total );
		}
	}

	function wizard( root ) {
		var steps = Array.prototype.slice.call( root.querySelectorAll( '.rinnovobb-section' ) );
		var nav = root.querySelector( '.rinnovobb-steps' );
		if ( steps.length < 2 || ! nav ) {
			return;
		}
		var at = 0;
		function show( i ) {
			at = Math.max( 0, Math.min( steps.length - 1, i ) );
			steps.forEach( function ( s, n ) { s.hidden = n !== at; } );
			nav.querySelectorAll( 'li' ).forEach( function ( li, n ) {
				li.classList.toggle( 'is-active', n === at );
				li.classList.toggle( 'is-done', n < at );
			} );
			var form = root.closest( 'form' );
			var buy = form ? form.querySelector( '[type=submit][name="add-to-cart"], .single_add_to_cart_button' ) : null;
			if ( buy ) {
				buy.hidden = at !== steps.length - 1;
			}
		}
		steps.forEach( function ( s, n ) {
			var li = document.createElement( 'li' );
			var b = document.createElement( 'button' );
			b.type = 'button';
			b.textContent = s.getAttribute( 'data-step' );
			b.addEventListener( 'click', function () { show( n ); } );
			li.appendChild( b );
			nav.appendChild( li );
			var bar = document.createElement( 'p' );
			bar.className = 'rinnovobb-step-buttons';
			if ( n > 0 ) {
				var back = document.createElement( 'button' );
				back.type = 'button';
				back.className = 'button';
				back.textContent = L.back || 'Back';
				back.addEventListener( 'click', function () { show( n - 1 ); } );
				bar.appendChild( back );
			}
			if ( n < steps.length - 1 ) {
				var next = document.createElement( 'button' );
				next.type = 'button';
				next.className = 'button alt';
				next.textContent = L.next || 'Next';
				next.addEventListener( 'click', function () { show( n + 1 ); } );
				bar.appendChild( next );
			}
			s.appendChild( bar );
		} );
		show( 0 );
	}

	function setup( root ) {
		if ( root.getAttribute( 'data-ready' ) ) {
			return;
		}
		root.setAttribute( 'data-ready', '1' );
		root.addEventListener( 'click', function ( e ) {
			var b = e.target.closest( '.rinnovobb-plus, .rinnovobb-minus' );
			if ( b ) {
				var input = b.parentNode.querySelector( 'input' );
				var max = parseInt( input.getAttribute( 'max' ), 10 ) || Infinity;
				var v = ( parseInt( input.value, 10 ) || 0 ) + ( b.classList.contains( 'rinnovobb-plus' ) ? 1 : -1 );
				input.value = Math.max( 0, Math.min( max, v ) );
				update( root );
				return;
			}
			var tab = e.target.closest( '.rinnovobb-tab' );
			if ( tab ) {
				var day = tab.getAttribute( 'data-day' );
				root.querySelectorAll( '.rinnovobb-tab' ).forEach( function ( t ) { t.classList.toggle( 'is-active', t === tab ); t.setAttribute( 'aria-selected', t === tab ? 'true' : 'false' ); } );
				root.querySelectorAll( '.rinnovobb-dayset' ).forEach( function ( s ) { s.classList.toggle( 'is-active', s.getAttribute( 'data-day' ) === day ); } );
			}
		} );
		root.addEventListener( 'input', function () { update( root ); } );
		root.addEventListener( 'change', function () { update( root ); } );
		if ( root.classList.contains( 'rinnovobb--wizard' ) ) {
			wizard( root );
		}
		update( root );
	}

	function all() {
		document.querySelectorAll( '.rinnovobb' ).forEach( setup );
	}
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', all );
	} else {
		all();
	}
	// Variable products: the builder stays when WooCommerce swaps the variation.
	document.addEventListener( 'rinnovobb:refresh', all );
}() );
