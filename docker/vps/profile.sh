#!/bin/sh
# Switches the hosted store between the two demo profiles. Runs inside the
# `wpcli` container (docker/vps.sh profile <name>; also re-applied by
# wp-init.sh on every start). The charger is switched by docker/vps.sh.
#
#   mainnet-demo  live checkout (real funds, Arbitrum/Base USDC → recurra-shop.near),
#                 subscriptions on recurra-vault.near plan 1 ("Demo plan", 1 USDC / 5 min)
#   judge-demo    mock checkout (no funds), subscriptions on the public testnet
#                 vault-v2 plan 2 with the faucet token, faucet notice on every page
#
# Idempotent. Only the Recurra settings this profile owns are changed; a 1Click
# API key entered in the admin is kept.
set -eu
PROFILE="${1:-}"
case "$PROFILE" in
	mainnet-demo | judge-demo) ;;
	*) echo "usage: recurra-profile.sh mainnet-demo|judge-demo" >&2; exit 2 ;;
esac
cd /var/www/html
export WP_CLI_CACHE_DIR=/tmp/wp-cli-cache

wp eval-file - "$PROFILE" <<'PHP'
<?php
$profile = $args[0];
$usdc    = 'nep141:17208628f84f5d6ad33f0da3bbbeb27ffcb398eac501a31bd6ad2011e36133a1';

if ( 'mainnet-demo' === $profile ) {
	$settings = array(
		'enabled'           => 'yes',
		'mode'              => 'live',
		'merchant_account'  => 'recurra-shop.near',
		'vault_contract'    => 'recurra-vault.near',
		'rpc_url'           => 'https://rpc.mainnet.fastnear.com',
		'allowed_chains'    => 'arb,base',
		'destination_asset' => $usdc,
		'description'       => 'Pay with USDC on Arbitrum or Base via NEAR Intents. Settles as USDC on NEAR.',
	);
	$products = array( // sku => [name, price, plan id or 0]
		'mainnet-demo-item' => array( 'Demo item', '1', 0 ),
		'mainnet-demo-plan' => array( 'Demo plan', '1', 1 ),
	);
	$hidden = array( 'coffee-beans', 'sticker-pack', 'pro-plan' );
	$notice = '';
} else {
	$settings = array(
		'enabled'           => 'yes',
		'mode'              => 'mock',
		'merchant_account'  => 'merchant.recurra-farzad.testnet',
		'vault_contract'    => 'vault-v2.recurra-farzad.testnet',
		'rpc_url'           => '',
		'allowed_chains'    => 'arb,base,eth,sol,op,pol,near,btc',
		'destination_asset' => $usdc,
		'description'       => 'Pay with USDC, ETH, SOL, BTC, NEAR and more via NEAR Intents.',
	);
	$products = array(
		'coffee-beans' => array( 'Coffee beans', '3', 0 ),
		'sticker-pack' => array( 'Digital sticker pack', '2', 0 ),
		'pro-plan'     => array( 'Pro plan (testnet)', '1', 2 ),
	);
	$hidden = array( 'mainnet-demo-item', 'mainnet-demo-plan' );
	$pro    = wc_get_product_id_by_sku( 'pro-plan' );
	$notice = 'Demo store: checkout runs in <strong>mock mode</strong> (no real funds) and subscriptions run on <strong>NEAR testnet</strong>. '
		. 'To subscribe: buy <a href="' . esc_url( $pro ? get_permalink( $pro ) : wc_get_page_permalink( 'shop' ) ) . '">Pro plan (testnet)</a>, connect a testnet wallet '
		. '(e.g. <a href="https://testnet.mynearwallet.com" target="_blank" rel="noopener">testnet.mynearwallet.com</a>), '
		. 'then click <em>Get 100 test tokens (testnet faucet)</em> on the subscribe page (once per hour).';
}

$current = get_option( 'woocommerce_recurra_settings', array() );
update_option( 'woocommerce_recurra_settings', array_merge( is_array( $current ) ? $current : array(), $settings ) );
update_option( 'woocommerce_demo_store', '' !== $notice ? 'yes' : 'no' );
if ( '' !== $notice ) {
	update_option( 'woocommerce_demo_store_notice', $notice );
}
if ( ! Recurra_Tokens::merchant_allowed_in_mode( $settings['mode'], $settings['merchant_account'] ) ) {
	WP_CLI::error( Recurra_Gateway::testnet_message() );
}

foreach ( $hidden as $sku ) {
	$id = wc_get_product_id_by_sku( $sku );
	if ( $id ) {
		$p = wc_get_product( $id );
		$p->set_status( 'draft' );
		$p->save();
	}
}

$failed = array();
foreach ( $products as $sku => list( $name, $price, $plan_id ) ) {
	$id = wc_get_product_id_by_sku( $sku );
	$p  = $id ? wc_get_product( $id ) : new WC_Product_Simple();
	if ( ! $id ) {
		$p->set_sku( $sku );
		$p->set_name( $name );
		$p->set_regular_price( $price );
		$p->set_virtual( 'coffee-beans' !== $sku );
	}
	if ( $plan_id ) {
		// Same check as the product editor: the plan must exist on this vault, belong to
		// this merchant, be active and match the price (reads the chain).
		$problem = Recurra_Subscriptions::plan_problem( Recurra_Subscriptions::plan( $plan_id, 0 ), (string) $p->get_regular_price() );
		if ( null !== $problem ) {
			$failed[] = "$name: $problem";
			$p->set_status( 'draft' );
			$p->save();
			continue;
		}
		$p->update_meta_data( Recurra_Subscriptions::META_PLAN, $plan_id );
		$p->set_virtual( true );
		$p->set_sold_individually( true );
	}
	$p->set_status( 'publish' );
	$p->save();
	WP_CLI::log( sprintf( '[recurra] %s: %s ($%s)%s', $profile, $name, $p->get_regular_price(), $plan_id ? " → plan $plan_id on " . $settings['vault_contract'] : '' ) );
}

$s = Recurra_Plugin::settings();
WP_CLI::log( sprintf( '[recurra] profile %s: checkout %s → %s · subscriptions on %s · chains %s', $profile, $s['mode'], $s['merchant_account'], $s['vault_contract'], $s['allowed_chains'] ) );
if ( $failed ) {
	WP_CLI::error( "plan not linked (product left as draft):\n  " . implode( "\n  ", $failed ) );
}
PHP
