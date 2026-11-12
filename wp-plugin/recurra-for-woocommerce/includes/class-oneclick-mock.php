<?php
/**
 * Deterministic 1Click mock for judges, demos and CI: zero funds, no network.
 *
 * Same request/response shapes as the live API. A quote gets a deposit address
 * `mock-<hash>`; its status then advances by time since the quote:
 *   0-5 s PENDING_DEPOSIT, 5-15 s KNOWN_DEPOSIT_TX, 15-30 s PROCESSING, >= 30 s SUCCESS.
 *
 * Developers can force another outcome with the option `recurra_mock_scenario`
 * (success | refunded | incomplete | failed), read when the quote is created.
 *
 * @package Recurra
 */

defined( 'ABSPATH' ) || exit;

/**
 * Mock implementation of the 1Click API.
 */
class Recurra_OneClick_Mock implements Recurra_OneClick {

	const OPTION_PREFIX = 'recurra_mock_quote_';

	/**
	 * Status timeline in seconds since quote creation.
	 */
	const T_KNOWN      = 5;
	const T_PROCESSING = 15;
	const T_DONE       = 30;

	/**
	 * Mock token list. Asset ids are real 1Click ids; prices are fixed (micro-USD).
	 *
	 * @return array<int, array{0:string,1:string,2:string,3:int,4:int,5:string}>
	 */
	private static function catalog(): array {
		// assetId, blockchain, symbol, decimals, price in micro-USD, contractAddress.
		return array(
			array( 'nep141:arb-0xaf88d065e77c8cc2239327c5edb3a432268e5831.omft.near', 'arb', 'USDC', 6, 1000000, '0xaf88d065e77c8cc2239327c5edb3a432268e5831' ),
			array( 'nep141:arb.omft.near', 'arb', 'ETH', 18, 2000000000, '' ),
			array( 'nep141:base-0x833589fcd6edb6e08f4c7c32d4f71b54bda02913.omft.near', 'base', 'USDC', 6, 1000000, '0x833589fcd6edb6e08f4c7c32d4f71b54bda02913' ),
			array( 'nep141:eth-0xa0b86991c6218b36c1d19d4a2e9eb0ce3606eb48.omft.near', 'eth', 'USDC', 6, 1000000, '0xa0b86991c6218b36c1d19d4a2e9eb0ce3606eb48' ),
			array( 'nep141:sol-5ce3bf3a31af18be40ba30f721101b4341690186.omft.near', 'sol', 'USDC', 6, 1000000, 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v' ),
			array( 'nep141:wrap.near', 'near', 'wNEAR', 24, 3750000, 'wrap.near' ),
			array( 'nep141:btc.omft.near', 'btc', 'BTC', 8, 70000000000, '' ),
			array( 'nep141:17208628f84f5d6ad33f0da3bbbeb27ffcb398eac501a31bd6ad2011e36133a1', 'near', 'USDC', 6, 1000000, '17208628f84f5d6ad33f0da3bbbeb27ffcb398eac501a31bd6ad2011e36133a1' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function tokens() {
		$now = gmdate( 'Y-m-d\TH:i:s.000\Z' );
		$out = array();
		foreach ( self::catalog() as $t ) {
			$out[] = array(
				'assetId'                            => $t[0],
				'decimals'                           => $t[3],
				'blockchain'                         => $t[1],
				'symbol'                             => $t[2],
				'price'                              => $t[4] / 1000000,
				// Display only (API returns a JSON number).
									'priceUpdatedAt' => $now,
				'contractAddress'                    => $t[5],
			);
		}
		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $request QuoteRequest.
	 */
	public function quote( array $request ) {
		$required = array( 'dry', 'swapType', 'slippageTolerance', 'originAsset', 'depositType', 'destinationAsset', 'amount', 'refundTo', 'refundType', 'recipient', 'recipientType', 'deadline' );
		foreach ( $required as $field ) {
			if ( ! array_key_exists( $field, $request ) ) {
				return new WP_Error( 'recurra_oneclick_http', $field . ' should not be empty', array( 'status' => 400 ) );
			}
		}
		if ( 'EXACT_OUTPUT' !== $request['swapType'] ) {
			return new WP_Error( 'recurra_oneclick_http', 'Mock supports EXACT_OUTPUT only', array( 'status' => 400 ) );
		}
		if ( ! Recurra_Amount::is_uint( $request['amount'] ) ) {
			return new WP_Error( 'recurra_oneclick_http', 'amount must be an integer string', array( 'status' => 400 ) );
		}
		$in  = $this->find( (string) $request['originAsset'] );
		$out = $this->find( (string) $request['destinationAsset'] );
		if ( ! $in || ! $out ) {
			return new WP_Error( 'recurra_oneclick_http', 'Unsupported asset', array( 'status' => 400 ) );
		}

		// amountIn = amountOut * priceOut * 10^decIn / (10^decOut * priceIn), + 0.5% simulated fees, rounded up.
		$amount_out = Recurra_Amount::normalize( (string) $request['amount'] );
		$usd_micro  = Recurra_Amount::shift_down( Recurra_Amount::mul( $amount_out, (string) $out[4] ), $out[3], true );
		$amount_in  = Recurra_Amount::div_small( Recurra_Amount::shift( $usd_micro, $in[3] ), $in[4], true );
		$amount_in  = Recurra_Amount::div_small( Recurra_Amount::mul( $amount_in, '1005' ), 1000, true );
		$usd_in     = Recurra_Amount::shift_down( Recurra_Amount::mul( $amount_in, (string) $in[4] ), $in[3] );
		$usd_out    = Recurra_Amount::base_to_decimal( $usd_micro, 6 );
		$usd_in     = Recurra_Amount::base_to_decimal( $usd_in, 6 );

		$now      = time();
		$quote    = array(
			'amountIn'           => $amount_in,
			'amountInFormatted'  => Recurra_Amount::base_to_decimal( $amount_in, $in[3] ),
			'amountInUsd'        => $usd_in,
			'minAmountIn'        => $amount_in,
			'amountOut'          => $amount_out,
			'amountOutFormatted' => Recurra_Amount::base_to_decimal( $amount_out, $out[3] ),
			'amountOutUsd'       => $usd_out,
			'minAmountOut'       => $amount_out,
			'timeEstimate'       => self::T_DONE,
		);
		$response = array(
			'correlationId' => wp_generate_uuid4(),
			'timestamp'     => gmdate( 'Y-m-d\TH:i:s.000\Z', $now ),
			'signature'     => 'mock:' . hash( 'sha256', wp_json_encode( $request ) . $now ),
			'quoteRequest'  => $request,
			'quote'         => $quote,
		);
		if ( ! empty( $request['dry'] ) ) {
			return $response;
		}

		$address                             = 'mock-' . substr( hash( 'sha256', wp_json_encode( $request ) . microtime() . wp_rand() ), 0, 24 );
		$response['quote']['depositAddress'] = $address;
		// Like the live API (observed 2026-11-11): 1Click's own deadline is 72 h after ours.
		$late                                  = gmdate( 'Y-m-d\TH:i:s.000\Z', (int) strtotime( (string) $request['deadline'] ) + 72 * HOUR_IN_SECONDS );
		$response['quote']['deadline']         = $late;
		$response['quote']['timeWhenInactive'] = $late;

		update_option(
			self::OPTION_PREFIX . $address,
			array(
				'created'  => $now,
				'scenario' => sanitize_key( (string) get_option( 'recurra_mock_scenario', 'success' ) ),
				'response' => $response,
			),
			false
		);
		return $response;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $deposit_address Deposit address.
	 */
	public function status( string $deposit_address ) {
		$stored = get_option( self::OPTION_PREFIX . $deposit_address );
		if ( ! is_array( $stored ) ) {
			return new WP_Error( 'recurra_oneclick_http', 'Deposit address ' . $deposit_address . ' not found', array( 'status' => 404 ) );
		}
		$elapsed  = time() - (int) $stored['created'];
		$response = $stored['response'];
		$quote    = $response['quote'];
		$in       = $this->find( (string) $response['quoteRequest']['originAsset'] );

		if ( $elapsed < self::T_KNOWN ) {
			$status = 'PENDING_DEPOSIT';
		} elseif ( $elapsed < self::T_PROCESSING ) {
			$status = 'KNOWN_DEPOSIT_TX';
		} elseif ( $elapsed < self::T_DONE ) {
			$status = 'PROCESSING';
		} else {
			$status = array(
				'refunded'   => 'REFUNDED',
				'incomplete' => 'INCOMPLETE_DEPOSIT',
				'failed'     => 'FAILED',
			)[ $stored['scenario'] ] ?? 'SUCCESS';
		}

		$seed    = substr( $deposit_address, 5 );
		$details = array(
			'intentHashes'             => array(),
			'nearTxHashes'             => array(),
			'originChainTxHashes'      => array(),
			'destinationChainTxHashes' => array(),
		);
		if ( 'PENDING_DEPOSIT' !== $status ) {
			$deposited                           = 'INCOMPLETE_DEPOSIT' === $status ? Recurra_Amount::div_small( $quote['amountIn'], 2 ) : $quote['amountIn'];
			$details['depositedAmount']          = $deposited;
			$details['depositedAmountFormatted'] = Recurra_Amount::base_to_decimal( $deposited, $in ? $in[3] : 0 );
			$details['originChainTxHashes']      = array(
				array(
					'hash'        => '0xmock' . $seed,
					'explorerUrl' => '',
				),
			);
		}
		if ( 'SUCCESS' === $status ) {
			$details['intentHashes']       = array( 'mock-intent-' . $seed );
			$details['nearTxHashes']       = array( 'mock-near-tx-' . $seed );
			$details['amountIn']           = $quote['amountIn'];
			$details['amountInFormatted']  = $quote['amountInFormatted'];
			$details['amountInUsd']        = $quote['amountInUsd'];
			$details['amountOut']          = $quote['amountOut'];
			$details['amountOutFormatted'] = $quote['amountOutFormatted'];
			$details['amountOutUsd']       = $quote['amountOutUsd'];
			$details['slippage']           = 0;
			$details['refundedAmount']     = '0';
		}
		if ( 'REFUNDED' === $status ) {
			$details['refundedAmount']          = $quote['amountIn'];
			$details['refundedAmountFormatted'] = $quote['amountInFormatted'];
			$details['refundReason']            = 'MOCK_REFUND';
		}

		return array(
			'correlationId' => $response['correlationId'],
			'quoteResponse' => $response,
			'status'        => $status,
			'updatedAt'     => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
			'swapDetails'   => $details,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $deposit_address Deposit address.
	 * @param string $tx_hash         Transaction hash.
	 */
	public function submit_deposit( string $deposit_address, string $tx_hash ) {
		$status = $this->status( $deposit_address );
		return is_wp_error( $status ) ? $status : array( 'status' => $status['status'] );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_mock(): bool {
		return true;
	}

	/**
	 * Finds a catalog entry by asset id.
	 *
	 * @param string $asset_id Asset id.
	 * @return array{0:string,1:string,2:string,3:int,4:int,5:string}|null
	 */
	private function find( string $asset_id ): ?array {
		foreach ( self::catalog() as $t ) {
			if ( $t[0] === $asset_id ) {
				return $t;
			}
		}
		return null;
	}
}
