/* Recurra pay page. Plain ES2017, no build step. Server is the source of truth:
 * this script only displays what /wp-json/recurra/v1 returns. */
( function () {
	'use strict';
	var root = document.getElementById( 'recurra-pay' );
	var cfg = null;
	try {
		cfg = root ? JSON.parse( root.getAttribute( 'data-config' ) ) : null;
	} catch ( e ) {
		cfg = null;
	}
	if ( ! cfg ) {
		return;
	}
	var $ = function ( id ) { return document.getElementById( id ); };
	var t = cfg.i18n || {};
	var pollTimer = null;
	var countdownTimer = null;
	var tokens = [];

	function api( method, path, body ) {
		var url = cfg.restBase + path + ( path.indexOf( '?' ) === -1 ? '?' : '&' ) + 'key=' + encodeURIComponent( cfg.orderKey );
		var opts = {
			method: method,
			credentials: 'same-origin',
			headers: { 'X-Recurra-Nonce': cfg.nonce, 'X-WP-Nonce': cfg.restNonce, 'Accept': 'application/json' }
		};
		if ( body ) {
			opts.headers[ 'Content-Type' ] = 'application/json';
			opts.body = JSON.stringify( body );
		}
		return fetch( url, opts ).then( function ( r ) {
			return r.json().then( function ( data ) {
				if ( ! r.ok ) {
					throw new Error( ( data && data.message ) || ( 'HTTP ' + r.status ) );
				}
				return data;
			} );
		} );
	}

	function showError( msg ) {
		var e = $( 'recurra-error' );
		if ( ! msg ) {
			e.hidden = true;
			return;
		}
		e.textContent = ( t.error || 'Error:' ) + ' ' + msg;
		e.hidden = false;
	}

	function loadTokens() {
		$( 'recurra-quote-form' ).hidden = false;
		api( 'GET', '/tokens' ).then( function ( data ) {
			tokens = data.tokens || [];
			var sel = $( 'recurra-token' );
			sel.innerHTML = '';
			tokens.forEach( function ( tok ) {
				var o = document.createElement( 'option' );
				o.value = tok.assetId;
				o.textContent = tok.symbol + ' — ' + tok.chainName;
				sel.appendChild( o );
			} );
			updateHint();
		} ).catch( function ( err ) { showError( err.message ); } );
	}

	function updateHint() {
		var id = $( 'recurra-token' ).value;
		var tok = tokens.filter( function ( x ) { return x.assetId === id; } )[ 0 ];
		$( 'recurra-refund' ).placeholder = tok ? ( tok.addressHint || '' ) : '';
	}

	function showQuote( q ) {
		$( 'recurra-quote-form' ).hidden = true;
		$( 'recurra-deposit' ).hidden = false;
		$( 'recurra-amount' ).textContent = q.amountInFormatted + ' ' + q.token;
		$( 'recurra-address' ).textContent = q.depositAddress;
		var qrBox = $( 'recurra-qr' );
		qrBox.innerHTML = '';
		if ( window.qrcode ) {
			var qr = window.qrcode( 0, 'M' );
			qr.addData( q.depositAddress );
			qr.make();
			qrBox.innerHTML = qr.createSvgTag( { cellSize: 4, margin: 2, scalable: true } );
		}
		startCountdown( q.deadlineTs );
		setStatus( q.lastStatus || 'PENDING_DEPOSIT', q );
		startPolling();
	}

	function startCountdown( deadlineTs ) {
		clearInterval( countdownTimer );
		var el = $( 'recurra-countdown' );
		function tick() {
			var left = Math.max( 0, Math.floor( deadlineTs - Date.now() / 1000 ) );
			var m = Math.floor( left / 60 );
			var s = left % 60;
			el.textContent = m + ':' + ( s < 10 ? '0' : '' ) + s;
		}
		tick();
		countdownTimer = setInterval( tick, 1000 );
	}

	function setStatus( status, q ) {
		var text = t[ status ] || status;
		if ( 'PENDING_DEPOSIT' === status && q && q.expired ) {
			text = t.expired;
		}
		$( 'recurra-status-text' ).textContent = text;
		root.setAttribute( 'data-status', status );
		var canRequote = ( q && q.expired && 'PENDING_DEPOSIT' === status ) || 'REFUNDED' === status || 'FAILED' === status;
		$( 'recurra-new-quote' ).hidden = ! canRequote;
	}

	function startPolling() {
		clearInterval( pollTimer );
		pollTimer = setInterval( poll, 5000 );
		poll();
	}

	function poll() {
		api( 'GET', '/status' ).then( function ( data ) {
			showError( null );
			if ( data.status ) {
				setStatus( data.status, data.quote );
			}
			if ( data.paid && data.redirect ) {
				clearInterval( pollTimer );
				setStatus( 'SUCCESS', data.quote );
				window.location.assign( data.redirect );
			} else if ( 'REFUNDED' === data.status || 'FAILED' === data.status ) {
				clearInterval( pollTimer );
			}
		} ).catch( function ( err ) { showError( err.message ); } );
	}

	$( 'recurra-token' ).addEventListener( 'change', updateHint );

	$( 'recurra-quote-form' ).addEventListener( 'submit', function ( ev ) {
		ev.preventDefault();
		var btn = ev.target.querySelector( 'button' );
		btn.disabled = true;
		showError( null );
		api( 'POST', '/quote', {
			origin_asset: $( 'recurra-token' ).value,
			refund_to: $( 'recurra-refund' ).value.trim()
		} ).then( function ( data ) {
			showQuote( data.quote );
		} ).catch( function ( err ) {
			showError( err.message );
		} ).then( function () { btn.disabled = false; } );
	} );

	$( 'recurra-tx-form' ).addEventListener( 'submit', function ( ev ) {
		ev.preventDefault();
		api( 'POST', '/deposit', { tx_hash: $( 'recurra-tx' ).value.trim() } ).then( function () {
			$( 'recurra-tx' ).value = '';
			poll();
		} ).catch( function ( err ) { showError( err.message ); } );
	} );

	$( 'recurra-copy' ).addEventListener( 'click', function () {
		var text = $( 'recurra-address' ).textContent;
		if ( navigator.clipboard ) {
			navigator.clipboard.writeText( text ).then( function () {
				$( 'recurra-copy' ).textContent = t.copied || 'Copied';
			} );
		}
	} );

	$( 'recurra-new-quote' ).addEventListener( 'click', function () {
		clearInterval( pollTimer );
		clearInterval( countdownTimer );
		$( 'recurra-deposit' ).hidden = true;
		loadTokens();
	} );

	if ( cfg.quote ) {
		showQuote( cfg.quote );
	} else {
		loadTokens();
	}
}() );
