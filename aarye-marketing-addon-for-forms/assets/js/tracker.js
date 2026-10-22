/* Aarye Marketing Addon for Forms – remembers how a visitor arrived (ad click IDs, UTM tags, referrer)
   in a first-party cookie, so the form entry they submit later can say which ad or source it came from.
   Nothing is sent anywhere by this script. */
( function () {
	'use strict';

	var cfg = window.aaryeTrack || {};
	var COOKIE = 'aarye_attr';
	var VISITOR = 'aarye_vid';
	var DAYS = parseInt( cfg.days, 10 ) || 90;
	var keys = ( cfg.params || [] ).map( function ( k ) { return k.toLowerCase(); } );

	function allowed() {
		if ( ! cfg.consent ) {
			return true;
		}
		// WP Consent API: only store after the visitor accepted marketing cookies.
		return typeof window.wp_has_consent === 'function' && window.wp_has_consent( 'marketing' );
	}

	function readCookie( name ) {
		var m = document.cookie.match( new RegExp( '(?:^|; )' + name + '=([^;]*)' ) );
		return m ? decodeURIComponent( m[ 1 ] ) : '';
	}

	function writeCookie( name, value ) {
		var d = new Date();
		d.setTime( d.getTime() + DAYS * 864e5 );
		document.cookie = name + '=' + encodeURIComponent( value ) + '; expires=' + d.toUTCString() + '; path=/; SameSite=Lax' + ( location.protocol === 'https:' ? '; Secure' : '' );
	}

	function cut( s, n ) {
		s = String( s || '' );
		return s.length > n ? s.slice( 0, n ) : s;
	}

	function params() {
		var out = {};
		var found = false;
		try {
			new URLSearchParams( location.search ).forEach( function ( value, key ) {
				var k = key.toLowerCase();
				if ( k.indexOf( 'utm_' ) === 0 || keys.indexOf( k ) !== -1 ) {
					out[ k ] = cut( value, 150 );
					found = true;
				}
			} );
		} catch ( e ) {}
		return found ? out : null;
	}

	function externalReferrer() {
		if ( ! document.referrer ) {
			return '';
		}
		try {
			var host = new URL( document.referrer ).hostname.replace( /^www\./, '' );
			if ( host === location.hostname.replace( /^www\./, '' ) ) {
				return '';
			}
		} catch ( e ) {
			return '';
		}
		return cut( document.referrer, 300 );
	}

	function run() {
		if ( ! allowed() ) {
			return;
		}
		var data = {};
		try {
			data = JSON.parse( readCookie( COOKIE ) || '{}' ) || {};
		} catch ( e ) {
			data = {};
		}

		var p = params();
		var ref = externalReferrer();
		var now = Math.floor( Date.now() / 1000 );
		var newSession = false;
		try {
			newSession = ! sessionStorage.getItem( 'aarye_s' );
			sessionStorage.setItem( 'aarye_s', '1' );
		} catch ( e ) {
			newSession = ! data.l;
		}

		if ( p || ref || newSession ) {
			var touch = { t: now, u: cut( location.pathname + location.search, 300 ), r: ref, p: p || {} };
			if ( ! data.f ) {
				data.f = touch;
			}
			// Last non-direct visit wins: a later direct visit doesn't hide the ad that brought them.
			if ( p || ref || ! data.l ) {
				data.l = touch;
			}
			if ( newSession ) {
				data.n = ( parseInt( data.n, 10 ) || 0 ) + 1;
			}
		}
		data.pg = cut( location.pathname + location.search, 300 );
		data.lg = cut( document.documentElement.lang || '', 20 );
		writeCookie( COOKIE, JSON.stringify( data ) );

		if ( ! readCookie( VISITOR ) ) {
			var id = '';
			for ( var i = 0; i < 20; i++ ) {
				id += 'abcdefghijklmnopqrstuvwxyz0123456789'.charAt( Math.floor( Math.random() * 36 ) );
			}
			writeCookie( VISITOR, id );
		}
	}

	run();
	// Store as soon as consent is given on this page.
	document.addEventListener( 'wp_listen_for_consent_change', function ( e ) {
		if ( e.detail && e.detail.marketing === 'allow' ) {
			run();
		}
	} );
}() );
