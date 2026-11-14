/* Vacanam Pro: JavaScript API (window.Vacanam) and vacanam:* events. */
( function () {
	'use strict';
	var data = window.vacanamData || { current: '', default: '', languages: {} };

	function emit( name, detail, cancelable ) {
		var event;
		try {
			event = new CustomEvent( 'vacanam:' + name, { detail: detail, cancelable: !! cancelable } );
		} catch ( e ) {
			event = document.createEvent( 'CustomEvent' );
			event.initCustomEvent( 'vacanam:' + name, false, !! cancelable, detail );
		}
		return document.dispatchEvent( event );
	}

	function slugOf( url ) {
		var found = '';
		Object.keys( data.languages ).forEach( function ( slug ) {
			if ( data.languages[ slug ].url === url ) {
				found = slug;
			}
		} );
		return found;
	}

	var api = {
		current: data.current,
		defaultLanguage: data.default,
		languages: data.languages,
		rest: data.rest,
		/** Address of this page in a language. */
		urlFor: function ( slug ) {
			return data.languages[ slug ] ? data.languages[ slug ].url : '';
		},
		/** Is this page translated into a language? */
		isTranslated: function ( slug ) {
			return !! ( data.languages[ slug ] && data.languages[ slug ].translated );
		},
		/** Go to this page in a language (vacanam:beforeSwitch can stop it). */
		switchTo: function ( slug ) {
			var url = api.urlFor( slug );
			if ( url && emit( 'beforeSwitch', { from: data.current, to: slug, url: url }, true ) ) {
				window.location.href = url;
			}
		},
		/** Listen to an event: ready, beforeSwitch, languageChanged. */
		on: function ( name, callback ) {
			document.addEventListener( 'vacanam:' + name, function ( e ) {
				callback( e.detail, e );
			} );
		}
	};
	window.Vacanam = api;

	// Clicks on switcher links.
	document.addEventListener( 'click', function ( e ) {
		var link = e.target && e.target.closest ? e.target.closest( '.vacanam-switcher a[href], a.vacanam-link[href]' ) : null;
		if ( ! link || e.defaultPrevented ) {
			return;
		}
		var to = slugOf( link.href ) || link.getAttribute( 'hreflang' ) || '';
		if ( ! emit( 'beforeSwitch', { from: data.current, to: to, url: link.href }, true ) ) {
			e.preventDefault();
		}
	}, true );

	// Switcher select boxes: runs before the switcher's own handler, which it can stop.
	document.addEventListener( 'change', function ( e ) {
		var select = e.target;
		if ( ! select || ! select.classList || ! select.classList.contains( 'vacanam-select' ) ) {
			return;
		}
		if ( ! emit( 'beforeSwitch', { from: data.current, to: slugOf( select.value ), url: select.value }, true ) ) {
			e.stopImmediatePropagation();
			Array.prototype.forEach.call( select.options, function ( o ) {
				o.selected = o.defaultSelected;
			} );
		}
	}, true );

	function ready() {
		emit( 'ready', { current: data.current, languages: data.languages } );
		var last = '';
		try {
			last = window.sessionStorage.getItem( 'vacanam_lang' ) || '';
			window.sessionStorage.setItem( 'vacanam_lang', data.current );
		} catch ( e ) {}
		if ( last && last !== data.current ) {
			emit( 'languageChanged', { from: last, to: data.current } );
		}
	}
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', ready );
	} else {
		ready();
	}
}() );
