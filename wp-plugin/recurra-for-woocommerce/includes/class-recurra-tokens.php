<?php
/**
 * Supported-token list (cached), per-order filtering and refund address checks.
 *
 * @package Recurra
 */

defined( 'ABSPATH' ) || exit;

/**
 * Token helpers.
 */
final class Recurra_Tokens {

	/**
	 * Chains whose deposits need a memo (depositMode MEMO). Excluded in the MVP.
	 */
	const MEMO_CHAINS = array( 'xrp', 'stellar', 'ton' );

	/**
	 * USD minimums for ORIGIN_CHAIN -> DESTINATION_CHAIN swaps, in micro-USD
	 * (docs.near-intents.org/resources/swap-limits).
	 */
	const MIN_USD_MICRO = array(
		'tron' => '100000000',
		'bsc'  => '1000000000',
	);

	/**
	 * EVM chains (0x addresses).
	 */
	const EVM_CHAINS = array( 'eth', 'arb', 'base', 'op', 'pol', 'bsc', 'avax', 'gnosis', 'bera', 'scroll', 'monad', 'xlayer', 'plasma', 'hlevm', 'adi', 'hood' );

	/**
	 * Human chain names for the pay page.
	 */
	const CHAIN_NAMES = array(
		'arb'  => 'Arbitrum',
		'base' => 'Base',
		'eth'  => 'Ethereum',
		'sol'  => 'Solana',
		'op'   => 'Optimism',
		'pol'  => 'Polygon',
		'near' => 'NEAR',
		'btc'  => 'Bitcoin',
		'bsc'  => 'BNB Chain',
		'tron' => 'Tron',
		'avax' => 'Avalanche',
	);

	/**
	 * Full token list for a client, cached 10 minutes per mode.
	 *
	 * @param Recurra_OneClick $client Client.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public static function all( Recurra_OneClick $client ) {
		$key    = 'recurra_tokens_' . ( $client->is_mock() ? 'mock' : 'live' );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$tokens = $client->tokens();
		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}
		$clean = array();
		foreach ( $tokens as $t ) {
			if ( ! is_array( $t ) || empty( $t['assetId'] ) || empty( $t['blockchain'] ) || ! isset( $t['decimals'], $t['symbol'] ) ) {
				continue;
			}
			$clean[] = array(
				'assetId'    => (string) $t['assetId'],
				'blockchain' => (string) $t['blockchain'],
				'symbol'     => (string) $t['symbol'],
				'decimals'   => (int) $t['decimals'],
			);
		}
		set_transient( $key, $clean, 10 * MINUTE_IN_SECONDS );
		return $clean;
	}

	/**
	 * Tokens a customer may pay an order with.
	 *
	 * @param Recurra_OneClick $client         Client.
	 * @param array<string>    $allowed_chains Allowed blockchains.
	 * @param string           $destination    Destination asset id (excluded as origin).
	 * @param string           $amount_micro   Order total in micro-USD.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public static function for_order( Recurra_OneClick $client, array $allowed_chains, string $destination, string $amount_micro ) {
		$all = self::all( $client );
		if ( is_wp_error( $all ) ) {
			return $all;
		}
		$out = array();
		foreach ( $all as $t ) {
			$chain = $t['blockchain'];
			if ( ! in_array( $chain, $allowed_chains, true ) || in_array( $chain, self::MEMO_CHAINS, true ) ) {
				continue;
			}
			if ( $t['assetId'] === $destination ) {
				continue;
			}
			if ( isset( self::MIN_USD_MICRO[ $chain ] ) && Recurra_Amount::cmp( $amount_micro, self::MIN_USD_MICRO[ $chain ] ) < 0 ) {
				continue;
			}
			$t['chainName'] = self::CHAIN_NAMES[ $chain ] ?? strtoupper( $chain );
			$out[]          = $t;
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return array( $a['chainName'], $a['symbol'] ) <=> array( $b['chainName'], $b['symbol'] );
			}
		);
		return $out;
	}

	/**
	 * Finds a token by asset id in a list.
	 *
	 * @param array<int, array<string, mixed>> $tokens   Tokens.
	 * @param string                           $asset_id Asset id.
	 * @return array<string, mixed>|null
	 */
	public static function find( array $tokens, string $asset_id ): ?array {
		foreach ( $tokens as $t ) {
			if ( $t['assetId'] === $asset_id ) {
				return $t;
			}
		}
		return null;
	}

	/**
	 * Basic per-chain address format check for refund addresses.
	 *
	 * @param string $chain   Blockchain id.
	 * @param string $address Address.
	 */
	public static function is_valid_address( string $chain, string $address ): bool {
		if ( '' === $address || strlen( $address ) > 128 || preg_match( '/\s/', $address ) ) {
			return false;
		}
		if ( in_array( $chain, self::EVM_CHAINS, true ) ) {
			return 1 === preg_match( '/^0x[a-fA-F0-9]{40}$/', $address );
		}
		switch ( $chain ) {
			case 'near':
				return self::is_valid_near_account( $address );
			case 'sol':
				return 1 === preg_match( '/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $address );
			case 'btc':
				return 1 === preg_match( '/^(bc1[ac-hj-np-z02-9]{11,71}|[13][1-9A-HJ-NP-Za-km-z]{25,34})$/', $address );
			case 'tron':
				return 1 === preg_match( '/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $address );
			default:
				return 1 === preg_match( '/^[A-Za-z0-9._:\-]{8,128}$/', $address );
		}
	}

	/**
	 * NEAR account id (named or 64-hex implicit), per nomicon rules.
	 *
	 * @param string $account Account id.
	 */
	public static function is_valid_near_account( string $account ): bool {
		$len = strlen( $account );
		if ( $len < 2 || $len > 64 ) {
			return false;
		}
		return 1 === preg_match( '/^(([a-z\d]+[\-_])*[a-z\d]+\.)*([a-z\d]+[\-_])*[a-z\d]+$/', $account );
	}

	/**
	 * Whether a NEAR account id belongs to testnet (top-level account "testnet").
	 *
	 * @param string $account Account id.
	 */
	public static function is_testnet_account( string $account ): bool {
		$account = strtolower( trim( $account ) );
		return 'testnet' === $account || str_ends_with( $account, '.testnet' );
	}

	/**
	 * Whether a merchant account may be used in a mode. Live mode is mainnet-only
	 * (1Click, native USDC and the RPC are mainnet), so testnet accounts are refused;
	 * mock mode moves no funds and accepts any account.
	 *
	 * @param string $mode    'live' | 'mock'.
	 * @param string $account Merchant account id.
	 */
	public static function merchant_allowed_in_mode( string $mode, string $account ): bool {
		return 'mock' === $mode || ! self::is_testnet_account( $account );
	}

	/**
	 * Placeholder hint for the refund address field.
	 *
	 * @param string $chain Blockchain id.
	 */
	public static function address_hint( string $chain ): string {
		if ( in_array( $chain, self::EVM_CHAINS, true ) ) {
			return '0x…';
		}
		$hints = array(
			'near' => 'you.near',
			'sol'  => 'Solana address',
			'btc'  => 'bc1…',
			'tron' => 'T…',
		);
		return $hints[ $chain ] ?? '';
	}
}
