<?php
/**
 * Plugin bootstrap: settings access, client factory, WooCommerce hooks.
 *
 * @package Recurra
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class (static).
 */
final class Recurra_Plugin {

	const GATEWAY_ID = 'recurra';

	/**
	 * Native USDC on NEAR (Circle). Verified against /v0/tokens on settings save.
	 */
	const USDC_NEAR = 'nep141:17208628f84f5d6ad33f0da3bbbeb27ffcb398eac501a31bd6ad2011e36133a1';

	/**
	 * Registers hooks.
	 */
	public static function init(): void {
		add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'register_gateway' ) );
		add_action( 'woocommerce_blocks_payment_method_type_registration', array( __CLASS__, 'register_blocks' ) );
		add_filter( 'woocommerce_cancel_unpaid_order', array( __CLASS__, 'keep_unpaid_order' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_pay_page' ) );
		Recurra_REST::init();
		Recurra_Poller::init();
	}

	/**
	 * Default settings (WooCommerce → Settings → Payments → Recurra).
	 *
	 * @return array<string, string>
	 */
	public static function defaults(): array {
		return array(
			'enabled'           => 'no',
			'title'             => __( 'Pay with crypto (any chain)', 'recurra' ),
			'description'       => __( 'Pay with USDC, ETH, SOL, BTC, NEAR and more on many chains via NEAR Intents.', 'recurra' ),
			'merchant_account'  => '',
			'destination_asset' => self::USDC_NEAR,
			'api_key'           => '',
			'allowed_chains'    => 'arb,base,eth,sol,op,pol,near,btc',
			'slippage_bps'      => '100',
			'quote_ttl_min'     => '30',
			'mode'              => 'live',
			'vault_contract'    => '',
			'rpc_url'           => 'https://rpc.mainnet.near.org',
		);
	}

	/**
	 * Current settings merged with defaults.
	 *
	 * @return array<string, string>
	 */
	public static function settings(): array {
		$saved = get_option( 'woocommerce_' . self::GATEWAY_ID . '_settings', array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * Allowed chains as an array.
	 *
	 * @return array<string>
	 */
	public static function allowed_chains(): array {
		$list = array_map( 'trim', explode( ',', strtolower( self::settings()['allowed_chains'] ) ) );
		return array_values( array_filter( $list, static fn( $c ) => 1 === preg_match( '/^[a-z0-9]+$/', $c ) ) );
	}

	/**
	 * Client for a mode ('mock' | 'live').
	 *
	 * @param string|null $mode Mode; null = current setting.
	 */
	public static function client( ?string $mode = null ): Recurra_OneClick {
		$s    = self::settings();
		$mode = $mode ?? $s['mode'];
		if ( 'mock' === $mode ) {
			return new Recurra_OneClick_Mock();
		}
		return new Recurra_OneClick_Client( (string) $s['api_key'] );
	}

	/**
	 * Client an order was quoted with (mode is fixed per order at checkout).
	 *
	 * @param WC_Order $order Order.
	 */
	public static function client_for_order( WC_Order $order ): Recurra_OneClick {
		$mode = (string) $order->get_meta( '_recurra_mode' );
		return self::client( '' === $mode ? null : $mode );
	}

	/**
	 * Adds the gateway.
	 *
	 * @param array<int|string, mixed> $gateways Gateways.
	 * @return array<int|string, mixed>
	 */
	public static function register_gateway( array $gateways ): array {
		$gateways[] = 'Recurra_Gateway';
		return $gateways;
	}

	/**
	 * Registers the Cart/Checkout Blocks integration.
	 *
	 * @param object $registry PaymentMethodRegistry.
	 */
	public static function register_blocks( $registry ): void {
		require_once RECURRA_DIR . 'includes/class-recurra-blocks.php';
		$registry->register( new Recurra_Blocks() );
	}

	/**
	 * Prevents WooCommerce's hold-stock cleanup from cancelling an order whose
	 * quote may still receive a deposit (deadline + 2h grace).
	 *
	 * @param bool     $cancel Whether to cancel.
	 * @param WC_Order $order  Order.
	 */
	public static function keep_unpaid_order( $cancel, $order ) {
		if ( $cancel && $order instanceof WC_Order && self::GATEWAY_ID === $order->get_payment_method() ) {
			$deadline = strtotime( (string) $order->get_meta( '_recurra_deadline' ) );
			if ( $deadline && time() < $deadline + 2 * HOUR_IN_SECONDS ) {
				return false;
			}
		}
		return $cancel;
	}

	/**
	 * Order total in micro-USD (USDC base units, 6 decimals).
	 *
	 * @param WC_Order $order Order.
	 * @throws InvalidArgumentException On malformed totals.
	 */
	public static function order_amount_micro( WC_Order $order ): string {
		$total = wc_format_decimal( (string) $order->get_total( 'edit' ), wc_get_price_decimals() );
		return Recurra_Amount::decimal_to_base( (string) $total, 6 );
	}

	/**
	 * Enqueues pay-page assets on the order-pay endpoint of a Recurra order.
	 */
	public static function enqueue_pay_page(): void {
		if ( ! function_exists( 'is_checkout_pay_page' ) || ! is_checkout_pay_page() ) {
			return;
		}
		global $wp;
		$order_id = isset( $wp->query_vars['order-pay'] ) ? absint( $wp->query_vars['order-pay'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order || self::GATEWAY_ID !== $order->get_payment_method() ) {
			return;
		}
		wp_enqueue_style( 'recurra-pay', RECURRA_URL . 'assets/css/pay-page.css', array(), RECURRA_VERSION );
		wp_enqueue_script( 'recurra-qrcode', RECURRA_URL . 'assets/vendor/qrcode-generator/qrcode.js', array(), '1.4.4', true );
		wp_enqueue_script( 'recurra-pay', RECURRA_URL . 'assets/js/pay-page.js', array( 'recurra-qrcode' ), RECURRA_VERSION, true );
	}

	/**
	 * Config passed to the pay page JS.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string, mixed>
	 */
	public static function pay_page_config( WC_Order $order ): array {
		return array(
			'orderId'   => $order->get_id(),
			'orderKey'  => $order->get_order_key(),
			'restBase'  => esc_url_raw( rest_url( 'recurra/v1/orders/' . $order->get_id() ) ),
			'nonce'     => wp_create_nonce( 'recurra_order_' . $order->get_id() ),
			'restNonce' => wp_create_nonce( 'wp_rest' ),
			'mock'      => self::client_for_order( $order )->is_mock(),
			'quote'     => Recurra_REST::quote_view( $order ),
			'i18n'      => array(
				'PENDING_DEPOSIT'    => __( 'Waiting for your deposit…', 'recurra' ),
				'KNOWN_DEPOSIT_TX'   => __( 'Payment detected, waiting for confirmation…', 'recurra' ),
				'PROCESSING'         => __( 'Payment received, converting…', 'recurra' ),
				'SUCCESS'            => __( 'Paid. Redirecting…', 'recurra' ),
				'INCOMPLETE_DEPOSIT' => __( 'Deposit is below the required amount. Send the remainder before the deadline.', 'recurra' ),
				'REFUNDED'           => __( 'The swap could not complete and was refunded to your refund address.', 'recurra' ),
				'FAILED'             => __( 'The payment failed.', 'recurra' ),
				'expired'            => __( 'This quote expired. Get a new one.', 'recurra' ),
				'copied'             => __( 'Copied', 'recurra' ),
				'error'              => __( 'Something went wrong:', 'recurra' ),
			),
		);
	}
}
