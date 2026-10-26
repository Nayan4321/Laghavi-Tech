/* Vacanam Language Switcher – dropdowns close on outside click / Escape, open on hover if set; select boxes go to the chosen language. */
( function () {
	'use strict';
	function dropdowns() {
		return document.querySelectorAll( '.vacanam-dropdown' );
	}
	document.addEventListener( 'click', function ( e ) {
		Array.prototype.forEach.call( dropdowns(), function ( d ) {
			if ( d.open && ! d.contains( e.target ) ) {
				d.open = false;
			}
		} );
	} );
	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key ) {
			Array.prototype.forEach.call( dropdowns(), function ( d ) {
				if ( d.open ) {
					d.open = false;
					d.querySelector( 'summary' ).focus();
				}
			} );
		}
	} );
	function setup() {
		Array.prototype.forEach.call( dropdowns(), function ( d ) {
			if ( 'hover' !== d.getAttribute( 'data-open-on' ) || d.vacanamReady || ! window.matchMedia( '(hover: hover)' ).matches ) {
				return;
			}
			d.vacanamReady = true;
			var timer;
			d.addEventListener( 'mouseenter', function () {
				clearTimeout( timer );
				d.open = true;
			} );
			d.addEventListener( 'mouseleave', function () {
				timer = setTimeout( function () {
					d.open = false;
				}, 250 );
			} );
		} );
	}
	document.addEventListener( 'change', function ( e ) {
		if ( e.target && e.target.classList && e.target.classList.contains( 'vacanam-select' ) && e.target.value ) {
			window.location.href = e.target.value;
		}
	} );
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', setup );
	} else {
		setup();
	}
	// Elementor editor and other builders add switchers later.
	window.addEventListener( 'load', setup );
}() );
