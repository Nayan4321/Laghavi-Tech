/**
 * Vacanam – slider arrows on right-to-left pages.
 *
 * Many slider widgets pin their "previous" arrow with `left` and their "next" arrow with `right`.
 * On right-to-left pages the slider library (Swiper, Slick, Owl…) or the theme's RTL styles add the
 * opposite side too, so the arrow is pinned on both sides and stretches across the whole slider.
 * This finds such stretched arrows and pins each to one side again, mirrored for right to left:
 * "previous" on the right, "next" on the left. Arrows that look right are left untouched.
 */
( function () {
	var root = document.documentElement;
	if ( 'rtl' !== ( root.getAttribute( 'dir' ) || '' ).toLowerCase() ) {
		return;
	}

	var SELECTOR = [
		'.swiper-button-prev', '.swiper-button-next', '.elementor-swiper-button',
		'.slick-prev', '.slick-next', '.slick-arrow',
		'.owl-prev', '.owl-next', '.owl-nav button',
		'.flickity-prev-next-button', '.splide__arrow', '.glide__arrow',
		'[class*="slider"] [class*="prev"]', '[class*="slider"] [class*="next"]',
		'[class*="carousel"] [class*="prev"]', '[class*="carousel"] [class*="next"]',
		'[class*="arrow-prev"]', '[class*="arrow-next"]'
	].join( ',' );

	function isPrev( el ) {
		var cls = ( el.getAttribute( 'class' ) || '' ).toLowerCase();
		return /prev|left/.test( cls ) && ! /next|right/.test( cls );
	}

	// The slider's other arrow, when it is not stretched itself.
	function partner( el, parent ) {
		var list = parent.querySelectorAll( SELECTOR );
		for ( var j = 0; j < list.length; j++ ) {
			var o = list[ j ];
			if ( o !== el && ! o.contains( el ) && ! el.contains( o ) && o.offsetWidth && o.offsetWidth < parent.clientWidth * 0.5 && isPrev( o ) !== isPrev( el ) ) {
				return o;
			}
		}
		return null;
	}

	function fix() {
		var arrows = document.querySelectorAll( SELECTOR );
		for ( var i = 0; i < arrows.length; i++ ) {
			var el = arrows[ i ];
			var cs = window.getComputedStyle( el );
			if ( 'absolute' !== cs.position && 'fixed' !== cs.position ) {
				continue;
			}
			if ( 'auto' === cs.left || 'auto' === cs.right ) {
				continue;
			}
			var parent = el.offsetParent;
			var width = el.offsetWidth;
			if ( ! parent || ! width || width < parent.clientWidth * 0.5 || width < el.offsetHeight * 3 ) {
				continue; // Not stretched.
			}
			// Pinned on both sides and as wide as the slider: keep one side only, opposite the
			// slider's other arrow (or mirrored for right to left: previous right, next left).
			var side = isPrev( el ) ? 'right' : 'left';
			var other = partner( el, parent );
			if ( other ) {
				var box = parent.getBoundingClientRect();
				var ob = other.getBoundingClientRect();
				side = ( ob.left + ob.width / 2 ) > ( box.left + box.width / 2 ) ? 'left' : 'right';
			}
			el.style.setProperty( 'left' === side ? 'right' : 'left', 'auto', 'important' );
			if ( 'auto' === window.getComputedStyle( el )[ side ] ) {
				el.style.setProperty( side, '10px', 'important' );
			}
			if ( el.offsetWidth >= parent.clientWidth * 0.5 ) {
				// Still wide (no width of its own): size it to its icon.
				el.style.setProperty( 'width', 'max-content', 'important' );
			}
		}
	}

	var timer = null;
	function later() {
		clearTimeout( timer );
		timer = setTimeout( fix, 150 );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', later );
	} else {
		later();
	}
	window.addEventListener( 'load', later );
	window.addEventListener( 'resize', later );
	// Sliders often start after the page has loaded (lazy widgets, popups, tabs).
	if ( window.MutationObserver ) {
		var count = 0;
		var observer = new MutationObserver( function () {
			later();
			if ( ++count > 200 ) {
				observer.disconnect();
			}
		} );
		observer.observe( document.body || root, { childList: true, subtree: true } );
		setTimeout( function () {
			observer.disconnect();
		}, 20000 );
	}
}() );
