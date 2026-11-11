<?php
/**
 * WooCommerce payment gateway: checkout via NEAR Intents 1Click (Feature A).
 *
 * @package Recurra
 */

defined( 'ABSPATH' ) || exit;

/**
 * Recurra gateway.
 */
class Recurra_Gateway extends WC_Payment_Gateway {

	/**
	 * Whether the testnet-merchant error was already added during this save.
	 *
	 * @var bool
	 */
	private $testnet_error_shown = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = Recurra_Plugin::GATEWAY_ID;
		$this->method_title       = __( 'Recurra (NEAR Intents)', 'recurra' );
		$this->method_description = __( 'Customers pay with any supported token on any chain; you receive the exact order amount in USDC on your NEAR account.', 'recurra' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'admin_order_details' ) );
	}

	/**
	 * Settings fields.
	 */
	public function init_form_fields() {
		$d                 = Recurra_Plugin::defaults();
		$this->form_fields = array(
			'enabled'           => array(
				'title'   => __( 'Enable', 'recurra' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Recurra', 'recurra' ),
				'default' => 'no',
			),
			'title'             => array(
				'title'   => __( 'Title', 'recurra' ),
				'type'    => 'text',
				'default' => $d['title'],
			),
			'description'       => array(
				'title'   => __( 'Description', 'recurra' ),
				'type'    => 'textarea',
				'default' => $d['description'],
			),
			'mode'              => array(
				'title'       => __( 'Mode', 'recurra' ),
				'type'        => 'select',
				'options'     => array(
					'live' => __( 'Live (real 1Click API, mainnet)', 'recurra' ),
					'mock' => __( 'Mock (no funds, simulated swap — demos/testing)', 'recurra' ),
				),
				'default'     => $d['mode'],
				'description' => __( 'Mock mode never contacts 1Click. Orders keep the mode they were placed with.', 'recurra' ),
			),
			'merchant_account'  => array(
				'title'       => __( 'Merchant NEAR account', 'recurra' ),
				'type'        => 'text',
				'description' => __( 'Receives USDC. Must be registered on the USDC contract (storage_deposit).', 'recurra' ),
				'default'     => '',
			),
			'destination_asset' => array(
				'title'       => __( 'Destination asset', 'recurra' ),
				'type'        => 'text',
				'description' => __( '1Click asset id you receive. Default: native USDC on NEAR. Checked against /v0/tokens on save (live mode).', 'recurra' ),
				'default'     => $d['destination_asset'],
			),
			'api_key'           => array(
				'title'       => __( '1Click API key', 'recurra' ),
				'type'        => 'password',
				'description' => __( 'Optional partner JWT from partners.near-intents.org, sent as X-API-Key. Without it 1Click adds a 0.25% fee.', 'recurra' ),
				'default'     => '',
			),
			'allowed_chains'    => array(
				'title'       => __( 'Allowed chains', 'recurra' ),
				'type'        => 'text',
				'description' => __( 'Comma-separated 1Click blockchain ids. Memo chains (xrp, stellar, ton) are always excluded; tron/bsc are only offered above their swap minimums ($100 / $1,000).', 'recurra' ),
				'default'     => $d['allowed_chains'],
			),
			'slippage_bps'      => array(
				'title'   => __( 'Slippage (bps)', 'recurra' ),
				'type'    => 'number',
				'default' => $d['slippage_bps'],
			),
			'quote_ttl_min'     => array(
				'title'       => __( 'Quote lifetime (minutes)', 'recurra' ),
				'type'        => 'number',
				'description' => __( 'Deposit deadline. Bitcoin always gets at least 90 minutes.', 'recurra' ),
				'default'     => $d['quote_ttl_min'],
			),
			'vault_contract'    => array(
				'title'       => __( 'Vault contract', 'recurra' ),
				'type'        => 'text',
				'description' => __( 'Recurra Vault account for subscriptions.', 'recurra' ),
				'default'     => '',
			),
			'rpc_url'           => array(
				'title'   => __( 'NEAR RPC URL', 'recurra' ),
				'type'    => 'text',
				'default' => $d['rpc_url'],
			),
		);
	}

	/**
	 * Validates settings before saving.
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();
		$s     = Recurra_Plugin::settings();

		if ( '' !== $s['merchant_account'] && ! Recurra_Tokens::is_valid_near_account( $s['merchant_account'] ) ) {
			WC_Admin_Settings::add_error( __( 'Recurra: merchant account is not a valid NEAR account id.', 'recurra' ) );
		}
		$slippage = (int) $s['slippage_bps'];
		if ( $slippage < 0 || $slippage > 5000 ) {
			WC_Admin_Settings::add_error( __( 'Recurra: slippage must be between 0 and 5000 bps.', 'recurra' ) );
		}
		$ttl = (int) $s['quote_ttl_min'];
		if ( $ttl < 5 || $ttl > 240 ) {
			WC_Admin_Settings::add_error( __( 'Recurra: quote lifetime must be 5–240 minutes.', 'recurra' ) );
		}
		if ( 'live' === $s['mode'] ) {
			delete_transient( 'recurra_tokens_live' );
			$tokens = Recurra_Tokens::all( Recurra_Plugin::client( 'live' ) );
			if ( is_wp_error( $tokens ) ) {
				WC_Admin_Settings::add_error( __( 'Recurra: could not reach 1Click to verify the destination asset: ', 'recurra' ) . $tokens->get_error_message() );
			} elseif ( ! Recurra_Tokens::find( $tokens, $s['destination_asset'] ) ) {
				WC_Admin_Settings::add_error( __( 'Recurra: destination asset is not listed by 1Click /v0/tokens.', 'recurra' ) );
			}
		}
		return $saved;
	}

	/**
	 * Refuses switching to live mode while the (posted) merchant is a testnet account;
	 * the previous mode is kept.
	 *
	 * @param string      $key   Field key.
	 * @param string|null $value Posted value.
	 */
	public function validate_mode_field( $key, $value ) {
		$value = $this->validate_select_field( $key, $value );
		if ( ! Recurra_Tokens::merchant_allowed_in_mode( $value, $this->posted_text( 'merchant_account' ) ) ) {
			$this->testnet_error();
			return (string) $this->get_option( 'mode' );
		}
		return $value;
	}

	/**
	 * Refuses a testnet merchant account in live mode; the previous account is kept.
	 *
	 * @param string      $key   Field key.
	 * @param string|null $value Posted value.
	 */
	public function validate_merchant_account_field( $key, $value ) {
		$value = trim( $this->validate_text_field( $key, $value ) );
		if ( ! Recurra_Tokens::merchant_allowed_in_mode( $this->posted_text( 'mode' ), $value ) ) {
			$this->testnet_error();
			return (string) $this->get_option( 'merchant_account' );
		}
		return $value;
	}

	/**
	 * A posted settings value (the settings page nonce is checked by WooCommerce).
	 *
	 * @param string $key Field key.
	 */
	private function posted_text( string $key ): string {
		$data  = $this->get_post_data();
		$field = $this->get_field_key( $key );
		return isset( $data[ $field ] ) ? sanitize_text_field( wp_unslash( (string) $data[ $field ] ) ) : (string) $this->get_option( $key );
	}

	/**
	 * Adds the "testnet account in live mode" error once per save.
	 */
	private function testnet_error(): void {
		if ( ! $this->testnet_error_shown ) {
			$this->testnet_error_shown = true;
			WC_Admin_Settings::add_error( self::testnet_message() );
		}
	}

	/**
	 * Message for a testnet merchant in live mode.
	 */
	public static function testnet_message(): string {
		return __( 'Recurra: live mode is mainnet-only (1Click, USDC and the RPC are mainnet), so the merchant account cannot be a .testnet account. The previous value was kept. Use mock mode for testnet accounts.', 'recurra' );
	}

	/**
	 * Only for USD stores with a merchant account that is valid for the mode.
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}
		if ( 'USD' !== get_woocommerce_currency() ) {
			return false;
		}
		$merchant = trim( (string) $this->get_option( 'merchant_account' ) );
		return '' !== $merchant && Recurra_Tokens::merchant_allowed_in_mode( (string) $this->get_option( 'mode' ), $merchant );
	}

	/**
	 * Order stays pending; the customer is sent to the pay page.
	 *
	 * @param int $order_id Order id.
	 * @return array<string, string>
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}
		$order->update_meta_data( '_recurra_mode', Recurra_Plugin::settings()['mode'] );
		if ( ! $order->has_status( 'pending' ) ) {
			$order->set_status( 'pending' );
		}
		$order->save();
		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		);
	}

	/**
	 * Pay page (order-pay endpoint with our receipt hook).
	 *
	 * @param int $order_id Order id.
	 */
	public function receipt_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$config = Recurra_Plugin::pay_page_config( $order );
		wc_get_template(
			'pay-page.php',
			array(
				'order'  => $order,
				'config' => $config,
			),
			'',
			RECURRA_DIR . 'templates/'
		);
	}

	/**
	 * Thank-you page details.
	 *
	 * @param int $order_id Order id.
	 */
	public function thankyou_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->is_paid() ) {
			return;
		}
		$tx = (string) $order->get_transaction_id();
		echo '<p class="recurra-thankyou">' . esc_html__( 'Paid via NEAR Intents.', 'recurra' );
		if ( '' !== $tx ) {
			echo ' ' . esc_html__( 'NEAR transaction:', 'recurra' ) . ' <code>' . esc_html( $tx ) . '</code>';
		}
		echo '</p>';
	}

	/**
	 * Admin order screen: deposit address and swap info.
	 *
	 * @param WC_Order $order Order.
	 */
	public function admin_order_details( $order ) {
		if ( ! $order instanceof WC_Order || $this->id !== $order->get_payment_method() ) {
			return;
		}
		$address = (string) $order->get_meta( '_recurra_deposit_address' );
		if ( '' === $address ) {
			return;
		}
		echo '<div class="recurra-admin"><h3>' . esc_html__( 'Recurra', 'recurra' ) . '</h3><p>';
		echo esc_html__( 'Mode:', 'recurra' ) . ' ' . esc_html( (string) $order->get_meta( '_recurra_mode' ) ) . '<br>';
		echo esc_html__( 'Deposit address:', 'recurra' ) . ' <code>' . esc_html( $address ) . '</code><br>';
		echo esc_html__( '1Click status:', 'recurra' ) . ' ' . esc_html( (string) $order->get_meta( '_recurra_last_status' ) ) . '<br>';
		echo '<a href="https://explorer.near-intents.org" target="_blank" rel="noopener noreferrer">' . esc_html__( 'NEAR Intents Explorer (search the deposit address)', 'recurra' ) . '</a>';
		echo '</p></div>';
	}
}
