<?php
/**
 * Pay page for a Recurra order (order-pay endpoint, receipt hook).
 *
 * @package Recurra
 * @var WC_Order             $order  Order.
 * @var array<string, mixed> $config JS config (see Recurra_Plugin::pay_page_config).
 */

defined( 'ABSPATH' ) || exit;

// Config travels as a data attribute: block themes render this template
// before scripts are enqueued, so wp_add_inline_script() would be too early.
?>
<div id="recurra-pay" class="recurra-pay" aria-live="polite" data-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
	<?php if ( ! empty( $config['mock'] ) ) : ?>
		<p class="recurra-mock-banner"><strong><?php esc_html_e( 'MOCK MODE', 'recurra' ); ?></strong> — <?php esc_html_e( 'no real payment is made; the swap is simulated and completes in about 30 seconds.', 'recurra' ); ?></p>
	<?php endif; ?>

	<p class="recurra-total">
		<?php
		printf(
			/* translators: %s: order total. */
			esc_html__( 'Amount due: %s. The store receives exactly this amount in USDC on NEAR.', 'recurra' ),
			wp_kses_post( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) )
		);
		?>
	</p>

	<form id="recurra-quote-form" class="recurra-step" hidden>
		<p>
			<label for="recurra-token"><?php esc_html_e( 'Pay with', 'recurra' ); ?></label>
			<select id="recurra-token" name="origin_asset" required>
				<option value=""><?php esc_html_e( 'Loading tokens…', 'recurra' ); ?></option>
			</select>
		</p>
		<p>
			<label for="recurra-refund"><?php esc_html_e( 'Your refund address on that chain', 'recurra' ); ?></label>
			<input id="recurra-refund" name="refund_to" type="text" autocomplete="off" spellcheck="false" required>
			<small><?php esc_html_e( 'If the swap cannot complete, your funds are returned here automatically.', 'recurra' ); ?></small>
		</p>
		<p><button type="submit" class="button alt"><?php esc_html_e( 'Get payment address', 'recurra' ); ?></button></p>
	</form>

	<div id="recurra-deposit" class="recurra-step" hidden>
		<p class="recurra-send">
			<?php esc_html_e( 'Send exactly', 'recurra' ); ?>
			<strong id="recurra-amount"></strong>
			<?php esc_html_e( 'to', 'recurra' ); ?>
		</p>
		<div class="recurra-address-row">
			<code id="recurra-address"></code>
			<button type="button" id="recurra-copy" class="button"><?php esc_html_e( 'Copy', 'recurra' ); ?></button>
		</div>
		<div id="recurra-qr" class="recurra-qr" aria-hidden="true"></div>
		<p class="recurra-deadline"><?php esc_html_e( 'Time left:', 'recurra' ); ?> <span id="recurra-countdown"></span></p>
		<p class="recurra-status"><span id="recurra-status-text"></span></p>
		<details class="recurra-tx">
			<summary><?php esc_html_e( "I've sent it — add transaction hash (optional, speeds things up)", 'recurra' ); ?></summary>
			<form id="recurra-tx-form">
				<input id="recurra-tx" type="text" autocomplete="off" spellcheck="false">
				<button type="submit" class="button"><?php esc_html_e( 'Submit', 'recurra' ); ?></button>
			</form>
		</details>
		<p class="recurra-explorer">
			<a id="recurra-explorer" href="https://explorer.near-intents.org" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Track on NEAR Intents Explorer', 'recurra' ); ?></a>
			<small><?php esc_html_e( '(search for the deposit address)', 'recurra' ); ?></small>
		</p>
		<p><button type="button" id="recurra-new-quote" class="button" hidden><?php esc_html_e( 'Get a new quote', 'recurra' ); ?></button></p>
	</div>

	<p id="recurra-error" class="recurra-error" role="alert" hidden></p>
	<noscript><?php esc_html_e( 'JavaScript is required to pay with crypto.', 'recurra' ); ?></noscript>
</div>
