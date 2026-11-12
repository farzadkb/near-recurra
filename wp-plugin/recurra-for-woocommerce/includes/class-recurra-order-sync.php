<?php
/**
 * Maps 1Click swap status onto the WooCommerce order. The single place where
 * order status changes; idempotent and protected by a per-order lock.
 *
 * Never trusts the browser: status is always fetched server-side from 1Click
 * (or the mock) by deposit address, and SUCCESS is verified against what we
 * stored when the quote was created.
 *
 * @package Recurra
 */

defined( 'ABSPATH' ) || exit;

/**
 * Order status synchronisation.
 */
final class Recurra_Order_Sync {

	const FINAL_STATUSES = array( 'SUCCESS', 'REFUNDED', 'FAILED' );

	/**
	 * Fetches status for the order's current quote (cached 5 s per order) and applies it.
	 *
	 * @param WC_Order $order     Order.
	 * @param bool     $use_cache Use the 5 s status cache.
	 * @return array<string, mixed>|WP_Error Raw 1Click status response.
	 */
	public static function refresh( WC_Order $order, bool $use_cache = true ) {
		$address = (string) $order->get_meta( '_recurra_deposit_address' );
		if ( '' === $address ) {
			return new WP_Error( 'recurra_no_quote', __( 'No quote for this order yet.', 'recurra' ) );
		}
		$cache_key = 'recurra_status_' . $order->get_id() . '_' . md5( $address );
		$status    = $use_cache ? get_transient( $cache_key ) : false;
		if ( ! is_array( $status ) ) {
			$status = Recurra_Plugin::client_for_order( $order )->status( $address );
			if ( is_wp_error( $status ) ) {
				return $status;
			}
			set_transient( $cache_key, $status, 5 );
		}
		self::apply( $order->get_id(), $status );
		return $status;
	}

	/**
	 * Applies a status response to an order.
	 *
	 * @param int                  $order_id Order id.
	 * @param array<string, mixed> $resp     GetExecutionStatusResponse.
	 */
	public static function apply( int $order_id, array $resp ): void {
		if ( ! self::lock( (string) $order_id ) ) {
			return;
			// Another request is applying a status right now.
		}
		try {
			// Fresh copy inside the lock.
			$order = wc_get_order( $order_id );
			if ( ! $order || Recurra_Plugin::GATEWAY_ID !== $order->get_payment_method() ) {
				return;
			}
			self::apply_locked( $order, $resp );
		} finally {
			self::unlock( (string) $order_id );
		}
	}

	/**
	 * The mapping (PLAN.md §4 "Status mapping").
	 *
	 * @param WC_Order             $order Order.
	 * @param array<string, mixed> $resp  Status response.
	 */
	private static function apply_locked( WC_Order $order, array $resp ): void {
		$status   = isset( $resp['status'] ) && is_string( $resp['status'] ) ? $resp['status'] : '';
		$address  = (string) $order->get_meta( '_recurra_deposit_address' );
		$returned = (string) ( $resp['quoteResponse']['quote']['depositAddress'] ?? '' );
		if ( '' === $status || $returned !== $address ) {
			return;
			// Response for a different (e.g. archived) quote.
		}
		if ( $order->is_paid() || $order->has_status( array( 'cancelled', 'refunded' ) ) ) {
			return;
		}

		$previous = (string) $order->get_meta( '_recurra_last_status' );
		$changed  = $previous !== $status;
		$details  = isset( $resp['swapDetails'] ) && is_array( $resp['swapDetails'] ) ? $resp['swapDetails'] : array();
		$order->update_meta_data( '_recurra_last_status', $status );

		switch ( $status ) {
			case 'PENDING_DEPOSIT':
				$order->save();
				return;

			case 'KNOWN_DEPOSIT_TX':
			case 'PROCESSING':
				if ( $order->has_status( 'pending' ) ) {
					/* translators: %s: 1Click status. */
					$order->update_status( 'on-hold', sprintf( __( 'Recurra: payment detected (%s).', 'recurra' ), $status ) );
				} else {
					$order->save();
				}
				return;

			case 'INCOMPLETE_DEPOSIT':
				if ( $changed ) {
					$order->update_status(
						'on-hold',
						/* translators: %s: deposited amount. */
						sprintf( __( 'Recurra: incomplete deposit (%s received). The customer can send the remainder before the deadline.', 'recurra' ), sanitize_text_field( (string) ( $details['depositedAmountFormatted'] ?? $details['depositedAmount'] ?? '?' ) ) )
					);
				} else {
					$order->save();
				}
				return;

			case 'REFUNDED':
				$order->update_status(
					'failed',
					sprintf(
						/* translators: 1: refund reason, 2: refunded amount. */
						__( 'Recurra: swap refunded to the customer (%1$s, %2$s refunded).', 'recurra' ),
						sanitize_text_field( (string) ( $details['refundReason'] ?? 'unknown reason' ) ),
						sanitize_text_field( (string) ( $details['refundedAmountFormatted'] ?? $details['refundedAmount'] ?? '?' ) )
					)
				);
				return;

			case 'FAILED':
				$order->update_status( 'failed', __( 'Recurra: swap failed.', 'recurra' ) );
				return;

			case 'SUCCESS':
				$problem = self::verify_success( $order, $resp );
				if ( null !== $problem ) {
					$order->update_meta_data( '_recurra_verification_failed', $problem );
					if ( $changed ) {
						$order->update_status( 'on-hold', __( 'Recurra: 1Click reported SUCCESS but verification failed: ', 'recurra' ) . $problem );
					} else {
						$order->save();
					}
					return;
				}
				$tx = (string) ( $details['nearTxHashes'][0] ?? '' );
				$order->update_meta_data( '_recurra_swap_details', wp_json_encode( $details ) );
				$order->add_order_note(
					sprintf(
						/* translators: 1: amount out, 2: NEAR tx hash. */
						__( 'Recurra: swap complete, merchant received %1$s (NEAR tx %2$s).', 'recurra' ),
						sanitize_text_field( (string) ( $details['amountOutFormatted'] ?? $details['amountOut'] ?? '' ) ),
						sanitize_text_field( $tx )
					)
				);
				$order->payment_complete( sanitize_text_field( $tx ) );
				return;
		}//end switch
		$order->save();
	}

	/**
	 * Verifies a SUCCESS against our own records. Returns null if OK, else a reason.
	 *
	 * @param WC_Order             $order Order.
	 * @param array<string, mixed> $resp  Status response.
	 */
	public static function verify_success( WC_Order $order, array $resp ): ?string {
		$details  = $resp['swapDetails'] ?? array();
		$request  = $resp['quoteResponse']['quoteRequest'] ?? array();
		$expected = (string) $order->get_meta( '_recurra_expected_out' );
		$out      = (string) ( $details['amountOut'] ?? '' );

		if ( ! Recurra_Amount::is_uint( $expected ) || ! Recurra_Amount::is_uint( $out ) ) {
			return 'missing amounts';
		}
		if ( Recurra_Amount::cmp( $out, $expected ) < 0 ) {
			return sprintf( 'amountOut %s < expected %s', $out, $expected );
		}
		if ( ( $request['recipient'] ?? '' ) !== (string) $order->get_meta( '_recurra_recipient' ) ) {
			return 'recipient mismatch';
		}
		if ( ( $request['destinationAsset'] ?? '' ) !== (string) $order->get_meta( '_recurra_destination_asset' ) ) {
			return 'destination asset mismatch';
		}
		return null;
	}

	/**
	 * Acquires a named lock (atomic add_option). Stale locks expire after 60 s.
	 *
	 * @param string $name Lock name (e.g. an order id).
	 */
	public static function lock( string $name ): bool {
		$key = 'recurra_lock_' . $name;
		if ( add_option( $key, time(), '', false ) ) {
			return true;
		}
		$since = (int) get_option( $key );
		if ( $since && time() - $since > 60 ) {
			delete_option( $key );
			return add_option( $key, time(), '', false );
		}
		return false;
	}

	/**
	 * Releases a lock.
	 *
	 * @param string $name Lock name.
	 */
	public static function unlock( string $name ): void {
		delete_option( 'recurra_lock_' . $name );
	}
}
