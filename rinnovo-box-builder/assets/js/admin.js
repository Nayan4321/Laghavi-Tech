/* Rinnovo Box Builder: product tab repeaters, drag and drop, mode parts. */
jQuery( function ( $ ) {
	'use strict';
	var $root = $( '#rinnovobb_data' );
	if ( ! $root.length ) {
		return;
	}
	var A = window.rinnovobbAdmin || { parts: {} };
	var counter = Date.now() % 100000;

	function initSelects( $scope ) {
		$( document.body ).trigger( 'wc-enhanced-select-init' );
		$scope.find( '.rinnovobb-sortable, .rinnovobb-rows' ).each( sortable );
	}

	function sortable() {
		var $list = $( this );
		if ( $list.data( 'ui-sortable' ) ) {
			return;
		}
		$list.sortable( { handle: '.rinnovobb-handle', items: '> .rinnovobb-card, > .rinnovobb-row', tolerance: 'pointer' } );
	}

	function modeParts() {
		var mode = $root.find( 'input[name="rinnovobb_cfg[mode]"]:checked' ).val() || 'off';
		var parts = A.parts[ mode ] || [];
		$root.find( '.rinnovobb-part' ).each( function () {
			var need = String( $( this ).data( 'part' ) ).split( ' ' );
			var on = need.some( function ( p ) { return parts.indexOf( p ) !== -1; } );
			$( this ).toggle( on );
		} );
		$root.find( '.rinnovobb-when-on' ).toggle( mode !== 'off' );
	}

	function deliveryDays() {
		var days = $root.find( '.rinnovobb-delivery-days input:checked' ).map( function () { return this.value; } ).get();
		$root.find( '.rinnovobb-week-day' ).each( function () {
			$( this ).prop( 'hidden', days.indexOf( $( this ).data( 'day' ) ) === -1 );
		} );
	}

	// Add a row, week, group or question from the <template> right before the button.
	$root.on( 'click', '.rinnovobb-add', function () {
		var $b = $( this );
		var tpl = $b.prevAll( 'template' ).get( 0 );
		var ph = $b.data( 'ph' );
		if ( ! tpl ) {
			return;
		}
		counter++;
		var html = tpl.innerHTML.split( ph ).join( String( counter ) );
		var $target = $( tpl ).prevAll( '.rinnovobb-rows, .rinnovobb-weeks, .rinnovobb-groups, .rinnovobb-fields-admin' ).first();
		var $new = $( html ).appendTo( $target );
		initSelects( $new );
		deliveryDays();
	} );

	$root.on( 'click', '.rinnovobb-remove', function () {
		if ( window.confirm( A.confirm || 'Remove?' ) ) {
			$( this ).closest( '.rinnovobb-row, .rinnovobb-card' ).remove();
		}
	} );

	// Duplicate a week (template): copy its products under a new index.
	$root.on( 'click', '.rinnovobb-duplicate', function () {
		var $week = $( this ).closest( '.rinnovobb-week' );
		var $copy = $week.clone();
		counter++;
		$copy.find( '.select2-container' ).remove();
		$copy.find( 'select' ).removeClass( 'enhanced select2-hidden-accessible' ).removeAttr( 'data-select2-id tabindex aria-hidden' ).find( 'option' ).removeAttr( 'data-select2-id' );
		$copy.find( '[name]' ).each( function () {
			this.name = this.name.replace( /\[weeks\]\[[^\]]+\]/, '[weeks][' + counter + ']' );
		} );
		$copy.find( 'template' ).each( function () {
			this.innerHTML = this.innerHTML.replace( /\[weeks\]\[[^\]]+\]/g, '[weeks][' + counter + ']' );
		} );
		var $name = $copy.find( 'input[name$="[name]"]' ).first();
		$name.val( $name.val() ? $name.val() + ' (copy)' : '' );
		$week.after( $copy );
		$copy.find( '.rinnovobb-rows' ).removeData( 'ui-sortable' );
		initSelects( $copy );
	} );

	// Layout manager: section order.
	$root.find( '.rinnovobb-sections' ).sortable( {
		handle: '.rinnovobb-handle',
		update: function () {
			$root.find( '.rinnovobb-order' ).val( $( this ).children().map( function () { return $( this ).data( 'section' ); } ).get().join( ',' ) );
		}
	} );

	$root.on( 'change', 'input[name="rinnovobb_cfg[mode]"]', modeParts );
	$root.on( 'change', '.rinnovobb-delivery-days input', deliveryDays );
	$root.find( '.rinnovobb-sortable, .rinnovobb-rows' ).each( sortable );
	modeParts();
	deliveryDays();
} );
