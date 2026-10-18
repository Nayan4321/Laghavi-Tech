/* Laghavi Subscriptions – Stripe on the block checkout. Payment happens on Stripe's page after "Place order". */
( function ( wc, wp ) {
	if ( ! wc || ! wc.wcBlocksRegistry || ! wc.wcSettings ) {
		return;
	}
	var data = wc.wcSettings.getSetting( 'lsub_stripe_data', {} );
	var decode = wp.htmlEntities ? wp.htmlEntities.decodeEntities : function ( s ) { return s; };
	var label = decode( data.title || 'Credit / debit card' );
	var Content = function () {
		return wp.element.createElement( 'p', null, decode( data.description || '' ) );
	};

	wc.wcBlocksRegistry.registerPaymentMethod( {
		name: 'lsub_stripe',
		label: wp.element.createElement( 'span', null, label ),
		ariaLabel: label,
		content: wp.element.createElement( Content ),
		edit: wp.element.createElement( Content ),
		canMakePayment: function () {
			return true;
		},
		supports: { features: data.supports || [ 'products' ] },
	} );
} )( window.wc, window.wp );
