/* Nyasah Social Feed – admin: source fields per network, live preview, copy shortcode. */
( function () {
	'use strict';
	var cfg = window.nyasahAdmin || {};

	// Copy shortcodes.
	document.addEventListener( 'click', function ( e ) {
		var code = e.target.closest( '.nyasah-copy' );
		if ( ! code || ! navigator.clipboard ) {
			return;
		}
		navigator.clipboard.writeText( code.textContent ).then( function () {
			code.classList.add( 'is-copied' );
			setTimeout( function () { code.classList.remove( 'is-copied' ); }, 1200 );
		} );
	} );
	document.addEventListener( 'click', function ( e ) {
		var del = e.target.closest( '.nyasah-delete' );
		if ( del && ! window.confirm( del.textContent + '?' ) ) {
			e.preventDefault();
		}
	} );

	var form = document.getElementById( 'nyasah-form' );
	if ( ! form ) {
		return;
	}
	var source = document.getElementById( 'nyasah-source' );
	var help = form.querySelector( '.nyasah-source-help' );
	var valueRow = form.querySelector( '.nyasah-value-row' );
	var value = document.getElementById( 'nyasah-value' );
	var accountRow = form.querySelector( '.nyasah-account-row' );
	var account = document.getElementById( 'nyasah-account' );
	var preview = document.getElementById( 'nyasah-preview' );

	function network() {
		var checked = form.querySelector( 'input[name="s[network]"]:checked' );
		return checked ? checked.value : 'youtube';
	}

	function fillSources() {
		var net = cfg.networks[ network() ];
		var current = source.value || source.getAttribute( 'data-current' );
		source.innerHTML = '';
		Object.keys( net.sources ).forEach( function ( key ) {
			var o = document.createElement( 'option' );
			o.value = key;
			o.textContent = net.sources[ key ].label;
			o.selected = key === current;
			source.appendChild( o );
		} );
		source.setAttribute( 'data-current', '' );
		updateSource();
	}

	function updateSource() {
		var net = cfg.networks[ network() ];
		var src = net.sources[ source.value ] || {};
		help.textContent = src.help || '';
		valueRow.hidden = ! src.input;
		valueRow.querySelector( 'label' ).textContent = src.input || '';
		value.rows = 'urls' === source.value ? 5 : 1;
		accountRow.hidden = ! src.account;
		[].forEach.call( account.options, function ( o ) {
			o.hidden = !! o.value && o.getAttribute( 'data-network' ) !== network();
		} );
		if ( account.selectedOptions[ 0 ] && account.selectedOptions[ 0 ].hidden ) {
			account.value = '';
		}
	}

	// Colours: a picked colour fills the hidden value; "Reset" empties it.
	form.querySelectorAll( 'input[type="color"]' ).forEach( function ( input ) {
		input.addEventListener( 'input', function () {
			document.getElementById( input.getAttribute( 'data-target' ) ).value = input.value;
			queue();
		} );
	} );
	form.querySelectorAll( '.nyasah-color__clear' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			document.getElementById( b.getAttribute( 'data-target' ) ).value = '';
			queue();
		} );
	} );

	var timer = null;
	var request = 0;
	function queue() {
		clearTimeout( timer );
		timer = setTimeout( refresh, 600 );
	}
	function refresh() {
		var data = new FormData( form );
		data.set( 'action', 'nyasah_preview' );
		data.set( 'nonce', cfg.nonce );
		var mine = ++request;
		preview.classList.add( 'is-loading' );
		if ( ! preview.innerHTML ) {
			preview.textContent = cfg.loading || '';
		}
		window.fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: data } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( mine !== request ) {
					return;
				}
				preview.classList.remove( 'is-loading' );
				preview.innerHTML = res && res.success ? res.data.html : '';
				if ( window.nyasahInit ) {
					window.nyasahInit();
				}
			} )
			.catch( function () { preview.classList.remove( 'is-loading' ); } );
	}

	form.addEventListener( 'change', function ( e ) {
		if ( 's[network]' === e.target.name ) {
			fillSources();
		} else if ( e.target === source ) {
			updateSource();
		}
		queue();
	} );
	form.addEventListener( 'input', function ( e ) {
		if ( 'number' === e.target.type || 'text' === e.target.type || 'TEXTAREA' === e.target.tagName ) {
			queue();
		}
	} );

	// Device widths and right-to-left preview.
	document.querySelectorAll( '.nyasah-devices .button' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			document.querySelectorAll( '.nyasah-devices .button' ).forEach( function ( x ) { x.classList.toggle( 'is-on', x === b ); } );
			preview.style.maxWidth = b.getAttribute( 'data-width' ) ? b.getAttribute( 'data-width' ) + 'px' : '';
		} );
	} );
	var rtl = document.getElementById( 'nyasah-rtl' );
	if ( rtl ) {
		rtl.addEventListener( 'change', function () {
			preview.dir = rtl.checked ? 'rtl' : '';
		} );
	}

	fillSources();
	refresh();
}() );
