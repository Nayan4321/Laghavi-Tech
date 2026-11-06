/* Sarala Login widget: AJAX login. */
( function () {
	'use strict';

	var settings = window.slppLoginWidget || {};

	function setCaptcha( form, data ) {
		if ( ! data || ! data.question ) {
			return;
		}
		var question = form.querySelector( '.slpp-lw__question' );
		var token = form.querySelector( 'input[name="saralopa_captcha_token"]' );
		var answer = form.querySelector( 'input[name="saralopa_captcha_answer"]' );
		if ( question ) {
			question.textContent = data.question;
		}
		if ( token ) {
			token.value = data.token;
		}
		if ( answer ) {
			answer.value = '';
		}
	}

	function showMessage( form, html, success ) {
		var box = form.querySelector( '.slpp-lw__message' );
		if ( ! box ) {
			return;
		}
		box.innerHTML = html;
		box.classList.toggle( 'is-success', !! success );
		box.hidden = false;
	}

	function post( data ) {
		return fetch( settings.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	// Pages can come from a cache: fetch a fresh nonce and question the first time the form is used.
	function refresh( form ) {
		if ( form.dataset.fresh ) {
			return Promise.resolve();
		}
		form.dataset.fresh = '1';
		var data = new FormData();
		data.append( 'action', 'slpp_lw_fresh' );
		return post( data ).then( function ( reply ) {
			if ( reply && reply.success ) {
				form.querySelector( 'input[name="slpp_lw_nonce"]' ).value = reply.data.nonce;
				var answer = form.querySelector( 'input[name="saralopa_captcha_answer"]' );
				if ( ! answer || '' === answer.value ) {
					setCaptcha( form, reply.data );
				}
			}
		} ).catch( function () {} );
	}

	function init( form ) {
		form.addEventListener( 'focusin', function () {
			refresh( form );
		}, { once: true } );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			var button = form.querySelector( '.slpp-lw__button' );
			var label = button.textContent;
			button.disabled = true;
			button.textContent = settings.checking;

			refresh( form ).then( function () {
				return post( new FormData( form ) );
			} ).then( function ( reply ) {
				if ( reply && reply.success ) {
					window.location.assign( reply.data.redirect );
					return;
				}
				showMessage( form, reply && reply.data && reply.data.message ? reply.data.message : settings.failed, false );
				setCaptcha( form, reply && reply.data );
				button.disabled = false;
				button.textContent = label;
			} ).catch( function () {
				showMessage( form, settings.failed, false );
				button.disabled = false;
				button.textContent = label;
			} );
		} );
	}

	document.querySelectorAll( '.slpp-lw__form' ).forEach( init );
}() );
