/* Transara: start delayed scripts when the visitor interacts (or after the optional timeout). */
( function () {
	var events = [ 'mousemove', 'mousedown', 'keydown', 'touchstart', 'touchmove', 'wheel', 'scroll' ];
	var started = false;
	var timeout = window.transaraDelay && parseInt( window.transaraDelay.timeout, 10 ) > 0 ? parseInt( window.transaraDelay.timeout, 10 ) * 1000 : 0;

	function run( list, i, done ) {
		if ( i >= list.length ) {
			done();
			return;
		}
		var old = list[ i ];
		var s = document.createElement( 'script' );
		var next = function () {
			run( list, i + 1, done );
		};
		for ( var a = 0; a < old.attributes.length; a++ ) {
			var at = old.attributes[ a ];
			if ( 'type' === at.name || 'data-transara-src' === at.name || 'data-transara-type' === at.name ) {
				continue;
			}
			s.setAttribute( at.name, at.value );
		}
		if ( old.getAttribute( 'data-transara-type' ) ) {
			s.type = old.getAttribute( 'data-transara-type' );
		}
		var src = old.getAttribute( 'data-transara-src' );
		if ( src ) {
			s.src = src;
			// Keep the order of scripts unless a script is async.
			if ( ! s.hasAttribute( 'async' ) ) {
				s.async = false;
				s.onload = next;
				s.onerror = next;
				old.parentNode.replaceChild( s, old );
				return;
			}
			old.parentNode.replaceChild( s, old );
			next();
			return;
		}
		s.text = old.text;
		old.parentNode.replaceChild( s, old );
		next();
	}

	function start() {
		if ( started ) {
			return;
		}
		started = true;
		events.forEach( function ( e ) {
			window.removeEventListener( e, start, { passive: true } );
		} );
		var list = [].slice.call( document.querySelectorAll( 'script[type="transara/delay"]' ) );
		run( list, 0, function () {
			// Scripts that wait for these events get them now.
			document.dispatchEvent( new Event( 'DOMContentLoaded', { bubbles: true } ) );
			window.dispatchEvent( new Event( 'load' ) );
			if ( window.jQuery ) {
				window.jQuery( window ).trigger( 'load' );
			}
		} );
	}

	events.forEach( function ( e ) {
		window.addEventListener( e, start, { passive: true } );
	} );
	if ( timeout ) {
		setTimeout( start, timeout );
	}
} )();
