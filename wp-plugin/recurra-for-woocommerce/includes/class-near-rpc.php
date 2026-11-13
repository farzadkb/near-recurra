<?php
/**
 * Minimal NEAR JSON-RPC client for view calls (no signing, no keys).
 *
 * @package Recurra
 */

defined( 'ABSPATH' ) || exit;

/**
 * NEAR RPC view calls.
 */
final class Recurra_Near_Rpc {

	const DEFAULT_RPC = array(
		'mainnet' => 'https://rpc.mainnet.fastnear.com',
		'testnet' => 'https://rpc.testnet.fastnear.com',
	);

	/**
	 * RPC endpoint.
	 *
	 * @var string
	 */
	private $url;

	/**
	 * Constructor.
	 *
	 * @param string $url RPC URL.
	 */
	public function __construct( string $url ) {
		$this->url = $url;
	}

	/**
	 * Network of an account id: ".testnet" accounts are testnet, everything else mainnet.
	 *
	 * @param string $account Account id.
	 */
	public static function network_of( string $account ): string {
		return Recurra_Tokens::is_testnet_account( $account ) ? 'testnet' : 'mainnet';
	}

	/**
	 * RPC URL for calls about `$account`: the configured URL when it serves the
	 * same network as the configured vault and `$account`, else the default for
	 * `$account`'s network. Keeps orders made on another vault (e.g. testnet
	 * subscriptions after the store switched to mainnet) syncing correctly.
	 *
	 * @param string $account          Contract the call goes to.
	 * @param string $configured_url   RPC URL from the settings ('' = default).
	 * @param string $configured_vault Vault from the settings.
	 */
	public static function url_for( string $account, string $configured_url, string $configured_vault ): string {
		$network = self::network_of( $account );
		if ( '' !== $configured_url && self::network_of( $configured_vault ) === $network ) {
			return $configured_url;
		}
		return self::DEFAULT_RPC[ $network ];
	}

	/**
	 * Explorer base URL for an account's network.
	 *
	 * @param string $account Account id.
	 */
	public static function explorer_for( string $account ): string {
		return 'testnet' === self::network_of( $account ) ? 'https://testnet.nearblocks.io' : 'https://nearblocks.io';
	}

	/**
	 * Calls a view method. Returns the decoded JSON result (may be null).
	 *
	 * @param string               $contract  Contract account.
	 * @param string               $method    View method.
	 * @param array<string, mixed> $args      Arguments.
	 * @param int                  $cache_ttl Seconds to cache a successful result (0 = no cache).
	 * @return mixed|WP_Error
	 */
	public function view( string $contract, string $method, array $args = array(), int $cache_ttl = 0 ) {
		$cache_key = 'recurra_view_' . md5( $this->url . '|' . $contract . '|' . $method . '|' . wp_json_encode( $args ) );
		if ( $cache_ttl > 0 ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) && array_key_exists( 'v', $cached ) ) {
				return $cached['v'];
			}
		}
		$result = $this->rpc(
			'query',
			array(
				'request_type' => 'call_function',
				'finality'     => 'final',
				'account_id'   => $contract,
				'method_name'  => $method,
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- NEAR RPC requires base64 args.
				'args_base64'  => base64_encode( (string) wp_json_encode( empty( $args ) ? new stdClass() : $args ) ),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( isset( $result['error'] ) ) {
			return new WP_Error( 'recurra_rpc_view', sanitize_text_field( is_string( $result['error'] ) ? $result['error'] : (string) wp_json_encode( $result['error'] ) ) );
		}
		if ( ! isset( $result['result'] ) || ! is_array( $result['result'] ) ) {
			return new WP_Error( 'recurra_rpc_shape', __( 'Unexpected NEAR RPC response.', 'recurra' ) );
		}
		$bytes = implode( '', array_map( 'chr', array_map( 'intval', $result['result'] ) ) );
		$value = '' === $bytes ? null : json_decode( $bytes, true, 64, JSON_BIGINT_AS_STRING );
		if ( '' !== $bytes && JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'recurra_rpc_json', __( 'NEAR view returned invalid JSON.', 'recurra' ) );
		}
		if ( $cache_ttl > 0 ) {
			set_transient( $cache_key, array( 'v' => $value ), $cache_ttl );
		}
		return $value;
	}

	/**
	 * Chain id of the RPC node ("mainnet" / "testnet").
	 *
	 * @return string|WP_Error
	 */
	public function chain_id() {
		$status = $this->rpc( 'status', array() );
		if ( is_wp_error( $status ) ) {
			return $status;
		}
		return isset( $status['chain_id'] ) && is_string( $status['chain_id'] ) ? $status['chain_id'] : new WP_Error( 'recurra_rpc_shape', __( 'Unexpected NEAR RPC status response.', 'recurra' ) );
	}

	/**
	 * Whether an account exists.
	 *
	 * @param string $account Account id.
	 * @return bool|WP_Error
	 */
	public function account_exists( string $account ) {
		$res = $this->rpc(
			'query',
			array(
				'request_type' => 'view_account',
				'finality'     => 'final',
				'account_id'   => $account,
			),
			true
		);
		if ( is_wp_error( $res ) ) {
			return 'recurra_rpc_unknown_account' === $res->get_error_code() ? false : $res;
		}
		return isset( $res['amount'] );
	}

	/**
	 * Raw JSON-RPC call.
	 *
	 * @param string               $method          RPC method.
	 * @param array<string, mixed> $params          Params.
	 * @param bool                 $unknown_is_soft Map UNKNOWN_ACCOUNT to a distinct error code.
	 * @return array<string, mixed>|WP_Error
	 */
	private function rpc( string $method, array $params, bool $unknown_is_soft = false ) {
		$response = wp_remote_post(
			$this->url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type' => 'application/json',
					'User-Agent'   => 'Recurra-for-WooCommerce/' . RECURRA_VERSION,
				),
				'body'    => wp_json_encode(
					array(
						'jsonrpc' => '2.0',
						'id'      => 'recurra',
						'method'  => $method,
						'params'  => empty( $params ) ? new stdClass() : $params,
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true, 64, JSON_BIGINT_AS_STRING );
		if ( ! is_array( $body ) ) {
			/* translators: %d: HTTP status. */
			return new WP_Error( 'recurra_rpc_http', sprintf( __( 'NEAR RPC error (HTTP %d).', 'recurra' ), $code ) );
		}
		if ( isset( $body['error'] ) ) {
			$cause = (string) ( $body['error']['cause']['name'] ?? $body['error']['name'] ?? '' );
			if ( $unknown_is_soft && 'UNKNOWN_ACCOUNT' === $cause ) {
				return new WP_Error( 'recurra_rpc_unknown_account', __( 'Account does not exist.', 'recurra' ) );
			}
			$msg = (string) ( $body['error']['data'] ?? $body['error']['message'] ?? 'unknown' );
			return new WP_Error( 'recurra_rpc', sanitize_text_field( is_string( $msg ) ? $msg : (string) wp_json_encode( $msg ) ) );
		}
		return isset( $body['result'] ) && is_array( $body['result'] ) ? $body['result'] : new WP_Error( 'recurra_rpc_shape', __( 'Unexpected NEAR RPC response.', 'recurra' ) );
	}
}
