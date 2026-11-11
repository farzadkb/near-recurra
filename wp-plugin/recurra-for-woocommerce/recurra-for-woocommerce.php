<?php
/**
 * Plugin Name:          Recurra for WooCommerce
 * Plugin URI:           https://github.com/OWNER/recurra
 * Description:          Accept any token on any chain via NEAR Intents (1Click). The merchant receives the exact invoice amount in USDC on NEAR.
 * Version:              0.1.0
 * Requires at least:    6.5
 * Requires PHP:         8.1
 * Requires Plugins:     woocommerce
 * WC requires at least: 8.0
 * WC tested up to:      11.2
 * Author:               Recurra contributors
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Text Domain:          recurra
 *
 * @package Recurra
 */

defined( 'ABSPATH' ) || exit;

define( 'RECURRA_VERSION', '0.1.0' );
define( 'RECURRA_FILE', __FILE__ );
define( 'RECURRA_DIR', plugin_dir_path( __FILE__ ) );
define( 'RECURRA_URL', plugin_dir_url( __FILE__ ) );

/*
 * Declare HPOS and Cart/Checkout Blocks compatibility.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}
		require_once RECURRA_DIR . 'includes/class-recurra-amount.php';
		require_once RECURRA_DIR . 'includes/interface-recurra-oneclick.php';
		require_once RECURRA_DIR . 'includes/class-oneclick-client.php';
		require_once RECURRA_DIR . 'includes/class-oneclick-mock.php';
		require_once RECURRA_DIR . 'includes/class-recurra-tokens.php';
		require_once RECURRA_DIR . 'includes/class-recurra-order-sync.php';
		require_once RECURRA_DIR . 'includes/class-recurra-gateway.php';
		require_once RECURRA_DIR . 'includes/class-rest.php';
		require_once RECURRA_DIR . 'includes/class-poller.php';
		require_once RECURRA_DIR . 'includes/class-recurra-plugin.php';
		Recurra_Plugin::init();
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'recurra_poll' );
		}
	}
);
