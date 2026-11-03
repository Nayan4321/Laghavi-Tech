/* Vacanam Language Switcher – admin screens. */
( function () {
	'use strict';
	var cfg = window.vacanamAdmin || {};
	var i18n = cfg.i18n || {};

	function $all( sel, root ) {
		return Array.prototype.slice.call( ( root || document ).querySelectorAll( sel ) );
	}
	function fmt( text ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var n = 0;
		return String( text || '' ).replace( /%(\d\$)?d/g, function ( m, pos ) {
			return pos ? args[ parseInt( pos, 10 ) - 1 ] : args[ n++ ];
		} );
	}

	// Confirmations.
	document.addEventListener( 'click', function ( e ) {
		var el = e.target.closest && e.target.closest( '[data-confirm]' );
		if ( el && ! window.confirm( el.getAttribute( 'data-confirm' ) ) ) {
			e.preventDefault();
		}
	} );

	// Add a language: fill the fields from the chosen language.
	$all( '.vacanam-locale-pick' ).forEach( function ( select ) {
		select.addEventListener( 'change', function () {
			var form = select.form;
			var info = cfg.catalog && cfg.catalog[ select.value ];
			if ( ! info ) {
				return;
			}
			form.elements.name.value = info[ 1 ];
			if ( form.elements.slug ) {
				form.elements.slug.placeholder = info[ 0 ];
			}
			form.elements.flag.value = info[ 3 ];
			form.elements.rtl.checked = !! info[ 4 ];
			form.elements.flag.dispatchEvent( new Event( 'change' ) );
		} );
	} );
	// Flag picker: a searchable grid of flags with country names, built from the select.
	$all( '.vacanam-flag-pick' ).forEach( function ( select ) {
		var box = select.parentNode.querySelector( '.vacanam-flag-preview' );
		var picker = document.createElement( 'div' );
		picker.className = 'vacanam-flag-picker';
		var current = document.createElement( 'button' );
		current.type = 'button';
		current.className = 'button vacanam-flag-current';
		var panel = document.createElement( 'div' );
		panel.className = 'vacanam-flag-panel';
		panel.hidden = true;
		var search = document.createElement( 'input' );
		search.type = 'search';
		search.placeholder = i18n.flagSearch || '';
		search.className = 'vacanam-flag-search';
		var grid = document.createElement( 'div' );
		grid.className = 'vacanam-flag-grid';
		panel.appendChild( search );
		panel.appendChild( grid );
		Array.prototype.forEach.call( select.options, function ( opt ) {
			var b = document.createElement( 'button' );
			b.type = 'button';
			b.className = 'vacanam-flag-option';
			b.setAttribute( 'data-value', opt.value );
			b.setAttribute( 'data-search', ( opt.text + ' ' + opt.value ).toLowerCase() );
			b.title = opt.text;
			b.innerHTML = ( opt.value ? '<img alt="" width="28" height="21" loading="lazy" src="' + cfg.flags + opt.value + '.svg" />' : '<span class="vacanam-flag-blank"></span>' ) + '<span></span>';
			b.lastChild.textContent = opt.text;
			b.addEventListener( 'click', function () {
				select.value = opt.value;
				select.dispatchEvent( new Event( 'change' ) );
				panel.hidden = true;
			} );
			grid.appendChild( b );
		} );
		function paint() {
			var opt = select.options[ select.selectedIndex ];
			current.innerHTML = ( select.value ? '<img alt="" width="24" height="18" src="' + cfg.flags + select.value + '.svg" /> ' : '' ) + '<span></span> <span class="dashicons dashicons-arrow-down-alt2"></span>';
			current.querySelector( 'span' ).textContent = opt ? opt.text : ( i18n.flagNone || '' );
			$all( '.vacanam-flag-option', grid ).forEach( function ( b ) {
				b.classList.toggle( 'is-current', b.getAttribute( 'data-value' ) === select.value );
			} );
		}
		current.addEventListener( 'click', function () {
			panel.hidden = ! panel.hidden;
			if ( ! panel.hidden ) {
				search.value = '';
				search.dispatchEvent( new Event( 'input' ) );
				search.focus();
				var on = grid.querySelector( '.is-current' );
				if ( on ) {
					on.scrollIntoView( { block: 'nearest' } );
				}
			}
		} );
		search.addEventListener( 'input', function () {
			var q = search.value.trim().toLowerCase();
			$all( '.vacanam-flag-option', grid ).forEach( function ( b ) {
				b.hidden = q && b.getAttribute( 'data-search' ).indexOf( q ) === -1;
			} );
		} );
		search.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key ) {
				panel.hidden = true;
				current.focus();
			} else if ( 'Enter' === e.key ) {
				e.preventDefault();
				var first = grid.querySelector( '.vacanam-flag-option:not([hidden])' );
				if ( first ) {
					first.click();
				}
			}
		} );
		document.addEventListener( 'click', function ( e ) {
			if ( ! picker.contains( e.target ) ) {
				panel.hidden = true;
			}
		} );
		select.addEventListener( 'change', function () {
			paint();
			if ( box ) {
				box.innerHTML = '';
			}
		} );
		picker.appendChild( current );
		picker.appendChild( panel );
		select.style.display = 'none';
		select.parentNode.insertBefore( picker, select );
		if ( box ) {
			box.innerHTML = '';
		}
		paint();
	} );

	// Import from another translation plugin, step by step.
	$all( '.vacanam-import-run' ).forEach( function ( box ) {
		var button = box.querySelector( 'button' );
		var progress = box.querySelector( '.vacanam-progress' );
		var text = box.querySelector( '.vacanam-progress-text' );
		var bar = box.querySelector( '.vacanam-bar span' );
		var result = box.querySelector( '.vacanam-import-result' );
		var width = 5;
		function call( start ) {
			var data = new FormData();
			data.append( 'action', 'vacanam_import' );
			data.append( 'nonce', cfg.importNonce );
			data.append( 'source', box.getAttribute( 'data-source' ) );
			if ( start ) {
				data.append( 'start', '1' );
			}
			fetch( cfg.ajax, { method: 'POST', body: data, credentials: 'same-origin' } )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( r ) {
					if ( ! r || ! r.success ) {
						throw new Error( r && r.data && r.data.message ? r.data.message : i18n.importFail );
					}
					text.textContent = r.data.text;
					width = Math.min( 95, width + 10 );
					bar.style.width = ( r.data.finished ? 100 : width ) + '%';
					if ( r.data.finished ) {
						progress.hidden = true;
						result.innerHTML = r.data.html;
						result.hidden = false;
						button.disabled = false;
					} else {
						call( false );
					}
				} )
				.catch( function ( e ) {
					text.textContent = e.message || i18n.importFail;
					button.disabled = false;
				} );
		}
		button.addEventListener( 'click', function () {
			button.disabled = true;
			result.hidden = true;
			progress.hidden = false;
			width = 5;
			bar.style.width = '5%';
			text.textContent = '';
			call( true );
		} );
	} );

	// Fonts: live preview of the chosen fonts.
	$all( '.vacanam-fonts-table tbody tr' ).forEach( function ( row ) {
		var selects = $all( '.vacanam-font-select', row );
		var preview = row.querySelector( '.vacanam-font-preview' );
		if ( 2 !== selects.length || ! preview ) {
			return;
		}
		function css( select ) {
			var opt = select.options[ select.selectedIndex ];
			return opt ? opt.getAttribute( 'data-css' ) || '' : '';
		}
		function paint() {
			var body = css( selects[ 0 ] );
			preview.querySelector( '.vacanam-font-preview-b' ).style.fontFamily = body;
			preview.querySelector( '.vacanam-font-preview-h' ).style.fontFamily = css( selects[ 1 ] ) || body;
		}
		selects.forEach( function ( s ) {
			s.addEventListener( 'change', paint );
		} );
		paint();
	} );

	// Flag: your own image from the media library.
	$all( '.vacanam-flag-own' ).forEach( function ( row ) {
		var input = row.querySelector( 'input[name="flag_img"]' );
		var view = row.querySelector( '.vacanam-flag-own-preview' );
		var remove = row.querySelector( '.vacanam-flag-own-remove' );
		var frame;
		row.querySelector( '.vacanam-flag-upload' ).addEventListener( 'click', function () {
			if ( ! window.wp || ! window.wp.media ) {
				return;
			}
			if ( ! frame ) {
				frame = window.wp.media( { title: i18n.flagImage, button: { text: i18n.flagUse }, library: { type: 'image' }, multiple: false } );
				frame.on( 'select', function () {
					var file = frame.state().get( 'selection' ).first().toJSON();
					input.value = file.url;
					view.innerHTML = '<img alt="" width="20" height="15" src="' + file.url + '" />';
					remove.hidden = false;
				} );
			}
			frame.open();
		} );
		remove.addEventListener( 'click', function () {
			input.value = '';
			view.innerHTML = '';
			remove.hidden = true;
		} );
	} );

	// Copy the site, step by step.
	$all( '.vacanam-copy-form' ).forEach( function ( form ) {
		var running = false;
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			if ( running ) {
				return;
			}
			var data = new FormData( form );
			if ( ! data.getAll( 'langs[]' ).length || ( ! data.getAll( 'post_types[]' ).length && ! data.get( 'terms' ) && ! data.get( 'menus' ) ) ) {
				window.alert( i18n.noChoice );
				return;
			}
			var box = form.querySelector( '.vacanam-progress' );
			var bar = box.querySelector( '.vacanam-bar span' );
			var text = box.querySelector( '.vacanam-progress-text' );
			var errors = box.querySelector( '.vacanam-progress-errors' );
			var button = form.querySelector( '.vacanam-copy-start' );
			box.hidden = false;
			errors.innerHTML = '';
			button.disabled = true;
			running = true;
			data.append( 'action', 'vacanam_job' );
			data.append( 'nonce', cfg.jobNonce );
			data.append( 'start', '1' );
			function step( body ) {
				fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function ( r ) {
						return r.json();
					} )
					.then( function ( res ) {
						if ( ! res || ! res.success ) {
							throw new Error( 'fail' );
						}
						var d = res.data;
						var pct = d.total ? Math.round( d.done * 100 / d.total ) : 100;
						bar.style.width = pct + '%';
						text.textContent = d.finished ? fmt( i18n.done, d.made ) : fmt( i18n.working, d.done, d.total );
						errors.innerHTML = '';
						( d.errors || [] ).forEach( function ( msg ) {
							var li = document.createElement( 'li' );
							li.textContent = msg;
							errors.appendChild( li );
						} );
						if ( d.finished ) {
							running = false;
							button.disabled = false;
							box.classList.add( 'is-done' );
							return;
						}
						var next = new FormData();
						next.append( 'action', 'vacanam_job' );
						next.append( 'nonce', cfg.jobNonce );
						step( next );
					} )
					.catch( function () {
						running = false;
						button.disabled = false;
						text.textContent = i18n.error;
					} );
			}
			step( data );
		} );
		window.addEventListener( 'beforeunload', function ( e ) {
			if ( running ) {
				e.preventDefault();
				e.returnValue = i18n.leave;
			}
		} );
	} );

	// Switcher look: live preview.
	var form = document.querySelector( '.vacanam-switcher-form' );
	if ( form ) {
		var vars = { text: '--vacanam-text', background: '--vacanam-bg', hover_text: '--vacanam-hover-text', hover_bg: '--vacanam-hover-bg', active_text: '--vacanam-active-text', active_bg: '--vacanam-active-bg', border: '--vacanam-border' };
		var update = function () {
			var get = function ( key ) {
				var el = form.querySelector( '[data-preview="' + key + '"]' );
				if ( ! el ) {
					return '';
				}
				return 'checkbox' === el.type ? el.checked : el.value;
			};
			var style = get( 'style' );
			$all( '.vacanam-preview' ).forEach( function ( p ) {
				p.hidden = p.getAttribute( 'data-style' ) !== style;
			} );
			$all( '.vacanam-preview .vacanam-switcher' ).forEach( function ( sw ) {
				Object.keys( vars ).forEach( function ( key ) {
					var v = get( key );
					if ( v ) {
						sw.style.setProperty( vars[ key ], v );
					} else {
						sw.style.removeProperty( vars[ key ] );
					}
				} );
				sw.style.setProperty( '--vacanam-radius', ( get( 'radius' ) || 0 ) + 'px' );
				sw.style.setProperty( '--vacanam-flag', ( get( 'flag_size' ) || 20 ) + 'px' );
				var font = parseInt( get( 'font_size' ), 10 );
				if ( font ) {
					sw.style.setProperty( '--vacanam-font', font + 'px' );
				} else {
					sw.style.removeProperty( '--vacanam-font' );
				}
				sw.classList.remove( 'vacanam-flag-rect', 'vacanam-flag-round', 'vacanam-flag-square' );
				sw.classList.add( 'vacanam-flag-' + get( 'flag_shape' ) );
				var names = get( 'names' );
				$all( '.vacanam-flag', sw ).forEach( function ( f ) {
					f.style.display = get( 'flags' ) ? '' : 'none';
				} );
				$all( '.vacanam-name', sw ).forEach( function ( n ) {
					n.textContent = 'code' === names ? n.getAttribute( 'data-code' ) : n.getAttribute( 'data-name' );
					n.style.display = 'none' === names && get( 'flags' ) ? 'none' : '';
				} );
			} );
		};
		var marks = function () {
			$all( '.vacanam-color-pick', form ).forEach( function ( pick ) {
				pick.classList.toggle( 'is-empty', '' === document.getElementById( pick.getAttribute( 'data-for' ) ).value );
			} );
		};
		form.addEventListener( 'input', marks );
		form.addEventListener( 'click', function () {
			setTimeout( marks, 0 );
		} );
		marks();
		form.addEventListener( 'input', update );
		form.addEventListener( 'change', update );
		$all( '.vacanam-color-pick', form ).forEach( function ( pick ) {
			pick.addEventListener( 'input', function () {
				var text = document.getElementById( pick.getAttribute( 'data-for' ) );
				text.value = pick.value;
				update();
			} );
		} );
		$all( '.vacanam-color-text', form ).forEach( function ( text ) {
			text.addEventListener( 'input', function () {
				var pick = form.querySelector( '[data-for="' + text.id + '"]' );
				if ( /^#[0-9a-f]{6}$/i.test( text.value ) ) {
					pick.value = text.value;
				}
			} );
		} );
		$all( '.vacanam-color-clear', form ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				document.getElementById( btn.getAttribute( 'data-for' ) ).value = '';
				update();
			} );
		} );
		update();
	}

	// Languages box: connect an existing page.
	$all( '.vacanam-link-field' ).forEach( function ( field ) {
		var input = field.querySelector( '.vacanam-link-search' );
		var hidden = field.querySelector( '.vacanam-link-id' );
		var list = field.querySelector( '.vacanam-link-results' );
		var timer;
		input.addEventListener( 'input', function () {
			clearTimeout( timer );
			if ( '' === input.value.trim() ) {
				hidden.value = '';
				list.hidden = true;
				return;
			}
			timer = setTimeout( function () {
				var url = cfg.ajax + '?action=vacanam_search&nonce=' + encodeURIComponent( cfg.searchNonce ) + '&lang=' + encodeURIComponent( field.getAttribute( 'data-lang' ) ) + '&type=' + encodeURIComponent( field.getAttribute( 'data-type' ) ) + '&exclude=' + encodeURIComponent( field.getAttribute( 'data-exclude' ) ) + '&q=' + encodeURIComponent( input.value );
				fetch( url, { credentials: 'same-origin' } ).then( function ( r ) {
					return r.json();
				} ).then( function ( res ) {
					list.innerHTML = '';
					( res && res.data ? res.data : [] ).forEach( function ( item ) {
						var li = document.createElement( 'li' );
						var b = document.createElement( 'button' );
						b.type = 'button';
						b.textContent = item.title + ( item.note ? ' (' + item.note + ')' : '' );
						b.addEventListener( 'click', function () {
							hidden.value = item.id;
							input.value = item.title;
							list.hidden = true;
						} );
						li.appendChild( b );
						list.appendChild( li );
					} );
					list.hidden = ! list.children.length;
				} );
			}, 250 );
		} );
		input.addEventListener( 'search', function () {
			if ( '' === input.value ) {
				hidden.value = '';
			}
		} );
	} );
}() );
