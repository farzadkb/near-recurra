#!/bin/sh
# Idempotent demo-store setup, run by the `wpcli` compose service.
# WooCommerce + Recurra (MOCK checkout), USD, guest checkout, demo products,
# and a subscription product backed by the public TESTNET vault.
set -eu

WP_URL="${WP_URL:-http://localhost:8080}"
WC_VERSION="11.2.2"
# Testnet merchant that owns the demo plans on the testnet vault. Mock checkout
# accepts any account; live mode would refuse a .testnet merchant.
MERCHANT="${RECURRA_MERCHANT:-merchant.recurra-farzad.testnet}"
VAULT="${RECURRA_VAULT:-vault-v2.recurra-farzad.testnet}"
export PLAN_ID="${RECURRA_PLAN_ID:-2}"
cd /var/www/html
export WP_CLI_CACHE_DIR=/tmp/wp-cli-cache

# `wp option update` exits 1 when the value is unchanged; that is fine on re-runs.
opt() { wp option update "$@" --quiet 2>/dev/null || [ "$(wp option get "$1" --format=json 2>/dev/null)" != "" ]; }

echo "[recurra] waiting for WordPress files..."
i=0
until [ -f wp-config.php ]; do
	i=$((i + 1)); [ "$i" -gt 120 ] && { echo "wp-config.php not found"; exit 1; }
	sleep 1
done
echo "[recurra] waiting for database..."
i=0
until wp db check --quiet >/dev/null 2>&1; do
	i=$((i + 1)); [ "$i" -gt 60 ] && { echo "database not reachable"; exit 1; }
	sleep 2
done

if ! wp core is-installed 2>/dev/null; then
	wp core install --url="$WP_URL" --title="Recurra Demo Store" \
		--admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
fi
wp rewrite structure '/%postname%/' --quiet

if ! wp plugin is-installed woocommerce; then
	wp plugin install woocommerce --version="$WC_VERSION"
fi
wp plugin activate woocommerce --quiet
wp plugin activate recurra-for-woocommerce --quiet

# Store basics: USD, no onboarding wizard, store visible to guests.
opt woocommerce_currency USD
opt woocommerce_default_country "US:CA"
opt woocommerce_store_address "1 Demo Street"
opt woocommerce_store_city "San Francisco"
opt woocommerce_store_postcode "94103"
opt woocommerce_coming_soon no
opt woocommerce_store_pages_only no
opt woocommerce_onboarding_profile '{"skipped":true}' --format=json
opt woocommerce_task_list_hidden yes
opt woocommerce_enable_guest_checkout yes
wp wc tool run install_pages --user=admin --quiet >/dev/null 2>&1 || true

# Free shipping everywhere so the physical demo product can check out.
if [ "$(wp wc shipping_zone_method list 0 --user=admin --format=count)" = "0" ]; then
	wp wc shipping_zone_method create 0 --method_id=free_shipping --enabled=true --user=admin --quiet
fi

# Recurra gateway: enabled, MOCK checkout (no funds); subscriptions on testnet.
opt woocommerce_recurra_settings "$(cat <<JSON
{"enabled":"yes","title":"Pay with crypto (any chain)","description":"Pay with USDC, ETH, SOL, BTC, NEAR and more via NEAR Intents.","mode":"mock","merchant_account":"$MERCHANT","destination_asset":"nep141:17208628f84f5d6ad33f0da3bbbeb27ffcb398eac501a31bd6ad2011e36133a1","api_key":"","allowed_chains":"arb,base,eth,sol,op,pol,near,btc","slippage_bps":"100","quote_ttl_min":"30","vault_contract":"$VAULT","rpc_url":""}
JSON
)" --format=json

# Demo products (by SKU, idempotent).
create_product() { # sku name price virtual
	if [ -z "$(wp wc product list --sku="$1" --field=id --user=admin)" ]; then
		wp wc product create --user=admin --sku="$1" --name="$2" --regular_price="$3" --virtual="$4" --status=publish --quiet
	fi
}
create_product coffee-beans "Coffee beans" 3 false
create_product sticker-pack "Digital sticker pack" 2 true
create_product pro-plan "Pro plan (testnet)" 1 true

# Link the Pro plan product to the vault plan (checked against the chain; needs network).
wp eval '
$id = wc_get_product_id_by_sku( "pro-plan" );
$p  = wc_get_product( $id );
$problem = Recurra_Subscriptions::plan_problem( Recurra_Subscriptions::plan( (int) getenv( "PLAN_ID" ), 0 ), (string) $p->get_regular_price() );
if ( null !== $problem ) { fwrite( STDERR, "[recurra] WARNING: Pro plan not linked: $problem\n" ); return; }
$p->update_meta_data( Recurra_Subscriptions::META_PLAN, (int) getenv( "PLAN_ID" ) );
$p->set_virtual( true );
$p->set_sold_individually( true );
$p->save();
echo "[recurra] Pro plan linked to vault plan " . getenv( "PLAN_ID" ) . "\n";
'

echo "[recurra] ready: $WP_URL (admin/admin) — checkout in MOCK mode, subscriptions on testnet ($VAULT)"
