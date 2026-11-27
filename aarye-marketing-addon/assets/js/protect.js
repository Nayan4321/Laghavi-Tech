/* Aarye Marketing Addon – security question above the submit button.
   The website prints the question into supported forms itself; this script only fetches a fresh question
   (so cached pages get new ones), adds the question to forms the website could not reach, and asks a new
   question after each send. Forms keep working without it. */
( function () {
	'use strict';

	var cfg = window.aaryeProtect || {};

	// "plugin:id" of a form, as Aarye names forms in its settings.
	function formKey( form ) {
		var input = function ( name ) {
			var el = form.querySelector( 'input[name="' + name + '"]' );
			return el ? el.value : '';
		};
		if ( form.classList.contains( 'elementor-form' ) ) {
			return 'elementor:' + input( 'form_id' );
		}
		if ( form.classList.contains( 'wpcf7-form' ) ) {
			return 'cf7:' + input( '_wpcf7' );
		}
		if ( form.classList.contains( 'wpforms-form' ) ) {
			return 'wpforms:' + ( form.getAttribute( 'data-formid' ) || '' );
		}
		if ( /^gform_\d+$/.test( form.id ) ) {
			return 'gravity:' + form.id.replace( 'gform_', '' );
		}
		if ( form.classList.contains( 'frm-fluent-form' ) || form.hasAttribute( 'data-form_id' ) ) {
			return 'fluentform:' + ( form.getAttribute( 'data-form_id' ) || '' );
		}
		if ( form.classList.contains( 'forminator-custom-form' ) ) {
			return 'forminator:' + ( form.getAttribute( 'data-form-id' ) || '' );
		}
		if ( form.classList.contains( 'frm-show-form' ) ) {
			return 'formidable:' + input( 'form_id' );
		}
		return '';
	}

	function protectedForm( key ) {
		if ( ! key || /:$/.test( key ) ) {
			return false;
		}
		return ( !! cfg.all && cfg.all !== '0' ) || ( cfg.forms || [] ).indexOf( key ) !== -1;
	}

	function submitButton( form ) {
		var buttons = form.querySelectorAll( 'button[type="submit"], input[type="submit"], button:not([type])' );
		for ( var i = buttons.length - 1; i >= 0; i-- ) {
			if ( ! buttons[ i ].closest( '.aarye-q' ) ) {
				return buttons[ i ];
			}
		}
		return null;
	}

	// Fresh question from the website; if it can't be reached, the printed question stays.
	function load( box ) {
		var text = box.querySelector( '.aarye-q__text' );
		var token = box.querySelector( 'input[name="aarye_q_token"]' );
		var answer = box.querySelector( 'input[name="aarye_q_answer"]' );
		if ( ! cfg.url || ! window.fetch ) {
			return;
		}
		fetch( cfg.url, { credentials: 'same-origin', cache: 'no-store' } )
			.then( function ( r ) {
				return r.ok ? r.json() : null;
			} )
			.then( function ( q ) {
				if ( q && q.text && q.token ) {
					answer.value = '';
					text.textContent = q.text;
					token.value = q.token;
				}
			} )
			.catch( function () {} );
	}

	function wire( box, form ) {
		if ( box.getAttribute( 'data-aarye-wired' ) ) {
			return;
		}
		box.setAttribute( 'data-aarye-wired', '1' );
		var again = box.querySelector( '.aarye-q__new' );
		if ( again ) {
			again.hidden = false;
			again.addEventListener( 'click', function () {
				load( box );
			} );
		}
		if ( box.getAttribute( 'data-aarye-q' ) === 'server' ) {
			// A cached page may carry an old printed question: ask a fresh one now.
			load( box );
		}
		if ( ! form ) {
			return;
		}
		// Fresh questions work once: after each send (good or bad) ask a new one.
		form.addEventListener( 'submit', function () {
			setTimeout( function () {
				load( box );
			}, 1500 );
		} );
		var cf7 = form.closest( '.wpcf7' );
		if ( cf7 ) {
			[ 'wpcf7mailsent', 'wpcf7invalid', 'wpcf7spam', 'wpcf7mailfailed' ].forEach( function ( type ) {
				cf7.addEventListener( type, function () {
					load( box );
				} );
			} );
		}
	}

	var count = 0;

	function build() {
		count++;
		var id = 'aarye-q-j' + count;
		var box = document.createElement( 'div' );
		box.className = 'aarye-q';
		box.setAttribute( 'data-aarye-q', 'script' );
		box.style.cssText = 'display:block;clear:both;width:100%;flex-basis:100%;margin:0 0 12px';
		box.innerHTML =
			'<label class="aarye-q__label" style="display:block;margin:0 0 6px" for="' + id + '"><span class="aarye-q__title"></span> <span class="aarye-q__text" style="font-weight:600">…</span></label>' +
			'<span class="aarye-q__row" style="display:flex;gap:8px;align-items:center"><input type="text" id="' + id + '" name="aarye_q_answer" class="aarye-q__answer" inputmode="numeric" autocomplete="off" required aria-required="true" style="width:7em;max-width:100%" />' +
			'<button type="button" class="aarye-q__new">&#8635;</button></span>' +
			'<input type="hidden" name="aarye_q_token" value="" />';
		box.querySelector( '.aarye-q__title' ).textContent = ( cfg.label || '' ) + ':';
		var again = box.querySelector( '.aarye-q__new' );
		again.setAttribute( 'aria-label', cfg.retry || '' );
		again.setAttribute( 'title', cfg.retry || '' );
		return box;
	}

	function add( form ) {
		var existing = form.querySelector( '.aarye-q' );
		if ( existing ) {
			wire( existing, form );
			return;
		}
		if ( ! protectedForm( formKey( form ) ) ) {
			return;
		}
		var button = submitButton( form );
		if ( ! button ) {
			return;
		}
		var box = build();
		var anchor = button;
		var wrap = button.closest( '.elementor-field-type-submit, .wpforms-submit-container, .gform_footer, .gform-footer, .ff_submit_btn_wrapper, .forminator-row-last, .frm_submit' );
		if ( wrap && form.contains( wrap ) ) {
			anchor = wrap;
		}
		anchor.parentNode.insertBefore( box, anchor );
		wire( box, form );
		load( box );
	}

	function scan() {
		Array.prototype.forEach.call( document.querySelectorAll( 'form' ), add );
		// Printed boxes outside a <form> element (forms built by scripts).
		Array.prototype.forEach.call( document.querySelectorAll( '.aarye-q' ), function ( box ) {
			wire( box, box.closest( 'form' ) );
		} );
	}

	function start() {
		scan();
		// Forms added later (popups, tabs, page builders).
		if ( window.MutationObserver ) {
			var timer;
			new MutationObserver( function () {
				clearTimeout( timer );
				timer = setTimeout( scan, 300 );
			} ).observe( document.body, { childList: true, subtree: true } );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
