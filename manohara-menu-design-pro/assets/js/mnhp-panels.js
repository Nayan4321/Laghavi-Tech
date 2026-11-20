/* Manohara Menu Design Pro – opens mega menus in any theme's menu, and the "Tabs" group style. No dependencies. */
( function () {
	'use strict';
	var cfg = window.mnhpPanels || { mobile: 1024 };
	var root = document.documentElement;
	var mq = window.matchMedia( '(max-width: ' + ( parseInt( cfg.mobile, 10 ) || 1024 ) + 'px)' );
	var canHover = window.matchMedia( '(hover: hover)' ).matches;
	var timers = new WeakMap();
	var openLi = null;
	root.classList.add( 'mnhp-js' );

	function each( list, fn ) {
		Array.prototype.forEach.call( list, fn );
	}
	function panelOf( li ) {
		for ( var i = 0; i < li.children.length; i++ ) {
			if ( li.children[ i ].classList.contains( 'mnhp-panel' ) ) {
				return li.children[ i ];
			}
		}
		return null;
	}
	function linkOf( li ) {
		for ( var i = 0; i < li.children.length; i++ ) {
			if ( 'A' === li.children[ i ].tagName ) {
				return li.children[ i ];
			}
		}
		return li.querySelector( 'a' );
	}
	function toggleOf( li ) {
		for ( var i = 0; i < li.children.length; i++ ) {
			if ( li.children[ i ].classList.contains( 'mnhp-panel-toggle' ) ) {
				return li.children[ i ];
			}
		}
		return null;
	}
	function isMobile() {
		return mq.matches;
	}

	function reset( panel ) {
		[ 'position', 'top', 'left', 'width', 'maxHeight', 'overflowY' ].forEach( function ( k ) {
			panel.style[ k ] = '';
		} );
	}

	// Desktop: fixed under the header, as wide as the mega menu's setting, inside the screen.
	function place( li ) {
		var panel = panelOf( li );
		if ( ! panel ) {
			return;
		}
		if ( isMobile() ) {
			reset( panel );
			return;
		}
		var item = li.getBoundingClientRect();
		var top = item.bottom;
		var header = li.closest( 'header, [data-elementor-type="header"], .site-header, #masthead, .elementor-location-header' );
		if ( header ) {
			var hb = header.getBoundingClientRect().bottom;
			if ( hb > top && hb - top < 32 ) {
				top = hb;
			}
		}
		var vw = root.clientWidth;
		var max = parseFloat( getComputedStyle( panel ).getPropertyValue( '--mnhp-pmax' ) ) || 1200;
		var width;
		var left;
		if ( panel.classList.contains( 'mnhp-pw-full' ) ) {
			width = vw;
			left = 0;
		} else if ( panel.classList.contains( 'mnhp-pw-item' ) ) {
			width = Math.min( max, vw - 24 );
			left = 'rtl' === getComputedStyle( li ).direction ? item.right - width : item.left;
			left = Math.max( 12, Math.min( left, vw - 12 - width ) );
		} else {
			width = Math.min( max, vw - 24 );
			left = ( vw - width ) / 2;
		}
		panel.style.position = 'fixed';
		panel.style.top = top + 'px';
		panel.style.left = left + 'px';
		panel.style.width = width + 'px';
		panel.style.maxHeight = Math.max( 200, window.innerHeight - top - 12 ) + 'px';
		panel.style.overflowY = 'auto';
		panel.style.setProperty( '--mnhp-bridge', Math.max( 0, top - item.bottom ) + 2 + 'px' );
		// A transformed header makes "fixed" relative to it: correct by the difference.
		var anim = panel.style.animation;
		panel.style.animation = 'none';
		var r = panel.getBoundingClientRect();
		panel.style.animation = anim;
		var dx = left - r.left;
		var dy = top - r.top;
		if ( Math.abs( dx ) > 1 || Math.abs( dy ) > 1 ) {
			panel.style.left = left + dx + 'px';
			panel.style.top = top + dy + 'px';
		}
	}

	function setExpanded( li, on ) {
		var a = linkOf( li );
		var t = toggleOf( li );
		if ( a ) {
			a.setAttribute( 'aria-expanded', on ? 'true' : 'false' );
		}
		if ( t ) {
			t.setAttribute( 'aria-expanded', on ? 'true' : 'false' );
		}
	}
	function open( li ) {
		if ( openLi && openLi !== li ) {
			close( openLi );
		}
		li.classList.add( 'mnhp-open' );
		setExpanded( li, true );
		openLi = li;
		place( li );
	}
	function close( li ) {
		li.classList.remove( 'mnhp-open' );
		setExpanded( li, false );
		if ( openLi === li ) {
			openLi = null;
		}
	}
	function later( li, fn, ms ) {
		clearTimeout( timers.get( li ) );
		timers.set( li, setTimeout( fn, ms ) );
	}

	function setup( li ) {
		if ( li.getAttribute( 'data-mnhp-ready' ) ) {
			return;
		}
		li.setAttribute( 'data-mnhp-ready', '1' );
		var a = linkOf( li );
		if ( a ) {
			a.setAttribute( 'aria-haspopup', 'true' );
			a.setAttribute( 'aria-expanded', 'false' );
		}
		li.addEventListener( 'mouseenter', function () {
			if ( canHover && ! isMobile() ) {
				later( li, function () {
					open( li );
				}, 70 );
			}
		} );
		li.addEventListener( 'mouseleave', function () {
			if ( canHover && ! isMobile() ) {
				later( li, function () {
					close( li );
				}, 260 );
			}
		} );
		li.addEventListener( 'focusin', function () {
			if ( ! isMobile() ) {
				clearTimeout( timers.get( li ) );
				open( li );
			}
		} );
		li.addEventListener( 'focusout', function ( e ) {
			if ( ! isMobile() && ( ! e.relatedTarget || ! li.contains( e.relatedTarget ) ) ) {
				later( li, function () {
					close( li );
				}, 120 );
			}
		} );
		if ( a ) {
			a.addEventListener( 'click', function ( e ) {
				var href = a.getAttribute( 'href' );
				var empty = ! href || '#' === href;
				// A link with nowhere to go, or the first tap on a touch screen, opens the panel.
				if ( empty || ( ! canHover && ! li.classList.contains( 'mnhp-open' ) ) ) {
					e.preventDefault();
					e.stopPropagation();
					if ( li.classList.contains( 'mnhp-open' ) ) {
						close( li );
					} else {
						open( li );
					}
				}
			}, true );
		}
		var t = toggleOf( li );
		if ( t ) {
			alignToggle( li );
			t.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				e.stopPropagation();
				if ( li.classList.contains( 'mnhp-open' ) ) {
					close( li );
				} else {
					open( li );
				}
			} );
		}
	}

	// Phones: the open button sits beside the item's link.
	function alignToggle( li ) {
		var t = toggleOf( li );
		var a = linkOf( li );
		if ( ! t ) {
			return;
		}
		if ( ! isMobile() || ! a ) {
			t.style.top = '';
			t.style.height = '';
			return;
		}
		t.style.top = a.offsetTop + 'px';
		t.style.height = Math.max( 32, a.offsetHeight ) + 'px';
	}

	function mode() {
		root.classList.toggle( 'mnhp-mobile', isMobile() );
		each( document.querySelectorAll( '.mnhp-has-panel' ), alignToggle );
		if ( openLi ) {
			close( openLi );
		}
		each( document.querySelectorAll( '.mnhp-panel' ), function ( p ) {
			if ( ! p.classList.contains( 'mnhp-static' ) ) {
				reset( p );
			}
		} );
	}

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && openLi ) {
			var li = openLi;
			close( li );
			var a = linkOf( li );
			if ( a ) {
				a.focus();
			}
		}
	} );
	document.addEventListener( 'click', function ( e ) {
		if ( openLi && ! openLi.contains( e.target ) && ! isMobile() ) {
			close( openLi );
		}
	} );
	var ticking = false;
	function reposition() {
		if ( ticking || ! openLi ) {
			return;
		}
		ticking = true;
		window.requestAnimationFrame( function () {
			ticking = false;
			if ( openLi ) {
				place( openLi );
			}
		} );
	}
	window.addEventListener( 'scroll', reposition, { passive: true } );
	window.addEventListener( 'resize', reposition );
	if ( mq.addEventListener ) {
		mq.addEventListener( 'change', mode );
	} else if ( mq.addListener ) {
		mq.addListener( mode );
	}

	// "Tabs (mega menu)" group style.
	function tabs( el ) {
		if ( el.classList.contains( 'mnhp-tabs-ready' ) || el.querySelector( '.mnhp-tabs-ready' ) ) {
			return;
		}
		var box = el.querySelector( ':scope > .wp-block-group__inner-container' ) || el;
		var panes = Array.prototype.filter.call( box.children, function ( c ) {
			return c.classList.contains( 'wp-block-group' );
		} );
		if ( panes.length < 2 ) {
			return;
		}
		var uid = 'mnhp-t' + Math.random().toString( 36 ).slice( 2, 8 );
		var list = document.createElement( 'div' );
		list.className = 'mnhp-tabs-list';
		list.setAttribute( 'role', 'tablist' );
		var buttons = [];
		panes.forEach( function ( pane, i ) {
			var h = pane.querySelector( 'h1, h2, h3, h4, h5, h6' );
			var label = h ? h.textContent.trim() : String( i + 1 );
			if ( h ) {
				h.classList.add( 'mnhp-tab-label' );
			}
			var b = document.createElement( 'button' );
			b.type = 'button';
			b.className = 'mnhp-tab-btn';
			b.id = uid + '-' + i;
			b.setAttribute( 'role', 'tab' );
			b.setAttribute( 'aria-controls', uid + '-p' + i );
			b.textContent = label;
			pane.id = uid + '-p' + i;
			pane.setAttribute( 'role', 'tabpanel' );
			pane.setAttribute( 'aria-labelledby', b.id );
			pane.classList.add( 'mnhp-tab-pane' );
			list.appendChild( b );
			buttons.push( b );
		} );
		function select( i ) {
			buttons.forEach( function ( b, j ) {
				b.setAttribute( 'aria-selected', i === j ? 'true' : 'false' );
				b.tabIndex = i === j ? 0 : -1;
				panes[ j ].hidden = i !== j;
			} );
		}
		buttons.forEach( function ( b, i ) {
			b.addEventListener( 'click', function () {
				select( i );
			} );
			b.addEventListener( 'mouseenter', function () {
				if ( canHover && ! isMobile() ) {
					select( i );
				}
			} );
			b.addEventListener( 'keydown', function ( e ) {
				var n = { ArrowDown: 1, ArrowRight: 1, ArrowUp: -1, ArrowLeft: -1 }[ e.key ];
				if ( n ) {
					e.preventDefault();
					var k = ( i + n + buttons.length ) % buttons.length;
					select( k );
					buttons[ k ].focus();
				}
			} );
		} );
		box.insertBefore( list, box.firstChild );
		box.classList.add( 'mnhp-tabs-ready' );
		select( 0 );
	}

	function start() {
		mode();
		each( document.querySelectorAll( '.mnhp-has-panel' ), setup );
		each( document.querySelectorAll( '.is-style-mnhp-tabs' ), tabs );
	}
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
