/* Sarala Login Page Pro – registration field builder */
( function () {
	var list = document.getElementById( 'slpp-field-list' );
	var tpl = document.getElementById( 'slpp-field-template' );
	var add = document.getElementById( 'slpp-add-field' );
	if ( ! list || ! tpl || ! add ) {
		return;
	}
	var next = list.children.length + 1000;
	function slug( text ) {
		return text.toLowerCase().normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' ).replace( /[^a-z0-9]+/g, '_' ).replace( /^_+|_+$/g, '' ).slice( 0, 60 );
	}
	function sync( card ) {
		var type = card.querySelector( '.slpp-type' ).value;
		card.setAttribute( 'data-type', type );
		card.querySelector( '.slpp-field-card__type' ).textContent = card.querySelector( '.slpp-type' ).selectedOptions[ 0 ].text;
		card.querySelectorAll( '.slpp-only-options' ).forEach( function ( el ) {
			el.style.display = /^(select|radio|multiselect|checkboxes)$/.test( type ) ? '' : 'none';
		} );
		card.querySelectorAll( '.slpp-only-number' ).forEach( function ( el ) {
			el.style.display = 'number' === type ? '' : 'none';
		} );
	}
	function wire( card ) {
		var label = card.querySelector( '.slpp-label' );
		var key = card.querySelector( '.slpp-key' );
		var title = card.querySelector( '.slpp-field-card__title' );
		var fresh = '' === key.value;
		label.addEventListener( 'input', function () {
			title.textContent = label.value || title.getAttribute( 'data-empty' ) || '';
			if ( fresh ) {
				key.value = slug( label.value );
			}
		} );
		key.addEventListener( 'input', function () {
			fresh = false;
		} );
		card.querySelector( '.slpp-type' ).addEventListener( 'change', function () {
			sync( card );
		} );
		card.querySelector( '.slpp-remove' ).addEventListener( 'click', function () {
			card.parentNode.removeChild( card );
		} );
		card.querySelector( '.slpp-up' ).addEventListener( 'click', function () {
			if ( card.previousElementSibling ) {
				list.insertBefore( card, card.previousElementSibling );
			}
		} );
		card.querySelector( '.slpp-down' ).addEventListener( 'click', function () {
			if ( card.nextElementSibling ) {
				list.insertBefore( card.nextElementSibling, card );
			}
		} );
		title.setAttribute( 'data-empty', title.textContent );
		sync( card );
	}
	Array.prototype.forEach.call( list.children, wire );
	add.addEventListener( 'click', function () {
		var html = tpl.innerHTML.replace( /__i__/g, String( next++ ) );
		var box = document.createElement( 'div' );
		box.innerHTML = html.trim();
		var card = box.firstElementChild;
		list.appendChild( card );
		wire( card );
		card.querySelector( '.slpp-label' ).focus();
	} );
} )();
