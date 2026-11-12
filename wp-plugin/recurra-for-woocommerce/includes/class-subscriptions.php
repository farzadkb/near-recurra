<?php
/**
 * Feature B: subscriptions backed by the Recurra Vault contract.
 *
 * - A product is linked to a vault plan (`_recurra_plan_id`); price must equal the plan amount.
 * - Checkout of such a product creates the parent order; the customer subscribes on the
 *   subscribe page with one wallet signature (`ft_transfer_call` to the vault).
 * - The order is paid only after an RPC view (`get_subscription_by_ref`) shows the
 *   subscription with its first period charged — never from a client-reported tx hash.
 * - A recurring job turns each new on-chain charge into a renewal order.
 * - My Account → Subscriptions lets the customer cancel / top up from their own wallet.
 *
 * @package Recurra
 */

defined( 'ABSPATH' ) || exit;

/**
 * Subscriptions.
 */
final class Recurra_Subscriptions {

	const META_PLAN      = '_recurra_plan_id';
	const RENEW_HOOK     = 'recurra_renewals';
	const RENEW_INTERVAL = 60;
	const ENDPOINT       = 'recurra-subscriptions';
	const PERIODS        = array( 1, 2, 3, 4, 6, 12 );
	const SUBSCRIBE_GAS  = '100000000000000';
	const CANCEL_GAS     = '50000000000000';
	const REWRITE_VER    = '1';

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'product_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'product_panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product' ) );

		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add_to_cart' ), 10, 3 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'check_cart' ) );
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'only_recurra_for_subscriptions' ) );

		add_action( self::RENEW_HOOK, array( __CLASS__, 'run_renewals' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );

		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'init', array( __CLASS__, 'maybe_flush_rewrites' ), 20 );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'account_menu' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'account_page' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( __CLASS__, 'account_title' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	// ------------------------------------------------------------------ config / chain

	/**
	 * Vault contract id ('' if not configured).
	 */
	public static function vault(): string {
		return trim( (string) Recurra_Plugin::settings()['vault_contract'] );
	}

	/**
	 * Network of the configured vault.
	 */
	public static function network(): string {
		return Recurra_Near_Rpc::network_of( self::vault() );
	}

	/**
	 * RPC URL for the vault's network.
	 */
	public static function rpc_url(): string {
		$url = trim( (string) Recurra_Plugin::settings()['rpc_url'] );
		return '' !== $url ? $url : Recurra_Near_Rpc::DEFAULT_RPC[ self::network() ];
	}

	/**
	 * RPC client.
	 */
	public static function rpc(): Recurra_Near_Rpc {
		return new Recurra_Near_Rpc( self::rpc_url() );
	}

	/**
	 * Explorer base URL for the vault's network.
	 */
	public static function explorer(): string {
		return 'testnet' === self::network() ? 'https://testnet.nearblocks.io' : 'https://nearblocks.io';
	}

	/**
	 * A plan from the vault.
	 *
	 * @param int $plan_id   Plan id.
	 * @param int $cache_ttl Cache seconds.
	 * @return array<string, mixed>|null|WP_Error
	 */
	public static function plan( int $plan_id, int $cache_ttl = 60 ) {
		if ( '' === self::vault() ) {
			return new WP_Error( 'recurra_no_vault', __( 'Vault contract is not configured.', 'recurra' ) );
		}
		return self::rpc()->view( self::vault(), 'get_plan', array( 'plan_id' => $plan_id ), $cache_ttl );
	}

	/**
	 * Plans of the configured merchant: [ [id, plan], ... ].
	 *
	 * @return array<int, array{0:int, 1:array<string, mixed>}>|WP_Error
	 */
	public static function merchant_plans() {
		$merchant = trim( (string) Recurra_Plugin::settings()['merchant_account'] );
		if ( '' === self::vault() || '' === $merchant ) {
			return array();
		}
		$plans = self::rpc()->view( self::vault(), 'get_plans', array( 'merchant' => $merchant ), 60 );
		return is_array( $plans ) || is_wp_error( $plans ) ? $plans : array();
	}

	/**
	 * Token metadata (decimals, symbol), cached for a day.
	 *
	 * @param string $token Token contract.
	 * @return array{decimals:int, symbol:string}|WP_Error
	 */
	public static function token_meta( string $token ) {
		$meta = self::rpc()->view( $token, 'ft_metadata', array(), DAY_IN_SECONDS );
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}
		if ( ! is_array( $meta ) || ! isset( $meta['decimals'] ) ) {
			return new WP_Error( 'recurra_token_meta', __( 'Token metadata unavailable.', 'recurra' ) );
		}
		return array(
			'decimals' => (int) $meta['decimals'],
			'symbol'   => sanitize_text_field( (string) ( $meta['symbol'] ?? '' ) ),
		);
	}

	/**
	 * Why a plan cannot back a product sold at $price, or null if it can.
	 *
	 * @param mixed  $plan  Plan from the vault (or null / WP_Error).
	 * @param string $price Product price as a decimal string.
	 */
	public static function plan_problem( $plan, string $price ): ?string {
		if ( is_wp_error( $plan ) ) {
			return __( 'Could not read the plan from the vault: ', 'recurra' ) . $plan->get_error_message();
		}
		if ( ! is_array( $plan ) ) {
			return __( 'Plan not found in the vault.', 'recurra' );
		}
		$merchant = trim( (string) Recurra_Plugin::settings()['merchant_account'] );
		if ( ( $plan['merchant'] ?? '' ) !== $merchant ) {
			return __( 'The plan belongs to a different merchant account than the one in the Recurra settings.', 'recurra' );
		}
		if ( empty( $plan['active'] ) ) {
			return __( 'The plan is inactive.', 'recurra' );
		}
		$token = (string) ( $plan['token'] ?? '' );
		if ( 'mainnet' === self::network() && Recurra_Plugin::USDC_CONTRACT !== $token ) {
			return __( 'On mainnet the plan token must be native USDC.', 'recurra' );
		}
		$meta = self::token_meta( $token );
		if ( is_wp_error( $meta ) ) {
			return $meta->get_error_message();
		}
		try {
			$price_base = Recurra_Amount::decimal_to_base( $price, $meta['decimals'] );
		} catch ( InvalidArgumentException $e ) {
			return __( 'The product price is not a valid token amount.', 'recurra' );
		}
		if ( 0 !== Recurra_Amount::cmp( $price_base, (string) ( $plan['amount'] ?? '' ) ) ) {
			return sprintf(
				/* translators: 1: price required by the plan, 2: token symbol. */
				__( 'The product price must equal the plan amount (%1$s %2$s per period).', 'recurra' ),
				Recurra_Amount::base_to_decimal( (string) $plan['amount'], $meta['decimals'] ),
				$meta['symbol']
			);
		}
		return null;
	}

	// ------------------------------------------------------------------ product admin

	/**
	 * Plan id linked to a product (0 = none).
	 *
	 * @param WC_Product|false|null $product Product.
	 */
	public static function product_plan_id( $product ): int {
		return $product instanceof WC_Product ? (int) $product->get_meta( self::META_PLAN ) : 0;
	}

	/**
	 * Adds the "Recurra subscription" product data tab.
	 *
	 * @param array<string, array<string, mixed>> $tabs Tabs.
	 * @return array<string, array<string, mixed>>
	 */
	public static function product_tab( array $tabs ): array {
		$tabs['recurra'] = array(
			'label'    => __( 'Recurra subscription', 'recurra' ),
			'target'   => 'recurra_product_data',
			'class'    => array( 'show_if_simple' ),
			'priority' => 80,
		);
		return $tabs;
	}

	/**
	 * Product data panel: choose a vault plan.
	 */
	public static function product_panel(): void {
		global $product_object;
		$current = self::product_plan_id( $product_object );
		$plans   = self::merchant_plans();
		echo '<div id="recurra_product_data" class="panel woocommerce_options_panel"><div class="options_group">';
		if ( '' === self::vault() ) {
			echo '<p>' . esc_html__( 'Set the Vault contract in WooCommerce → Settings → Payments → Recurra first.', 'recurra' ) . '</p>';
		} elseif ( is_wp_error( $plans ) ) {
			echo '<p>' . esc_html__( 'Could not load plans from the vault:', 'recurra' ) . ' ' . esc_html( $plans->get_error_message() ) . '</p>';
		}
		$options = array( '0' => __( '— Not a subscription —', 'recurra' ) );
		foreach ( is_array( $plans ) ? $plans : array() as $row ) {
			if ( ! is_array( $row ) || ! isset( $row[0], $row[1] ) || ! is_array( $row[1] ) ) {
				continue;
			}
			$p                           = $row[1];
			$options[ (string) $row[0] ] = sprintf(
				/* translators: 1: plan id, 2: name, 3: amount in base units, 4: period seconds, 5: inactive marker. */
				__( '#%1$s %2$s — %3$s base units every %4$s s%5$s', 'recurra' ),
				(int) $row[0],
				(string) ( $p['name'] ?? '' ),
				(string) ( $p['amount'] ?? '' ),
				(int) ( $p['period_sec'] ?? 0 ),
				empty( $p['active'] ) ? ' ' . __( '(inactive)', 'recurra' ) : ''
			);
		}
		if ( $current && ! isset( $options[ (string) $current ] ) ) {
			/* translators: %d: plan id. */
			$options[ (string) $current ] = sprintf( __( '#%d (not found for this merchant)', 'recurra' ), $current );
		}
		woocommerce_wp_select(
			array(
				'id'          => self::META_PLAN,
				'label'       => __( 'Vault plan', 'recurra' ),
				'options'     => $options,
				'value'       => (string) $current,
				'desc_tip'    => true,
				'description' => __( 'Customers prepay into the Recurra Vault; the contract charges this plan once per period. The regular price must equal the plan amount. Subscription products are virtual and sold one at a time.', 'recurra' ),
			)
		);
		echo '<p class="form-field">' . esc_html__( 'Create plans with the vault\'s create_plan method (see README → Subscriptions).', 'recurra' ) . ' ';
		echo esc_html__( 'Vault:', 'recurra' ) . ' <code>' . esc_html( self::vault() ) . '</code></p>';
		echo '</div></div>';
	}

	/**
	 * Saves the plan link after validating it against the vault.
	 *
	 * @param WC_Product $product Product (props already set from the form; WooCommerce verified the nonce).
	 */
	public static function save_product( $product ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by WooCommerce before this hook.
		$plan_id = isset( $_POST[ self::META_PLAN ] ) ? absint( wp_unslash( $_POST[ self::META_PLAN ] ) ) : 0;
		if ( 0 === $plan_id ) {
			$product->delete_meta_data( self::META_PLAN );
			return;
		}
		$problem = null;
		if ( ! $product->is_type( 'simple' ) ) {
			$problem = __( 'Only simple products can be subscriptions.', 'recurra' );
		} elseif ( '' !== (string) $product->get_sale_price( 'edit' ) ) {
			$problem = __( 'Subscription products cannot have a sale price.', 'recurra' );
		} else {
			$problem = self::plan_problem( self::plan( $plan_id, 0 ), (string) $product->get_regular_price( 'edit' ) );
		}
		if ( null !== $problem ) {
			WC_Admin_Meta_Boxes::add_error( __( 'Recurra: the product was not linked to the plan. ', 'recurra' ) . $problem );
			$product->delete_meta_data( self::META_PLAN );
			return;
		}
		$product->update_meta_data( self::META_PLAN, $plan_id );
		$product->set_virtual( true );
		$product->set_sold_individually( true );
	}

	// ------------------------------------------------------------------ cart / checkout

	/**
	 * Why the current cart cannot be checked out, or null.
	 */
	public static function cart_problem(): ?string {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return null;
		}
		$items = WC()->cart->get_cart();
		$subs  = 0;
		foreach ( $items as $item ) {
			if ( self::product_plan_id( $item['data'] ?? null ) ) {
				++$subs;
				if ( (int) $item['quantity'] > 1 ) {
					return __( 'A subscription can only be bought once per order.', 'recurra' );
				}
			}
		}
		if ( $subs && count( $items ) > 1 ) {
			return __( 'Subscriptions must be checked out on their own. Remove the other items from your cart.', 'recurra' );
		}
		return null;
	}

	/**
	 * Whether the cart contains a subscription product.
	 */
	public static function cart_has_subscription(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( self::product_plan_id( $item['data'] ?? null ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Subscription products must be alone in the cart.
	 *
	 * @param bool $passed     Passed.
	 * @param int  $product_id Product id.
	 * @param int  $quantity   Quantity.
	 */
	public static function validate_add_to_cart( $passed, $product_id, $quantity ) {
		if ( ! $passed || ! WC()->cart ) {
			return $passed;
		}
		$adding_sub = self::product_plan_id( wc_get_product( $product_id ) ) > 0;
		if ( $adding_sub && (int) $quantity > 1 ) {
			wc_add_notice( __( 'A subscription can only be bought once per order.', 'recurra' ), 'error' );
			return false;
		}
		if ( ( $adding_sub && ! WC()->cart->is_empty() ) || ( ! $adding_sub && self::cart_has_subscription() ) ) {
			wc_add_notice( __( 'Subscriptions must be checked out on their own. Empty your cart first.', 'recurra' ), 'error' );
			return false;
		}
		return $passed;
	}

	/**
	 * Blocks checkout of mixed carts (classic and Store API checkout).
	 */
	public static function check_cart(): void {
		$problem = self::cart_problem();
		if ( null !== $problem ) {
			wc_add_notice( $problem, 'error' );
		}
	}

	/**
	 * Only Recurra can take a subscription order.
	 *
	 * @param array<string, WC_Payment_Gateway> $gateways Gateways.
	 * @return array<string, WC_Payment_Gateway>
	 */
	public static function only_recurra_for_subscriptions( $gateways ) {
		if ( ! is_array( $gateways ) || ! self::cart_has_subscription() ) {
			return $gateways;
		}
		return array_intersect_key( $gateways, array( Recurra_Plugin::GATEWAY_ID => true ) );
	}

	/**
	 * The subscription product line of an order, or null.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function order_subscription_item( WC_Order $order ): ?WC_Order_Item_Product {
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product && self::product_plan_id( $item->get_product() ) ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * Whether an order is a subscription (parent) order.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function is_subscription_order( WC_Order $order ): bool {
		return 'subscription' === $order->get_meta( '_recurra_kind' );
	}

	/**
	 * Called from process_payment: snapshots the plan into the order.
	 *
	 * @param WC_Order $order Order.
	 * @return true|null|WP_Error True if this is a subscription order, null if not.
	 */
	public static function prepare_order( WC_Order $order ) {
		$item = self::order_subscription_item( $order );
		if ( ! $item ) {
			return null;
		}
		if ( count( $order->get_items() ) > 1 || $item->get_quantity() > 1 ) {
			return new WP_Error( 'recurra_sub_cart', __( 'Subscriptions must be checked out on their own.', 'recurra' ) );
		}
		$plan_id = self::product_plan_id( $item->get_product() );
		$plan    = self::plan( $plan_id, 0 );
		$total   = wc_format_decimal( (string) $order->get_total( 'edit' ), wc_get_price_decimals() );
		$problem = self::plan_problem( $plan, (string) $total );
		if ( null !== $problem ) {
			return new WP_Error( 'recurra_sub_plan', __( 'This subscription is not available right now.', 'recurra' ) . ' ' . $problem );
		}
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$host = strlen( $host ) > 40 ? substr( md5( $host ), 0, 12 ) : $host;
		$order->update_meta_data( '_recurra_kind', 'subscription' );
		$order->update_meta_data( '_recurra_vault', self::vault() );
		$order->update_meta_data( '_recurra_plan_id', $plan_id );
		$order->update_meta_data(
			'_recurra_plan',
			wp_json_encode(
				array(
					'merchant'   => (string) $plan['merchant'],
					'token'      => (string) $plan['token'],
					'amount'     => (string) $plan['amount'],
					'period_sec' => (int) $plan['period_sec'],
					'name'       => (string) $plan['name'],
				)
			)
		);
		if ( '' === (string) $order->get_meta( '_recurra_external_ref' ) ) {
			// Random suffix: nobody can pre-register a subscription under this order's reference.
			$order->update_meta_data( '_recurra_external_ref', 'wc:' . $host . ':' . $order->get_id() . ':' . strtolower( wp_generate_password( 10, false ) ) );
		}
		return true;
	}

	/**
	 * Plan snapshot stored on a subscription order.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string, mixed>
	 */
	public static function order_plan( WC_Order $order ): array {
		$plan = json_decode( (string) $order->get_meta( '_recurra_plan' ), true );
		return is_array( $plan ) ? $plan : array();
	}

	// ------------------------------------------------------------------ confirmation

	/**
	 * Verifies an on-chain subscription against the order's snapshot. Returns a reason or null.
	 *
	 * @param WC_Order             $order Order.
	 * @param array<string, mixed> $sub   Subscription view.
	 */
	public static function subscription_problem( WC_Order $order, array $sub ): ?string {
		$plan = self::order_plan( $order );
		if ( (int) ( $sub['plan_id'] ?? 0 ) !== (int) $order->get_meta( '_recurra_plan_id' ) ) {
			return 'plan mismatch';
		}
		if ( ( $sub['merchant'] ?? '' ) !== ( $plan['merchant'] ?? null ) ) {
			return 'merchant mismatch';
		}
		if ( ( $sub['token'] ?? '' ) !== ( $plan['token'] ?? null ) ) {
			return 'token mismatch';
		}
		if ( ( $sub['amount'] ?? '' ) !== ( $plan['amount'] ?? null ) ) {
			return 'amount mismatch';
		}
		if ( ( $sub['period_ns'] ?? '' ) !== Recurra_Amount::shift( (string) (int) ( $plan['period_sec'] ?? 0 ), 9 ) ) {
			return 'period mismatch';
		}
		if ( ( $sub['external_ref'] ?? '' ) !== (string) $order->get_meta( '_recurra_external_ref' ) ) {
			return 'reference mismatch';
		}
		return null;
	}

	/**
	 * Number of the charge currently in flight (0 if none).
	 *
	 * @param array<string, mixed> $sub Subscription view.
	 */
	private static function in_flight_number( array $sub ): int {
		return is_array( $sub['in_flight'] ?? null ) ? (int) ( $sub['in_flight']['charge_number'] ?? 0 ) : 0;
	}

	/**
	 * Confirms a subscription order from chain state.
	 *
	 * @param WC_Order $order Order.
	 * @return string 'paid' | 'waiting' | 'charging' | 'mismatch' | 'error'
	 */
	public static function confirm( WC_Order $order ): string {
		if ( $order->is_paid() ) {
			return 'paid';
		}
		$lock = 'confirm_' . $order->get_id();
		if ( ! Recurra_Order_Sync::lock( $lock ) ) {
			return 'waiting';
		}
		try {
			$order = wc_get_order( $order->get_id() );
			if ( $order->is_paid() ) {
				return 'paid';
			}
			$plan = self::order_plan( $order );
			$sub  = self::rpc()->view(
				(string) $order->get_meta( '_recurra_vault' ),
				'get_subscription_by_ref',
				array(
					'merchant'     => (string) ( $plan['merchant'] ?? '' ),
					'external_ref' => (string) $order->get_meta( '_recurra_external_ref' ),
				)
			);
			if ( is_wp_error( $sub ) ) {
				return 'error';
			}
			if ( ! is_array( $sub ) ) {
				return 'waiting';
			}
			$problem = self::subscription_problem( $order, $sub );
			if ( null !== $problem ) {
				if ( (string) $order->get_meta( '_recurra_verification_failed' ) !== $problem ) {
					$order->update_meta_data( '_recurra_verification_failed', $problem );
					/* translators: %s: reason. */
					$order->add_order_note( sprintf( __( 'Recurra: on-chain subscription does not match this order (%s). Not marked paid.', 'recurra' ), $problem ) );
					$order->save();
				}
				return 'mismatch';
			}
			$charges = (int) ( $sub['charges_count'] ?? 0 );
			if ( 0 === $charges ) {
				// First charge failed and was re-credited; the charger retries it.
				return 'waiting';
			}
			if ( 1 === self::in_flight_number( $sub ) ) {
				return 'charging';
			}
			$sub_id = (int) $sub['id'];
			$order->update_meta_data( '_recurra_sub_id', $sub_id );
			$order->update_meta_data( '_recurra_sub_owner', sanitize_text_field( (string) $sub['owner'] ) );
			$order->update_meta_data( '_recurra_charges_seen', 1 );
			$order->update_meta_data( '_recurra_sub_active', 'yes' );
			self::set_active( $order->get_id(), true );
			self::store_snapshot( $order, $sub );
			$order->add_order_note(
				sprintf(
					/* translators: 1: subscription id, 2: owner account, 3: vault account. */
					__( 'Recurra: subscription #%1$d created on-chain by %2$s in vault %3$s; first period charged to the merchant.', 'recurra' ),
					$sub_id,
					esc_html( (string) $sub['owner'] ),
					esc_html( (string) $order->get_meta( '_recurra_vault' ) )
				)
			);
			$order->payment_complete( 'recurra:sub' . $sub_id . ':1' );
			return 'paid';
		} finally {
			Recurra_Order_Sync::unlock( $lock );
		}//end try
	}

	/**
	 * Stores the last-seen chain state for display.
	 *
	 * @param WC_Order             $order Order.
	 * @param array<string, mixed> $sub   Subscription view.
	 */
	private static function store_snapshot( WC_Order $order, array $sub ): void {
		$order->update_meta_data( '_recurra_sub_status', sanitize_text_field( (string) ( $sub['status'] ?? '' ) ) );
		$order->update_meta_data(
			'_recurra_sub_snapshot',
			wp_json_encode(
				array(
					'balance'           => (string) ( $sub['balance'] ?? '0' ),
					'next_charge_at_ns' => (string) ( $sub['next_charge_at_ns'] ?? '0' ),
					'charges_count'     => (int) ( $sub['charges_count'] ?? 0 ),
					'max_charges'       => $sub['max_charges'] ?? null,
					'status'            => (string) ( $sub['status'] ?? '' ),
					'synced_at'         => time(),
				)
			)
		);
	}

	// ------------------------------------------------------------------ renewals

	/**
	 * Ensures the recurring renewal job exists.
	 */
	public static function schedule(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || as_has_scheduled_action( self::RENEW_HOOK, array(), Recurra_Poller::GROUP ) ) {
			return;
		}
		as_schedule_recurring_action( time() + self::RENEW_INTERVAL, self::RENEW_INTERVAL, self::RENEW_HOOK, array(), Recurra_Poller::GROUP, true );
	}

	/**
	 * Syncs every active subscription order with the chain.
	 */
	public static function run_renewals(): void {
		update_option( 'recurra_last_renewal_run', time(), false );
		foreach ( self::active_ids() as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				self::sync( $order );
			} else {
				self::set_active( $order_id, false );
			}
		}
		// Backup confirmation for customers who signed and closed the tab.
		$pending = wc_get_orders(
			array(
				'payment_method' => Recurra_Plugin::GATEWAY_ID,
				'status'         => array( 'pending' ),
				'date_created'   => '>' . ( time() - DAY_IN_SECONDS ),
				'limit'          => 50,
			)
		);
		foreach ( $pending as $order ) {
			if ( self::is_subscription_order( $order ) ) {
				self::confirm( $order );
			}
		}
	}

	/**
	 * Ids of subscription orders whose subscription may still change on-chain.
	 *
	 * Kept in an option instead of an order meta query: `wc_get_orders()` ignores
	 * `meta_query` with the legacy posts datastore (HPOS off).
	 *
	 * @return array<int, int>
	 */
	public static function active_ids(): array {
		$ids = get_option( 'recurra_active_subscriptions', array() );
		return is_array( $ids ) ? array_values( array_unique( array_map( 'intval', $ids ) ) ) : array();
	}

	/**
	 * Adds / removes an order from the active registry (and its meta flag).
	 *
	 * @param int  $order_id Order id.
	 * @param bool $active   Active.
	 */
	private static function set_active( int $order_id, bool $active ): void {
		$ids = array_diff( self::active_ids(), array( $order_id ) );
		if ( $active ) {
			$ids[] = $order_id;
		}
		update_option( 'recurra_active_subscriptions', array_values( $ids ), false );
	}

	/**
	 * Syncs one subscription parent order: creates renewal orders for new charges
	 * and records status changes. Idempotent; per-subscription lock.
	 *
	 * @param WC_Order $main Subscription (parent) order.
	 * @return array<string, mixed>|WP_Error Subscription view.
	 */
	public static function sync( WC_Order $main ) {
		$sub_id = (int) $main->get_meta( '_recurra_sub_id' );
		if ( ! $sub_id ) {
			return new WP_Error( 'recurra_no_sub', __( 'Order has no subscription.', 'recurra' ) );
		}
		$lock = 'sub_' . $main->get_id();
		if ( ! Recurra_Order_Sync::lock( $lock ) ) {
			return new WP_Error( 'recurra_busy', __( 'Sync already running.', 'recurra' ) );
		}
		try {
			$main  = wc_get_order( $main->get_id() );
			$vault = (string) $main->get_meta( '_recurra_vault' );
			$sub   = self::rpc()->view( $vault, 'get_subscription', array( 'sub_id' => $sub_id ) );
			if ( is_wp_error( $sub ) ) {
				return $sub;
			}
			if ( ! is_array( $sub ) || null !== self::subscription_problem( $main, $sub ) ) {
				$main->update_meta_data( '_recurra_sub_active', 'no' );
				self::set_active( $main->get_id(), false );
				$main->add_order_note( __( 'Recurra: the on-chain subscription no longer matches this order; renewal tracking stopped.', 'recurra' ) );
				$main->save();
				return new WP_Error( 'recurra_sub_mismatch', __( 'Subscription mismatch.', 'recurra' ) );
			}

			$seen      = max( 1, (int) $main->get_meta( '_recurra_charges_seen' ) );
			$count     = (int) $sub['charges_count'];
			$in_flight = self::in_flight_number( $sub );
			for ( $n = $seen + 1; $n <= $count; $n++ ) {
				if ( $n === $in_flight ) {
					break;
					// Settles on the next run, once the callback has resolved.
				}
				$renewal = self::create_renewal( $main, $sub, $n );
				if ( is_wp_error( $renewal ) ) {
					break;
				}
			}

			$previous = (string) $main->get_meta( '_recurra_sub_status' );
			$status   = (string) $sub['status'];
			self::store_snapshot( $main, $sub );
			if ( $previous !== $status ) {
				self::note_status_change( $main, $previous, $status, $sub );
			}
			if ( 'Cancelled' === $status && 0 === self::in_flight_number( $sub ) ) {
				// Keep tracking while a charge is in flight: it may still settle into a renewal.
				$main->update_meta_data( '_recurra_sub_active', 'no' );
				self::set_active( $main->get_id(), false );
			}
			$main->save();
			return $sub;
		} finally {
			Recurra_Order_Sync::unlock( $lock );
		}//end try
	}

	/**
	 * Adds notes for a status change.
	 *
	 * @param WC_Order             $main   Parent order.
	 * @param string               $previous Previous status.
	 * @param string               $status   New status.
	 * @param array<string, mixed> $sub      Subscription view.
	 */
	private static function note_status_change( WC_Order $main, string $previous, string $status, array $sub ): void {
		switch ( $status ) {
			case 'PastDue':
				$main->add_order_note(
					sprintf(
						/* translators: %s: My Account subscriptions URL. */
						__( 'Your subscription balance is too low for the next charge. Top it up from your wallet in My Account → Subscriptions: %s', 'recurra' ),
						esc_url( wc_get_account_endpoint_url( self::ENDPOINT ) )
					),
					1
				);
				break;
			case 'Active':
				if ( 'PastDue' === $previous ) {
					$main->add_order_note( __( 'Recurra: subscription topped up and active again.', 'recurra' ) );
				}
				break;
			case 'Cancelled':
				$main->add_order_note(
					sprintf(
						/* translators: %s: owner account. */
						__( 'Recurra: subscription cancelled on-chain. The contract refunded the remaining balance to %s.', 'recurra' ),
						esc_html( (string) $sub['owner'] )
					)
				);
				break;
		}//end switch
	}

	/**
	 * Renewal orders created so far: charge number => order id.
	 *
	 * @param WC_Order $main Parent order.
	 * @return array<int, int>
	 */
	public static function renewal_map( WC_Order $main ): array {
		$map = json_decode( (string) $main->get_meta( '_recurra_renewals' ), true );
		$out = array();
		foreach ( is_array( $map ) ? $map : array() as $n => $id ) {
			$out[ (int) $n ] = (int) $id;
		}
		return $out;
	}

	/**
	 * Creates (once) the paid renewal order for charge $n.
	 *
	 * @param WC_Order             $main Parent order.
	 * @param array<string, mixed> $sub    Subscription view.
	 * @param int                  $n      Charge number (>= 2).
	 * @return WC_Order|WP_Error
	 */
	private static function create_renewal( WC_Order $main, array $sub, int $n ) {
		$key  = (string) $main->get_meta( '_recurra_vault' ) . ':' . (int) $sub['id'] . ':' . $n;
		$done = self::renewal_map( $main );
		if ( isset( $done[ $n ] ) ) {
			$main->update_meta_data( '_recurra_charges_seen', max( $n, (int) $main->get_meta( '_recurra_charges_seen' ) ) );
			return wc_get_order( $done[ $n ] );
		}
		$item = self::order_subscription_item( $main );
		$plan = self::order_plan( $main );
		$meta = self::token_meta( (string) $plan['token'] );
		if ( ! $item || is_wp_error( $meta ) ) {
			return new WP_Error( 'recurra_renewal', __( 'Cannot build renewal order.', 'recurra' ) );
		}
		$price   = Recurra_Amount::base_to_decimal( (string) $plan['amount'], $meta['decimals'] );
		$renewal = wc_create_order(
			array(
				'customer_id' => $main->get_customer_id(),
				'created_via' => 'recurra',
				'parent'      => $main->get_id(),
			)
		);
		if ( is_wp_error( $renewal ) ) {
			return $renewal;
		}
		$renewal->set_address( $main->get_address( 'billing' ), 'billing' );
		$renewal->set_currency( $main->get_currency() );
		$product = $item->get_product();
		if ( $product ) {
			$renewal->add_product(
				$product,
				1,
				array(
					'subtotal' => $price,
					'total'    => $price,
				)
			);
		}
		$renewal->set_payment_method( Recurra_Plugin::GATEWAY_ID );
		$renewal->set_payment_method_title( (string) $main->get_payment_method_title() );
		$renewal->update_meta_data( '_recurra_kind', 'renewal' );
		$renewal->update_meta_data( '_recurra_renewal_key', $key );
		$renewal->update_meta_data( '_recurra_parent_order', $main->get_id() );
		$renewal->update_meta_data( '_recurra_sub_id', (int) $sub['id'] );
		$renewal->update_meta_data( '_recurra_charge_number', $n );
		$renewal->calculate_totals( false );
		$renewal->save();
		// Record on the parent first (under the sync lock), so a retry can never create a duplicate.
		$done[ $n ] = $renewal->get_id();
		$main->update_meta_data( '_recurra_renewals', wp_json_encode( $done ) );
		$main->update_meta_data( '_recurra_charges_seen', $n );
		$main->save();
		$renewal->add_order_note(
			sprintf(
				/* translators: 1: charge number, 2: subscription id, 3: parent order id, 4: vault explorer URL. */
				__( 'Recurra: renewal charge #%1$d of subscription #%2$d (order #%3$d), paid on-chain from the customer\'s vault balance. Vault activity: %4$s', 'recurra' ),
				$n,
				(int) $sub['id'],
				$main->get_id(),
				esc_url( self::explorer() . '/address/' . rawurlencode( (string) $main->get_meta( '_recurra_vault' ) ) )
			)
		);
		$renewal->payment_complete( 'recurra:sub' . (int) $sub['id'] . ':' . $n );
		/* translators: 1: charge number, 2: renewal order id. */
		$main->add_order_note( sprintf( __( 'Recurra: renewal charge #%1$d → order #%2$d.', 'recurra' ), $n, $renewal->get_id() ) );
		return $renewal;
	}

	// ------------------------------------------------------------------ My Account

	/**
	 * Registers the account endpoint with WooCommerce.
	 *
	 * @param array<string, string> $vars Query vars.
	 * @return array<string, string>
	 */
	public static function query_vars( $vars ) {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * Flushes rewrite rules once after the endpoint is added or changed.
	 */
	public static function maybe_flush_rewrites(): void {
		if ( get_option( 'recurra_rewrite_version' ) !== self::REWRITE_VER ) {
			flush_rewrite_rules( false );
			update_option( 'recurra_rewrite_version', self::REWRITE_VER, false );
		}
	}

	/**
	 * Adds "Subscriptions" to My Account.
	 *
	 * @param array<string, string> $items Menu items.
	 * @return array<string, string>
	 */
	public static function account_menu( $items ) {
		$out = array();
		foreach ( $items as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'orders' === $key ) {
				$out[ self::ENDPOINT ] = __( 'Subscriptions', 'recurra' );
			}
		}
		if ( ! isset( $out[ self::ENDPOINT ] ) ) {
			$out[ self::ENDPOINT ] = __( 'Subscriptions', 'recurra' );
		}
		return $out;
	}

	/**
	 * Endpoint title.
	 */
	public static function account_title(): string {
		return __( 'Subscriptions', 'recurra' );
	}

	/**
	 * Subscription parent orders of a customer.
	 *
	 * @param int $user_id User id.
	 * @return array<int, WC_Order>
	 */
	public static function customer_subscription_orders( int $user_id ): array {
		if ( ! $user_id ) {
			return array();
		}
		$orders = wc_get_orders(
			array(
				'customer_id'    => $user_id,
				'payment_method' => Recurra_Plugin::GATEWAY_ID,
				'limit'          => 200,
			)
		);
		return array_values(
			array_filter(
				$orders,
				static function ( $o ) {
					return self::is_subscription_order( $o ) && (int) $o->get_meta( '_recurra_sub_id' ) > 0;
				}
			)
		);
	}

	/**
	 * Row data for the account page / JS.
	 *
	 * @param WC_Order $order Parent order.
	 * @return array<string, mixed>
	 */
	public static function row( WC_Order $order ): array {
		$plan  = self::order_plan( $order );
		$snap  = json_decode( (string) $order->get_meta( '_recurra_sub_snapshot' ), true );
		$snap  = is_array( $snap ) ? $snap : array();
		$meta  = self::token_meta( (string) ( $plan['token'] ?? '' ) );
		$dec   = is_wp_error( $meta ) ? 6 : $meta['decimals'];
		$sym   = is_wp_error( $meta ) ? '' : $meta['symbol'];
		$next  = (string) ( $snap['next_charge_at_ns'] ?? '0' );
		$next  = Recurra_Amount::shift_down( $next, 9 );
		$total = Recurra_Amount::base_to_decimal( (string) ( $plan['amount'] ?? '0' ), $dec );
		return array(
			'orderId'     => $order->get_id(),
			'subId'       => (int) $order->get_meta( '_recurra_sub_id' ),
			'vault'       => (string) $order->get_meta( '_recurra_vault' ),
			'token'       => (string) ( $plan['token'] ?? '' ),
			'owner'       => (string) $order->get_meta( '_recurra_sub_owner' ),
			'name'        => (string) ( $plan['name'] ?? '' ),
			'amount'      => (string) ( $plan['amount'] ?? '0' ),
			'amountText'  => $total . ' ' . $sym,
			'periodSec'   => (int) ( $plan['period_sec'] ?? 0 ),
			'decimals'    => $dec,
			'symbol'      => $sym,
			'status'      => (string) ( $snap['status'] ?? $order->get_meta( '_recurra_sub_status' ) ),
			'balanceText' => Recurra_Amount::base_to_decimal( (string) ( $snap['balance'] ?? '0' ), $dec ) . ' ' . $sym,
			'charges'     => (int) ( $snap['charges_count'] ?? 0 ),
			'nextCharge'  => ( 'Cancelled' === ( $snap['status'] ?? '' ) || '0' === $next ) ? '' : wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $next ),
		);
	}

	/**
	 * My Account → Subscriptions.
	 */
	public static function account_page(): void {
		$orders = self::customer_subscription_orders( get_current_user_id() );
		foreach ( $orders as $order ) {
			// Fresh state for the page; cheap (one view per subscription).
			if ( 'yes' === $order->get_meta( '_recurra_sub_active' ) ) {
				self::sync( $order );
			}
		}
		$rows = array();
		foreach ( self::customer_subscription_orders( get_current_user_id() ) as $order ) {
			$rows[] = self::row( $order );
		}
		wc_get_template(
			'account-subscriptions.php',
			array(
				'rows'   => $rows,
				'config' => self::account_config(),
			),
			'',
			RECURRA_DIR . 'templates/'
		);
	}

	/**
	 * Config for the account page JS.
	 *
	 * @return array<string, mixed>
	 */
	public static function account_config(): array {
		return array(
			'network'   => self::network(),
			'rpcUrl'    => self::rpc_url(),
			'restBase'  => esc_url_raw( rest_url( Recurra_REST::NS . '/subscriptions/' ) ),
			'restNonce' => wp_create_nonce( 'wp_rest' ),
			'cancelGas' => self::CANCEL_GAS,
			'topUpGas'  => self::SUBSCRIBE_GAS,
			'periods'   => self::PERIODS,
			'explorer'  => self::explorer(),
			'i18n'      => array(
				'connect'       => __( 'Connect NEAR wallet', 'recurra' ),
				'disconnect'    => __( 'Disconnect', 'recurra' ),
				'connectedAs'   => __( 'Connected as', 'recurra' ),
				'wrongAccount'  => __( 'Connect the subscription owner account to manage it:', 'recurra' ),
				'confirmCancel' => __( 'Cancel this subscription? The contract refunds the remaining balance to your wallet.', 'recurra' ),
				'working'       => __( 'Waiting for your wallet…', 'recurra' ),
				'syncing'       => __( 'Confirming on-chain…', 'recurra' ),
				'error'         => __( 'Something went wrong:', 'recurra' ),
			),
		);
	}

	/**
	 * Enqueues wallet assets on the subscribe page and the account endpoint.
	 */
	public static function enqueue(): void {
		$account = function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( self::ENDPOINT );
		$pay     = false;
		if ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() ) {
			global $wp;
			$order_id = isset( $wp->query_vars['order-pay'] ) ? absint( $wp->query_vars['order-pay'] ) : 0;
			$order    = $order_id ? wc_get_order( $order_id ) : false;
			$pay      = $order && Recurra_Plugin::GATEWAY_ID === $order->get_payment_method() && self::is_subscription_order( $order );
		}
		if ( ! $account && ! $pay ) {
			return;
		}
		wp_enqueue_style( 'recurra-pay', RECURRA_URL . 'assets/css/pay-page.css', array(), RECURRA_VERSION );
		wp_enqueue_script( 'recurra-wallet', RECURRA_URL . 'assets/dist/recurra-wallet.js', array(), RECURRA_VERSION, true );
		if ( $pay ) {
			wp_enqueue_script( 'recurra-subscribe', RECURRA_URL . 'assets/js/subscribe-page.js', array( 'recurra-wallet' ), RECURRA_VERSION, true );
		} else {
			wp_enqueue_script( 'recurra-account', RECURRA_URL . 'assets/js/account-subscriptions.js', array( 'recurra-wallet' ), RECURRA_VERSION, true );
		}
	}

	/**
	 * Config for the subscribe page JS.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string, mixed>
	 */
	public static function subscribe_page_config( WC_Order $order ): array {
		$plan = self::order_plan( $order );
		$meta = self::token_meta( (string) ( $plan['token'] ?? '' ) );
		return array(
			'orderId'     => $order->get_id(),
			'orderKey'    => $order->get_order_key(),
			'restBase'    => esc_url_raw( rest_url( Recurra_REST::NS . '/orders/' . $order->get_id() ) ),
			'nonce'       => wp_create_nonce( 'recurra_order_' . $order->get_id() ),
			'restNonce'   => wp_create_nonce( 'wp_rest' ),
			'network'     => Recurra_Near_Rpc::network_of( (string) $order->get_meta( '_recurra_vault' ) ),
			'rpcUrl'      => self::rpc_url(),
			'vault'       => (string) $order->get_meta( '_recurra_vault' ),
			'token'       => (string) ( $plan['token'] ?? '' ),
			'decimals'    => is_wp_error( $meta ) ? 6 : $meta['decimals'],
			'symbol'      => is_wp_error( $meta ) ? '' : $meta['symbol'],
			'planId'      => (int) $order->get_meta( '_recurra_plan_id' ),
			'planName'    => (string) ( $plan['name'] ?? '' ),
			'amount'      => (string) ( $plan['amount'] ?? '0' ),
			'periodSec'   => (int) ( $plan['period_sec'] ?? 0 ),
			'merchant'    => (string) ( $plan['merchant'] ?? '' ),
			'externalRef' => (string) $order->get_meta( '_recurra_external_ref' ),
			'periods'     => self::PERIODS,
			'gas'         => self::SUBSCRIBE_GAS,
			'paid'        => $order->is_paid(),
			'returnUrl'   => $order->get_checkout_order_received_url(),
			'explorer'    => self::explorer(),
			'i18n'        => array(
				'connect'      => __( 'Connect NEAR wallet', 'recurra' ),
				'disconnect'   => __( 'Disconnect', 'recurra' ),
				'connectedAs'  => __( 'Connected as', 'recurra' ),
				'balance'      => __( 'Your balance:', 'recurra' ),
				'notEnough'    => __( 'Not enough balance for this prepayment.', 'recurra' ),
				'working'      => __( 'Waiting for your wallet…', 'recurra' ),
				'confirming'   => __( 'Confirming your subscription on-chain…', 'recurra' ),
				'charging'     => __( 'Subscription created, first charge settling…', 'recurra' ),
				'paid'         => __( 'Subscribed. Redirecting…', 'recurra' ),
				'mismatch'     => __( 'The on-chain subscription does not match this order. Please contact the store.', 'recurra' ),
				'slow'         => __( 'Still waiting for the subscription to appear on-chain. If your wallet showed an error, try again.', 'recurra' ),
				'failed'       => __( 'The transaction failed in your wallet. No subscription was created.', 'recurra' ),
				'error'        => __( 'Something went wrong:', 'recurra' ),
				'every'        => __( 'every', 'recurra' ),
				'faucetFailed' => __( 'Faucet refused (claimed in the last hour?).', 'recurra' ),
			),
		);
	}
}
