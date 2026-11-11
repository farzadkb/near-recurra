<?php
/**
 * Dependency-free unit tests for the pure helpers (amount math, address
 * validation, token filtering, mock pricing). Run: php tests/unit.php
 *
 * @package Recurra
 */

// phpcs:disable WordPress.WP.AlternativeFunctions,WordPress.Security.EscapeOutput,WordPress.PHP.DevelopmentFunctions

define( 'ABSPATH', __DIR__ . '/' );
define( 'RECURRA_VERSION', 'test' );
define( 'MINUTE_IN_SECONDS', 60 );

// Minimal WordPress stubs.
$GLOBALS['recurra_test_options'] = array();
/**
 * Stub.
 *
 * @param string $s String.
 */
function esc_html( $s ) {
	return htmlspecialchars( (string) $s );
}
/**
 * Stub.
 *
 * @param string $k Key.
 */
function get_transient( $k ) {
	return false;
}
/**
 * Stub.
 */
function set_transient() {
	return true;
}
/**
 * Stub.
 *
 * @param mixed $v Value.
 */
function is_wp_error( $v ) {
	return $v instanceof WP_Error;
}
/**
 * Stub.
 *
 * @param mixed $v Value.
 */
function wp_json_encode( $v ) {
	return json_encode( $v );
}
/**
 * Stub.
 */
function wp_generate_uuid4() {
	return '00000000-0000-4000-8000-000000000000';
}
/**
 * Stub.
 */
function wp_rand() {
	return 4;
}
/**
 * Stub.
 *
 * @param string $s String.
 */
function sanitize_key( $s ) {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) );
}
/**
 * Stub.
 *
 * @param string $k Key.
 * @param mixed  $d Default.
 */
function get_option( $k, $d = false ) {
	return $GLOBALS['recurra_test_options'][ $k ] ?? $d;
}
/**
 * Stub.
 *
 * @param string $k Key.
 * @param mixed  $v Value.
 */
function update_option( $k, $v ) {
	$GLOBALS['recurra_test_options'][ $k ] = $v;
	return true;
}
/** Stub WP_Error. */
class WP_Error {
	/**
	 * Message.
	 *
	 * @var string
	 */
	public $m;
	/**
	 * Constructor.
	 *
	 * @param string $c Code.
	 * @param string $m Message.
	 */
	public function __construct( $c = '', $m = '' ) {
		$this->m = $m;
	}
}

require __DIR__ . '/../includes/class-recurra-amount.php';
require __DIR__ . '/../includes/interface-recurra-oneclick.php';
require __DIR__ . '/../includes/class-oneclick-mock.php';
require __DIR__ . '/../includes/class-recurra-tokens.php';

$failures = 0;
$count    = 0;
/**
 * Assert equal.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual   Actual.
 * @param string $label    Label.
 */
function eq( $expected, $actual, string $label ): void {
	global $failures, $count;
	++$count;
	if ( $expected !== $actual ) {
		++$failures;
		echo "FAIL {$label}: expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
	}
}
/**
 * Assert throws.
 *
 * @param callable $fn    Function.
 * @param string   $label Label.
 */
function throws( callable $fn, string $label ): void {
	global $failures, $count;
	++$count;
	try {
		$fn();
		++$failures;
		echo "FAIL {$label}: no exception\n";
	} catch ( InvalidArgumentException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
	}
}

// Recurra_Amount.
eq( '12500000', Recurra_Amount::decimal_to_base( '12.50', 6 ), 'decimal_to_base 12.50' );
eq( '3000000', Recurra_Amount::decimal_to_base( '3', 6 ), 'decimal_to_base 3' );
eq( '1', Recurra_Amount::decimal_to_base( '0.000001', 6 ), 'decimal_to_base 1e-6' );
eq( '0', Recurra_Amount::decimal_to_base( '0.00', 6 ), 'decimal_to_base zero' );
eq( '1000000000000000000', Recurra_Amount::decimal_to_base( '1', 18 ), 'decimal_to_base 18 decimals' );
throws( fn() => Recurra_Amount::decimal_to_base( '0.0000001', 6 ), 'precision loss refused' );
throws( fn() => Recurra_Amount::decimal_to_base( '-1', 6 ), 'negative refused' );
throws( fn() => Recurra_Amount::decimal_to_base( '1e3', 6 ), 'scientific refused' );
throws( fn() => Recurra_Amount::decimal_to_base( '1,5', 6 ), 'comma refused' );
eq( '12.5', Recurra_Amount::base_to_decimal( '12500000', 6 ), 'base_to_decimal' );
eq( '0.000001', Recurra_Amount::base_to_decimal( '1', 6 ), 'base_to_decimal small' );
eq( '2', Recurra_Amount::base_to_decimal( '2000000', 6 ), 'base_to_decimal integer' );
eq( 1, Recurra_Amount::cmp( '10', '9' ), 'cmp length' );
eq( -1, Recurra_Amount::cmp( '0009', '10' ), 'cmp leading zeros' );
eq( 0, Recurra_Amount::cmp( '00', '0' ), 'cmp zero' );
eq( '121932631112635269', Recurra_Amount::mul( '123456789', '987654321' ), 'mul' );
eq( '333', Recurra_Amount::div_small( '1000', 3 ), 'div floor' );
eq( '334', Recurra_Amount::div_small( '1000', 3, true ), 'div ceil' );
eq( '1000000000000000000001', Recurra_Amount::add( '1000000000000000000000', '1' ), 'add big' );
eq( '12', Recurra_Amount::shift_down( '12345', 3 ), 'shift_down floor' );
eq( '13', Recurra_Amount::shift_down( '12001', 3, true ), 'shift_down ceil' );
eq( '12', Recurra_Amount::shift_down( '12000', 3, true ), 'shift_down exact' );

// Addresses.
eq( true, Recurra_Tokens::is_valid_address( 'arb', '0x' . str_repeat( 'aB', 20 ) ), 'evm ok' );
eq( false, Recurra_Tokens::is_valid_address( 'arb', '0x123' ), 'evm short' );
eq( true, Recurra_Tokens::is_valid_address( 'near', 'alice.near' ), 'near named' );
eq( true, Recurra_Tokens::is_valid_address( 'near', str_repeat( 'a1', 32 ) ), 'near implicit' );
eq( false, Recurra_Tokens::is_valid_address( 'near', 'Alice.near' ), 'near uppercase' );
eq( false, Recurra_Tokens::is_valid_address( 'near', 'a..near' ), 'near double dot' );
eq( true, Recurra_Tokens::is_valid_address( 'sol', 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v' ), 'sol ok' );
eq( false, Recurra_Tokens::is_valid_address( 'sol', '0OIl' . str_repeat( 'a', 30 ) ), 'sol bad base58' );
eq( true, Recurra_Tokens::is_valid_address( 'btc', 'bc1qar0srrr7xfkvy5l643lydnw9re59gtzzwf5mdq' ), 'btc bech32' );
eq( true, Recurra_Tokens::is_valid_address( 'btc', '1BoatSLRHtKNngkdXEeobR76b53LETtpyT' ), 'btc base58' );
eq( false, Recurra_Tokens::is_valid_address( 'btc', 'bc1 space' ), 'btc whitespace' );
eq( false, Recurra_Tokens::is_valid_address( 'eth', '' ), 'empty' );

// Token filtering (mock list): BSC/Tron minimums, memo chains, destination excluded.
$mock    = new Recurra_OneClick_Mock();
$usdc    = 'nep141:17208628f84f5d6ad33f0da3bbbeb27ffcb398eac501a31bd6ad2011e36133a1';
$allowed = Recurra_Tokens::for_order( $mock, array( 'arb', 'near', 'btc' ), $usdc, '2000000' );
$ids     = array_column( $allowed, 'assetId' );
eq( false, in_array( $usdc, $ids, true ), 'destination asset not offered as origin' );
eq( true, in_array( 'nep141:wrap.near', $ids, true ), 'wNEAR offered' );
eq( false, in_array( 'nep141:sol-5ce3bf3a31af18be40ba30f721101b4341690186.omft.near', $ids, true ), 'sol not allowed' );

// Mock pricing: EXACT_OUTPUT 2 USDC from Arbitrum USDC = 2 * 1.005 = 2.01.
$req = array(
	'dry'               => false,
	'swapType'          => 'EXACT_OUTPUT',
	'slippageTolerance' => 100,
	'originAsset'       => 'nep141:arb-0xaf88d065e77c8cc2239327c5edb3a432268e5831.omft.near',
	'depositType'       => 'ORIGIN_CHAIN',
	'destinationAsset'  => $usdc,
	'amount'            => '2000000',
	'recipient'         => 'merchant.near',
	'recipientType'     => 'DESTINATION_CHAIN',
	'refundTo'          => '0x' . str_repeat( 'a', 40 ),
	'refundType'        => 'ORIGIN_CHAIN',
	'deadline'          => '2030-01-01T00:00:00.000Z',
);
$q   = $mock->quote( $req );
eq( '2010000', $q['quote']['amountIn'], 'mock amountIn USDC' );
eq( '2.01', $q['quote']['amountInFormatted'], 'mock amountInFormatted' );
eq( '2000000', $q['quote']['amountOut'], 'mock amountOut' );
eq( 0, strpos( $q['quote']['depositAddress'], 'mock-' ), 'mock deposit address' );
$st = $mock->status( $q['quote']['depositAddress'] );
eq( 'PENDING_DEPOSIT', $st['status'], 'mock starts pending' );
eq( $q['quote']['depositAddress'], $st['quoteResponse']['quote']['depositAddress'], 'mock status echoes quote' );

// Advance the clock by rewriting the stored creation time.
$key = Recurra_OneClick_Mock::OPTION_PREFIX . $q['quote']['depositAddress'];
foreach ( array(
	6  => 'KNOWN_DEPOSIT_TX',
	16 => 'PROCESSING',
	31 => 'SUCCESS',
) as $ago => $expected ) {
	$GLOBALS['recurra_test_options'][ $key ]['created'] = time() - $ago;
	$st = $mock->status( $q['quote']['depositAddress'] );
	eq( $expected, $st['status'], "mock status after {$ago}s" );
}
eq( '2000000', $st['swapDetails']['amountOut'], 'mock success amountOut' );
eq( 1, count( $st['swapDetails']['nearTxHashes'] ), 'mock success near tx' );

// BTC: 2 USDC at 70,000 USD/BTC = 2857.14 sat -> ceil 2858 -> * 1.005 ceil = 2873.
$req['originAsset'] = 'nep141:btc.omft.near';
$req['dry']         = true;
$q                  = $mock->quote( $req );
eq( '2873', $q['quote']['amountIn'], 'mock amountIn BTC' );
eq( false, isset( $q['quote']['depositAddress'] ), 'dry quote has no deposit address' );
// wNEAR (24 decimals, big numbers): 2 USD at 3.75 = 0.5333.. NEAR.
$req['originAsset'] = 'nep141:wrap.near';
$q                  = $mock->quote( $req );
eq( '536000000000000000000001', $q['quote']['amountIn'], 'mock amountIn wNEAR (24 decimals)' );

echo "{$count} assertions, {$failures} failures\n";
exit( $failures ? 1 : 0 );
