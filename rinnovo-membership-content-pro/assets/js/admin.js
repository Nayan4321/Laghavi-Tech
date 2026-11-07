/* Rinnovo Membership Content Pro: tier editor (add, remove, reorder). */
jQuery( function ( $ ) {
	var $list = $( '.rinnovomem-tiers' );
	var next = $list.children().length + 1000;

	function number() {
		$list.children( '.rinnovomem-tier' ).each( function ( i ) {
			$( this ).find( '.rinnovomem-tier__level' ).text( 'Level ' + ( i + 1 ) );
		} );
	}

	$( '.rinnovomem-add' ).on( 'click', function () {
		var html = $( '#tmpl-rinnovomem-tier' ).html().replace( /__i__/g, String( next++ ) ).replace( /wc-product-search-new/g, 'wc-product-search' );
		$list.append( html );
		$( document.body ).trigger( 'wc-enhanced-select-init' );
		number();
	} );
	$list.on( 'click', '.rinnovomem-remove', function () {
		$( this ).closest( '.rinnovomem-tier' ).remove();
		number();
	} );
	$list.on( 'click', '.rinnovomem-down', function () {
		var $t = $( this ).closest( '.rinnovomem-tier' );
		$t.prev( '.rinnovomem-tier' ).before( $t );
		number();
	} );
	$list.on( 'click', '.rinnovomem-up', function () {
		var $t = $( this ).closest( '.rinnovomem-tier' );
		$t.next( '.rinnovomem-tier' ).after( $t );
		number();
	} );
	number();
} );
