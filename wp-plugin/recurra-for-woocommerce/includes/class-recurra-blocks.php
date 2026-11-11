<?php
/**
 * Cart/Checkout Blocks integration (the default checkout since WooCommerce 8.3).
 *
 * @package Recurra
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Registers Recurra as a block-checkout payment method. Payment itself happens
 * on the pay page after the order is placed, exactly as in classic checkout.
 */
final class Recurra_Blocks extends AbstractPaymentMethodType {

	/**
	 * Payment method name (matches the gateway id).
	 *
	 * @var string
	 */
	protected $name = Recurra_Plugin::GATEWAY_ID;

	/**
	 * Loads settings.
	 */
	public function initialize() {
		$this->settings = Recurra_Plugin::settings();
	}

	/**
	 * Active when the gateway is available (enabled, USD store, merchant set).
	 */
	public function is_active() {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		return isset( $gateways[ $this->name ] ) && $gateways[ $this->name ]->is_available();
	}

	/**
	 * Script handles.
	 *
	 * @return array<string>
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'recurra-blocks',
			RECURRA_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			RECURRA_VERSION,
			true
		);
		return array( 'recurra-blocks' );
	}

	/**
	 * Data available to the script as getSetting( 'recurra_data' ).
	 *
	 * @return array<string, mixed>
	 */
	public function get_payment_method_data() {
		return array(
			'title'       => $this->settings['title'],
			'description' => $this->settings['description'],
			'mock'        => 'mock' === $this->settings['mode'],
			'supports'    => array( 'products' ),
		);
	}
}
