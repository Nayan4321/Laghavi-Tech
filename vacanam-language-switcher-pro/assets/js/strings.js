/* Vacanam Pro: String translation screen. */
( function () {
	'use strict';
	var cfg = window.vacanamproStrings;
	if ( ! cfg ) {
		return;
	}
	var t = cfg.i18n;

	function post( data ) {
		var body = new FormData();
		body.append( 'nonce', cfg.nonce );
		Object.keys( data ).forEach( function ( k ) {
			body.append( k, data[ k ] );
		} );
		return fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( r ) {
			return r.json();
		} );
	}

	function fmt( s ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;
		return s.replace( /%(\d+\$)?[sd]/g, function ( m, pos ) {
			return pos ? args[ parseInt( pos, 10 ) - 1 ] : args[ i++ ];
		} );
	}

	var lang = ( document.querySelector( '.vacanampro-filter select[name="lang"]' ) || {} ).value || '';

	// Save a row when a field is left.
	document.querySelectorAll( '.vacanampro-string-list tr[data-hash]' ).forEach( function ( row ) {
		var fields = row.querySelectorAll( '.vacanampro-tr' );
		var note = row.querySelector( '.vacanampro-saved' );
		var last = Array.prototype.map.call( fields, function ( f ) {
			return f.value;
		} ).join( '\u0004' );
		fields.forEach( function ( field ) {
			field.addEventListener( 'change', save );
			field.addEventListener( 'blur', save );
		} );
		function save() {
			var now = Array.prototype.map.call( fields, function ( f ) {
				return f.value;
			} ).join( '\u0004' );
			if ( now === last ) {
				return;
			}
			last = now;
			var data = { action: 'vacanampro_strings_save', hash: row.getAttribute( 'data-hash' ), lang: lang, translation: '', plural: '' };
			fields.forEach( function ( f ) {
				data[ f.getAttribute( 'data-field' ) ] = f.value;
			} );
			note.textContent = t.saving;
			note.className = 'vacanampro-saved';
			post( data ).then( function ( r ) {
				note.textContent = r && r.success ? '✓ ' + t.saved : t.error;
				note.className = 'vacanampro-saved ' + ( r && r.success ? 'is-ok' : 'is-error' );
			} ).catch( function () {
				note.textContent = t.error;
				note.className = 'vacanampro-saved is-error';
			} );
		}
	} );

	// Find texts in the chosen themes and plugins.
	var scan = document.getElementById( 'vacanampro-scan' );
	if ( scan ) {
		scan.addEventListener( 'click', function () {
			var status = document.getElementById( 'vacanampro-scan-status' );
			var queue = Array.prototype.filter.call( document.querySelectorAll( '.vacanampro-components input' ), function ( c ) {
				return c.checked;
			} );
			var found = 0;
			scan.disabled = true;
			function next( offset ) {
				if ( ! queue.length ) {
					status.textContent = fmt( t.scanned, found );
					window.location.reload();
					return;
				}
				var box = queue[ 0 ];
				post( { action: 'vacanampro_strings_scan', component: box.value, offset: offset } ).then( function ( r ) {
					if ( ! r || ! r.success ) {
						status.textContent = r && r.data && r.data.message ? r.data.message : t.error;
						queue.shift();
						return next( 0 );
					}
					found += r.data.found;
					status.textContent = fmt( t.scanning, box.getAttribute( 'data-name' ), r.data.next, r.data.total );
					if ( r.data.next >= r.data.total ) {
						queue.shift();
						return next( 0 );
					}
					next( r.data.next );
				} ).catch( function () {
					status.textContent = t.error;
					scan.disabled = false;
				} );
			}
			next( 0 );
		} );
	}

	// Translate the untranslated texts of the list with AI.
	var ai = document.getElementById( 'vacanampro-strings-ai' );
	if ( ai ) {
		ai.addEventListener( 'click', function () {
			var status = document.getElementById( 'vacanampro-strings-ai-status' );
			var total = 0;
			var skip = 0;
			ai.disabled = true;
			function step() {
				post( { action: 'vacanampro_strings_ai', lang: ai.dataset.lang, group: ai.dataset.group, s: ai.dataset.s, skip: skip } ).then( function ( r ) {
					if ( ! r || ! r.success ) {
						status.textContent = r && r.data && r.data.message ? r.data.message : t.error;
						ai.disabled = false;
						return;
					}
					total += r.data.done;
					// Texts with nothing to translate stay in the list: step over them.
					if ( ! r.data.done ) {
						skip++;
					}
					status.textContent = fmt( t.aiBusy, total, r.data.left );
					if ( r.data.left > skip * 40 ) {
						step();
					} else {
						status.textContent = fmt( t.aiDone, total );
						window.location.reload();
					}
				} ).catch( function () {
					status.textContent = t.error;
					ai.disabled = false;
				} );
			}
			step();
		} );
	}
} )();
