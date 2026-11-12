<?php
/**
 * End-to-end subscription test on NEAR TESTNET, against the Docker store:
 *
 *   Store API checkout (logged-in customer) → subscribe page → customer signs
 *   ft_transfer_call (near CLI) → server confirms via RPC view → order paid →
 *   charger charges twice → 2 renewal orders → customer cancels (near CLI) →
 *   refund of the remaining balance → My Account shows "Cancelled".
 *
 * Runs on the HOST (needs php, docker, near CLI, node >= 22.18) because it signs
 * as the testnet customer with the near CLI legacy keychain and runs the charger
 * from charger/.env. Takes ~3 minutes (plan 1 on vault-v2 has a 60 s period).
 *
 *   php docker/e2e-subscription.php
 *
 * Env: E2E_BASE (default http://localhost:8080), E2E_CUSTOMER (default
 * customer.recurra-farzad.testnet), E2E_PLAN_ID (default 1), E2E_PREPAY (periods,
 * default 5), E2E_CHARGER_ENV (charger env file for charge #2, default charger/.env),
 * E2E_CLI_CHARGER (account that signs charge #3 with the near CLI one-liner,
 * default: the customer — `charge` is permissionless).
 *
 * @package Recurra
 */

// phpcs:disable WordPress.WP.AlternativeFunctions,WordPress.Security.EscapeOutput,WordPress.WP.GlobalVariablesOverride,WordPress.PHP.DevelopmentFunctions,WordPress.PHP.DiscouragedPHPFunctions -- CLI test script.

$root     = dirname( __DIR__ );
$base     = getenv( 'E2E_BASE' ) ? getenv( 'E2E_BASE' ) : 'http://localhost:8080';
$customer = getenv( 'E2E_CUSTOMER' ) ? getenv( 'E2E_CUSTOMER' ) : 'customer.recurra-farzad.testnet';
$plan_id  = getenv( 'E2E_PLAN_ID' ) ? (int) getenv( 'E2E_PLAN_ID' ) : 1;
$prepay   = getenv( 'E2E_PREPAY' ) ? (int) getenv( 'E2E_PREPAY' ) : 5;
$rpc      = 'https://rpc.testnet.fastnear.com';
$wp       = 'docker compose -f ' . escapeshellarg( $root . '/docker/docker-compose.yml' ) . ' run --rm -T wpcli wp';
$cookie   = '';
$t0       = time();
$links    = array();

/**
 * Prints a timestamped line.
 *
 * @param string $msg Message.
 */
function say( string $msg ): void {
	global $t0;
	printf( "[t+%3ds] %s\n", time() - $t0, $msg );
}

/**
 * Fails the run.
 *
 * @param string $msg  Message.
 * @param mixed  $data Data.
 */
function fail( string $msg, $data = null ): void {
	fwrite( STDERR, "E2E FAIL: {$msg}\n" . ( null === $data ? '' : print_r( $data, true ) . "\n" ) );
	exit( 1 );
}

/**
 * HTTP request: [status, headers(lowercase), decoded-or-raw body].
 *
 * @param string     $method  Method.
 * @param string     $url     URL.
 * @param array|null $body    JSON body.
 * @param array      $headers Extra headers.
 */
function http( string $method, string $url, ?array $body = null, array $headers = array() ): array {
	global $cookie;
	$h = array( 'Accept: application/json' );
	if ( '' !== $cookie ) {
		$h[] = 'Cookie: ' . $cookie;
	}
	foreach ( $headers as $k => $v ) {
		$h[] = $k . ': ' . $v;
	}
	$opts = array(
		'method'        => $method,
		'ignore_errors' => true,
		'timeout'       => 60,
	);
	if ( null !== $body ) {
		$h[]             = 'Content-Type: application/json';
		$opts['content'] = json_encode( $body );
	}
	$opts['header'] = implode( "\r\n", $h );
	$raw            = file_get_contents( $url, false, stream_context_create( array( 'http' => $opts ) ) );
	$status         = 0;
	$out            = array();
	foreach ( $http_response_header ?? array() as $line ) {
		if ( preg_match( '#^HTTP/\S+ (\d+)#', $line, $m ) ) {
			$status = (int) $m[1];
			$out    = array();
		} elseif ( strpos( $line, ':' ) ) {
			list( $k, $v )                   = explode( ':', $line, 2 );
			$out[ strtolower( trim( $k ) ) ] = trim( $v );
		}
	}
	$json = json_decode( (string) $raw, true );
	return array( $status, $out, null === $json ? $raw : $json );
}

/**
 * NEAR view call (testnet RPC).
 *
 * @param string $contract Contract.
 * @param string $method   Method.
 * @param array  $args     Args.
 * @return mixed
 */
function view( string $contract, string $method, array $args ) {
	global $rpc;
	$body = array(
		'jsonrpc' => '2.0',
		'id'      => 'e2e',
		'method'  => 'query',
		'params'  => array(
			'request_type' => 'call_function',
			'finality'     => 'final',
			'account_id'   => $contract,
			'method_name'  => $method,
			'args_base64'  => base64_encode( json_encode( (object) $args ) ),
		),
	);
	$res  = json_decode(
		(string) file_get_contents(
			$rpc,
			false,
			stream_context_create(
				array(
					'http' => array(
						'method'  => 'POST',
						'header'  => 'Content-Type: application/json',
						'content' => json_encode( $body ),
						'timeout' => 30,
					),
				)
			)
		),
		true
	);
	isset( $res['result']['result'] ) || fail( "view {$contract}.{$method} failed", $res );
	return json_decode( implode( '', array_map( 'chr', $res['result']['result'] ) ), true );
}

/**
 * wp-cli eval in the Docker stack; returns trimmed stdout.
 *
 * @param string $php PHP code.
 */
function wp_eval( string $php ): string {
	global $wp;
	return trim( (string) shell_exec( $wp . ' eval ' . escapeshellarg( $php ) . ' 2>/dev/null' ) );
}

/**
 * Signs a function call as $signer with the near CLI legacy keychain. Returns the tx hash.
 *
 * @param string $signer   Signer.
 * @param string $contract Receiver.
 * @param string $method   Method.
 * @param array  $args     Args.
 * @param string $deposit  Deposit (near CLI syntax).
 * @param int    $tgas     Gas in TGas.
 */
function near_call( string $signer, string $contract, string $method, array $args, string $deposit, int $tgas ): string {
	$cmd = sprintf(
		'near contract call-function as-transaction %s %s json-args %s prepaid-gas %s attached-deposit %s sign-as %s network-config testnet sign-with-legacy-keychain send 2>&1',
		escapeshellarg( $contract ),
		escapeshellarg( $method ),
		escapeshellarg( json_encode( $args ) ),
		escapeshellarg( $tgas . '.0 Tgas' ),
		escapeshellarg( $deposit ),
		escapeshellarg( $signer )
	);
	$out = (string) shell_exec( $cmd );
	preg_match( '/Transaction ID: ([1-9A-HJ-NP-Za-km-z]{43,44})/', $out, $m ) || fail( "near call {$method} failed", preg_replace( '/ed25519:[1-9A-HJ-NP-Za-km-z]{40,}/', 'ed25519:…', $out ) );
	if ( preg_match( '/(Smart contract panicked[^\n]*|Failure[^\n]*)/', $out, $e ) ) {
		fail( "near call {$method} failed on-chain: {$e[1]} (tx {$m[1]})" );
	}
	return $m[1];
}

/**
 * Waits until the subscription is due, then runs the charger once.
 *
 * @param string $vault  Vault.
 * @param int    $sub_id Subscription id.
 */
function charge_when_due( string $vault, int $sub_id, string $via = 'charger' ): void {
	global $root, $links;
	$sub  = view( $vault, 'get_subscription', array( 'sub_id' => $sub_id ) );
	$wait = (int) ceil( ( (int) substr( $sub['next_charge_at_ns'], 0, -9 ) - time() ) ) + 3;
	if ( $wait > 0 ) {
		say( "waiting {$wait}s for the next period" );
		sleep( $wait );
	}
	if ( 'charger' !== $via ) {
		// The README one-liner: anyone can call charge; the contract enforces the rule.
		$tx      = near_call( $via, $vault, 'charge', array( 'sub_id' => $sub_id ), '0 NEAR', 50 );
		$links[] = 'charge: https://testnet.nearblocks.io/txns/' . $tx;
		say( "near CLI: {$via} called charge({$sub_id}) https://testnet.nearblocks.io/txns/{$tx}" );
		return;
	}
	$env = getenv( 'E2E_CHARGER_ENV' ) ? 'CHARGER_ENV_FILE=' . escapeshellarg( (string) getenv( 'E2E_CHARGER_ENV' ) ) . ' ' : '';
	for ( $i = 0; $i < 6; $i++ ) {
		$out = (string) shell_exec( 'cd ' . escapeshellarg( $root . '/charger' ) . ' && ' . $env . 'node src/index.ts --once 2>&1' );
		if ( preg_match( '/sub ' . $sub_id . ': charged (\S+)/', $out, $m ) ) {
			$links[] = 'charge: ' . $m[1];
			say( "charger: sub {$sub_id} charged {$m[1]}" );
			return;
		}
		sleep( 5 );
	}
	fail( 'charger did not charge', $out );
}

/**
 * Runs the renewal job and returns the parent order state.
 *
 * @param int $order_id Parent order id.
 * @return array<string, mixed>
 */
function order_state( int $order_id ): array {
	$json = wp_eval(
		'Recurra_Subscriptions::run_renewals();
		$o = wc_get_order(' . $order_id . ');
		$r = wc_get_orders( array( "parent" => ' . $order_id . ', "limit" => 20, "orderby" => "id", "order" => "ASC" ) );
		echo wp_json_encode( array(
			"status" => $o->get_status(),
			"sub_id" => (int) $o->get_meta("_recurra_sub_id"),
			"sub_status" => $o->get_meta("_recurra_sub_status"),
			"active" => $o->get_meta("_recurra_sub_active"),
			"seen" => (int) $o->get_meta("_recurra_charges_seen"),
			"tx" => $o->get_transaction_id(),
			"renewals" => array_map( function ( $x ) { return array( "id" => $x->get_id(), "status" => $x->get_status(), "total" => $x->get_total(), "tx" => $x->get_transaction_id(), "n" => (int) $x->get_meta("_recurra_charge_number") ); }, $r ),
		) );'
	);
	$state = json_decode( $json, true );
	is_array( $state ) || fail( 'could not read order state', $json );
	return $state;
}

$store = $base . '/wp-json/wc/store/v1';

// 0. Product linked to the fast plan + a logged-in customer session.
$pid = wp_eval(
	'$id = wc_get_product_id_by_sku( "pro-plan-fast" );
	if ( ! $id ) { $p = new WC_Product_Simple(); $p->set_sku( "pro-plan-fast" ); $p->set_name( "Pro plan, 1 min (testnet e2e)" ); $p->set_regular_price( "1" ); $p->set_status( "publish" ); $p->save(); $id = $p->get_id(); }
	$p = wc_get_product( $id );
	$problem = Recurra_Subscriptions::plan_problem( Recurra_Subscriptions::plan( ' . $plan_id . ', 0 ), (string) $p->get_regular_price() );
	if ( null !== $problem ) { echo "ERR " . $problem; return; }
	$p->update_meta_data( Recurra_Subscriptions::META_PLAN, ' . $plan_id . ' ); $p->set_virtual( true ); $p->set_sold_individually( true ); $p->save();
	echo $id;'
);
ctype_digit( $pid ) || fail( 'could not link product to plan', $pid );
$login = json_decode(
	wp_eval(
		'$u = get_user_by( "login", "e2e-customer" );
		$id = $u ? $u->ID : wc_create_new_customer( "e2e-customer@example.com", "e2e-customer", wp_generate_password() );
		$exp = time() + 2 * HOUR_IN_SECONDS;
		$c = wp_generate_auth_cookie( $id, $exp, "logged_in" );
		$parts = wp_parse_auth_cookie( $c, "logged_in" );
		echo wp_json_encode( array( "id" => $id, "name" => LOGGED_IN_COOKIE, "value" => $c, "token" => $parts["token"] ) );'
	),
	true
);
is_array( $login ) || fail( 'could not create customer session' );
$cookie = $login['name'] . '=' . $login['value'];
say( "customer user #{$login['id']} logged in; product #{$pid} → vault plan {$plan_id}" );

// 1. Store API checkout.
list( , $hdr )      = http( 'GET', $store . '/cart' );
$auth               = array(
	'Nonce'      => $hdr['nonce'] ?? '',
	'Cart-Token' => $hdr['cart-token'] ?? '',
);
list( $s, , $cart ) = http( 'GET', $store . '/cart/items', null, $auth );
foreach ( ( is_array( $cart ) ? $cart : array() ) as $item ) {
	http( 'DELETE', $store . '/cart/items/' . $item['key'], null, $auth );
}
list( $s, , $cart ) = http(
	'POST',
	$store . '/cart/add-item',
	array(
		'id'       => (int) $pid,
		'quantity' => 1,
	),
	$auth
);
( 200 === $s || 201 === $s ) || fail( "add-item HTTP {$s}", $cart );
$addr                   = array(
	'first_name' => 'Sub',
	'last_name'  => 'Scriber',
	'address_1'  => '1 Test Street',
	'city'       => 'San Francisco',
	'state'      => 'CA',
	'postcode'   => '94103',
	'country'    => 'US',
);
list( $s, , $checkout ) = http(
	'POST',
	$store . '/checkout',
	array(
		'billing_address'  => $addr + array( 'email' => 'e2e-customer@example.com' ),
		'shipping_address' => $addr,
		'payment_method'   => 'recurra',
	),
	$auth
);
( 200 === $s && ! empty( $checkout['order_id'] ) ) || fail( "checkout HTTP {$s}", $checkout );
$order_id = (int) $checkout['order_id'];
say( "order #{$order_id} placed via Store API ({$checkout['status']})" );

// 2. Subscribe page.
list( $s, , $html ) = http( 'GET', (string) $checkout['payment_result']['redirect_url'], null, array( 'Accept' => 'text/html' ) );
( 200 === $s && is_string( $html ) ) || fail( "subscribe page HTTP {$s}" );
preg_match( '/id="recurra-subscribe"[^>]*data-config="([^"]+)"/', $html, $m ) || fail( 'subscribe page config not found' );
$cfg = json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true );
strpos( $html, 'TESTNET' ) !== false || fail( 'TESTNET banner missing' );
strpos( $html, 'assets/dist/recurra-wallet.js' ) !== false || fail( 'wallet bundle not enqueued' );
strpos( $html, 'assets/js/subscribe-page.js' ) !== false || fail( 'subscribe-page.js not enqueued' );
( 'testnet' === $cfg['network'] && $cfg['planId'] === $plan_id ) || fail( 'unexpected subscribe config', $cfg );
say( "subscribe page OK: {$cfg['planName']}, {$cfg['amount']} {$cfg['symbol']} base units / {$cfg['periodSec']}s, vault {$cfg['vault']}, ref {$cfg['externalRef']}" );

$rest  = $cfg['restBase'];
$q     = '?key=' . rawurlencode( $cfg['orderKey'] );
$rauth = array(
	'X-Recurra-Nonce' => $cfg['nonce'],
	'X-WP-Nonce'      => $cfg['restNonce'],
);
list( $s ) = http( 'POST', $rest . '/subscription?key=wc_order_wrong', array(), $rauth );
404 === $s || fail( "wrong key should be 404, got {$s}" );
list( $s ) = http( 'POST', $rest . '/subscription' . $q, array() );
403 === $s || fail( "missing nonce should be 403, got {$s}" );
list( $s ) = http( 'POST', $rest . '/quote' . $q, array( 'origin_asset' => 'x', 'refund_to' => 'y' ), $rauth );
409 === $s || fail( "quote on a subscription order should be 409, got {$s}" );
list( $s, , $r ) = http( 'POST', $rest . '/subscription' . $q, array(), $rauth );
( 200 === $s && 'waiting' === $r['state'] ) || fail( 'confirm before subscribing should be waiting', $r );
list( $s, , $r ) = http( 'GET', $rest . '/subscription/balance' . $q . '&account=' . rawurlencode( $customer ), null, $rauth );
200 === $s || fail( "balance HTTP {$s}", $r );
$total = bcmul( $cfg['amount'], (string) $prepay );
bccomp( $r['balance'], $total ) >= 0 || fail( "customer has {$r['balance']}, needs {$total}" );
say( "security checks OK (404 / 403 / 409), not confirmed before subscribing; customer balance {$r['balance']}" );

// 3. Customer subscribes (one signature), prepaying $prepay periods.
$msg     = json_encode(
	array(
		'subscribe' => array(
			'plan_id'      => $plan_id,
			'owner'        => $customer,
			'external_ref' => $cfg['externalRef'],
			'charge_now'   => true,
		),
	)
);
$tx      = near_call(
	$customer,
	$cfg['token'],
	'ft_transfer_call',
	array(
		'receiver_id' => $cfg['vault'],
		'amount'      => $total,
		'msg'         => $msg,
	),
	'1 yoctoNEAR',
	100
);
$links[] = 'subscribe: https://testnet.nearblocks.io/txns/' . $tx;
say( "subscribed: ft_transfer_call {$total} → {$cfg['vault']} https://testnet.nearblocks.io/txns/{$tx}" );

// 4. Server confirms from chain (tx hash is only recorded).
$state = '';
for ( $i = 0; $i < 20 && 'paid' !== $state; $i++ ) {
	list( $s, , $r ) = http( 'POST', $rest . '/subscription' . $q, array( 'tx_hash' => $tx ), $rauth );
	$state           = $r['state'] ?? ( 'HTTP ' . $s );
	'paid' === $state || sleep( 3 );
}
'paid' === $state || fail( "order not confirmed: {$state}" );
$st = order_state( $order_id );
in_array( $st['status'], array( 'processing', 'completed' ), true ) || fail( 'parent order not paid', $st );
say( "order #{$order_id} {$st['status']} (tx id {$st['tx']}), subscription #{$st['sub_id']}" );

// 5. Two renewals via the charger.
$cli_charger = getenv( 'E2E_CLI_CHARGER' ) ? (string) getenv( 'E2E_CLI_CHARGER' ) : $customer;
for ( $n = 2; $n <= 3; $n++ ) {
	charge_when_due( $cfg['vault'], $st['sub_id'], 2 === $n ? 'charger' : $cli_charger );
	for ( $i = 0; $i < 10; $i++ ) {
		$st = order_state( $order_id );
		if ( count( $st['renewals'] ) >= $n - 1 ) {
			break;
		}
		sleep( 3 );
	}
	count( $st['renewals'] ) === $n - 1 || fail( "expected " . ( $n - 1 ) . ' renewal order(s)', $st );
	$last = end( $st['renewals'] );
	( $last['n'] === $n && in_array( $last['status'], array( 'processing', 'completed' ), true ) && 'recurra:sub' . $st['sub_id'] . ':' . $n === $last['tx'] ) || fail( 'bad renewal order', $last );
	say( "renewal order #{$last['id']} for charge #{$n}: {$last['status']}, \${$last['total']}" );
}

// Early charge is refused by the contract.
$sub = view( $cfg['vault'], 'get_subscription', array( 'sub_id' => $st['sub_id'] ) );
in_array( $st['sub_id'], view( $cfg['vault'], 'get_due', array( 'from_index' => 0, 'limit' => 100 ) ), true ) && fail( 'subscription should not be due right after a charge' );

// 6. My Account lists it; customer cancels from their wallet; refund arrives.
list( $s, , $page ) = http( 'GET', $base . '/my-account/recurra-subscriptions/', null, array( 'Accept' => 'text/html' ) );
( 200 === $s && is_string( $page ) && preg_match( '/id="recurra-account"[^>]*data-config="([^"]+)"/', $page, $am ) ) || fail( "My Account subscriptions page HTTP {$s}" );
strpos( $page, 'Subscription #' . $st['sub_id'] ) !== false || fail( 'subscription not listed in My Account' );
strpos( $page, 'assets/js/account-subscriptions.js' ) !== false || fail( 'account script not enqueued' );
$acfg = json_decode( html_entity_decode( $am[1], ENT_QUOTES ), true );
say( "My Account → Subscriptions lists subscription #{$st['sub_id']} ({$sub['status']}, balance {$sub['balance']})" );

$before  = view( $cfg['token'], 'ft_balance_of', array( 'account_id' => $customer ) );
$refund  = $sub['balance'];
$tx      = near_call( $customer, $cfg['vault'], 'cancel', array( 'sub_id' => $st['sub_id'] ), '1 yoctoNEAR', 50 );
$links[] = 'cancel: https://testnet.nearblocks.io/txns/' . $tx;
say( "customer cancelled https://testnet.nearblocks.io/txns/{$tx}" );
sleep( 3 );
$after = view( $cfg['token'], 'ft_balance_of', array( 'account_id' => $customer ) );
bccomp( bcsub( $after, $before ), $refund ) === 0 || fail( "refund mismatch: before {$before}, after {$after}, expected +{$refund}" );
say( "refund OK: customer +{$refund} (" . bcdiv( $refund, '1000000', 6 ) . " {$cfg['symbol']})" );

// Sync through the customer's REST route (what the My Account page calls after the wallet).
list( $s, , $row ) = http( 'POST', $acfg['restBase'] . $order_id . '/sync', null, array( 'X-WP-Nonce' => $acfg['restNonce'] ) );
( 200 === $s && 'Cancelled' === $row['status'] ) || fail( "sync after cancel HTTP {$s}", $row );
$st = order_state( $order_id );
( 'Cancelled' === $st['sub_status'] && 'no' === $st['active'] && 2 === count( $st['renewals'] ) ) || fail( 'final state wrong', $st );
list( $s ) = http( 'POST', $acfg['restBase'] . $order_id . '/sync' );
401 === $s || 404 === $s || 403 === $s || fail( "sync without nonce should be refused, got {$s}" );

// Log out only the session this test created.
wp_eval( 'WP_Session_Tokens::get_instance( ' . (int) $login['id'] . ' )->destroy( ' . var_export( $login['token'], true ) . ' );' );

say( "E2E OK (testnet subscription): order #{$order_id} paid → 2 renewal orders → cancelled → refund {$refund} in " . ( time() - $t0 ) . 's' );
echo implode( "\n", $links ) . "\n";
