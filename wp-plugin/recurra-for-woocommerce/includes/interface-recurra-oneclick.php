<?php
/**
 * 1Click Swap API surface used by Recurra. Implemented by the live client and
 * by the deterministic mock (same request/response shapes).
 *
 * Shapes follow https://1click.chaindefuser.com/docs/v0/openapi.yaml
 * (TokenResponse, QuoteRequest/QuoteResponse, GetExecutionStatusResponse,
 * SubmitDepositTxRequest).
 *
 * @package Recurra
 */

defined( 'ABSPATH' ) || exit;

interface Recurra_OneClick {

	/**
	 * GET /v0/tokens.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function tokens();

	/**
	 * POST /v0/quote.
	 *
	 * @param array<string, mixed> $request QuoteRequest.
	 * @return array<string, mixed>|WP_Error QuoteResponse.
	 */
	public function quote( array $request );

	/**
	 * GET /v0/status?depositAddress=...
	 *
	 * @param string $deposit_address Deposit address from the quote.
	 * @return array<string, mixed>|WP_Error GetExecutionStatusResponse.
	 */
	public function status( string $deposit_address );

	/**
	 * POST /v0/deposit/submit (optional speed-up).
	 *
	 * @param string $deposit_address Deposit address.
	 * @param string $tx_hash         Origin-chain transaction hash.
	 * @return array<string, mixed>|WP_Error
	 */
	public function submit_deposit( string $deposit_address, string $tx_hash );

	/**
	 * Whether this is the mock implementation.
	 */
	public function is_mock(): bool;
}
