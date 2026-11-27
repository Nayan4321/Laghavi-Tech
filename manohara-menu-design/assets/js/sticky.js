/* Manohara Menu Design – keeps the site header at the top of the screen on the chosen devices.
   The header becomes fixed once the page scrolls past it, and an empty block of the same height takes
   its place, so the page does not jump. No dependencies. */
( function () {
	'use strict';

	var cfg = window.manoharaSticky || {};
	var state = null;
	var ticking = false;

	function device() {
		var w = window.innerWidth;
		if ( w <= cfg.mobile ) {
			return 'mobile';
		}
		return w <= cfg.tablet ? 'tablet' : 'desktop';
	}

	function shown( el ) {
		return el.offsetHeight > 0 && getComputedStyle( el ).visibility !== 'hidden';
	}

	function matches( el, selector ) {
		try {
			return el.matches( selector );
		} catch ( e ) {
			return false;
		}
	}

	// The site header: the outermost visible match of the header selectors nearest the top, else a
	// Manohara header bar.
	function findHeader() {
		var list = [];
		( cfg.selectors || '' ).split( ',' ).forEach( function ( sel ) {
			sel = sel.trim();
			if ( ! sel ) {
				return;
			}
			try {
				Array.prototype.forEach.call( document.querySelectorAll( sel ), function ( el ) {
					if ( list.indexOf( el ) === -1 && ! el.closest( '.mnh' ) ) {
						list.push( el );
					}
				} );
			} catch ( e ) {}
		} );
		var best = null;
		var bestTop = Infinity;
		list.forEach( function ( el ) {
			if ( ! shown( el ) ) {
				return;
			}
			// Use the outermost match (a header inside another matched header).
			var outer = el;
			var p = el.parentElement;
			while ( p && p !== document.body ) {
				if ( list.indexOf( p ) !== -1 && shown( p ) ) {
					outer = p;
				}
				p = p.parentElement;
			}
			var top = outer.getBoundingClientRect().top + window.pageYOffset;
			if ( top < bestTop ) {
				best = outer;
				bestTop = top;
			}
		} );
		if ( best && bestTop < 600 ) {
			return best;
		}
		var bars = document.querySelectorAll( '.mnh.mnh-with-bar' );
		for ( var i = 0; i < bars.length; i++ ) {
			if ( shown( bars[ i ] ) ) {
				return bars[ i ];
			}
		}
		return null;
	}

	function isClear( color ) {
		return ! color || color === 'transparent' || /rgba\([^)]*,\s*0\)$/.test( color );
	}

	// The colour behind an element: the first ancestor with a background, else white.
	function pageBackground( el ) {
		var p = el.parentElement;
		while ( p ) {
			var c = getComputedStyle( p ).backgroundColor;
			if ( ! isClear( c ) ) {
				return c;
			}
			p = p.parentElement;
		}
		return '#fff';
	}

	function adminBar() {
		var bar = document.getElementById( 'wpadminbar' );
		return bar && getComputedStyle( bar ).position === 'fixed' ? bar.offsetHeight : 0;
	}

	function release() {
		if ( ! state ) {
			return;
		}
		unstick();
		state.el.classList.remove( 'mnh-sticky-header', 'mnh-sticky-shadow', 'mnh-sticky-scrolled' );
		if ( state.spacer.parentNode ) {
			state.spacer.parentNode.removeChild( state.spacer );
		}
		state = null;
	}

	function stick() {
		var s = state;
		var rect = s.el.getBoundingClientRect();
		// A header laid over the page (transparent headers) takes no room: no placeholder then.
		s.spacer.style.height = s.overlay ? '0' : s.el.offsetHeight + 'px';
		s.spacer.style.display = s.overlay ? 'none' : 'block';
		var cs = getComputedStyle( s.el );
		var pl = parseFloat( cs.paddingLeft ) || 0;
		var pr = parseFloat( cs.paddingRight ) || 0;
		var full = document.documentElement.clientWidth;
		// Full width while stuck, with its content where it was (boxed headers included).
		s.el.style.top = adminBar() + 'px';
		s.el.style.left = '0px';
		s.el.style.right = '0px';
		s.el.style.width = 'auto';
		s.el.style.maxWidth = 'none';
		s.el.style.marginLeft = '0px';
		s.el.style.marginRight = '0px';
		s.el.style.paddingLeft = Math.max( 0, rect.left + pl ) + 'px';
		s.el.style.paddingRight = Math.max( 0, full - rect.right + pr ) + 'px';
		// A header without its own background would show the page through it.
		if ( isClear( cs.backgroundColor ) && ! cs.backgroundImage.match( /url|gradient/ ) ) {
			s.el.style.backgroundColor = pageBackground( s.el );
		}
		s.el.classList.add( 'mnh-is-stuck' );
		s.stuck = true;
	}

	function unstick() {
		var s = state;
		s.el.classList.remove( 'mnh-is-stuck' );
		[ 'top', 'left', 'right', 'width', 'maxWidth', 'marginLeft', 'marginRight', 'paddingLeft', 'paddingRight', 'backgroundColor' ].forEach( function ( k ) {
			s.el.style[ k ] = '';
		} );
		s.spacer.style.display = 'none';
		s.stuck = false;
	}

	function update() {
		ticking = false;
		if ( ! state ) {
			return;
		}
		var y = window.pageYOffset;
		var pass = y + adminBar() > state.start;
		if ( pass && ! state.stuck ) {
			stick();
		} else if ( ! pass && state.stuck ) {
			unstick();
		}
		state.el.classList.toggle( 'mnh-sticky-scrolled', y > 4 );
	}

	function setup() {
		release();
		if ( ( cfg.devices || [] ).indexOf( device() ) === -1 ) {
			return;
		}
		var el = findHeader();
		if ( ! el ) {
			return;
		}
		var pos = getComputedStyle( el ).position;
		// The theme already keeps it at the top (or Manohara Pro does): leave it alone.
		if ( pos === 'fixed' || pos === 'sticky' || el.classList.contains( 'mnhp-is-stuck' ) || el.classList.contains( 'mnh-sticky' ) ) {
			return;
		}
		var spacer = document.createElement( 'div' );
		spacer.className = 'mnh-sticky-spacer';
		spacer.setAttribute( 'aria-hidden', 'true' );
		spacer.style.display = 'none';
		el.parentNode.insertBefore( spacer, el );
		el.classList.add( 'mnh-sticky-header' );
		if ( cfg.shadow ) {
			el.classList.add( 'mnh-sticky-shadow' );
		}
		state = {
			el: el,
			spacer: spacer,
			start: el.getBoundingClientRect().top + window.pageYOffset,
			stuck: false,
			overlay: pos === 'absolute',
		};
		update();
	}

	window.addEventListener( 'scroll', function () {
		if ( ! ticking ) {
			ticking = true;
			window.requestAnimationFrame( update );
		}
	}, { passive: true } );

	var resizeTimer;
	window.addEventListener( 'resize', function () {
		clearTimeout( resizeTimer );
		resizeTimer = setTimeout( setup, 200 );
	} );

	if ( document.readyState === 'complete' ) {
		setup();
	} else {
		window.addEventListener( 'load', setup );
	}
}() );
