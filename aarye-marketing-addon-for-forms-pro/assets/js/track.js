/*
 * Aarye Pro – form analytics and pages viewed.
 * Counts when a form is seen, when someone starts filling it in, and the last field before they leave
 * without sending. Sends nothing typed in the form.
 */
( function () {
	'use strict';
	var cfg = window.aaryeProTrack;
	if ( ! cfg || ! cfg.url || ! window.JSON ) {
		return;
	}
	var queue = [];
	var timer = null;
	var forms = [];
	var shownAt = Date.now();
	var visible = 0;

	function cookie( name ) {
		var m = document.cookie.match( new RegExp( '(?:^|; )' + name + '=([^;]*)' ) );
		return m ? m[ 1 ] : '';
	}

	function send( body, leaving ) {
		var json = JSON.stringify( body );
		if ( leaving && navigator.sendBeacon ) {
			navigator.sendBeacon( cfg.url, new Blob( [ json ], { type: 'application/json' } ) );
			return;
		}
		if ( window.fetch ) {
			fetch( cfg.url, { method: 'POST', credentials: 'same-origin', keepalive: true, headers: { 'Content-Type': 'application/json' }, body: json } ).catch( function () {} );
		}
	}

	function flush( leaving, extra ) {
		clearTimeout( timer );
		var body = extra || {};
		if ( queue.length ) {
			body.e = queue.splice( 0, 30 );
		}
		if ( body.e || body.pv || body.lv ) {
			send( body, leaving );
		}
	}

	function push( ev ) {
		queue.push( ev );
		clearTimeout( timer );
		timer = setTimeout( function () { flush( false ); }, 1500 );
	}

	function data( el, name ) {
		return el && el.getAttribute ? el.getAttribute( name ) || '' : '';
	}

	function value( form, selector ) {
		var el = form.querySelector( selector );
		return el ? el.value || '' : '';
	}

	// Which plugin and form this is.
	function identify( form ) {
		var c = form.classList;
		var wrap;
		if ( c.contains( 'wpcf7-form' ) ) {
			return [ 'cf7', value( form, 'input[name="_wpcf7"]' ) ];
		}
		if ( c.contains( 'wpforms-form' ) ) {
			return [ 'wpforms', data( form, 'data-formid' ) ];
		}
		if ( c.contains( 'elementor-form' ) ) {
			return [ 'elementor', value( form, 'input[name="form_id"]' ) ];
		}
		if ( c.contains( 'frm-fluent-form' ) ) {
			return [ 'fluentform', data( form, 'data-form_id' ) ];
		}
		if ( /^gform_\d+$/.test( form.id ) ) {
			return [ 'gravity', form.id.replace( 'gform_', '' ) ];
		}
		if ( c.contains( 'forminator-custom-form' ) ) {
			return [ 'forminator', data( form, 'data-form-id' ) ];
		}
		wrap = form.closest( '.nf-form-cont' );
		if ( wrap && /^nf-form-(\d+)-cont$/.test( wrap.id ) ) {
			return [ 'ninja', wrap.id.replace( /^nf-form-(\d+)-cont$/, '$1' ) ];
		}
		if ( c.contains( 'frm-show-form' ) ) {
			return [ 'formidable', value( form, 'input[name="form_id"]' ) ];
		}
		if ( c.contains( 'jet-form-builder' ) ) {
			return [ 'jetformbuilder', data( form, 'data-form-id' ) ];
		}
		wrap = form.closest( '.mf-form-wrapper' );
		if ( wrap ) {
			var id = data( wrap, 'data-form-id' );
			if ( ! id ) {
				try {
					id = String( JSON.parse( data( wrap, 'data-form-config' ) || '{}' ).formId || '' );
				} catch ( e ) {}
			}
			return [ 'metform', id ];
		}
		return null;
	}

	// A readable name for a field: its label, else placeholder or name.
	function label( el ) {
		var text = '';
		if ( el.labels && el.labels.length ) {
			text = el.labels[ 0 ].textContent;
		}
		if ( ! text ) {
			var group = el.closest( '.wpforms-field, .elementor-field-group, .ff-el-group, .gfield, .forminator-field, .nf-field-container, .frm_form_field, .jet-form-builder-row, .mf-input-wrapper' );
			var l = group ? group.querySelector( 'label, legend, .gfield_label, .nf-field-label' ) : null;
			text = l ? l.textContent : '';
		}
		if ( ! text ) {
			text = el.getAttribute( 'aria-label' ) || el.getAttribute( 'placeholder' ) || el.name || '';
		}
		return text.replace( /\s+/g, ' ' ).replace( /\*/g, '' ).trim().slice( 0, 100 );
	}

	function watch( form ) {
		if ( form.getAttribute( 'data-aarye-track' ) ) {
			return;
		}
		var id = identify( form );
		if ( ! id || ! id[ 1 ] ) {
			return;
		}
		form.setAttribute( 'data-aarye-track', '1' );
		var st = { p: id[ 0 ], f: id[ 1 ], viewed: false, started: false, sent: false, field: '', el: form };
		forms.push( st );

		function seen() {
			if ( ! st.viewed ) {
				st.viewed = true;
				push( { t: 'view', p: st.p, f: st.f } );
			}
		}
		if ( window.IntersectionObserver ) {
			var io = new IntersectionObserver( function ( entries ) {
				entries.forEach( function ( en ) {
					if ( en.isIntersecting ) {
						seen();
						io.disconnect();
					}
				} );
			}, { threshold: 0.3 } );
			io.observe( form );
		} else {
			seen();
		}

		function touched( e ) {
			var el = e.target;
			if ( ! el || ! el.name || /^(submit|button|hidden|image|reset)$/.test( el.type ) ) {
				return;
			}
			seen();
			st.sent = false;
			st.field = label( el );
			if ( ! st.started ) {
				st.started = true;
				push( { t: 'start', p: st.p, f: st.f } );
			}
		}
		form.addEventListener( 'input', touched, true );
		form.addEventListener( 'change', touched, true );
		form.addEventListener( 'submit', function () { st.sent = true; }, true );
		form.addEventListener( 'click', function ( e ) {
			var b = e.target && e.target.closest ? e.target.closest( '[type="submit"], .nf-element[type="button"], .jet-form-builder__submit, .forminator-button-submit' ) : null;
			if ( b ) {
				st.sent = true;
			}
		}, true );
	}

	function scan() {
		Array.prototype.forEach.call( document.querySelectorAll( 'form' ), watch );
	}

	function store( body ) {
		try {
			if ( body ) {
				// Leaving can run twice (hidden, then pagehide): add to what is already kept.
				var kept = JSON.parse( window.sessionStorage.getItem( 'aaryeProPending' ) || 'null' );
				if ( kept && kept.e ) {
					body.e = kept.e.concat( body.e || [] ).slice( -30 );
				}
				window.sessionStorage.setItem( 'aaryeProPending', JSON.stringify( body ) );
			} else {
				var saved = window.sessionStorage.getItem( 'aaryeProPending' );
				window.sessionStorage.removeItem( 'aaryeProPending' );
				return saved ? JSON.parse( saved ) : null;
			}
		} catch ( e ) {}
		return null;
	}

	function leave() {
		forms.forEach( function ( st ) {
			if ( st.started && ! st.sent && ! st.done ) {
				st.done = true;
				queue.push( { t: 'abandon', p: st.p, f: st.f, fld: st.field } );
			}
		} );
		var extra = {};
		if ( null !== shownAt ) {
			visible += Date.now() - shownAt;
			shownAt = null;
		}
		if ( cfg.journey && cookie( 'aarye_vid' ) ) {
			extra.lv = { u: location.href, s: Math.round( visible / 1000 ) };
		}
		// Events sent while leaving get a one-time key (k): they are sent twice (beacon, and again from the
		// next page in case the beacon was cut off) but counted once.
		var events = queue.slice( 0, 30 );
		events.forEach( function ( ev ) {
			if ( ! ev.k ) {
				ev.k = Math.random().toString( 36 ).slice( 2 ) + Date.now().toString( 36 );
			}
		} );
		if ( events.length || extra.lv ) {
			// Beacons can be cut off when the next page loads: keep a copy for the next page to send.
			store( { e: events, lv: extra.lv } );
		}
		flush( true, extra );
	}

	function start() {
		var pending = store();
		if ( pending ) {
			send( pending, false );
		}
		scan();
		if ( window.MutationObserver ) {
			var mo = new MutationObserver( scan );
			mo.observe( document.body, { childList: true, subtree: true } );
			setTimeout( function () { mo.disconnect(); }, 30000 );
		}
		// Page view (the tracker sets the visitor cookie first; wait a moment for it).
		setTimeout( function () {
			if ( cfg.journey && cookie( 'aarye_vid' ) ) {
				flush( false, { pv: { u: location.href, t: document.title, r: document.referrer } } );
			}
		}, 300 );
	}

	document.addEventListener( 'visibilitychange', function () {
		if ( 'hidden' === document.visibilityState ) {
			leave();
		} else {
			shownAt = Date.now();
		}
	} );
	window.addEventListener( 'pagehide', leave );

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
