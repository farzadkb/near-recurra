<?php
/**
 * My Account → Subscriptions.
 *
 * @package Recurra
 * @var array<int, array<string, mixed>> $rows   Rows (see Recurra_Subscriptions::row).
 * @var array<string, mixed>             $config JS config (see Recurra_Subscriptions::account_config).
 */

defined( 'ABSPATH' ) || exit;
?>
<div id="recurra-account" class="recurra-pay" data-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
	<?php if ( empty( $rows ) ) : ?>
		<p><?php esc_html_e( 'You have no subscriptions yet.', 'recurra' ); ?></p>
	<?php else : ?>
		<p><?php esc_html_e( 'Your subscription funds are held by the Recurra Vault contract, not by the store. Cancelling refunds the remaining balance to your wallet.', 'recurra' ); ?></p>
		<p class="recurra-account-bar"><span id="recurra-account-label"></span> <span id="recurra-wallets" class="recurra-wallets"></span></p>
		<table class="shop_table shop_table_responsive recurra-subs">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Plan', 'recurra' ); ?></th>
					<th><?php esc_html_e( 'Status', 'recurra' ); ?></th>
					<th><?php esc_html_e( 'Balance', 'recurra' ); ?></th>
					<th><?php esc_html_e( 'Next charge', 'recurra' ); ?></th>
					<th><?php esc_html_e( 'Charges', 'recurra' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'recurra' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr class="recurra-sub" data-sub="<?php echo esc_attr( wp_json_encode( $row ) ); ?>">
					<td data-title="<?php esc_attr_e( 'Plan', 'recurra' ); ?>">
						<?php echo esc_html( $row['name'] ); ?><br>
						<small><?php echo esc_html( $row['amountText'] ); ?> / <?php echo esc_html( human_time_diff( 0, (int) $row['periodSec'] ) ); ?></small><br>
						<small>
							<?php
							printf(
								/* translators: 1: subscription id, 2: order link. */
								esc_html__( 'Subscription #%1$s, order %2$s', 'recurra' ),
								esc_html( (string) $row['subId'] ),
								'<a href="' . esc_url( wc_get_endpoint_url( 'view-order', (string) $row['orderId'], wc_get_page_permalink( 'myaccount' ) ) ) . '">#' . esc_html( (string) $row['orderId'] ) . '</a>'
							);
							?>
						</small>
					</td>
					<td data-title="<?php esc_attr_e( 'Status', 'recurra' ); ?>" class="recurra-sub-status"><?php echo esc_html( $row['status'] ); ?></td>
					<td data-title="<?php esc_attr_e( 'Balance', 'recurra' ); ?>"><?php echo esc_html( $row['balanceText'] ); ?></td>
					<td data-title="<?php esc_attr_e( 'Next charge', 'recurra' ); ?>"><?php echo esc_html( '' !== $row['nextCharge'] ? $row['nextCharge'] : '—' ); ?></td>
					<td data-title="<?php esc_attr_e( 'Charges', 'recurra' ); ?>"><?php echo esc_html( (string) $row['charges'] ); ?></td>
					<td data-title="<?php esc_attr_e( 'Actions', 'recurra' ); ?>">
						<?php if ( 'Cancelled' !== $row['status'] ) : ?>
							<select class="recurra-topup-periods" aria-label="<?php esc_attr_e( 'Periods to add', 'recurra' ); ?>"></select>
							<button type="button" class="button recurra-topup"><?php esc_html_e( 'Top up', 'recurra' ); ?></button>
							<button type="button" class="button recurra-cancel"><?php esc_html_e( 'Cancel', 'recurra' ); ?></button>
						<?php endif; ?>
						<br><small><?php esc_html_e( 'Owner:', 'recurra' ); ?> <code><?php echo esc_html( $row['owner'] ); ?></code></small>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="recurra-status"><span id="recurra-status-text"></span></p>
		<p id="recurra-error" class="recurra-error" role="alert" hidden></p>
	<?php endif; ?>
</div>
