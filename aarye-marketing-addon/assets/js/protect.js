/* Aarye Marketing Addon – adds a short security question above the submit button of protected forms.
   The question comes from this website (never cached); the answer is checked by the website before the
   form plugin accepts the message. */
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
		return !! cfg.all || ( cfg.forms || [] ).indexOf( key ) !== -1;
	}

	function submitButton( form ) {
		var buttons = form.querySelectorAll( 'button[type="submit"], input[type="submit"], button:not([type])' );
		return buttons.length ? buttons[ buttons.length - 1 ] : null;
	}

	var count = 0;

	function load( box ) {
		var text = box.querySelector( '.aarye-q__text' );
		var token = box.querySelector( 'input[name="aarye_q_token"]' );
		var answer = box.querySelector( 'input[name="aarye_q_answer"]' );
		answer.value = '';
		text.textContent = '…';
		fetch( cfg.url, { credentials: 'same-origin', cache: 'no-store' } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( q ) {
				text.textContent = q.text;
				token.value = q.token;
			} )
			.catch( function () {
				text.textContent = '';
			} );
	}

	function add( form ) {
		if ( form.querySelector( '.aarye-q' ) || ! protectedForm( formKey( form ) ) ) {
			return;
		}
		var button = submitButton( form );
		if ( ! button ) {
			return;
		}
		count++;
		var id = 'aarye-q-' + count;
		var box = document.createElement( 'div' );
		box.className = 'aarye-q';
		box.innerHTML =
			'<label class="aarye-q__label" for="' + id + '"><span class="aarye-q__title"></span> <span class="aarye-q__text"></span></label>' +
			'<span class="aarye-q__row"><input type="text" id="' + id + '" name="aarye_q_answer" class="aarye-q__answer" inputmode="numeric" pattern="[0-9]*" autocomplete="off" required aria-required="true" />' +
			'<button type="button" class="aarye-q__new"></button></span>' +
			'<input type="hidden" name="aarye_q_token" value="" />';
		box.querySelector( '.aarye-q__title' ).textContent = ( cfg.label || '' ) + ':';
		var again = box.querySelector( '.aarye-q__new' );
		again.textContent = '↻';
		again.setAttribute( 'aria-label', cfg.retry || '' );
		again.setAttribute( 'title', cfg.retry || '' );
		again.addEventListener( 'click', function () {
			load( box );
		} );
		// Put it right above the submit button (its wrapper, in form plugins that wrap buttons).
		var anchor = button;
		var wrap = button.closest( '.elementor-field-type-submit, .wpforms-submit-container, .gform_footer, .gform-footer, .ff_submit_btn_wrapper, .forminator-row-last, .frm_submit, p' );
		if ( wrap && form.contains( wrap ) ) {
			anchor = wrap;
		}
		anchor.parentNode.insertBefore( box, anchor );
		load( box );
		// Each question works once: after a send (good or bad) ask a new one.
		form.addEventListener( 'submit', function () {
			setTimeout( function () {
				load( box );
			}, 1500 );
		} );
		[ 'wpcf7mailsent', 'wpcf7invalid', 'wpcf7spam', 'wpcf7mailfailed' ].forEach( function ( type ) {
			form.closest( '.wpcf7' ) && form.closest( '.wpcf7' ).addEventListener( type, function () {
				load( box );
			} );
		} );
	}

	function scan() {
		Array.prototype.forEach.call( document.querySelectorAll( 'form' ), add );
	}

	scan();
	// Forms added later (popups, tabs, page builders).
	if ( window.MutationObserver ) {
		var timer;
		new MutationObserver( function () {
			clearTimeout( timer );
			timer = setTimeout( scan, 300 );
		} ).observe( document.body, { childList: true, subtree: true } );
	}
}() );
