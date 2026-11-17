/* Manohara Menu Design Pro – mega panel placement, tabs, carousels, sticky headers, swipe and tap. No dependencies. */
( function () {
	'use strict';
	var HEADERS = '#masthead, .site-header, #site-header, header.wp-block-template-part, .elementor-location-header, #header, header';
	var canHover = window.matchMedia && window.matchMedia( '(hover: hover)' ).matches;

	function each( list, fn ) {
		Array.prototype.forEach.call( list, fn );
	}
	function closest( el, sel ) {
		return el && el.closest ? el.closest( sel ) : null;
	}
	function rtl() {
		return 'rtl' === ( document.documentElement.getAttribute( 'dir' ) || getComputedStyle( document.documentElement ).direction );
	}

	/* Mega panels in bar menus: content width, full screen width or a fixed width, kept on the screen. */
	function placeMega( li ) {
		var nav = closest( li, '.mnh' );
		var wrap = li.querySelector( ':scope > .mnh-mega-wrap' );
		var mega = wrap && wrap.querySelector( '.mnhp-mega' );
		if ( ! nav || ! mega || ! nav.classList.contains( 'mnh-kind-bar' ) || nav.classList.contains( 'mnh-t-vertical' ) ) {
			return;
		}
		var vw = document.documentElement.clientWidth;
		var r = nav.getBoundingClientRect();
		var width;
		var left;
		if ( mega.classList.contains( 'mnhp-w-full' ) ) {
			width = vw;
			left = 0;
		} else {
			if ( mega.classList.contains( 'mnhp-w-custom' ) ) {
				width = parseFloat( getComputedStyle( mega ).getPropertyValue( '--mnhp-w' ) ) || 960;
			} else {
				width = Math.max( r.width, Math.min( vw - 32, 1160 ) );
			}
			width = Math.min( width, vw - 16 );
			var item = li.getBoundingClientRect();
			var centre = mega.classList.contains( 'mnhp-w-custom' ) ? item.left + item.width / 2 : r.left + r.width / 2;
			left = Math.max( 8, Math.min( vw - 8 - width, centre - width / 2 ) );
		}
		wrap.style.width = width + 'px';
		wrap.style.left = ( left - r.left ) + 'px';
		wrap.style.right = 'auto';
	}
	function placeAll() {
		each( document.querySelectorAll( '.mnh-kind-bar .mnh-has-mega' ), placeMega );
	}
	document.addEventListener( 'mouseover', function ( e ) {
		var li = closest( e.target, '.mnh-has-mega' );
		if ( li && ! li.contains( e.relatedTarget ) ) {
			placeMega( li );
		}
	} );
	document.addEventListener( 'focusin', function ( e ) {
		var li = closest( e.target, '.mnh-has-mega' );
		if ( li ) {
			placeMega( li );
		}
	} );
	document.addEventListener( 'click', function ( e ) {
		var li = closest( e.target, '.mnh-has-mega' );
		if ( li ) {
			placeMega( li );
		}
	}, true );

	/* Tabs. */
	function select( tab ) {
		var list = closest( tab, '.mnhp-tabs' );
		if ( ! list ) {
			return;
		}
		each( list.querySelectorAll( '.mnhp-tab' ), function ( t ) {
			var on = t === tab;
			t.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			t.tabIndex = on ? 0 : -1;
			var pane = document.getElementById( t.getAttribute( 'aria-controls' ) );
			if ( pane ) {
				pane.hidden = ! on;
			}
		} );
	}
	var hoverTimer;
	document.addEventListener( 'mouseover', function ( e ) {
		var tab = closest( e.target, '.mnhp-tab' );
		if ( tab && canHover ) {
			clearTimeout( hoverTimer );
			hoverTimer = setTimeout( function () {
				select( tab );
			}, 90 );
		}
	} );
	document.addEventListener( 'click', function ( e ) {
		var tab = closest( e.target, '.mnhp-tab' );
		if ( tab ) {
			var url = tab.getAttribute( 'data-url' );
			if ( url && 'true' === tab.getAttribute( 'aria-selected' ) ) {
				window.location.href = url;
				return;
			}
			select( tab );
			return;
		}
		var btn = closest( e.target, '.mnhp-prev, .mnhp-next' );
		if ( btn ) {
			var pane = closest( btn, '.mnhp-pane' ) || btn.parentNode.parentNode;
			var track = pane && pane.querySelector( '.mnhp-carousel' );
			if ( track ) {
				var dir = btn.classList.contains( 'mnhp-next' ) ? 1 : -1;
				track.scrollBy( { left: dir * ( rtl() ? -1 : 1 ) * track.clientWidth * 0.8, behavior: 'smooth' } );
			}
		}
	} );
	document.addEventListener( 'keydown', function ( e ) {
		var tab = closest( e.target, '.mnhp-tab' );
		if ( ! tab || -1 === [ 'ArrowDown', 'ArrowUp', 'ArrowLeft', 'ArrowRight', 'Home', 'End' ].indexOf( e.key ) ) {
			return;
		}
		var tabs = Array.prototype.slice.call( closest( tab, '.mnhp-tablist' ).querySelectorAll( '.mnhp-tab' ) );
		var i = tabs.indexOf( tab );
		var back = 'ArrowUp' === e.key || ( rtl() ? 'ArrowRight' : 'ArrowLeft' ) === e.key;
		if ( 'Home' === e.key ) {
			i = 0;
		} else if ( 'End' === e.key ) {
			i = tabs.length - 1;
		} else {
			i = ( i + ( back ? -1 : 1 ) + tabs.length ) % tabs.length;
		}
		e.preventDefault();
		tabs[ i ].focus();
		select( tabs[ i ] );
	} );

	/* Sticky, shrinking and hide-on-scroll headers. */
	var sticky = [];
	function visible( el ) {
		return el.offsetParent !== null || 'fixed' === getComputedStyle( el ).position;
	}
	function setupSticky() {
		each( sticky, function ( s ) {
			s.target.classList.remove( 'mnhp-is-stuck', 'mnhp-reveal', 'mnhp-hidden', 'mnhp-shrinkable', 'mnhp-scrolled' );
		} );
		sticky = [];
		each( document.querySelectorAll( '.mnh.mnhp-sticky-sticky, .mnh.mnhp-sticky-shrink, .mnh.mnhp-sticky-reveal' ), function ( nav ) {
			if ( ! visible( nav ) || closest( nav, '[data-mnh-screen]' ) ) {
				return;
			}
			var target = nav.classList.contains( 'mnh-with-bar' ) ? nav : ( closest( nav, HEADERS ) || nav );
			if ( target !== nav && nav.classList.contains( 'mnh-sticky' ) ) {
				target = nav;
			}
			target.classList.add( 'mnhp-is-stuck' );
			if ( nav.classList.contains( 'mnhp-sticky-reveal' ) ) {
				target.classList.add( 'mnhp-reveal' );
			}
			if ( nav.classList.contains( 'mnhp-sticky-shrink' ) && target !== nav ) {
				target.classList.add( 'mnhp-shrinkable' );
			}
			sticky.push( { nav: nav, target: target } );
		} );
		onScroll();
	}
	var lastY = window.pageYOffset;
	var ticking = false;
	function onScroll() {
		var y = window.pageYOffset;
		each( sticky, function ( s ) {
			var scrolled = y > 12;
			s.nav.classList.toggle( 'mnhp-scrolled', scrolled );
			s.target.classList.toggle( 'mnhp-scrolled', scrolled );
			if ( s.target.classList.contains( 'mnhp-reveal' ) ) {
				var hide = y > lastY && y > s.target.offsetHeight + 40 && ! s.nav.classList.contains( 'mnh-open' ) && ! s.nav.querySelector( '.mnh-sub-open' );
				if ( y < lastY - 4 || y < 40 ) {
					hide = false;
				} else if ( Math.abs( y - lastY ) < 4 ) {
					hide = s.target.classList.contains( 'mnhp-hidden' );
				}
				s.target.classList.toggle( 'mnhp-hidden', hide );
			}
		} );
		lastY = y;
		ticking = false;
	}
	window.addEventListener( 'scroll', function () {
		if ( ! ticking ) {
			ticking = true;
			window.requestAnimationFrame( onScroll );
		}
	}, { passive: true } );

	/* Swipe a panel away to close it. */
	var touch = null;
	document.addEventListener( 'touchstart', function ( e ) {
		var panel = closest( e.target, '.mnhp-swipe.mnh-open .mnh-panel' );
		if ( panel && 1 === e.touches.length ) {
			touch = { panel: panel, x: e.touches[ 0 ].clientX, y: e.touches[ 0 ].clientY };
		}
	}, { passive: true } );
	document.addEventListener( 'touchend', function ( e ) {
		if ( ! touch ) {
			return;
		}
		var t = e.changedTouches[ 0 ];
		var dx = t.clientX - touch.x;
		var dy = t.clientY - touch.y;
		var panel = touch.panel;
		touch = null;
		if ( Math.max( Math.abs( dx ), Math.abs( dy ) ) < 60 ) {
			return;
		}
		var nav = closest( panel, '.mnh' );
		var r = panel.getBoundingClientRect();
		var vw = document.documentElement.clientWidth;
		var close = false;
		if ( Math.abs( dx ) > Math.abs( dy ) * 1.5 && r.width < vw * 0.95 ) {
			// A side panel closes towards its edge.
			close = r.left + r.width / 2 > vw / 2 ? dx > 60 : dx < -60;
		} else if ( Math.abs( dy ) > Math.abs( dx ) * 1.5 && dy < -70 && panel.scrollTop <= 0 && panel.scrollHeight - panel.clientHeight - panel.scrollTop < 4 ) {
			// Full-width panels close with an upward swipe once scrolled to the end.
			close = true;
		}
		if ( close && nav ) {
			var btn = nav.querySelector( '.mnh-close' ) || nav.querySelector( '.mnh-toggle' );
			if ( btn ) {
				btn.click();
			}
		}
	}, { passive: true } );

	/* Touch screens: the first tap on a parent item opens its submenu, the second follows the link. */
	document.addEventListener( 'click', function ( e ) {
		var link = closest( e.target, '.mnhp-tap .mnh-link' );
		if ( ! link ) {
			return;
		}
		var li = closest( link, 'li' );
		var nav = closest( link, '.mnh' );
		var href = link.getAttribute( 'href' ) || '';
		if ( ! li || ! li.classList.contains( 'mnh-has-sub' ) || li.classList.contains( 'mnh-sub-open' ) || '' === href || '#' === href ) {
			return;
		}
		if ( nav.classList.contains( 'mnh-kind-bar' ) && canHover ) {
			return;
		}
		var toggle = li.querySelector( ':scope > .mnh-row > .mnh-sub-toggle' );
		if ( toggle ) {
			e.preventDefault();
			e.stopPropagation();
			toggle.click();
		}
	}, true );

	var timer;
	window.addEventListener( 'resize', function () {
		clearTimeout( timer );
		timer = setTimeout( function () {
			placeAll();
			setupSticky();
		}, 150 );
	} );
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', setupSticky );
	} else {
		setupSticky();
	}
}() );
