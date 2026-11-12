<?php
/**
 * REST API for the pay page: /wp-json/recurra/v1/orders/{id}/...
 *
 * Every route requires the order key (`key`) and a per-order nonce
 * (`X-Recurra-Nonce`). Client-reported status is never trusted.
 *
 * @package Recurra
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST routes.
 */
final class Recurra_REST {

	const NS              = 'recurra/v1';
	const MAX_QUOTES      = 5;
	const BTC_MIN_TTL_MIN = 90;

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Registers routes.
	 */
	public static function routes(): void {
		$base = '/orders/(?P<id>\d+)';
		$args = array(
			'id'  => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'key' => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
		register_rest_route(
			self::NS,
			$base . '/tokens',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'tokens' ),
				'permission_callback' => array( __CLASS__, 'can_access' ),
				'args'                => $args,
			)
		);
		register_rest_route(
			self::NS,
			$base . '/quote',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'quote' ),
				'permission_callback' => array( __CLASS__, 'can_access' ),
				'args'                => $args + array(
					'origin_asset' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'refund_to'    => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			$base . '/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'status' ),
				'permission_callback' => array( __CLASS__, 'can_access' ),
				'args'                => $args,
			)
		);
		register_rest_route(
			self::NS,
			$base . '/deposit',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'deposit' ),
				'permission_callback' => array( __CLASS__, 'can_access' ),
				'args'                => $args + array(
					'tx_hash' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			$base . '/subscription',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'subscription_confirm' ),
				'permission_callback' => array( __CLASS__, 'can_access' ),
				'args'                => $args + array(
					'tx_hash' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			$base . '/subscription/balance',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'subscription_balance' ),
				'permission_callback' => array( __CLASS__, 'can_access' ),
				'args'                => $args + array(
					'account' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/subscriptions/(?P<order_id>\d+)/sync',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'subscription_sync' ),
				'permission_callback' => array( __CLASS__, 'owns_subscription' ),
				'args'                => array(
					'order_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Order key + nonce check. Returns a 404 for wrong keys (no order enumeration).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function can_access( WP_REST_Request $request ) {
		$order = wc_get_order( (int) $request['id'] );
		$key   = (string) $request['key'];
		if ( ! $order || Recurra_Plugin::GATEWAY_ID !== $order->get_payment_method() || '' === $key || ! hash_equals( $order->get_order_key(), $key ) ) {
			return new WP_Error( 'recurra_not_found', __( 'Order not found.', 'recurra' ), array( 'status' => 404 ) );
		}
		$nonce = (string) $request->get_header( 'X-Recurra-Nonce' );
		if ( ! wp_verify_nonce( $nonce, 'recurra_order_' . $order->get_id() ) ) {
			return new WP_Error( 'recurra_bad_nonce', __( 'Session expired. Reload the page.', 'recurra' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * GET tokens for this order.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function tokens( WP_REST_Request $request ) {
		$order  = wc_get_order( (int) $request['id'] );
		$tokens = self::order_tokens( $order );
		if ( is_wp_error( $tokens ) ) {
			return self::upstream_error( $tokens );
		}
		foreach ( $tokens as &$t ) {
			$t['addressHint'] = Recurra_Tokens::address_hint( $t['blockchain'] );
		}
		return rest_ensure_response( array( 'tokens' => $tokens ) );
	}

	/**
	 * POST quote: creates a 1Click quote for the order total (EXACT_OUTPUT).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function quote( WP_REST_Request $request ) {
		$order = wc_get_order( (int) $request['id'] );
		if ( Recurra_Subscriptions::is_subscription_order( $order ) ) {
			return self::error( 'recurra_subscription', __( 'Subscriptions are paid from a NEAR wallet on the subscribe page.', 'recurra' ), 409 );
		}
		if ( ! $order->has_status( array( 'pending', 'failed' ) ) ) {
			return self::error( 'recurra_not_payable', __( 'This order does not need payment.', 'recurra' ), 409 );
		}
		$count = (int) $order->get_meta( '_recurra_quote_count' );
		if ( $count >= self::MAX_QUOTES ) {
			return self::error( 'recurra_rate_limited', __( 'Too many quotes for this order. Please contact the store.', 'recurra' ), 429 );
		}
		$blocked = self::replacement_blocked( $order );
		if ( null !== $blocked ) {
			return self::error( 'recurra_quote_active', $blocked, 409 );
		}
		// The check above may have synced the order; continue on a fresh copy.
		$order = wc_get_order( $order->get_id() );
		if ( ! $order->has_status( array( 'pending', 'failed' ) ) ) {
			return self::error( 'recurra_not_payable', __( 'This order does not need payment.', 'recurra' ), 409 );
		}

		$tokens = self::order_tokens( $order );
		if ( is_wp_error( $tokens ) ) {
			return self::upstream_error( $tokens );
		}
		$token = Recurra_Tokens::find( $tokens, (string) $request['origin_asset'] );
		if ( ! $token ) {
			return self::error( 'recurra_bad_token', __( 'This token is not available for this order.', 'recurra' ), 400 );
		}
		$refund_to = trim( (string) $request['refund_to'] );
		if ( ! Recurra_Tokens::is_valid_address( $token['blockchain'], $refund_to ) ) {
			/* translators: %s: chain name. */
			return self::error( 'recurra_bad_refund', sprintf( __( 'Enter a valid %s refund address.', 'recurra' ), $token['chainName'] ), 400 );
		}

		$s    = Recurra_Plugin::settings();
		$mode = (string) $order->get_meta( '_recurra_mode' );
		if ( ! Recurra_Tokens::merchant_allowed_in_mode( '' === $mode ? (string) $s['mode'] : $mode, (string) $s['merchant_account'] ) ) {
			// Settings changed outside the settings page (e.g. WP-CLI); never quote to a testnet recipient on mainnet.
			return self::error( 'recurra_misconfigured', __( 'This payment method is temporarily unavailable. Please contact the store.', 'recurra' ), 503 );
		}
		try {
			$amount = Recurra_Plugin::order_amount_micro( $order );
		} catch ( InvalidArgumentException $e ) {
			return self::error( 'recurra_bad_total', __( 'Order total cannot be paid with this method.', 'recurra' ), 400 );
		}
		$ttl_min = max( 5, (int) $s['quote_ttl_min'] );
		if ( 'btc' === $token['blockchain'] ) {
			$ttl_min = max( $ttl_min, self::BTC_MIN_TTL_MIN );
		}
		$req = array(
			'dry'                => false,
			'swapType'           => 'EXACT_OUTPUT',
			'slippageTolerance'  => max( 0, min( 5000, (int) $s['slippage_bps'] ) ),
			'originAsset'        => $token['assetId'],
			'depositType'        => 'ORIGIN_CHAIN',
			'destinationAsset'   => $s['destination_asset'],
			'amount'             => $amount,
			'recipient'          => $s['merchant_account'],
			'recipientType'      => 'DESTINATION_CHAIN',
			'refundTo'           => $refund_to,
			'refundType'         => 'ORIGIN_CHAIN',
			'deadline'           => gmdate( 'Y-m-d\TH:i:s.000\Z', time() + $ttl_min * MINUTE_IN_SECONDS ),
			'referral'           => 'recurra',
			'quoteWaitingTimeMs' => 3000,
		);

		$client = Recurra_Plugin::client_for_order( $order );
		$res    = $client->quote( $req );
		$order->update_meta_data( '_recurra_quote_count', $count + 1 );
		if ( is_wp_error( $res ) ) {
			$order->save();
			return self::upstream_error( $res );
		}
		$problem = self::check_quote( $res, $req );
		if ( null !== $problem ) {
			$order->save();
			return self::error( 'recurra_bad_quote', __( 'Unexpected quote from 1Click: ', 'recurra' ) . $problem, 502 );
		}

		// Archive the previous quote (dispute evidence) and keep watching its address
		// until 1Click's own deadline (+2 h), then store the new one.
		Recurra_Order_Sync::archive_current( $order );
		$old = (string) $order->get_meta( '_recurra_quote' );
		if ( '' !== $old ) {
			$history   = json_decode( (string) $order->get_meta( '_recurra_quote_history' ), true );
			$history   = is_array( $history ) ? $history : array();
			$history[] = array(
				'archived_at'     => time(),
				'last_status'     => (string) $order->get_meta( '_recurra_last_status' ),
				'deposit_address' => (string) $order->get_meta( '_recurra_deposit_address' ),
				'quote'           => json_decode( $old, true ),
			);
			$order->update_meta_data( '_recurra_quote_history', wp_json_encode( $history ) );
		}
		$q = $res['quote'];
		$order->update_meta_data( '_recurra_quote', wp_json_encode( $res ) );
		// Entire response incl. signature.
		$order->update_meta_data( '_recurra_deposit_address', (string) $q['depositAddress'] );
		$order->update_meta_data( '_recurra_expected_out', $amount );
		// UTC ISO-8601. Ours drives the UI; 1Click's (often +72 h) drives how long we watch.
		$order->update_meta_data( '_recurra_deadline', $req['deadline'] );
		$order->update_meta_data( '_recurra_quote_deadline', (string) ( $q['deadline'] ?? $q['timeWhenInactive'] ?? '' ) );
		$order->update_meta_data( '_recurra_recipient', $req['recipient'] );
		$order->update_meta_data( '_recurra_destination_asset', $req['destinationAsset'] );
		$order->update_meta_data( '_recurra_origin_asset', $token['assetId'] );
		$order->update_meta_data( '_recurra_origin_label', $token['symbol'] . ' (' . $token['chainName'] . ')' );
		$order->update_meta_data( '_recurra_refund_to', $refund_to );
		$order->update_meta_data( '_recurra_last_status', 'PENDING_DEPOSIT' );
		$order->delete_meta_data( '_recurra_verification_failed' );
		if ( $order->has_status( 'failed' ) ) {
			$order->set_status( 'pending' );
		}
		$order->add_order_note(
			sprintf(
				/* translators: 1: amount, 2: token, 3: deposit address. */
				__( 'Recurra: quote created — customer sends %1$s %2$s to %3$s.', 'recurra' ),
				sanitize_text_field( (string) $q['amountInFormatted'] ),
				$token['symbol'] . ' (' . $token['chainName'] . ')',
				sanitize_text_field( (string) $q['depositAddress'] )
			)
		);
		$order->save();

		return rest_ensure_response( array( 'quote' => self::quote_view( $order ) ) );
	}

	/**
	 * GET status: server-side fetch from 1Click (5 s cache), applied to the order.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function status( WP_REST_Request $request ) {
		$order = wc_get_order( (int) $request['id'] );
		$resp  = Recurra_Order_Sync::refresh( $order );
		if ( is_wp_error( $resp ) && 'recurra_no_quote' !== $resp->get_error_code() ) {
			return self::upstream_error( $resp );
		}
		$order = wc_get_order( $order->get_id() );
		// Reload after sync.
		$view = self::quote_view( $order );
		return rest_ensure_response(
			array(
				'status'      => is_wp_error( $resp ) ? null : $resp['status'],
				'orderStatus' => $order->get_status(),
				'paid'        => $order->is_paid(),
				'redirect'    => $order->is_paid() ? $order->get_checkout_order_received_url() : null,
				'quote'       => $view,
			)
		);
	}

	/**
	 * POST deposit: optional tx hash to speed up detection.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function deposit( WP_REST_Request $request ) {
		$order   = wc_get_order( (int) $request['id'] );
		$address = (string) $order->get_meta( '_recurra_deposit_address' );
		$tx      = trim( (string) $request['tx_hash'] );
		if ( '' === $address ) {
			return self::error( 'recurra_no_quote', __( 'No quote for this order yet.', 'recurra' ), 409 );
		}
		if ( 1 !== preg_match( '/^(0x)?[A-Za-z0-9]{16,128}$/', $tx ) ) {
			return self::error( 'recurra_bad_tx', __( 'That does not look like a transaction hash.', 'recurra' ), 400 );
		}
		$res = Recurra_Plugin::client_for_order( $order )->submit_deposit( $address, $tx );
		if ( is_wp_error( $res ) ) {
			return self::upstream_error( $res );
		}
		/* translators: %s: transaction hash. */
		$order->add_order_note( sprintf( __( 'Recurra: customer submitted deposit tx %s.', 'recurra' ), $tx ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Public view of the current quote (no signature, no internal fields).
	 *
	 * @param WC_Order $order Order.
	 * @return array<string, mixed>|null
	 */
	public static function quote_view( WC_Order $order ): ?array {
		$raw = json_decode( (string) $order->get_meta( '_recurra_quote' ), true );
		if ( ! is_array( $raw ) || empty( $raw['quote']['depositAddress'] ) ) {
			return null;
		}
		$ts = Recurra_Order_Sync::order_display_deadline( $order );
		return array(
			'depositAddress'    => (string) $raw['quote']['depositAddress'],
			'amountIn'          => (string) $raw['quote']['amountIn'],
			'amountInFormatted' => (string) $raw['quote']['amountInFormatted'],
			'amountOut'         => (string) $raw['quote']['amountOutFormatted'],
			'token'             => (string) $order->get_meta( '_recurra_origin_label' ),
			'originAsset'       => (string) $order->get_meta( '_recurra_origin_asset' ),
			'refundTo'          => (string) $order->get_meta( '_recurra_refund_to' ),
			'deadline'          => $ts ? gmdate( 'c', $ts ) : '',
			'deadlineTs'        => $ts ? $ts : null,
			// Site timezone for display; all stored values are UTC.
			'deadlineText'      => $ts ? wp_date( (string) get_option( 'time_format' ) . ' T', $ts ) : '',
			'expired'           => $ts && time() > $ts,
			'lastStatus'        => (string) $order->get_meta( '_recurra_last_status' ),
			'explorer'          => 'https://explorer.near-intents.org',
		);
	}

	/**
	 * A new quote may replace the current one only when no deposit can still
	 * arrive on it: it expired while PENDING_DEPOSIT, or ended REFUNDED/FAILED.
	 * Prevents a customer paying an address the order no longer watches.
	 *
	 * @param WC_Order $order Order.
	 * @return string|null Reason it is blocked, or null.
	 */
	private static function replacement_blocked( WC_Order $order ): ?string {
		if ( '' === (string) $order->get_meta( '_recurra_deposit_address' ) ) {
			return null;
		}
		$resp = Recurra_Order_Sync::refresh( $order, false );
		if ( is_wp_error( $resp ) ) {
			return __( 'Could not check the current quote. Try again in a moment.', 'recurra' );
		}
		$status = (string) $resp['status'];
		if ( in_array( $status, array( 'REFUNDED', 'FAILED' ), true ) ) {
			return null;
		}
		// After OUR deadline a still-unpaid address may be replaced; it stays watched
		// (Recurra_Order_Sync::archive_current) in case the customer pays it anyway.
		$deadline = Recurra_Order_Sync::order_display_deadline( $order );
		if ( 'PENDING_DEPOSIT' === $status && $deadline && time() > $deadline ) {
			return null;
		}
		return __( 'A payment address is already active for this order. Use it, or wait until it expires.', 'recurra' );
	}

	/**
	 * Sanity checks on a live quote before showing it to the customer.
	 *
	 * @param array<string, mixed> $res QuoteResponse.
	 * @param array<string, mixed> $req Our QuoteRequest.
	 */
	private static function check_quote( array $res, array $req ): ?string {
		$q = $res['quote'] ?? null;
		if ( ! is_array( $q ) || empty( $q['depositAddress'] ) || ! isset( $q['amountIn'], $q['amountInFormatted'], $q['amountOut'] ) ) {
			return 'missing fields';
		}
		if ( ! empty( $q['depositMemo'] ) ) {
			return 'memo deposits are not supported';
		}
		if ( ! Recurra_Amount::is_uint( (string) $q['amountOut'] ) || Recurra_Amount::cmp( (string) $q['amountOut'], $req['amount'] ) < 0 ) {
			return 'amountOut below order total';
		}
		$echo = $res['quoteRequest'] ?? array();
		if ( ( $echo['recipient'] ?? '' ) !== $req['recipient'] || ( $echo['destinationAsset'] ?? '' ) !== $req['destinationAsset'] ) {
			return 'recipient or destination asset mismatch';
		}
		return null;
	}

	/**
	 * Tokens available for an order.
	 *
	 * @param WC_Order $order Order.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private static function order_tokens( WC_Order $order ) {
		try {
			$amount = Recurra_Plugin::order_amount_micro( $order );
		} catch ( InvalidArgumentException $e ) {
			return new WP_Error( 'recurra_bad_total', $e->getMessage() );
		}
		return Recurra_Tokens::for_order(
			Recurra_Plugin::client_for_order( $order ),
			Recurra_Plugin::allowed_chains(),
			Recurra_Plugin::settings()['destination_asset'],
			$amount
		);
	}

	/**
	 * Error response.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 */
	private static function error( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/**
	 * Wraps a 1Click error. Its message is shown (e.g. "Amount is too low for bridge").
	 *
	 * @param WP_Error $e Error.
	 */
	private static function upstream_error( WP_Error $e ): WP_Error {
		$data   = $e->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) && (int) $data['status'] < 500 ? 400 : 502;
		return new WP_Error( $e->get_error_code(), sanitize_text_field( $e->get_error_message() ), array( 'status' => $status ) );
	}

	/**
	 * Logged-in customer who owns the subscription order (cookie auth + X-WP-Nonce).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function owns_subscription( WP_REST_Request $request ) {
		$order = wc_get_order( (int) $request['order_id'] );
		$user  = get_current_user_id();
		if ( ! $user || ! $order || $order->get_customer_id() !== $user || ! Recurra_Subscriptions::is_subscription_order( $order ) ) {
			return new WP_Error( 'recurra_not_found', __( 'Subscription not found.', 'recurra' ), array( 'status' => 404 ) );
		}
		return true;
	}

	/**
	 * POST: confirm a subscription order from chain state. The tx hash is only recorded,
	 * never trusted.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function subscription_confirm( WP_REST_Request $request ) {
		$order = wc_get_order( (int) $request['id'] );
		if ( ! Recurra_Subscriptions::is_subscription_order( $order ) ) {
			return self::error( 'recurra_not_subscription', __( 'This order is not a subscription.', 'recurra' ), 409 );
		}
		$tx = (string) $request['tx_hash'];
		if ( '' !== $tx && 1 === preg_match( '/^[1-9A-HJ-NP-Za-km-z]{43,44}$/', $tx ) && $tx !== (string) $order->get_meta( '_recurra_sub_tx' ) && ! $order->is_paid() ) {
			$order->update_meta_data( '_recurra_sub_tx', $tx );
			/* translators: %s: transaction hash. */
			$order->add_order_note( sprintf( __( 'Recurra: customer reports subscribe transaction %s (verifying on-chain).', 'recurra' ), $tx ) );
			$order->save();
		}
		$state = Recurra_Subscriptions::confirm( $order );
		if ( 'error' === $state ) {
			return self::error( 'recurra_rpc', __( 'NEAR RPC is unavailable. Retrying…', 'recurra' ), 502 );
		}
		$order = wc_get_order( $order->get_id() );
		return rest_ensure_response(
			array(
				'state'    => $state,
				'redirect' => 'paid' === $state ? $order->get_checkout_order_received_url() : null,
			)
		);
	}

	/**
	 * GET: the customer's token balance (so the page can warn before signing).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function subscription_balance( WP_REST_Request $request ) {
		$order   = wc_get_order( (int) $request['id'] );
		$account = strtolower( trim( (string) $request['account'] ) );
		if ( ! Recurra_Subscriptions::is_subscription_order( $order ) || ! Recurra_Tokens::is_valid_near_account( $account ) ) {
			return self::error( 'recurra_bad_account', __( 'Invalid account.', 'recurra' ), 400 );
		}
		$plan    = Recurra_Subscriptions::order_plan( $order );
		$balance = Recurra_Subscriptions::rpc()->view( (string) $plan['token'], 'ft_balance_of', array( 'account_id' => $account ), 5 );
		if ( is_wp_error( $balance ) ) {
			return self::error( 'recurra_rpc', __( 'NEAR RPC is unavailable.', 'recurra' ), 502 );
		}
		return rest_ensure_response( array( 'balance' => Recurra_Amount::is_uint( $balance ) ? $balance : '0' ) );
	}

	/**
	 * POST: re-read a subscription from chain after a cancel / top-up in the wallet.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function subscription_sync( WP_REST_Request $request ) {
		$order = wc_get_order( (int) $request['order_id'] );
		$sub   = Recurra_Subscriptions::sync( $order );
		if ( is_wp_error( $sub ) && 'recurra_busy' !== $sub->get_error_code() ) {
			return self::error( $sub->get_error_code(), $sub->get_error_message(), 502 );
		}
		return rest_ensure_response( Recurra_Subscriptions::row( wc_get_order( $order->get_id() ) ) );
	}
}
