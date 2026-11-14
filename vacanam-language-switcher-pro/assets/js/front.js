/* Vacanam Pro: first-visit suggestion and chooser, bottom sheet, dynamic content, analytics. */
( function () {
	'use strict';
	var cfg = window.vacanamproFront;
	if ( ! cfg ) {
		return;
	}
	var langs = cfg.languages || {};
	var store = {
		get: function ( k ) {
			try {
				return window.localStorage.getItem( k );
			} catch ( e ) {
				return null;
			}
		},
		set: function ( k, v ) {
			try {
				window.localStorage.setItem( k, v );
			} catch ( e ) {}
		}
	};
	var session = {
		get: function ( k ) {
			try {
				return window.sessionStorage.getItem( k );
			} catch ( e ) {
				return null;
			}
		},
		set: function ( k, v ) {
			try {
				window.sessionStorage.setItem( k, v );
			} catch ( e ) {}
		},
		del: function ( k ) {
			try {
				window.sessionStorage.removeItem( k );
			} catch ( e ) {}
		}
	};

	/* Analytics: a few counters, sent without cookies. */
	function track( event, extra ) {
		if ( ! cfg.track ) {
			return;
		}
		var data = { e: event, lang: cfg.current };
		Object.keys( extra || {} ).forEach( function ( k ) {
			data[ k ] = extra[ k ];
		} );
		var body = JSON.stringify( data );
		if ( navigator.sendBeacon ) {
			navigator.sendBeacon( cfg.track, new Blob( [ body ], { type: 'application/json' } ) );
		} else {
			fetch( cfg.track, { method: 'POST', body: body, headers: { 'Content-Type': 'application/json' }, keepalive: true } );
		}
	}

	function langOfLink( a ) {
		var slug = a.getAttribute( 'data-vacanam-lang' );
		if ( slug ) {
			return slug;
		}
		var found = '';
		Object.keys( langs ).forEach( function ( s ) {
			if ( langs[ s ].url === a.href ) {
				found = s;
			}
		} );
		return found || a.getAttribute( 'hreflang' ) || '';
	}

	function choose( slug, source ) {
		store.set( 'vacanampro_seen', '1' );
		if ( slug && slug !== cfg.current ) {
			track( 'switch', { to: slug, via: source || 'switcher' } );
			session.set( 'vacanampro_switched', String( Date.now() ) );
		}
	}

	/* Any language link of the site counts as a choice. */
	document.addEventListener( 'click', function ( e ) {
		var a = e.target && e.target.closest ? e.target.closest( '.vacanam-switcher a[href], .vacanampro-grid a, .vacanampro-sheet-list a, a[data-vacanam-lang]' ) : null;
		if ( a ) {
			choose( langOfLink( a ), a.closest( '.vacanampro-grid' ) ? 'chooser' : ( a.closest( '.vacanampro-sheet-list' ) ? 'sheet' : 'switcher' ) );
			return;
		}
		var link = e.target && e.target.closest ? e.target.closest( 'a[href]' ) : null;
		if ( link && link.host === window.location.host ) {
			session.set( 'vacanampro_internal', '1' );
		}
	}, true );
	document.addEventListener( 'change', function ( e ) {
		var s = e.target;
		if ( s && s.classList && s.classList.contains( 'vacanam-select' ) ) {
			var slug = '';
			Object.keys( langs ).forEach( function ( k ) {
				if ( langs[ k ].url === s.value ) {
					slug = k;
				}
			} );
			choose( slug, 'select' );
		}
	}, true );

	/* Dialogs. */
	var lastFocus = null;
	function open( el ) {
		if ( ! el ) {
			return;
		}
		lastFocus = document.activeElement;
		el.hidden = false;
		document.documentElement.classList.add( 'vacanampro-locked' );
		window.requestAnimationFrame( function () {
			el.classList.add( 'is-open' );
			var first = el.querySelector( 'a[aria-current], a, button' );
			if ( first ) {
				first.focus();
			}
		} );
	}
	function close( el ) {
		if ( ! el || el.hidden ) {
			return;
		}
		el.classList.remove( 'is-open' );
		document.documentElement.classList.remove( 'vacanampro-locked' );
		store.set( 'vacanampro_seen', '1' );
		window.setTimeout( function () {
			el.hidden = true;
		}, 200 );
		if ( lastFocus && lastFocus.focus ) {
			lastFocus.focus();
		}
	}
	var chooser = document.querySelector( '.vacanampro-chooser' );
	var sheet = document.querySelector( '.vacanampro-sheet' );
	[ chooser, sheet ].forEach( function ( el ) {
		if ( ! el ) {
			return;
		}
		el.addEventListener( 'click', function ( e ) {
			if ( e.target === el || ( e.target.closest && e.target.closest( '.vacanampro-close' ) ) ) {
				close( el );
			}
		} );
	} );
	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key ) {
			close( chooser );
			close( sheet );
		}
	} );
	document.addEventListener( 'click', function ( e ) {
		if ( e.target.closest && e.target.closest( '.vacanampro-open-chooser' ) ) {
			open( chooser );
		}
		if ( e.target.closest && e.target.closest( '.vacanampro-fab' ) ) {
			open( sheet );
		}
	} );

	/* First visit: suggest the browser's language, or ask. */
	function browserLanguage() {
		var wanted = ( navigator.languages && navigator.languages.length ? navigator.languages : [ navigator.language || '' ] ).map( function ( l ) {
			return String( l ).toLowerCase();
		} );
		for ( var i = 0; i < wanted.length; i++ ) {
			var code = wanted[ i ];
			var base = code.split( '-' )[ 0 ];
			var hit = '';
			Object.keys( langs ).forEach( function ( s ) {
				var l = langs[ s ];
				var hl = String( l.hreflang ).toLowerCase();
				var loc = String( l.locale ).toLowerCase().replace( '_', '-' );
				if ( ! hit && ( hl === code || loc === code ) ) {
					hit = s;
				}
			} );
			if ( ! hit ) {
				Object.keys( langs ).forEach( function ( s ) {
					if ( ! hit && String( langs[ s ].locale ).toLowerCase().split( '_' )[ 0 ] === base ) {
						hit = s;
					}
				} );
			}
			if ( hit ) {
				return hit;
			}
		}
		return '';
	}
	function suggest( slug ) {
		var to = langs[ slug ];
		var here = langs[ cfg.current ];
		if ( ! to || ! here ) {
			return;
		}
		var bar = document.createElement( 'div' );
		bar.className = 'vacanampro-suggest';
		bar.setAttribute( 'role', 'dialog' );
		bar.setAttribute( 'aria-live', 'polite' );
		bar.setAttribute( 'lang', to.hreflang );
		bar.setAttribute( 'dir', 'auto' );
		var text = document.createElement( 'p' );
		text.textContent = cfg.i18n.suggest.replace( '%s', to.name );
		var go = document.createElement( 'a' );
		go.className = 'vacanampro-suggest-go';
		go.href = to.url;
		go.setAttribute( 'data-vacanam-lang', slug );
		go.textContent = cfg.i18n.switch.replace( '%s', to.name );
		var stay = document.createElement( 'button' );
		stay.type = 'button';
		stay.className = 'vacanampro-suggest-stay';
		stay.textContent = cfg.i18n.stay.replace( '%s', here.name );
		stay.addEventListener( 'click', function () {
			store.set( 'vacanampro_seen', '1' );
			track( 'suggest_dismissed', { to: slug } );
			bar.remove();
		} );
		go.addEventListener( 'click', function () {
			track( 'suggest_accepted', { to: slug } );
		} );
		bar.appendChild( text );
		bar.appendChild( go );
		bar.appendChild( stay );
		document.body.appendChild( bar );
		window.requestAnimationFrame( function () {
			bar.classList.add( 'is-open' );
		} );
	}
	if ( cfg.firstVisit && ! store.get( 'vacanampro_seen' ) ) {
		if ( 'chooser' === cfg.firstVisit ) {
			open( chooser );
		} else {
			var wanted = browserLanguage();
			if ( wanted && wanted !== cfg.current && langs[ wanted ] ) {
				suggest( wanted );
			} else {
				store.set( 'vacanampro_seen', '1' );
			}
		}
	}

	/* Dynamic content: requests from scripts carry the page's language. */
	if ( cfg.dynamic ) {
		var addLang = function ( url ) {
			try {
				var u = new URL( url, window.location.href );
				if ( u.host !== window.location.host || u.searchParams.has( 'lang' ) ) {
					return url;
				}
				if ( /admin-ajax\.php|\/wp-json\/|[?&]rest_route=|[?&]wc-ajax=/.test( u.href ) ) {
					u.searchParams.set( 'lang', cfg.current );
					return u.toString();
				}
			} catch ( e ) {}
			return url;
		};
		if ( window.fetch ) {
			var nativeFetch = window.fetch;
			window.fetch = function ( input, init ) {
				if ( 'string' === typeof input ) {
					input = addLang( input );
				} else if ( input && input.url && window.Request && input instanceof window.Request ) {
					var changed = addLang( input.url );
					if ( changed !== input.url ) {
						input = new window.Request( changed, input );
					}
				}
				return nativeFetch.call( this, input, init );
			};
		}
		var nativeOpen = window.XMLHttpRequest && window.XMLHttpRequest.prototype.open;
		if ( nativeOpen ) {
			window.XMLHttpRequest.prototype.open = function ( method, url ) {
				var args = Array.prototype.slice.call( arguments );
				args[ 1 ] = addLang( url );
				return nativeOpen.apply( this, args );
			};
		}
	}

	/* Page view, and leaving right after a language change. */
	if ( cfg.track ) {
		track( 'view' );
		var switched = parseInt( session.get( 'vacanampro_switched' ) || '0', 10 );
		if ( switched && Date.now() - switched < 60000 ) {
			session.del( 'vacanampro_switched' );
			session.del( 'vacanampro_internal' );
			var arrived = Date.now();
			window.addEventListener( 'pagehide', function () {
				if ( Date.now() - arrived < 15000 && ! session.get( 'vacanampro_internal' ) ) {
					track( 'abandon' );
				}
			} );
		}
	}
}() );
