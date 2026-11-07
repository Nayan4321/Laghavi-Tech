/* Rinnovo Physical Subscription Boxes & Goods Pro: show the frequency and gift fields of the chosen plan. */
( function () {
	document.querySelectorAll( '.rinnovobox-picker' ).forEach( function ( picker ) {
		function update() {
			var checked = picker.querySelector( 'input[name="rinnovobox_mode"]:checked' );
			var mode = checked ? checked.value : picker.dataset.default;
			picker.querySelectorAll( '[data-for]' ).forEach( function ( el ) {
				var show = 'prepaid' === el.dataset.for ? 0 === mode.indexOf( 'prepaid_' ) : el.dataset.for === mode;
				el.style.display = show ? '' : 'none';
			} );
		}
		if ( ! picker.querySelector( 'input[name="rinnovobox_mode"]:checked' ) ) {
			var first = picker.querySelector( 'input[name="rinnovobox_mode"][value="' + picker.dataset.default + '"]' ) || picker.querySelector( 'input[name="rinnovobox_mode"]' );
			if ( first ) {
				first.checked = true;
			}
		}
		picker.addEventListener( 'change', update );
		update();
	} );
} )();
