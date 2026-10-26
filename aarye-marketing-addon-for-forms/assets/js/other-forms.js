/*
 * Aarye Marketing Addon for Forms – catches forms that no supported form plugin handles (theme forms such
 * as Houzez property enquiries, page-builder and hand-made forms). When such a form with an email or phone
 * field is sent, its fields go to Aarye, which saves them with the visitor's source.
 */
( function () {
	'use strict';
	var cfg = window.aaryeOther;
	if ( ! cfg || ! cfg.url || ! window.fetch || ! window.JSON ) {
		return;
	}
	var SKIP_NAME = /nonce|token|security|captcha|recaptcha|turnstile|honeypot|^action$|referer|^_|password|pass$|card|cvv|cvc|iban/i;
	var GENERIC = /^(form|row|clearfix|container|wrap|wrapper|inner|form-horizontal|form-inline|needs-validation|was-validated|ajax-form|contact-form)$/i;
	var last = {};

	function skip( form ) {
		if ( ! form || form.getAttribute( 'data-aarye-skip' ) ) {
			return true;
		}
		try {
			if ( form.matches( cfg.skip ) || ( cfg.exclude && form.matches( cfg.exclude ) ) ) {
				return true;
			}
		} catch ( e ) {}
		// Logins, payments and searches are never captured.
		return !! form.querySelector( 'input[type="password"], input[autocomplete^="cc-"], input[name*="card" i]' );
	}

	function labelOf( el ) {
		var text = '';
		if ( el.labels && el.labels.length ) {
			text = el.labels[ 0 ].textContent;
		}
		if ( ! text && el.id ) {
			var l = document.querySelector( 'label[for="' + el.id.replace( /"/g, '' ) + '"]' );
			text = l ? l.textContent : '';
		}
		if ( ! text ) {
			text = el.getAttribute( 'aria-label' ) || el.getAttribute( 'placeholder' ) || el.getAttribute( 'data-label' ) || '';
		}
		return text.replace( /\s+/g, ' ' ).replace( /\*/g, '' ).trim().slice( 0, 100 );
	}

	function fields( form ) {
		var out = [];
		var seen = {};
		Array.prototype.forEach.call( form.elements, function ( el ) {
			var name = el.name || el.id || '';
			var type = ( el.type || el.tagName || '' ).toLowerCase();
			if ( ! name || el.disabled || SKIP_NAME.test( name ) || /^(submit|button|image|reset|file|password)$/.test( type ) ) {
				return;
			}
			var value = '';
			if ( 'checkbox' === type || 'radio' === type ) {
				if ( ! el.checked ) {
					return;
				}
				value = el.value && 'on' !== el.value ? el.value : labelOf( el ) || 'Yes';
			} else if ( 'select-one' === type || 'select-multiple' === type ) {
				value = Array.prototype.filter.call( el.options, function ( o ) { return o.selected && o.value; } ).map( function ( o ) { return o.text.trim(); } ).join( ', ' );
			} else {
				value = String( el.value || '' ).trim();
			}
			// Hidden security tokens (WordPress nonces are 10 hex characters) are not form answers.
			if ( ! value || ( 'hidden' === type && ( value.length > 200 || /^[a-f0-9]{10}$/i.test( value ) ) ) ) {
				return;
			}
			if ( seen[ name ] ) {
				seen[ name ].v += ', ' + value; // Checkbox groups.
				return;
			}
			var field = { n: name.slice( 0, 100 ), l: 'hidden' === type ? '' : labelOf( el ), t: type.slice( 0, 20 ), v: value.slice( 0, 5000 ) };
			seen[ name ] = field;
			out.push( field );
		} );
		return out.slice( 0, 60 );
	}

	function hasContact( list ) {
		return list.some( function ( f ) {
			return ( /@/.test( f.v ) && /\S+@\S+\.\S+/.test( f.v ) ) || ( /(phone|mobile|tel|whats|cell)/i.test( f.n + ' ' + f.t + ' ' + f.l ) && ( f.v.replace( /\D/g, '' ).length >= 6 ) );
		} );
	}

	function identify( form ) {
		var id = form.getAttribute( 'id' ) || form.getAttribute( 'name' ) || '';
		if ( ! id || /^\d+$/.test( id ) ) {
			id = Array.prototype.filter.call( form.classList, function ( c ) { return ! GENERIC.test( c ) && c.length > 2; } )[ 0 ] || 'form';
		}
		var name = form.getAttribute( 'aria-label' ) || form.getAttribute( 'data-title' ) || form.getAttribute( 'title' ) || '';
		if ( ! name ) {
			var h = form.querySelector( 'h1, h2, h3, h4, h5, .title, .widget-title, legend' );
			if ( ! h ) {
				var box = form.parentElement;
				for ( var i = 0; i < 3 && box && ! h; i++, box = box.parentElement ) {
					h = box.querySelector( 'h2, h3, h4, h5, .title, .widget-title' );
				}
			}
			name = h ? h.textContent : '';
		}
		if ( ! name ) {
			var btn = form.querySelector( 'button, input[type="submit"]' );
			name = btn ? ( btn.textContent || btn.value || '' ) : '';
		}
		return { id: id.replace( /[^\w-]/g, '' ).slice( 0, 60 ) || 'form', name: name.replace( /\s+/g, ' ' ).trim().slice( 0, 120 ) };
	}

	function report( form ) {
		if ( skip( form ) ) {
			return;
		}
		var list = fields( form );
		if ( ! list.length || ! hasContact( list ) ) {
			return;
		}
		var who = identify( form );
		var body = JSON.stringify( { f: who.id, n: who.name, p: location.href, fields: list } );
		var now = Date.now();
		if ( last[ who.id ] && last[ who.id ].body === body && now - last[ who.id ].t < 15000 ) {
			return; // Same click reported twice (button and submit event).
		}
		last[ who.id ] = { body: body, t: now };
		fetch( cfg.url, { method: 'POST', credentials: 'same-origin', keepalive: true, headers: { 'Content-Type': 'application/json' }, body: body } ).catch( function () {} );
	}

	// A real submit…
	document.addEventListener( 'submit', function ( e ) {
		report( e.target );
	}, true );
	// …or a "Send" button handled by the theme's own script (AJAX forms often never submit).
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target && e.target.closest ? e.target.closest( 'button, input[type="submit"], input[type="button"], a[class*="btn"], a[class*="button"], [role="button"]' ) : null;
		if ( ! btn ) {
			return;
		}
		var type = ( btn.getAttribute( 'type' ) || '' ).toLowerCase();
		var text = ( btn.textContent || btn.value || '' ) + ' ' + ( btn.className || '' ) + ' ' + ( btn.id || '' );
		var form = btn.form || btn.closest( 'form' );
		if ( ! form || 'reset' === type ) {
			return;
		}
		if ( 'submit' === type || ( 'BUTTON' === btn.tagName && ! type ) || /send|submit|request|contact|enquir|inquir|book|schedule|message|get|apply|call|register|subscribe|continue/i.test( text ) ) {
			// Let the theme's script read the form first; report a moment later.
			setTimeout( function () { report( form ); }, 300 );
		}
	}, true );
}() );
