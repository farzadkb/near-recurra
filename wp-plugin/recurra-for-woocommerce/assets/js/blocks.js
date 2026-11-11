/* Recurra: block checkout payment method. Plain ES5, no build step. */
( function () {
	'use strict';
	var registry = window.wc && window.wc.wcBlocksRegistry;
	var wcSettings = window.wc && window.wc.wcSettings;
	if ( ! registry || ! wcSettings || ! window.wp || ! window.wp.element ) {
		return;
	}
	var el = window.wp.element.createElement;
	var decode = ( window.wp.htmlEntities && window.wp.htmlEntities.decodeEntities ) || function ( s ) { return s; };
	var data = wcSettings.getSetting( 'recurra_data', {} );
	var title = decode( data.title || 'Pay with crypto' );

	function Content() {
		var children = [ decode( data.description || '' ) ];
		if ( data.mock ) {
			children.push( el( 'strong', { key: 'mock', style: { display: 'block', marginTop: '0.5em' } }, 'MOCK MODE — no real payment is made.' ) );
		}
		return el( 'div', { className: 'recurra-blocks-description' }, children );
	}

	registry.registerPaymentMethod( {
		name: 'recurra',
		label: el( 'span', null, title ),
		ariaLabel: title,
		content: el( Content ),
		edit: el( Content ),
		canMakePayment: function () { return true; },
		supports: { features: data.supports || [ 'products' ] }
	} );
}() );
