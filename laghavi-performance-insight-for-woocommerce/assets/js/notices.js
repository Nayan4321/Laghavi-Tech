/* Performance Insight — review notice buttons and the deactivation feedback form. */
( function () {
	'use strict';
	var C = window.LGPI_NOTICES || {};

	function post( data ) {
		var b = new URLSearchParams();
		Object.keys( data ).forEach( function ( k ) { b.append( k, data[ k ] ); } );
		return fetch( C.ajax, { method: 'POST', credentials: 'same-origin', body: b } );
	}

	// Review notice.
	var n = document.querySelector( '.lgpi-review' );
	if ( n ) {
		var answered = false;
		var send = function ( act ) {
			if ( answered ) { return; }
			answered = true;
			post( { action: 'lgpi_review', nonce: n.getAttribute( 'data-nonce' ), act: act } );
		};
		n.addEventListener( 'click', function ( e ) {
			var t = e.target.closest( '[data-act], .notice-dismiss' );
			if ( ! t ) { return; }
			send( t.classList.contains( 'notice-dismiss' ) ? 'later' : t.getAttribute( 'data-act' ) );
			if ( ! t.classList.contains( 'notice-dismiss' ) ) { n.remove(); }
		} );
	}

	// Deactivation form.
	var m = document.getElementById( 'lgpi-deact' );
	if ( ! m || ! C.plugin ) { return; }
	var link = document.querySelector( 'tr[data-plugin="' + C.plugin + '"] .deactivate a' );
	if ( ! link ) { return; }
	var f = m.querySelector( 'form' );
	var submit = f.querySelector( '[data-act="submit"]' );
	var href = link.getAttribute( 'href' );
	var last = null;

	function open( e ) {
		e.preventDefault();
		last = document.activeElement;
		m.hidden = false;
		document.body.classList.add( 'lgpi-deact-open' );
		var r = f.querySelector( 'input[name="reason"]' );
		if ( r ) { r.focus(); }
	}
	function close() {
		m.hidden = true;
		document.body.classList.remove( 'lgpi-deact-open' );
		if ( last ) { last.focus(); }
	}
	function go() { window.location.href = href; }

	link.addEventListener( 'click', open );
	m.addEventListener( 'click', function ( e ) {
		if ( e.target === m ) { close(); return; }
		var t = e.target.closest( '[data-act]' );
		if ( ! t ) { return; }
		var a = t.getAttribute( 'data-act' );
		if ( 'close' === a ) { close(); }
		if ( 'skip' === a ) { go(); }
	} );
	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && ! m.hidden ) { close(); }
	} );
	f.addEventListener( 'change', function ( e ) {
		if ( 'reason' !== e.target.name ) { return; }
		f.querySelectorAll( 'li' ).forEach( function ( li ) {
			var on = li.contains( e.target );
			li.querySelectorAll( 'textarea, .lgpi-deact-tip' ).forEach( function ( x ) { x.hidden = ! on; } );
			if ( on ) { var ta = li.querySelector( 'textarea' ); if ( ta ) { ta.focus(); } }
		} );
		submit.disabled = false;
	} );
	f.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		var r = f.querySelector( 'input[name="reason"]:checked' );
		if ( ! r ) { return; }
		var ta = f.querySelector( 'textarea[name="details_' + r.value + '"]' );
		submit.disabled = true;
		submit.textContent += '…';
		var done = false;
		var finish = function () { if ( ! done ) { done = true; go(); } };
		setTimeout( finish, 3000 );   // never hold up deactivation
		post( {
			action: 'lgpi_deactivate_feedback',
			nonce: C.nonce,
			reason: r.value,
			details: ta ? ta.value : '',
			email: f.email.value,
			site: f.site.checked ? '1' : ''
		} ).then( finish, finish );
	} );
}() );
