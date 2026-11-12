/* Recurra subscribe page. Plain ES2020, no build step; wallet access goes
 * through window.RecurraWallet (assets/dist/recurra-wallet.js).
 * The server is the source of truth: the order is paid only when its RPC view
 * of the vault shows the subscription. Amounts are BigInt / strings, never floats. */
( function () {
	'use strict';
	var root = document.getElementById( 'recurra-subscribe' );
	var cfg = null;
	try {
		cfg = root ? JSON.parse( root.getAttribute( 'data-config' ) ) : null;
	} catch ( e ) {
		cfg = null;
	}
	if ( ! cfg || ! window.RecurraWallet ) {
		return;
	}
	var W = window.RecurraWallet;
	var $ = function ( id ) { return document.getElementById( id ); };
	var t = cfg.i18n || {};
	var account = null;
	var pollTimer = null;
	var pollStarted = 0;
	var PENDING_KEY = 'recurra_sub_pending_' + cfg.orderId;

	function fmt( base ) {
		var s = String( base ).replace( /^0+/, '' ) || '0';
		var d = cfg.decimals;
		if ( d === 0 ) {
			return s;
		}
		s = s.padStart( d + 1, '0' );
		var frac = s.slice( -d ).replace( /0+$/, '' );
		return s.slice( 0, -d ) + ( frac ? '.' + frac : '' ) + ( cfg.symbol ? ' ' + cfg.symbol : '' );
	}

	function period( sec ) {
		if ( sec % 86400 === 0 ) {
			return ( sec / 86400 ) + ' d';
		}
		if ( sec % 3600 === 0 ) {
			return ( sec / 3600 ) + ' h';
		}
		if ( sec % 60 === 0 ) {
			return ( sec / 60 ) + ' min';
		}
		return sec + ' s';
	}

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

	function status( msg ) {
		$( 'recurra-status-text' ).textContent = msg || '';
	}

	function showError( msg ) {
		var e = $( 'recurra-error' );
		e.hidden = ! msg;
		e.textContent = msg ? ( t.error || 'Error:' ) + ' ' + msg : '';
	}

	function total() {
		return ( BigInt( cfg.amount ) * BigInt( $( 'recurra-periods' ).value || '1' ) ).toString();
	}

	function renderTotal() {
		$( 'recurra-total' ).textContent = fmt( total() );
		if ( account ) {
			api( 'GET', '/subscription/balance?account=' + encodeURIComponent( account ) ).then( function ( r ) {
				var enough = BigInt( r.balance ) >= BigInt( total() );
				$( 'recurra-balance' ).textContent = ( t.balance || '' ) + ' ' + fmt( r.balance ) + ( enough ? '' : ' — ' + ( t.notEnough || '' ) );
				$( 'recurra-subscribe-btn' ).disabled = ! enough;
			} ).catch( function () {
				$( 'recurra-balance' ).textContent = '';
				$( 'recurra-subscribe-btn' ).disabled = false;
			} );
		}
	}

	function renderAccount() {
		account = W.accountId();
		$( 'recurra-connect' ).hidden = !! account;
		$( 'recurra-subscribe-form' ).hidden = ! account;
		if ( account ) {
			$( 'recurra-account' ).textContent = ( t.connectedAs || '' ) + ' ' + account;
			renderTotal();
		}
	}

	function renderWallets() {
		var box = $( 'recurra-wallets' );
		box.innerHTML = '';
		W.wallets().forEach( function ( w ) {
			var b = document.createElement( 'button' );
			b.type = 'button';
			b.className = 'button recurra-wallet-btn';
			if ( w.iconUrl ) {
				var img = document.createElement( 'img' );
				img.src = w.iconUrl;
				img.alt = '';
				img.width = 20;
				img.height = 20;
				b.appendChild( img );
			}
			b.appendChild( document.createTextNode( ' ' + w.name ) );
			b.addEventListener( 'click', function () {
				showError( '' );
				W.signIn( w.id ).then( renderAccount ).catch( function ( e ) {
					showError( e && e.message ? e.message : String( e ) );
				} );
			} );
			box.appendChild( b );
		} );
	}

	function confirmLoop( txHash ) {
		if ( pollTimer ) {
			return;
		}
		pollStarted = Date.now();
		status( t.confirming );
		var tick = function () {
			api( 'POST', '/subscription', txHash ? { tx_hash: txHash } : {} ).then( function ( r ) {
				txHash = null;
				if ( r.state === 'paid' ) {
					clearInterval( pollTimer );
					try {
						sessionStorage.removeItem( PENDING_KEY );
					} catch ( e ) {}
					status( t.paid );
					window.location.href = r.redirect || cfg.returnUrl;
				} else if ( r.state === 'mismatch' ) {
					clearInterval( pollTimer );
					status( '' );
					showError( t.mismatch );
				} else {
					status( r.state === 'charging' ? t.charging : t.confirming );
					if ( Date.now() - pollStarted > 120000 ) {
						status( t.slow );
					}
				}
			} ).catch( function ( e ) {
				status( e.message );
			} );
		};
		tick();
		pollTimer = setInterval( tick, 4000 );
	}

	function subscribe( ev ) {
		ev.preventDefault();
		showError( '' );
		var msg = JSON.stringify( {
			subscribe: {
				plan_id: cfg.planId,
				owner: account,
				external_ref: cfg.externalRef,
				charge_now: true
			}
		} );
		status( t.working );
		try {
			sessionStorage.setItem( PENDING_KEY, '1' );
		} catch ( e ) {}
		W.call( {
			receiverId: cfg.token,
			methodName: 'ft_transfer_call',
			args: { receiver_id: cfg.vault, amount: total(), msg: msg },
			gas: cfg.gas,
			deposit: '1',
			callbackUrl: window.location.href
		} ).then( function ( res ) {
			if ( res && res.failed ) {
				status( '' );
				showError( t.failed );
				return;
			}
			confirmLoop( res && res.hash );
		} ).catch( function ( e ) {
			status( '' );
			showError( e && e.message ? e.message : String( e ) );
		} );
	}

	function init() {
		$( 'recurra-plan-terms' ).textContent = fmt( cfg.amount ) + ' ' + ( t.every || 'every' ) + ' ' + period( cfg.periodSec );
		var sel = $( 'recurra-periods' );
		cfg.periods.forEach( function ( n ) {
			var o = document.createElement( 'option' );
			o.value = String( n );
			o.textContent = n + ' × ' + period( cfg.periodSec ) + ' = ' + fmt( ( BigInt( cfg.amount ) * BigInt( n ) ).toString() );
			sel.appendChild( o );
		} );
		sel.addEventListener( 'change', renderTotal );
		$( 'recurra-subscribe-form' ).addEventListener( 'submit', subscribe );
		$( 'recurra-disconnect' ).textContent = t.disconnect;
		$( 'recurra-disconnect' ).addEventListener( 'click', function () {
			W.signOut().then( renderAccount );
		} );

		if ( cfg.paid ) {
			status( t.paid );
			window.location.href = cfg.returnUrl;
			return;
		}

		// Back from a redirect-based wallet?
		var params = new URLSearchParams( window.location.search );
		var hashes = params.get( 'transactionHashes' );
		var pending = false;
		try {
			pending = sessionStorage.getItem( PENDING_KEY ) === '1';
		} catch ( e ) {}
		if ( params.get( 'errorCode' ) ) {
			showError( params.get( 'errorMessage' ) || params.get( 'errorCode' ) );
		}

		W.init( { network: cfg.network, rpcUrl: cfg.rpcUrl } ).then( function () {
			renderWallets();
			renderAccount();
			W.onChange( renderAccount );
			if ( hashes || pending ) {
				confirmLoop( hashes ? hashes.split( ',' ).pop() : null );
			}
		} ).catch( function ( e ) {
			showError( e && e.message ? e.message : String( e ) );
		} );
	}

	init();
}() );
