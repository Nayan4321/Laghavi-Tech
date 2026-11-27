/* Aarye Pro – Google reCAPTCHA or Cloudflare Turnstile above the submit button of chosen forms. */
( function () {
	'use strict';

	var cfg = window.aaryeproCaptcha || {};

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

	function api() {
		return cfg.type === 'turnstile' ? window.turnstile : window.grecaptcha;
	}

	function add( form ) {
		var key = formKey( form );
		if ( form.querySelector( '.aaryepro-captcha' ) || ! key || /:$/.test( key ) || ! ( cfg.all || ( cfg.forms || [] ).indexOf( key ) !== -1 ) ) {
			return;
		}
		var buttons = form.querySelectorAll( 'button[type="submit"], input[type="submit"], button:not([type])' );
		if ( ! buttons.length ) {
			return;
		}
		var button = buttons[ buttons.length - 1 ];
		var anchor = button.closest( '.elementor-field-type-submit, .wpforms-submit-container, .gform_footer, .gform-footer, .ff_submit_btn_wrapper, .forminator-row-last, .frm_submit, p' );
		anchor = anchor && form.contains( anchor ) ? anchor : button;
		// Below Aarye's question when both are on.
		var question = form.querySelector( '.aarye-q' );
		var box = document.createElement( 'div' );
		box.className = 'aaryepro-captcha';
		box.style.margin = '0 0 12px';
		box.style.flexBasis = '100%';
		anchor.parentNode.insertBefore( box, question && question.nextSibling === anchor ? anchor : anchor );
		var id = api().render( box, { sitekey: cfg.key, hl: cfg.lang, language: cfg.lang } );
		var reset = function () {
			try {
				api().reset( id );
			} catch ( e ) {}
		};
		// A token works once: get a new one after every send.
		form.addEventListener( 'submit', function () {
			setTimeout( reset, 1500 );
		} );
		var cf7 = form.closest( '.wpcf7' );
		if ( cf7 ) {
			[ 'wpcf7mailsent', 'wpcf7invalid', 'wpcf7spam', 'wpcf7mailfailed' ].forEach( function ( type ) {
				cf7.addEventListener( type, reset );
			} );
		}
	}

	function scan() {
		if ( ! api() || typeof api().render !== 'function' ) {
			return false;
		}
		Array.prototype.forEach.call( document.querySelectorAll( 'form' ), add );
		return true;
	}

	function start() {
		if ( ! scan() ) {
			setTimeout( start, 300 );
			return;
		}
		if ( window.MutationObserver ) {
			var timer;
			new MutationObserver( function () {
				clearTimeout( timer );
				timer = setTimeout( scan, 300 );
			} ).observe( document.body, { childList: true, subtree: true } );
		}
	}

	start();
}() );
