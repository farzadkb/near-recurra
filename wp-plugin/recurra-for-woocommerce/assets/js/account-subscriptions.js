/* Recurra My Account → Subscriptions. Cancel / top up are signed by the
 * customer's own wallet; the store only re-reads the result from chain. */
( function () {
	'use strict';
	var root = document.getElementById( 'recurra-account' );
	var cfg = null;
	try {
		cfg = root ? JSON.parse( root.getAttribute( 'data-config' ) ) : null;
	} catch ( e ) {
		cfg = null;
	}
	if ( ! cfg || ! window.RecurraWallet || ! document.querySelector( '.recurra-sub' ) ) {
		return;
	}
	var W = window.RecurraWallet;
	var $ = function ( id ) { return document.getElementById( id ); };
	var t = cfg.i18n || {};

	function status( msg ) {
		$( 'recurra-status-text' ).textContent = msg || '';
	}

	function showError( msg ) {
		var e = $( 'recurra-error' );
		e.hidden = ! msg;
		e.textContent = msg ? ( t.error || 'Error:' ) + ' ' + msg : '';
	}

	function fmt( base, d, sym ) {
		var s = String( base ).replace( /^0+/, '' ) || '0';
		if ( d === 0 ) {
			return s + ' ' + sym;
		}
		s = s.padStart( d + 1, '0' );
		var frac = s.slice( -d ).replace( /0+$/, '' );
		return s.slice( 0, -d ) + ( frac ? '.' + frac : '' ) + ' ' + sym;
	}

	function sync( row ) {
		status( t.syncing );
		return fetch( cfg.restBase + row.orderId + '/sync', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.restNonce, 'Accept': 'application/json' }
		} ).then( function ( r ) {
			return r.json().then( function ( d ) {
				if ( ! r.ok ) {
					throw new Error( ( d && d.message ) || ( 'HTTP ' + r.status ) );
				}
				return d;
			} );
		} );
	}

	// Chain state may lag the wallet by a block or two: re-sync until it changes.
	function syncUntil( row, done ) {
		var tries = 0;
		var loop = function () {
			sync( row ).then( function ( fresh ) {
				if ( done( fresh ) || ++tries >= 10 ) {
					window.location.reload();
				} else {
					setTimeout( loop, 3000 );
				}
			} ).catch( function ( e ) {
				showError( e.message );
			} );
		};
		loop();
	}

	function requireOwner( row ) {
		var acc = W.accountId();
		if ( acc !== row.owner ) {
			showError( ( t.wrongAccount || '' ) + ' ' + row.owner );
			return false;
		}
		return true;
	}

	function act( row, call, done ) {
		showError( '' );
		if ( ! requireOwner( row ) ) {
			return;
		}
		status( t.working );
		W.call( Object.assign( { callbackUrl: window.location.href.split( '?' )[ 0 ] + '?recurra_sync=' + row.orderId }, call ) ).then( function ( res ) {
			if ( res && res.failed ) {
				status( '' );
				showError( 'transaction failed' );
				return;
			}
			syncUntil( row, done );
		} ).catch( function ( e ) {
			status( '' );
			showError( e && e.message ? e.message : String( e ) );
		} );
	}

	function renderAccount() {
		var acc = W.accountId();
		var label = $( 'recurra-account-label' );
		var box = $( 'recurra-wallets' );
		box.innerHTML = '';
		if ( acc ) {
			label.textContent = ( t.connectedAs || '' ) + ' ' + acc;
			var out = document.createElement( 'button' );
			out.type = 'button';
			out.className = 'button';
			out.textContent = t.disconnect;
			out.addEventListener( 'click', function () {
				W.signOut().then( renderAccount );
			} );
			box.appendChild( out );
			return;
		}
		label.textContent = t.connect + ':';
		W.wallets().forEach( function ( w ) {
			var b = document.createElement( 'button' );
			b.type = 'button';
			b.className = 'button recurra-wallet-btn';
			b.textContent = w.name;
			b.addEventListener( 'click', function () {
				W.signIn( w.id ).then( renderAccount ).catch( function ( e ) {
					showError( e && e.message ? e.message : String( e ) );
				} );
			} );
			box.appendChild( b );
		} );
	}

	document.querySelectorAll( '.recurra-sub' ).forEach( function ( tr ) {
		var row = JSON.parse( tr.getAttribute( 'data-sub' ) );
		var sel = tr.querySelector( '.recurra-topup-periods' );
		if ( sel ) {
			cfg.periods.forEach( function ( n ) {
				var o = document.createElement( 'option' );
				o.value = String( n );
				o.textContent = '+' + fmt( ( BigInt( row.amount ) * BigInt( n ) ).toString(), row.decimals, row.symbol );
				sel.appendChild( o );
			} );
		}
		var cancel = tr.querySelector( '.recurra-cancel' );
		if ( cancel ) {
			cancel.addEventListener( 'click', function () {
				if ( ! window.confirm( t.confirmCancel ) ) {
					return;
				}
				act( row, {
					receiverId: row.vault,
					methodName: 'cancel',
					args: { sub_id: row.subId },
					gas: cfg.cancelGas,
					deposit: '1'
				}, function ( fresh ) { return fresh.status === 'Cancelled'; } );
			} );
		}
		var topup = tr.querySelector( '.recurra-topup' );
		if ( topup ) {
			topup.addEventListener( 'click', function () {
				var before = row.balanceText;
				act( row, {
					receiverId: row.token,
					methodName: 'ft_transfer_call',
					args: {
						receiver_id: row.vault,
						amount: ( BigInt( row.amount ) * BigInt( sel.value ) ).toString(),
						msg: JSON.stringify( { top_up: { sub_id: row.subId } } )
					},
					gas: cfg.topUpGas,
					deposit: '1'
				}, function ( fresh ) { return fresh.balanceText !== before; } );
			} );
		}
	} );

	W.init( { network: cfg.network, rpcUrl: cfg.rpcUrl } ).then( function () {
		renderAccount();
		W.onChange( renderAccount );
		var back = new URLSearchParams( window.location.search ).get( 'recurra_sync' );
		var tr = back && document.querySelector( '.recurra-sub' );
		if ( back ) {
			document.querySelectorAll( '.recurra-sub' ).forEach( function ( el ) {
				var r = JSON.parse( el.getAttribute( 'data-sub' ) );
				if ( String( r.orderId ) === back ) {
					tr = el;
				}
			} );
			if ( tr ) {
				sync( JSON.parse( tr.getAttribute( 'data-sub' ) ) ).then( function () {
					window.location.replace( window.location.href.split( '?' )[ 0 ] );
				} );
			}
		}
	} ).catch( function ( e ) {
		showError( e && e.message ? e.message : String( e ) );
	} );
}() );
