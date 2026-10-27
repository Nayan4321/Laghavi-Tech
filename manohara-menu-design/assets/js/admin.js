/* Manohara Menu Design – the design editor: section tabs, fields that fit the template, live preview. */
( function () {
	'use strict';
	var cfg = window.manoharaAdmin || {};
	var each = function ( list, fn ) {
		Array.prototype.forEach.call( list, fn );
	};

	// Confirm links (delete) work on every screen.
	document.addEventListener( 'click', function ( e ) {
		var el = e.target.closest && e.target.closest( '[data-confirm]' );
		if ( el && ! window.confirm( el.getAttribute( 'data-confirm' ) ) ) {
			e.preventDefault();
		}
	} );

	var form = document.getElementById( 'mnh-editor' );
	if ( ! form ) {
		return;
	}
	var templates = cfg.templates || {};
	var presets = cfg.presets || {};
	var i18n = cfg.i18n || {};
	var KEYS = {
		colours: [ 'bar_bg', 'bar_text', 'panel_bg', 'text', 'text_hover', 'accent', 'accent_text', 'bubble', 'divider', 'secondary_bg', 'secondary_text', 'backdrop' ],
		typography: [ 'font_family', 'font_custom', 'font_size', 'sub_font_size', 'font_weight', 'text_transform', 'letter_spacing', 'item_gap', 'radius' ],
		animation: [ 'duration', 'easing' ],
	};
	var dirty = false;
	var previewDevice = 'mobile';

	function $( sel, root ) {
		return ( root || form ).querySelector( sel );
	}
	function $$( sel, root ) {
		return ( root || form ).querySelectorAll( sel );
	}
	function field( name ) {
		return form.elements.namedItem( name );
	}
	function value( name ) {
		var el = field( name );
		if ( ! el ) {
			return '';
		}
		if ( el instanceof RadioNodeList ) {
			return el.value;
		}
		return 'checkbox' === el.type ? ( el.checked ? el.value : '' ) : el.value;
	}
	function setValue( el, v ) {
		if ( 'checkbox' === el.type ) {
			el.checked = !! v && '0' !== String( v );
		} else if ( 'radio' === el.type ) {
			el.checked = String( v ) === el.value;
		} else {
			el.value = v;
		}
	}
	function setField( name, v ) {
		var el = field( name );
		if ( ! el ) {
			return;
		}
		if ( el instanceof RadioNodeList ) {
			each( el, function ( r ) {
				setValue( r, v );
			} );
		} else {
			setValue( el, v );
		}
	}
	function toast( text ) {
		var t = document.createElement( 'div' );
		t.className = 'mnh-toast';
		t.textContent = text;
		document.body.appendChild( t );
		setTimeout( function () {
			t.classList.add( 'mnh-toast-out' );
		}, 2200 );
		setTimeout( function () {
			t.remove();
		}, 2700 );
	}

	/* Sections. */

	function showSection( key, keepHash ) {
		if ( ! $( '.mnh-section[data-section="' + key + '"]' ) ) {
			key = 'general';
		}
		each( $$( '.mnh-tab' ), function ( tab ) {
			var on = tab.getAttribute( 'data-section' ) === key;
			tab.classList.toggle( 'is-active', on );
			tab.setAttribute( 'aria-current', on ? 'true' : 'false' );
		} );
		each( $$( '.mnh-section' ), function ( sec ) {
			sec.hidden = sec.getAttribute( 'data-section' ) !== key;
		} );
		$( '.mnh-current-section' ).value = key;
		if ( ! keepHash && window.history.replaceState ) {
			window.history.replaceState( null, '', '#' + key );
		}
		if ( [ 'desktop', 'tablet', 'mobile' ].indexOf( key ) > -1 ) {
			setDevice( key );
		}
	}
	each( $$( '.mnh-tab' ), function ( tab ) {
		tab.addEventListener( 'click', function () {
			showSection( tab.getAttribute( 'data-section' ) );
		} );
	} );

	/* Fields that fit the chosen template. */

	function deviceTemplate( device ) {
		return value( 'devices[' + device + '][template]' ) || 'slide-down';
	}
	function refresh( sec ) {
		var device = sec.getAttribute( 'data-device' );
		var p = 'devices[' + device + ']';
		var design = 'design' === value( p + '[mode]' );
		var t = templates[ deviceTemplate( device ) ] || { options: [], kind: 'toggle' };
		var bar = 'toggle' === t.kind && 'bar' === value( p + '[placement]' );
		$( '.mnh-design-fields', sec ).hidden = ! design;
		each( $$( '[data-group]', sec ), function ( el ) {
			var group = el.getAttribute( 'data-group' );
			el.hidden = t.options.indexOf( group ) < 0 || ( 'bar' === group && ! bar );
		} );
		each( $$( '[data-kind]', sec ), function ( el ) {
			el.hidden = el.getAttribute( 'data-kind' ) !== t.kind;
		} );
		var logo = $( '.mnh-logo-mode', sec );
		$( '.mnh-logo-custom', sec ).hidden = ! logo || 'custom' !== logo.value;
		$( '.mnh-override', sec ).hidden = ! value( p + '[style_override]' );
		each( $$( '.mnh-template', sec ), function ( card ) {
			card.classList.toggle( 'is-selected', card.querySelector( 'input' ).checked );
		} );
		each( $$( '.mnh-mode', sec ), function ( card ) {
			card.classList.toggle( 'is-selected', card.querySelector( 'input' ).checked );
		} );
		var tab = $( '.mnh-tab[data-section="' + device + '"]' );
		if ( tab ) {
			tab.classList.toggle( 'is-theme', ! design );
		}
	}
	function refreshAll() {
		each( $$( '.mnh-device-section' ), refresh );
		each( $$( '.mnh-font-family' ), function ( sel ) {
			var custom = sel.closest( '.mnh-type' ).querySelector( '.mnh-font-custom' );
			if ( custom ) {
				custom.hidden = 'custom' !== sel.value;
			}
		} );
	}

	// A new template brings the defaults of the options it has and the previous one did not.
	var lastTemplate = {};
	each( $$( '.mnh-device-section' ), function ( sec ) {
		var device = sec.getAttribute( 'data-device' );
		lastTemplate[ device ] = deviceTemplate( device );
	} );
	function templateChanged( device ) {
		var now = templates[ deviceTemplate( device ) ];
		var before = templates[ lastTemplate[ device ] ] || { options: [] };
		lastTemplate[ device ] = deviceTemplate( device );
		if ( ! now ) {
			return;
		}
		var fresh = {};
		each( $$( '.mnh-device-section[data-device="' + device + '"] [data-group]' ), function ( el ) {
			var group = el.getAttribute( 'data-group' );
			if ( now.options.indexOf( group ) > -1 && before.options.indexOf( group ) < 0 ) {
				each( $$( '[name]', el ), function ( input ) {
					fresh[ input.name ] = true;
				} );
			}
		} );
		Object.keys( now.opts || {} ).forEach( function ( key ) {
			var name = 'devices[' + device + '][opts][' + key + ']';
			if ( fresh[ name ] ) {
				setField( name, now.opts[ key ] );
			}
		} );
	}

	/* Colours, ranges, fonts. */

	form.addEventListener( 'input', function ( e ) {
		var t = e.target;
		if ( t.classList.contains( 'mnh-colour-pick' ) ) {
			var text = t.parentNode.querySelector( '.mnh-colour-text' );
			text.value = t.value;
			t.parentNode.style.setProperty( '--mnh-swatch', t.value );
		} else if ( t.classList.contains( 'mnh-colour-text' ) ) {
			syncColour( t );
		} else if ( 'range' === t.type && t.nextElementSibling && 'OUTPUT' === t.nextElementSibling.tagName ) {
			t.nextElementSibling.textContent = t.value;
		}
	} );
	function syncColour( text ) {
		var pick = text.parentNode.querySelector( '.mnh-colour-pick' );
		if ( /^#[0-9a-f]{6}$/i.test( text.value ) ) {
			pick.value = text.value;
		} else if ( /^#[0-9a-f]{3}$/i.test( text.value ) ) {
			pick.value = '#' + text.value.slice( 1 ).replace( /(.)/g, '$1$1' );
		}
		text.parentNode.style.setProperty( '--mnh-swatch', text.value || 'transparent' );
	}
	each( $$( '.mnh-colour-text' ), syncColour );

	function styleFields( prefix, keys ) {
		var out = [];
		keys.forEach( function ( key ) {
			var el = field( prefix + '[' + key + ']' );
			if ( el ) {
				out.push( [ key, el ] );
			}
		} );
		return out;
	}
	function applyStyle( prefix, preset, keys ) {
		styleFields( prefix, keys ).forEach( function ( pair ) {
			if ( undefined !== preset[ pair[ 0 ] ] ) {
				setValue( pair[ 1 ], preset[ pair[ 0 ] ] );
				if ( pair[ 1 ].classList.contains( 'mnh-colour-text' ) ) {
					syncColour( pair[ 1 ] );
				}
				if ( 'range' === pair[ 1 ].type && pair[ 1 ].nextElementSibling ) {
					pair[ 1 ].nextElementSibling.textContent = pair[ 1 ].value;
				}
			}
		} );
	}
	// The design's own colours follow the mobile template, or the first device with a design.
	function mainTemplate() {
		var order = [ 'mobile', 'tablet', 'desktop' ];
		for ( var i = 0; i < order.length; i++ ) {
			if ( 'design' === value( 'devices[' + order[ i ] + '][mode]' ) ) {
				return deviceTemplate( order[ i ] );
			}
		}
		return 'slide-down';
	}
	each( $$( '.mnh-apply-preset' ), function ( btn ) {
		btn.addEventListener( 'click', function () {
			var device = btn.getAttribute( 'data-device' );
			var preset = presets[ device ? deviceTemplate( device ) : mainTemplate() ] || {};
			applyStyle( btn.getAttribute( 'data-target' ), preset, KEYS.colours );
			changed();
			toast( i18n.colours || '' );
		} );
	} );

	/* Copy one device to another. */

	each( $$( '.mnh-copy' ), function ( btn ) {
		btn.addEventListener( 'click', function () {
			var from = 'devices[' + btn.getAttribute( 'data-from' ) + ']';
			var to = 'devices[' + btn.getAttribute( 'data-to' ) + ']';
			each( form.elements, function ( el ) {
				if ( ! el.name || 0 !== el.name.indexOf( from ) ) {
					return;
				}
				var target = to + el.name.slice( from.length );
				if ( 'radio' === el.type ) {
					if ( el.checked ) {
						setField( target, el.value );
					}
				} else if ( 'checkbox' === el.type ) {
					setField( target, el.checked ? el.value : '' );
				} else {
					setField( target, el.value );
				}
			} );
			lastTemplate[ btn.getAttribute( 'data-to' ) ] = deviceTemplate( btn.getAttribute( 'data-to' ) );
			each( $$( '.mnh-colour-text' ), syncColour );
			changed();
			toast( i18n.copied || '' );
		} );
	} );

	/* Reset. */

	$( '.mnh-reset-section' ).addEventListener( 'click', function () {
		var key = $( '.mnh-current-section' ).value;
		var sec = $( '.mnh-section[data-section="' + key + '"]' );
		if ( KEYS[ key ] ) {
			applyStyle( 'style', presets[ mainTemplate() ] || {}, KEYS[ key ] );
		} else if ( sec && sec.hasAttribute( 'data-device' ) ) {
			var p = 'devices[' + key + ']';
			var t = templates[ deviceTemplate( key ) ] || {};
			each( $$( '[name^="' + p + '[opts]"]', sec ), function ( el ) {
				setValue( el, el.type === 'checkbox' || el.type === 'radio' ? el.defaultChecked : el.defaultValue );
			} );
			Object.keys( t.opts || {} ).forEach( function ( k ) {
				setField( p + '[opts][' + k + ']', t.opts[ k ] );
			} );
			setField( p + '[menu]', '0' );
			setField( p + '[menu2]', '0' );
			setField( p + '[placement]', 'bar' );
			setField( p + '[style_override]', '' );
		} else if ( sec ) {
			// General: back to what is saved.
			each( $$( 'input[name], select[name]', sec ), function ( el ) {
				if ( 'SELECT' === el.tagName ) {
					each( el.options, function ( o ) {
						o.selected = o.defaultSelected;
					} );
				} else {
					setValue( el, el.defaultValue );
				}
			} );
		}
		changed();
	} );
	$( '.mnh-reset-all' ).addEventListener( 'click', function ( e ) {
		if ( ! window.confirm( i18n.resetAll ) ) {
			e.preventDefault();
		}
	} );

	/* Logo from the media library. */

	var frame;
	var logoTarget;
	each( $$( '.mnh-pick-logo' ), function ( btn ) {
		btn.addEventListener( 'click', function () {
			logoTarget = btn.parentNode.querySelector( '.mnh-logo-url' );
			if ( ! window.wp || ! window.wp.media ) {
				return;
			}
			if ( ! frame ) {
				frame = window.wp.media( {
					title: i18n.choose,
					button: { text: i18n.use },
					library: { type: 'image' },
					multiple: false,
				} );
				frame.on( 'select', function () {
					var img = frame.state().get( 'selection' ).first().toJSON();
					logoTarget.value = img.url;
					changed();
				} );
			}
			frame.open();
		} );
	} );

	/* Live preview. */

	var SIZES = {
		mobile: [ 375, 720 ],
		tablet: [ 820, 1020 ],
		desktop: [ 1280, 760 ],
	};
	var previewCol = $( '.mnh-preview-col' );
	var previewFrame = $( '.mnh-preview-frame' );
	var screen = $( '.mnh-preview-screen' );
	var inner = $( '.mnh-preview-inner' );
	var request = 0;
	var timer;

	function fit() {
		var size = SIZES[ previewDevice ];
		var room = previewFrame.clientWidth || size[ 0 ];
		var scale = Math.min( 1, room / size[ 0 ] );
		screen.style.width = size[ 0 ] + 'px';
		screen.style.height = size[ 1 ] + 'px';
		screen.style.transform = 'scale(' + scale + ')';
		screen.style.left = Math.max( 0, Math.round( ( room - size[ 0 ] * scale ) / 2 ) ) + 'px';
		previewFrame.style.height = Math.round( size[ 1 ] * scale ) + 'px';
	}
	function setDevice( device ) {
		if ( ! SIZES[ device ] ) {
			return;
		}
		var same = device === previewDevice && inner.innerHTML;
		previewDevice = device;
		previewFrame.setAttribute( 'data-device', device );
		previewCol.setAttribute( 'data-device', device );
		form.classList.toggle( 'mnh-wide-preview', 'desktop' === device );
		each( $$( '.mnh-preview-device' ), function ( b ) {
			var on = b.getAttribute( 'data-device' ) === device;
			b.classList.toggle( 'is-active', on );
			b.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
		} );
		fit();
		if ( ! same ) {
			preview();
		}
	}
	function preview() {
		var data = new FormData( form );
		data.set( 'action', 'manohara_preview' );
		data.set( 'nonce', cfg.nonce );
		data.set( 'device', previewDevice );
		data.delete( '_wpnonce' );
		var mine = ++request;
		previewCol.classList.add( 'is-loading' );
		fetch( cfg.ajax, { method: 'POST', body: data, credentials: 'same-origin' } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( r ) {
				if ( mine !== request || ! r || ! r.success ) {
					return;
				}
				document.documentElement.classList.remove( 'mnh-lock' );
				inner.innerHTML = r.data.html;
				// Preview links go nowhere.
				each( inner.querySelectorAll( 'a' ), function ( a ) {
					a.setAttribute( 'href', '#' );
				} );
			} )
			.catch( function () {} )
			.then( function () {
				if ( mine === request ) {
					previewCol.classList.remove( 'is-loading' );
				}
			} );
	}
	function schedule() {
		clearTimeout( timer );
		timer = setTimeout( preview, 350 );
	}
	function changed() {
		dirty = true;
		refreshAll();
		schedule();
	}
	inner.addEventListener( 'click', function ( e ) {
		var a = e.target.closest( 'a' );
		if ( a && '#' === a.getAttribute( 'href' ) && ! a.closest( '.mnh-has-sub' ) ) {
			e.preventDefault();
		}
	} );
	inner.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		e.stopPropagation();
	} );
	each( $$( '.mnh-preview-device' ), function ( b ) {
		b.addEventListener( 'click', function () {
			setDevice( b.getAttribute( 'data-device' ) );
		} );
	} );
	window.addEventListener( 'resize', fit );

	form.addEventListener( 'change', function ( e ) {
		var t = e.target;
		var m = t.name && t.name.match( /^devices\[(\w+)\]\[template\]$/ );
		if ( m ) {
			templateChanged( m[ 1 ] );
		}
		changed();
	} );
	form.addEventListener( 'input', function ( e ) {
		if ( e.target.closest( '.mnh-preview-col' ) ) {
			return;
		}
		dirty = true;
		if ( 'text' === e.target.type || 'url' === e.target.type || 'number' === e.target.type || 'range' === e.target.type || 'color' === e.target.type ) {
			schedule();
		}
	} );
	form.addEventListener( 'submit', function () {
		dirty = false;
	} );
	window.addEventListener( 'beforeunload', function ( e ) {
		if ( dirty ) {
			e.preventDefault();
			e.returnValue = i18n.leave || '';
		}
	} );

	refreshAll();
	var start = ( window.location.hash || '' ).replace( '#', '' );
	if ( [ 'desktop', 'tablet', 'mobile' ].indexOf( start ) < 0 ) {
		setDevice( 'mobile' );
	}
	showSection( start || 'general', ! start );
}() );
