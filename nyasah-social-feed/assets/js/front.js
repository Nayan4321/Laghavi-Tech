/* Nyasah Social Feed – popup viewer, carousel and "Load more". Works the same in right-to-left pages. */
( function () {
	'use strict';
	var cfg = window.nyasahFront || {};

	function isRtl( el ) {
		return 'rtl' === window.getComputedStyle( el ).direction;
	}

	function el( tag, cls, html ) {
		var e = document.createElement( tag );
		if ( cls ) {
			e.className = cls;
		}
		if ( html ) {
			e.innerHTML = html;
		}
		return e;
	}

	/* ---------- Popup ---------- */
	var popup, box, media, info, list = [], index = 0, lastFocus = null;

	function buildPopup() {
		popup = el( 'div', 'nyasah-popup' );
		popup.hidden = true;
		popup.setAttribute( 'role', 'dialog' );
		popup.setAttribute( 'aria-modal', 'true' );
		box = el( 'div', 'nyasah-popup__box' );
		media = el( 'div', 'nyasah-popup__media' );
		info = el( 'div', 'nyasah-popup__info' );
		var close = el( 'button', 'nyasah-popup__close', '<span aria-hidden="true">&times;</span>' );
		close.type = 'button';
		close.setAttribute( 'aria-label', cfg.close || 'Close' );
		var prev = el( 'button', 'nyasah-popup__step nyasah-popup__step--prev', '<span aria-hidden="true">&#8249;</span>' );
		var next = el( 'button', 'nyasah-popup__step nyasah-popup__step--next', '<span aria-hidden="true">&#8250;</span>' );
		prev.type = next.type = 'button';
		prev.setAttribute( 'aria-label', cfg.prev || 'Previous' );
		next.setAttribute( 'aria-label', cfg.next || 'Next' );
		box.appendChild( media );
		box.appendChild( info );
		box.appendChild( close );
		box.appendChild( prev );
		box.appendChild( next );
		popup.appendChild( box );
		document.body.appendChild( popup );
		close.addEventListener( 'click', hide );
		prev.addEventListener( 'click', function () { step( -1 ); } );
		next.addEventListener( 'click', function () { step( 1 ); } );
		popup.addEventListener( 'click', function ( e ) {
			if ( e.target === popup ) {
				hide();
			}
		} );
		document.addEventListener( 'keydown', function ( e ) {
			if ( ! popup || popup.hidden ) {
				return;
			}
			var rtl = isRtl( popup );
			if ( 'Escape' === e.key ) {
				hide();
			} else if ( 'ArrowRight' === e.key ) {
				step( rtl ? -1 : 1 );
			} else if ( 'ArrowLeft' === e.key ) {
				step( rtl ? 1 : -1 );
			}
		} );
	}

	function showMedia( d, src ) {
		media.innerHTML = '';
		media.className = 'nyasah-popup__media';
		if ( d.embed && ! src ) {
			var frame = document.createElement( 'iframe' );
			frame.src = d.embed;
			frame.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
			frame.setAttribute( 'allowfullscreen', '' );
			frame.title = d.more || '';
			if ( 'tiktok' === d.net || 'snapchat' === d.net || ( d.embed.indexOf( 'facebook.com/plugins/post' ) > -1 ) ) {
				media.className += ' nyasah-popup__media--tall';
			}
			media.appendChild( frame );
		} else if ( d.video && ! src ) {
			var v = document.createElement( 'video' );
			v.src = d.video;
			v.controls = true;
			v.autoplay = true;
			v.playsInline = true;
			if ( d.image ) {
				v.poster = d.image;
			}
			media.appendChild( v );
		} else {
			var img = document.createElement( 'img' );
			img.src = src || d.image;
			img.alt = d.title || '';
			img.referrerPolicy = 'no-referrer';
			media.appendChild( img );
		}
	}

	function show( i ) {
		if ( ! popup ) {
			buildPopup();
		}
		index = ( i + list.length ) % list.length;
		var link = list[ index ];
		var d;
		try {
			d = JSON.parse( link.getAttribute( 'data-nyasah-popup' ) );
		} catch ( err ) {
			return;
		}
		var feed = link.closest( '.nyasah' );
		if ( feed ) {
			popup.style.setProperty( '--ny-accent', window.getComputedStyle( feed ).getPropertyValue( '--ny-accent' ) );
		}
		showMedia( d );
		info.innerHTML = '';
		if ( d.title ) {
			var t = el( 'h3', 'nyasah-popup__title' );
			t.textContent = d.title;
			t.dir = 'auto';
			info.appendChild( t );
		}
		if ( d.pics && d.pics.length > 1 ) {
			var pics = el( 'div', 'nyasah-popup__pics' );
			d.pics.forEach( function ( src, n ) {
				var b = el( 'button', n ? '' : 'is-on' );
				b.type = 'button';
				var im = document.createElement( 'img' );
				im.src = src;
				im.alt = '';
				im.referrerPolicy = 'no-referrer';
				b.appendChild( im );
				b.addEventListener( 'click', function () {
					[].forEach.call( pics.children, function ( c ) { c.classList.toggle( 'is-on', c === b ); } );
					showMedia( d, src );
				} );
				pics.appendChild( b );
			} );
			info.appendChild( pics );
		}
		if ( d.text ) {
			var p = el( 'p', 'nyasah-popup__text' );
			p.textContent = d.text;
			p.dir = 'auto';
			info.appendChild( p );
		}
		if ( d.date ) {
			var dt = el( 'div', 'nyasah-popup__date' );
			dt.textContent = d.date;
			info.appendChild( dt );
		}
		if ( d.url ) {
			var a = el( 'a', 'nyasah-popup__open' );
			a.href = d.url;
			a.target = '_blank';
			a.rel = 'noopener nofollow';
			a.textContent = d.more || d.url;
			info.appendChild( a );
		}
		popup.querySelectorAll( '.nyasah-popup__step' ).forEach( function ( b ) {
			b.hidden = list.length < 2;
		} );
		if ( popup.hidden ) {
			lastFocus = document.activeElement;
			popup.hidden = false;
			document.documentElement.style.overflow = 'hidden';
			popup.querySelector( '.nyasah-popup__close' ).focus();
		}
	}

	function step( n ) {
		show( index + n );
	}

	function hide() {
		popup.hidden = true;
		media.innerHTML = '';
		document.documentElement.style.overflow = '';
		if ( lastFocus && lastFocus.focus ) {
			lastFocus.focus();
		}
	}

	document.addEventListener( 'click', function ( e ) {
		var link = e.target.closest ? e.target.closest( '[data-nyasah-popup]' ) : null;
		if ( ! link || e.ctrlKey || e.metaKey || e.shiftKey ) {
			return;
		}
		var feed = link.closest( '.nyasah' );
		if ( ! feed || 'popup' !== feed.getAttribute( 'data-click' ) ) {
			return;
		}
		e.preventDefault();
		list = [].slice.call( feed.querySelectorAll( '[data-nyasah-popup]' ) );
		show( list.indexOf( link ) );
	} );

	/* ---------- Carousel ---------- */
	function setupCarousel( feed ) {
		var track = feed.querySelector( '.nyasah__items' );
		var prev = feed.querySelector( '.nyasah__nav--prev' );
		var next = feed.querySelector( '.nyasah__nav--next' );
		if ( ! track || ! prev || ! next || feed.getAttribute( 'data-ny-ready' ) ) {
			return;
		}
		feed.setAttribute( 'data-ny-ready', '1' );
		function move( dir ) {
			var sign = isRtl( track ) ? -1 : 1;
			track.scrollBy( { left: sign * dir * track.clientWidth * 0.9, behavior: 'smooth' } );
		}
		function update() {
			var max = track.scrollWidth - track.clientWidth - 2;
			var pos = Math.abs( track.scrollLeft );
			prev.hidden = pos <= 2;
			next.hidden = pos >= max;
		}
		prev.addEventListener( 'click', function () { move( -1 ); } );
		next.addEventListener( 'click', function () { move( 1 ); } );
		track.addEventListener( 'scroll', update, { passive: true } );
		window.addEventListener( 'resize', update );
		update();
	}

	/* ---------- Load more ---------- */
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '.nyasah__more' ) : null;
		if ( ! btn ) {
			return;
		}
		var feed = btn.closest( '.nyasah' );
		var id = feed && feed.getAttribute( 'data-feed' );
		if ( ! id || ! window.fetch ) {
			return;
		}
		btn.disabled = true;
		var offset = parseInt( btn.getAttribute( 'data-offset' ), 10 ) || 0;
		window.fetch( ( cfg.rest || '/wp-json/nyasah/v1/feed/' ) + id + '?offset=' + offset, { credentials: 'same-origin' } )
			.then( function ( r ) {
				if ( ! r.ok ) {
					throw new Error( r.status );
				}
				return r.json();
			} )
			.then( function ( data ) {
				var items = feed.querySelector( '.nyasah__items' );
				var holder = document.createElement( 'div' );
				holder.innerHTML = data.html || '';
				var added = holder.children.length;
				while ( holder.firstChild ) {
					items.appendChild( holder.firstChild );
				}
				btn.setAttribute( 'data-offset', offset + added );
				btn.disabled = false;
				if ( ! data.more || ! added ) {
					btn.remove();
				}
			} )
			.catch( function () {
				btn.disabled = false;
				btn.title = cfg.error || '';
			} );
	} );

	function ready() {
		document.querySelectorAll( '.nyasah--carousel' ).forEach( setupCarousel );
	}
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', ready );
	} else {
		ready();
	}
	// Feeds added later (editor previews, page builders).
	window.nyasahInit = ready;
}() );
