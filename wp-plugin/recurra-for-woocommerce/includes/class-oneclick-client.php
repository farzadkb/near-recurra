<?php
/**
 * Live 1Click Swap API client (wp_remote_*; no external dependencies).
 *
 * @package Recurra
 */

defined( 'ABSPATH' ) || exit;

/**
 * HTTP client for https://1click.chaindefuser.com.
 */
class Recurra_OneClick_Client implements Recurra_OneClick {

	const BASE_URL = 'https://1click.chaindefuser.com';

	/**
	 * Optional partner key, sent as X-API-Key. Empty = +0.25% fee.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Constructor.
	 *
	 * @param string $api_key Partner key (JWT) or empty.
	 */
	public function __construct( string $api_key = '' ) {
		$this->api_key = $api_key;
	}

	/**
	 * {@inheritDoc}
	 */
	public function tokens() {
		return $this->request( 'GET', '/v0/tokens' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $request QuoteRequest.
	 */
	public function quote( array $request ) {
		return $this->request( 'POST', '/v0/quote', $request, 30 );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $deposit_address Deposit address.
	 */
	public function status( string $deposit_address ) {
		return $this->request( 'GET', '/v0/status?' . http_build_query( array( 'depositAddress' => $deposit_address ) ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $deposit_address Deposit address.
	 * @param string $tx_hash         Transaction hash.
	 */
	public function submit_deposit( string $deposit_address, string $tx_hash ) {
		return $this->request(
			'POST',
			'/v0/deposit/submit',
			array(
				'depositAddress' => $deposit_address,
				'txHash'         => $tx_hash,
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_mock(): bool {
		return false;
	}

	/**
	 * Performs a request. Any 2xx is success (POST /v0/quote answers 201).
	 *
	 * @param string                    $method  HTTP method.
	 * @param string                    $path    Path incl. query.
	 * @param array<string, mixed>|null $body    JSON body.
	 * @param int                       $timeout Seconds.
	 * @return array<mixed>|WP_Error
	 */
	private function request( string $method, string $path, ?array $body = null, int $timeout = 15 ) {
		$headers = array(
			'Accept'     => 'application/json',
			'User-Agent' => 'Recurra-for-WooCommerce/' . RECURRA_VERSION,
		);
		if ( '' !== $this->api_key ) {
			$headers['X-API-Key'] = $this->api_key;
		}
		$args = array(
			'method'  => $method,
			'timeout' => $timeout,
			'headers' => $headers,
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$res = wp_remote_request( self::BASE_URL . $path, $args );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'recurra_oneclick_unreachable', $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code < 200 || $code > 299 ) {
			$message = is_array( $data ) && isset( $data['message'] ) && is_string( $data['message'] )
				? $data['message']
				: 'HTTP ' . $code;
			return new WP_Error( 'recurra_oneclick_http', $message, array( 'status' => $code ) );
		}
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'recurra_oneclick_invalid', 'Invalid JSON from 1Click.' );
		}
		return $data;
	}
}
