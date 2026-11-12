<?php
/**
 * Subscribe page for a Recurra subscription order (order-pay endpoint, receipt hook).
 *
 * @package Recurra
 * @var WC_Order             $order  Order.
 * @var array<string, mixed> $config JS config (see Recurra_Subscriptions::subscribe_page_config).
 */

defined( 'ABSPATH' ) || exit;

$recurra_testnet = 'testnet' === $config['network'];
?>
<div id="recurra-subscribe" class="recurra-pay" aria-live="polite" data-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
	<?php if ( $recurra_testnet ) : ?>
		<p class="recurra-mock-banner"><strong><?php esc_html_e( 'TESTNET', 'recurra' ); ?></strong> — <?php esc_html_e( 'this subscription uses NEAR testnet tokens; no real money moves.', 'recurra' ); ?></p>
	<?php endif; ?>

	<p class="recurra-total">
		<strong><?php echo esc_html( (string) $config['planName'] ); ?></strong>:
		<span id="recurra-plan-terms"></span>
	</p>
	<p>
		<small>
			<?php
			printf(
				/* translators: 1: vault contract, 2: merchant account. */
				esc_html__( 'You prepay into the Recurra Vault contract %1$s. It can only pay %2$s this amount, at most once per period, and you can cancel any time from your wallet to get the remaining balance back.', 'recurra' ),
				'<a href="' . esc_url( $config['explorer'] . '/address/' . rawurlencode( (string) $config['vault'] ) ) . '" target="_blank" rel="noopener noreferrer"><code>' . esc_html( (string) $config['vault'] ) . '</code></a>',
				'<code>' . esc_html( (string) $config['merchant'] ) . '</code>'
			);
			?>
		</small>
	</p>

	<div id="recurra-connect" class="recurra-step">
		<p><?php esc_html_e( 'Step 1 — connect the NEAR account that pays and owns the subscription:', 'recurra' ); ?></p>
		<p id="recurra-wallets" class="recurra-wallets"></p>
	</div>

	<form id="recurra-subscribe-form" class="recurra-step" hidden>
		<p class="recurra-account"><span id="recurra-account"></span> <button type="button" id="recurra-disconnect" class="button"></button></p>
		<p>
			<label for="recurra-periods"><?php esc_html_e( 'Step 2 — prepay', 'recurra' ); ?></label>
			<select id="recurra-periods" name="periods"></select>
		</p>
		<p><?php esc_html_e( 'Total now:', 'recurra' ); ?> <strong id="recurra-total"></strong> <small id="recurra-balance"></small></p>
		<p><small><?php esc_html_e( 'The first period is charged immediately; the rest stays in the vault under your control.', 'recurra' ); ?></small></p>
		<p><button type="submit" class="button alt" id="recurra-subscribe-btn"><?php esc_html_e( 'Subscribe (one signature)', 'recurra' ); ?></button></p>
	</form>

	<p class="recurra-status"><span id="recurra-status-text"></span></p>
	<p id="recurra-error" class="recurra-error" role="alert" hidden></p>
	<noscript><?php esc_html_e( 'JavaScript is required to subscribe with a NEAR wallet.', 'recurra' ); ?></noscript>
</div>
