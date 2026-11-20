/* Manohara Menu Design – menu buttons, submenus, search, keyboard. No dependencies. */
( function () {
	'use strict';
	var current = null;
	var FOCUSABLE = 'a[href],button:not([disabled]),input,select,textarea,[tabindex]:not([tabindex="-1"])';

	function closest( el, sel ) {
		return el && el.closest ? el.closest( sel ) : null;
	}
	// A menu button inside a header with a transform (sticky headers, page builders) would trap the
	// fixed panel inside the header; such panels live at the end of the page, linked to their button.
	function box( nav ) {
		return nav && nav.mnhPortal ? nav.mnhPortal : nav;
	}
	function origin( el ) {
		return el && el.mnhNav ? el.mnhNav : el;
	}
	function portals() {
		Array.prototype.forEach.call( document.querySelectorAll( 'nav.mnh.mnh-kind-toggle:not(.mnh-with-bar)' ), function ( nav ) {
			if ( nav.mnhPortal || closest( nav, '[data-mnh-screen]' ) ) {
				return;
			}
			var panel = nav.querySelector( ':scope > .mnh-panel' );
			if ( ! panel ) {
				return;
			}
			var wrap = document.createElement( 'div' );
			wrap.className = nav.className + ' mnh-portal';
			wrap.setAttribute( 'style', nav.getAttribute( 'style' ) || '' );
			wrap.setAttribute( 'role', 'navigation' );
			wrap.setAttribute( 'aria-label', nav.getAttribute( 'aria-label' ) || '' );
			wrap.appendChild( panel );
			var backdrop = nav.querySelector( ':scope > .mnh-backdrop' );
			if ( backdrop ) {
				wrap.appendChild( backdrop );
			}
			document.body.appendChild( wrap );
			nav.mnhPortal = wrap;
			wrap.mnhNav = nav;
		} );
	}
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', portals );
	} else {
		portals();
	}
	function duration( nav ) {
		var d = parseFloat( getComputedStyle( nav ).getPropertyValue( '--mnh-dur' ) );
		return isNaN( d ) ? 350 : d;
	}
	function locks( nav ) {
		return nav.classList.contains( 'mnh-t-overlay' ) || nav.classList.contains( 'mnh-t-drawer' );
	}
	function place( nav ) {
		var ref = nav.querySelector( '.mnh-bar' ) || origin( nav ).querySelector( '.mnh-toggle' );
		if ( ref ) {
			var bottom = ref.getBoundingClientRect().bottom;
			// Inside a scaled preview screen (the admin), fixed panels sit in that screen.
			var screen = closest( origin( nav ), '[data-mnh-screen]' );
			if ( screen ) {
				var s = screen.getBoundingClientRect();
				bottom = ( bottom - s.top ) / ( s.width / screen.offsetWidth || 1 );
			}
			nav.style.setProperty( '--mnh-top', Math.max( 0, Math.round( bottom + ( nav.querySelector( '.mnh-bar' ) ? 0 : 8 ) ) ) + 'px' );
		}
	}
	function closeSubs( root ) {
		Array.prototype.forEach.call( root.querySelectorAll( '.mnh-sub-open' ), function ( li ) {
			li.classList.remove( 'mnh-sub-open' );
			var b = li.querySelector( ':scope > .mnh-row > .mnh-sub-toggle' );
			if ( b ) {
				b.setAttribute( 'aria-expanded', 'false' );
			}
		} );
	}
	function setOpen( nav, open ) {
		nav = box( nav );
		var toggle = origin( nav ).querySelector( '.mnh-toggle' );
		var backdrop = nav.querySelector( '.mnh-backdrop' );
		if ( open ) {
			if ( current && current !== nav ) {
				setOpen( current, false );
			}
			place( nav );
			if ( backdrop ) {
				backdrop.hidden = false;
			}
			// Let the browser draw the closed state first, so the opening animates.
			requestAnimationFrame( function () {
				nav.classList.add( 'mnh-open' );
			} );
			current = nav;
			if ( locks( nav ) ) {
				document.documentElement.classList.add( 'mnh-lock' );
			}
			setTimeout( function () {
				var first = nav.querySelector( '.mnh-panel ' + FOCUSABLE );
				if ( first && locks( nav ) ) {
					first.focus( { preventScroll: true } );
				}
			}, 60 );
		} else {
			nav.classList.remove( 'mnh-open' );
			if ( current === nav ) {
				current = null;
			}
			document.documentElement.classList.remove( 'mnh-lock' );
			setTimeout( function () {
				if ( ! nav.classList.contains( 'mnh-open' ) ) {
					if ( backdrop ) {
						backdrop.hidden = true;
					}
					if ( nav.classList.contains( 'mnh-submenu-drilldown' ) ) {
						closeSubs( nav );
					}
				}
			}, duration( nav ) );
		}
		if ( toggle ) {
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		}
	}
	function toggleSub( li, force ) {
		var open = typeof force === 'boolean' ? force : ! li.classList.contains( 'mnh-sub-open' );
		var nav = closest( li, '.mnh' );
		// Bar menus and drill-downs: one submenu open per level.
		if ( open && nav && ( nav.classList.contains( 'mnh-kind-bar' ) || nav.classList.contains( 'mnh-submenu-drilldown' ) ) && li.parentNode ) {
			Array.prototype.forEach.call( li.parentNode.children, function ( sib ) {
				if ( sib !== li && sib.classList.contains( 'mnh-sub-open' ) ) {
					toggleSub( sib, false );
				}
			} );
		}
		li.classList.toggle( 'mnh-sub-open', open );
		var b = li.querySelector( ':scope > .mnh-row > .mnh-sub-toggle' );
		if ( b ) {
			b.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		}
		if ( open && nav && nav.classList.contains( 'mnh-submenu-drilldown' ) ) {
			var back = li.querySelector( ':scope > .mnh-sub-wrap .mnh-back' );
			if ( back ) {
				setTimeout( function () {
					back.focus( { preventScroll: true } );
				}, 50 );
			}
		}
	}

	document.addEventListener( 'click', function ( e ) {
		var t = e.target;
		var el;
		if ( ( el = closest( t, '.mnh-toggle' ) ) ) {
			var nav = box( closest( el, '.mnh' ) );
			setOpen( nav, ! nav.classList.contains( 'mnh-open' ) );
			return;
		}
		if ( ( el = closest( t, '.mnh-close, .mnh-backdrop' ) ) ) {
			setOpen( closest( el, '.mnh' ), false );
			return;
		}
		if ( ( el = closest( t, '.mnh-sub-toggle' ) ) ) {
			e.preventDefault();
			toggleSub( closest( el, 'li' ) );
			return;
		}
		if ( ( el = closest( t, '.mnh-back' ) ) ) {
			var li = closest( el, '.mnh-has-sub' );
			toggleSub( li, false );
			var b = li.querySelector( '.mnh-sub-toggle' );
			if ( b ) {
				b.focus( { preventScroll: true } );
			}
			return;
		}
		if ( ( el = closest( t, '.mnh-search-toggle' ) ) ) {
			var n = closest( el, '.mnh' );
			var on = ! n.classList.contains( 'mnh-search-open' );
			n.classList.toggle( 'mnh-search-open', on );
			el.setAttribute( 'aria-expanded', on ? 'true' : 'false' );
			if ( on ) {
				var input = n.querySelector( '.mnh-bar .mnh-search input' );
				setTimeout( function () {
					if ( input ) {
						input.focus();
					}
				}, 60 );
			}
			return;
		}
		if ( ( el = closest( t, '.mnh-link' ) ) ) {
			var href = el.getAttribute( 'href' ) || '';
			var item = closest( el, 'li' );
			// "#" parents open their submenu instead of going nowhere.
			if ( ( '' === href || '#' === href ) && item && item.classList.contains( 'mnh-has-sub' ) ) {
				e.preventDefault();
				toggleSub( item );
				return;
			}
			// Links to a part of this page close the panel.
			if ( current && 0 === href.indexOf( '#' ) && href.length > 1 ) {
				setOpen( current, false );
			}
			return;
		}
		// A click elsewhere closes an open panel without a dimmed page, and bar dropdowns opened by click.
		if ( current && ! closest( t, '.mnh-open' ) && ! current.querySelector( '.mnh-backdrop:not([hidden])' ) ) {
			setOpen( current, false );
		}
		Array.prototype.forEach.call( document.querySelectorAll( '.mnh-kind-bar .mnh-sub-open' ), function ( li ) {
			if ( ! li.contains( t ) ) {
				toggleSub( li, false );
			}
		} );
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key ) {
			var open = document.querySelector( '.mnh-kind-bar .mnh-sub-open' );
			if ( open ) {
				toggleSub( open, false );
			}
			if ( current ) {
				var nav = current;
				setOpen( nav, false );
				var t = origin( nav ).querySelector( '.mnh-toggle' );
				if ( t ) {
					t.focus();
				}
			}
			return;
		}
		// Keep focus inside full-screen and drawer menus.
		if ( 'Tab' === e.key && current && locks( current ) ) {
			var items = Array.prototype.filter.call( current.querySelectorAll( '.mnh-panel ' + FOCUSABLE ), function ( el ) {
				return el.offsetParent !== null;
			} );
			if ( ! items.length ) {
				return;
			}
			var first = items[ 0 ];
			var last = items[ items.length - 1 ];
			if ( e.shiftKey && document.activeElement === first ) {
				e.preventDefault();
				last.focus();
			} else if ( ! e.shiftKey && document.activeElement === last ) {
				e.preventDefault();
				first.focus();
			}
		}
	} );

	// Screen resized past a breakpoint: close what is open there.
	var timer;
	window.addEventListener( 'resize', function () {
		clearTimeout( timer );
		timer = setTimeout( function () {
			if ( current && null === current.offsetParent && 'fixed' !== getComputedStyle( current ).position ) {
				setOpen( current, false );
			} else if ( current ) {
				place( current );
			}
		}, 150 );
	} );
}() );
