/* Sarala Login Page Pro – CAPTCHA with the security question as backup. */
( function () {
	var boxes = [];

	function questionOf( box ) {
		var form = box.closest( 'form' );
		return form ? form.querySelector( '.saralopa-captcha' ) : null;
	}
	function setQuestion( box, show ) {
		var q = questionOf( box );
		var mode = box.querySelector( 'input[name="slpp_captcha_mode"]' );
		if ( mode ) {
			mode.value = show ? 'fallback' : 'captcha';
		}
		if ( ! q ) {
			return;
		}
		q.style.display = show ? '' : 'none';
		q.querySelectorAll( 'input[type="text"]' ).forEach( function ( input ) {
			input.required = !! show;
		} );
	}
	function fallbackAll() {
		boxes.forEach( function ( box ) {
			box.dataset.failed = '1';
			setQuestion( box, true );
		} );
	}

	window.slppCaptchaOk = function () {
		boxes.forEach( function ( box ) {
			if ( '1' !== box.dataset.failed ) {
				setQuestion( box, false );
			}
		} );
	};
	window.slppCaptchaFailed = fallbackAll;
	window.slppCaptchaExpired = function () {};

	function loaded( service ) {
		return ( 'turnstile' === service && window.turnstile ) || ( 'recaptcha' === service && window.grecaptcha && window.grecaptcha.render ) || ( 'hcaptcha' === service && window.hcaptcha );
	}

	function start() {
		boxes = Array.prototype.slice.call( document.querySelectorAll( '.slpp-captcha' ) );
		if ( ! boxes.length ) {
			return;
		}
		boxes.forEach( function ( box ) {
			setQuestion( box, false );
		} );
		var service = boxes[ 0 ].dataset.service;
		var waited = 0;
		var timer = setInterval( function () {
			waited += 500;
			var ok = loaded( service ) && boxes.every( function ( box ) {
				return box.querySelector( 'iframe' );
			} );
			if ( ok ) {
				clearInterval( timer );
			} else if ( waited >= 8000 ) {
				// The CAPTCHA did not appear: use the security question instead.
				clearInterval( timer );
				fallbackAll();
			}
		}, 500 );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
