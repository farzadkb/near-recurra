<?php
/**
 * End-to-end mock checkout, driven like a browser through the public APIs:
 * WooCommerce Store API (what Block Checkout uses) → Recurra pay page →
 * Recurra REST quote → status polling until the order is paid.
 *
 * Run (stack must be up):
 *   docker compose -f docker/docker-compose.yml run --rm wpcli php /usr/local/bin/e2e-mock.php
 *
 * Expected: order status goes pending → on-hold → processing in ~30 s.
 *
 * Env: E2E_BASE (site URL incl. path, default http://wordpress), E2E_HOST (Host
 * header, default localhost:8080), E2E_SKU or E2E_PRODUCT_ID, E2E_WP (wp-cli
 * command, default "wp"), E2E_SCENARIO, E2E_POLL, E2E_COOKIE (optional Cookie
 * header, e.g. to test a store in "coming soon" mode as a logged-in user).
 *
 * @package Recurra
 */

// phpcs:disable WordPress.WP.AlternativeFunctions,WordPress.Security.EscapeOutput,WordPress.WP.GlobalVariablesOverride,WordPress.PHP.DevelopmentFunctions,WordPress.PHP.DiscouragedPHPFunctions -- CLI test script; shells out to wp-cli.

$base   = getenv( 'E2E_BASE' ) ? getenv( 'E2E_BASE' ) : 'http://wordpress';
$host   = getenv( 'E2E_HOST' ) ? getenv( 'E2E_HOST' ) : 'localhost:8080';
$sku    = getenv( 'E2E_SKU' ) ? getenv( 'E2E_SKU' ) : 'coffee-beans';
$pid    = (int) getenv( 'E2E_PRODUCT_ID' );
$wp     = getenv( 'E2E_WP' ) ? getenv( 'E2E_WP' ) : 'wp';
$origin = preg_replace( '#^(https?://[^/]+).*$#', '$1', $base );

/**
 * HTTP request. Returns [status, headers(lowercase), decoded-or-raw body].
 *
 * @param string     $method  Method.
 * @param string     $url     URL (absolute; host is rewritten to $base).
 * @param array|null $body    JSON body.
 * @param array      $headers Extra headers.
 */
function http( string $method, string $url, ?array $body = null, array $headers = array() ): array {
	global $origin, $host;
	$url = preg_replace( '#^https?://[^/]+#', $origin, $url );
	$h   = array( 'Host: ' . $host, 'Accept: application/json' );
	if ( getenv( 'E2E_COOKIE' ) ) {
		$h[] = 'Cookie: ' . getenv( 'E2E_COOKIE' );
	}
	foreach ( $headers as $k => $v ) {
		$h[] = $k . ': ' . $v;
	}
	$opts = array(
		'method'        => $method,
		'header'        => '',
		'ignore_errors' => true,
		'timeout'       => 30,
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
 * Fails the run.
 *
 * @param string $msg  Message.
 * @param mixed  $data Data.
 */
function fail( string $msg, $data = null ): void {
	fwrite( STDERR, "E2E FAIL: {$msg}\n" . ( null === $data ? '' : print_r( $data, true ) . "\n" ) );
	exit( 1 );
}

$store = '/wp-json/wc/store/v1';

// 1. Find the product.
list( $s, , $products ) = http( 'GET', $base . $store . '/products?per_page=100' );
$product_id             = $pid;
foreach ( is_array( $products ) ? $products : array() as $p ) {
	if ( ! $pid && ( $p['sku'] ?? '' ) === $sku ) {
		$product_id = (int) $p['id'];
	}
}
$product_id || fail( "product {$sku} not found (HTTP {$s})", $products );

// 2. Cart session + add item.
list( , $hdr )      = http( 'GET', $base . $store . '/cart' );
$auth               = array(
	'Nonce'      => $hdr['nonce'] ?? '',
	'Cart-Token' => $hdr['cart-token'] ?? '',
);
list( $s, , $cart ) = http(
	'POST',
	$base . $store . '/cart/add-item',
	array(
		'id'       => $product_id,
		'quantity' => 1,
	),
	$auth
);
200 === $s || 201 === $s || fail( "add-item HTTP {$s}", $cart );

// 3. Block-checkout order with payment method "recurra".
$addr                   = array(
	'first_name' => 'Demo',
	'last_name'  => 'Customer',
	'address_1'  => '1 Test Street',
	'city'       => 'San Francisco',
	'state'      => 'CA',
	'postcode'   => '94103',
	'country'    => 'US',
);
list( $s, , $checkout ) = http(
	'POST',
	$base . $store . '/checkout',
	array(
		'billing_address'  => $addr + array( 'email' => 'customer@example.com' ),
		'shipping_address' => $addr,
		'payment_method'   => 'recurra',
	),
	$auth
);
( 200 === $s && ! empty( $checkout['order_id'] ) ) || fail( "checkout HTTP {$s}", $checkout );
$order_id = (int) $checkout['order_id'];
$redirect = (string) ( $checkout['payment_result']['redirect_url'] ?? '' );
echo "order #{$order_id} placed via Store API, status={$checkout['status']}\n";
strpos( $redirect, 'order-pay' ) !== false || fail( 'no pay-page redirect', $checkout );

// 4. Pay page renders the Recurra UI and its config.
list( $s, , $html ) = http( 'GET', $redirect, null, array( 'Accept' => 'text/html' ) );
( 200 === $s && is_string( $html ) ) || fail( "pay page HTTP {$s}" );
preg_match( '/id="recurra-pay"[^>]*data-config="([^"]+)"/', $html, $m ) || fail( 'Recurra pay page config not found' );
$cfg = json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true );
is_array( $cfg ) || fail( 'pay page config is not JSON' );
strpos( $html, 'MOCK MODE' ) !== false || fail( 'MOCK banner missing' );
strpos( $html, 'assets/js/pay-page.js' ) !== false || fail( 'pay-page.js not enqueued' );
echo 'pay page OK (mock=' . var_export( $cfg['mock'], true ) . ")\n";

$rest  = $cfg['restBase'];
$q     = '?key=' . rawurlencode( $cfg['orderKey'] );
$rauth = array(
	'X-Recurra-Nonce' => $cfg['nonce'],
	'X-WP-Nonce'      => $cfg['restNonce'],
);

// Security: wrong key and missing nonce are rejected.
list( $s ) = http( 'GET', $rest . '/status?key=wc_order_wrong', null, $rauth );
404 === $s || fail( "wrong key should be 404, got {$s}" );
list( $s ) = http( 'GET', $rest . '/status' . $q );
403 === $s || fail( "missing nonce should be 403, got {$s}" );

// 5. Tokens + quote (USDC on Arbitrum).
list( $s, , $tok ) = http( 'GET', $rest . '/tokens' . $q, null, $rauth );
200 === $s || fail( "tokens HTTP {$s}", $tok );
$asset = 'nep141:arb-0xaf88d065e77c8cc2239327c5edb3a432268e5831.omft.near';
in_array( $asset, array_column( $tok['tokens'], 'assetId' ), true ) || fail( 'Arbitrum USDC not offered', $tok );

list( $s, , $bad ) = http(
	'POST',
	$rest . '/quote' . $q,
	array(
		'origin_asset' => $asset,
		'refund_to'    => 'not-an-address',
	),
	$rauth
);
400 === $s || fail( "invalid refund address should be 400, got {$s}", $bad );

$refund = '0x' . str_repeat( 'ab', 20 );

// Mock outcome for this run (success | refunded | incomplete | failed | double).
// "double": the quote is replaced after OUR deadline and BOTH addresses get paid.
$scenario = getenv( 'E2E_SCENARIO' ) ? getenv( 'E2E_SCENARIO' ) : 'success';
in_array( $scenario, array( 'success', 'refunded', 'incomplete', 'failed', 'double' ), true ) || fail( "unknown E2E_SCENARIO {$scenario}" );
shell_exec( $wp . ' option update recurra_mock_scenario ' . escapeshellarg( 'double' === $scenario ? 'success' : $scenario ) . ' --quiet 2>/dev/null' );

list( $s, , $quote ) = http(
	'POST',
	$rest . '/quote' . $q,
	array(
		'origin_asset' => $asset,
		'refund_to'    => $refund,
	),
	$rauth
);
200 === $s || fail( "quote HTTP {$s}", $quote );
echo "quote ({$scenario}): send {$quote['quote']['amountInFormatted']} {$quote['quote']['token']} to {$quote['quote']['depositAddress']}\n";

list( $s ) = http(
	'POST',
	$rest . '/quote' . $q,
	array(
		'origin_asset' => $asset,
		'refund_to'    => $refund,
	),
	$rauth
);
409 === $s || fail( "second quote while first is active should be 409, got {$s}" );

// Countdown = OUR deadline (quote lifetime 30 min), not 1Click's (+72 h).
$left = (int) $quote['quote']['deadlineTs'] - time();
( $left > 25 * 60 && $left <= 30 * 60 + 5 ) || fail( "countdown should be ~30 min, got {$left}s", $quote['quote'] );
echo 'countdown OK: ' . intdiv( $left, 60 ) . " min, ends {$quote['quote']['deadlineText']} (site time); 1Click deadline is +72 h\n";

$first_address = $quote['quote']['depositAddress'];
if ( 'double' === $scenario ) {
	// Our deadline passes while the first address is still PENDING_DEPOSIT.
	shell_exec( $wp . ' eval ' . escapeshellarg( '$o = wc_get_order(' . $order_id . '); $o->update_meta_data( "_recurra_deadline", gmdate( "Y-m-d\\TH:i:s.000\\Z", time() - 60 ) ); $o->save();' ) . ' 2>/dev/null' );
	list( $s, , $quote ) = http(
		'POST',
		$rest . '/quote' . $q,
		array(
			'origin_asset' => $asset,
			'refund_to'    => $refund,
		),
		$rauth
	);
	200 === $s || fail( "new quote after our deadline should be allowed, got {$s}", $quote );
	$quote['quote']['depositAddress'] !== $first_address || fail( 'new quote reused the old address' );
	echo "new quote after our deadline: {$quote['quote']['depositAddress']} (old {$first_address} stays watched); customer pays BOTH\n";
}
shell_exec( $wp . ' option delete recurra_mock_scenario --quiet 2>/dev/null' );

// 6. Watch the order. E2E_POLL=1 (default): poll the REST status endpoint like the
// pay page. E2E_POLL=0: customer closed the tab — only the backup poller
// (Action Scheduler via the `cron` service) may move the order; read it via WP-CLI.
$poll    = '0' !== getenv( 'E2E_POLL' );
$timeout = $poll ? 90 : 180;
$expect  = array(
	'success'    => array( 'processing', 'completed' ),
	'double'     => array( 'processing', 'completed' ),
	'refunded'   => array( 'failed' ),
	'failed'     => array( 'failed' ),
	'incomplete' => array( 'on-hold' ),
)[ $scenario ];
$seen    = array();
$start   = time();
while ( time() - $start < $timeout ) {
	if ( $poll ) {
		list( $s, , $st ) = http( 'GET', $rest . '/status' . $q, null, $rauth );
		200 === $s || fail( "status HTTP {$s}", $st );
		$label = $st['orderStatus'] . ' (' . $st['status'] . ')';
	} else {
		$label = trim( (string) shell_exec( $wp . ' eval ' . escapeshellarg( '$o = wc_get_order(' . $order_id . '); echo $o->get_status() . " (" . $o->get_meta("_recurra_last_status") . ")";' ) . ' 2>/dev/null' ) );
	}
	if ( end( $seen ) !== $label ) {
		$seen[] = $label;
		printf( "  t+%3ds  order=%s\n", time() - $start, $label );
	}
	$current = strtok( $label, ' ' );
	if ( in_array( $current, $expect, true ) && ( 'incomplete' !== $scenario || false !== strpos( $label, 'INCOMPLETE' ) ) ) {
		break;
	}
	sleep( $poll ? 3 : 5 );
}
$statuses = array_values( array_unique( array_map( static fn( $l ) => strtok( $l, ' ' ), $seen ) ) );
$final    = end( $statuses );
in_array( $final, $expect, true ) || fail( 'unexpected final status: ' . implode( ' → ', $statuses ) );
if ( 'double' === $scenario ) {
	// The backup poller (cron service, every 60 s) watches the replaced address too.
	$notes = '';
	for ( $i = 0; $i < 30 && false === strpos( $notes, 'EXTRA PAYMENT' ); $i++ ) {
		sleep( 5 );
		$notes = (string) shell_exec( $wp . ' eval ' . escapeshellarg( 'foreach ( wc_get_order_notes( array( "order_id" => ' . $order_id . ' ) ) as $n ) { echo $n->content, "\n"; } echo "paid_address=", wc_get_order(' . $order_id . ')->get_meta( "_recurra_paid_address" ), "\n";' ) . ' 2>/dev/null' );
	}
	false !== strpos( $notes, 'EXTRA PAYMENT' ) || fail( 'no extra-payment note for the second address', $notes );
	1 === substr_count( $notes, 'swap complete' ) || fail( 'order must be completed exactly once', $notes );
	preg_match( '/paid_address=(\S+)/', $notes, $pm );
	echo 'paid once via ' . ( $pm[1] ?? '?' ) . "; extra payment flagged for manual refund\n";
}
if ( in_array( $scenario, array( 'success', 'double' ), true ) && $poll ) {
	( in_array( 'pending', $statuses, true ) && in_array( 'on-hold', $statuses, true ) ) || fail( 'expected pending → on-hold before paid: ' . implode( ' → ', $statuses ) );
}

printf( "E2E OK (%s, %s): order #%d %s in %ds\n", $scenario, $poll ? 'pay-page polling' : 'backup poller only', $order_id, implode( ' → ', $statuses ), time() - $start );
