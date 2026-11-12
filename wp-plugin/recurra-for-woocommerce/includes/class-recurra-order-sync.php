<?php
/**
 * Maps 1Click swap status onto the WooCommerce order. The single place where
 * order status changes; idempotent and protected by a per-order lock.
 *
 * Never trusts the browser: status is always fetched server-side from 1Click
 * (or the mock) by deposit address, and SUCCESS is verified against what we
 * stored when that quote was created.
 *
 * Deadlines (all UTC): `_recurra_deadline` is OUR requested deadline (what the
 * customer is shown, capped by 1Click's if earlier); `_recurra_quote_deadline`
 * is 1Click's `quote.deadline`, which is typically much later (observed +72 h).
 * Every deposit address — current or replaced — is watched until
 * max(ours, 1Click's) + 2 h, because 1Click still accepts deposits until its own
 * deadline. Replaced quotes live in `_recurra_watch`.
 *
 * @package Recurra
 */

defined( 'ABSPATH' ) || exit;

/**
 * Order status synchronisation.
 */
final class Recurra_Order_Sync {

	const FINAL_STATUSES = array( 'SUCCESS', 'REFUNDED', 'FAILED' );
	const GRACE          = 7200;

	// ------------------------------------------------------------------ deadline math (pure)

	/**
	 * Parses an ISO-8601 UTC timestamp; 0 if empty/invalid.
	 *
	 * @param string $iso Timestamp.
	 */
	public static function ts( string $iso ): int {
		if ( '' === $iso ) {
			return 0;
		}
		$t = strtotime( $iso );
		return false === $t ? 0 : $t;
	}

	/**
	 * Deadline shown to the customer: ours, capped by 1Click's if that is earlier.
	 *
	 * @param int $ours  Our requested deadline (UTC ts, 0 = unknown).
	 * @param int $quote 1Click quote.deadline (UTC ts, 0 = unknown).
	 */
	public static function display_deadline( int $ours, int $quote ): int {
		if ( $ours && $quote ) {
			return min( $ours, $quote );
		}
		return max( $ours, $quote );
	}

	/**
	 * Until when a deposit address is polled: max(ours, 1Click's) + 2 h.
	 *
	 * @param int $ours  Our deadline.
	 * @param int $quote 1Click deadline.
	 */
	public static function watch_until( int $ours, int $quote ): int {
		$latest = max( $ours, $quote );
		return $latest ? $latest + self::GRACE : 0;
	}

	/**
	 * Deadline shown for the order's current quote (UTC ts).
	 *
	 * @param WC_Order $order Order.
	 */
	public static function order_display_deadline( WC_Order $order ): int {
		return self::display_deadline( self::ts( (string) $order->get_meta( '_recurra_deadline' ) ), self::ts( (string) $order->get_meta( '_recurra_quote_deadline' ) ) );
	}

	/**
	 * Watch deadline for the order's current quote (UTC ts).
	 *
	 * @param WC_Order $order Order.
	 */
	public static function order_watch_until( WC_Order $order ): int {
		return self::watch_until( self::ts( (string) $order->get_meta( '_recurra_deadline' ) ), self::ts( (string) $order->get_meta( '_recurra_quote_deadline' ) ) );
	}

	// ------------------------------------------------------------------ watch list (replaced quotes)

	/**
	 * Replaced quotes still being watched.
	 *
	 * @param WC_Order $order Order.
	 * @return array<int, array<string, mixed>>
	 */
	public static function watch_list( WC_Order $order ): array {
		$list = json_decode( (string) $order->get_meta( '_recurra_watch' ), true );
		return is_array( $list ) ? array_values( array_filter( $list, 'is_array' ) ) : array();
	}

	/**
	 * Saves the watch list (caller saves the order).
	 *
	 * @param WC_Order                         $order Order.
	 * @param array<int, array<string, mixed>> $entries Entries.
	 */
	private static function set_watch_list( WC_Order $order, array $entries ): void {
		$order->update_meta_data( '_recurra_watch', wp_json_encode( array_values( $entries ) ) );
	}

	/**
	 * Moves the current quote's address to the watch list before it is replaced
	 * (caller saves the order). Its own expectations are kept for verification.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function archive_current( WC_Order $order ): void {
		$address = (string) $order->get_meta( '_recurra_deposit_address' );
		if ( '' === $address ) {
			return;
		}
		$last   = (string) $order->get_meta( '_recurra_last_status' );
		$list   = self::watch_list( $order );
		$list[] = array(
			'address'           => $address,
			'expected_out'      => (string) $order->get_meta( '_recurra_expected_out' ),
			'recipient'         => (string) $order->get_meta( '_recurra_recipient' ),
			'destination_asset' => (string) $order->get_meta( '_recurra_destination_asset' ),
			'watch_until'       => self::order_watch_until( $order ),
			'last_status'       => $last,
			'final'             => in_array( $last, self::FINAL_STATUSES, true ),
		);
		self::set_watch_list( $order, $list );
	}

	/**
	 * Whether anything on this order still needs polling at $now.
	 *
	 * @param WC_Order $order Order.
	 * @param int      $now   Now (UTC ts).
	 */
	public static function needs_watch( WC_Order $order, int $now ): bool {
		if ( self::current_needs_watch( $order, $now ) ) {
			return true;
		}
		foreach ( self::watch_list( $order ) as $e ) {
			if ( empty( $e['final'] ) && $now <= (int) ( $e['watch_until'] ?? 0 ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the current address still needs polling.
	 *
	 * @param WC_Order $order Order.
	 * @param int      $now   Now.
	 */
	private static function current_needs_watch( WC_Order $order, int $now ): bool {
		if ( '' === (string) $order->get_meta( '_recurra_deposit_address' ) ) {
			return false;
		}
		if ( in_array( (string) $order->get_meta( '_recurra_last_status' ), self::FINAL_STATUSES, true ) ) {
			return false;
		}
		$until = self::order_watch_until( $order );
		return ! $until || $now <= $until;
	}

	/**
	 * Polls every address of an order that still needs it (backup poller).
	 *
	 * @param WC_Order $order Order.
	 */
	public static function poll_all( WC_Order $order ): void {
		$now = time();
		if ( self::current_needs_watch( $order, $now ) ) {
			self::refresh( $order, false );
		}
		$client = Recurra_Plugin::client_for_order( $order );
		foreach ( self::watch_list( $order ) as $e ) {
			if ( ! empty( $e['final'] ) || $now > (int) ( $e['watch_until'] ?? 0 ) ) {
				continue;
			}
			$resp = $client->status( (string) $e['address'] );
			if ( ! is_wp_error( $resp ) ) {
				self::apply_archived( $order->get_id(), (string) $e['address'], $resp );
			}
		}
	}

	// ------------------------------------------------------------------ current quote

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
	 * Applies a status response for the current quote.
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

		$previous = (string) $order->get_meta( '_recurra_last_status' );
		$changed  = $previous !== $status;
		$details  = isset( $resp['swapDetails'] ) && is_array( $resp['swapDetails'] ) ? $resp['swapDetails'] : array();
		$order->update_meta_data( '_recurra_last_status', $status );

		// Already paid (e.g. on a replaced address) or closed: only flag a second payment.
		if ( $order->is_paid() || $order->has_status( array( 'cancelled', 'refunded' ) ) ) {
			if ( 'SUCCESS' === $status && null === self::verify_success( $order, $resp ) ) {
				self::flag_extra_payment( $order, $address, $details );
			}
			$order->save();
			return;
		}

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
				self::complete( $order, $address, $details, '' );
				return;
		}//end switch
		$order->save();
	}

	// ------------------------------------------------------------------ replaced quotes

	/**
	 * Applies a status response for a replaced (watched) address.
	 *
	 * @param int                  $order_id Order id.
	 * @param string               $address  Watched deposit address.
	 * @param array<string, mixed> $resp     Status response.
	 */
	public static function apply_archived( int $order_id, string $address, array $resp ): void {
		if ( ! self::lock( (string) $order_id ) ) {
			return;
		}
		try {
			$order = wc_get_order( $order_id );
			if ( ! $order || Recurra_Plugin::GATEWAY_ID !== $order->get_payment_method() ) {
				return;
			}
			$status = isset( $resp['status'] ) && is_string( $resp['status'] ) ? $resp['status'] : '';
			if ( '' === $status || (string) ( $resp['quoteResponse']['quote']['depositAddress'] ?? '' ) !== $address ) {
				return;
			}
			$list = self::watch_list( $order );
			$idx  = null;
			foreach ( $list as $i => $e ) {
				if ( ( $e['address'] ?? '' ) === $address ) {
					$idx = $i;
				}
			}
			if ( null === $idx ) {
				return;
			}
			$entry   = $list[ $idx ];
			$details = isset( $resp['swapDetails'] ) && is_array( $resp['swapDetails'] ) ? $resp['swapDetails'] : array();
			$changed = ( $entry['last_status'] ?? '' ) !== $status;

			$list[ $idx ]['last_status'] = $status;
			$list[ $idx ]['final']       = in_array( $status, self::FINAL_STATUSES, true );
			self::set_watch_list( $order, $list );

			if ( 'SUCCESS' === $status ) {
				$problem = self::verify_success( $order, $resp, $entry );
				if ( null !== $problem ) {
					if ( $changed ) {
						/* translators: 1: deposit address, 2: reason. */
						$order->add_order_note( sprintf( __( 'Recurra: previous address %1$s reported SUCCESS but verification failed: %2$s', 'recurra' ), esc_html( $address ), esc_html( $problem ) ) );
					}
				} elseif ( $order->is_paid() || $order->has_status( array( 'cancelled', 'refunded' ) ) ) {
					self::flag_extra_payment( $order, $address, $details );
				} else {
					self::complete( $order, $address, $details, __( 'on a previous payment address', 'recurra' ) );
					return;
				}
			} elseif ( $changed && in_array( $status, array( 'KNOWN_DEPOSIT_TX', 'PROCESSING' ), true ) && $order->has_status( 'pending' ) ) {
				/* translators: 1: deposit address, 2: status. */
				$order->update_status( 'on-hold', sprintf( __( 'Recurra: payment detected on previous address %1$s (%2$s).', 'recurra' ), esc_html( $address ), $status ) );
				return;
			} elseif ( $changed && 'REFUNDED' === $status ) {
				/* translators: %s: deposit address. */
				$order->add_order_note( sprintf( __( 'Recurra: a deposit to previous address %s was refunded to the customer by 1Click.', 'recurra' ), esc_html( $address ) ) );
			}//end if
			$order->save();
		} finally {
			self::unlock( (string) $order_id );
		}//end try
	}

	// ------------------------------------------------------------------ settlement

	/**
	 * Marks the order paid exactly once.
	 *
	 * @param WC_Order             $order   Order.
	 * @param string               $address Deposit address that was paid.
	 * @param array<string, mixed> $details swapDetails.
	 * @param string               $where   Extra note text ('' for the current address).
	 */
	private static function complete( WC_Order $order, string $address, array $details, string $where ): void {
		$tx = (string) ( $details['nearTxHashes'][0] ?? '' );
		$order->update_meta_data( '_recurra_swap_details', wp_json_encode( $details ) );
		$order->update_meta_data( '_recurra_paid_address', $address );
		$order->add_order_note(
			trim(
				sprintf(
					/* translators: 1: amount out, 2: NEAR tx hash, 3: where (may be empty). */
					__( 'Recurra: swap complete, merchant received %1$s (NEAR tx %2$s) %3$s', 'recurra' ),
					sanitize_text_field( (string) ( $details['amountOutFormatted'] ?? $details['amountOut'] ?? '' ) ),
					sanitize_text_field( $tx ),
					'' === $where ? '' : '— ' . $where . ' ' . $address
				)
			)
		);
		$order->payment_complete( sanitize_text_field( $tx ) );
	}

	/**
	 * Flags (once per address) a verified payment that arrived after the order was
	 * already paid or closed. The merchant must refund it manually.
	 *
	 * @param WC_Order             $order   Order.
	 * @param string               $address Deposit address.
	 * @param array<string, mixed> $details swapDetails.
	 */
	private static function flag_extra_payment( WC_Order $order, string $address, array $details ): void {
		$paid_address = (string) $order->get_meta( '_recurra_paid_address' );
		if ( $paid_address === $address ) {
			return;
		}
		// Orders paid before `_recurra_paid_address` existed: same NEAR tx = same payment.
		$tx = (string) ( $details['nearTxHashes'][0] ?? '' );
		if ( '' === $paid_address && '' !== $tx && $tx === (string) $order->get_transaction_id() ) {
			return;
		}
		$flagged = json_decode( (string) $order->get_meta( '_recurra_extra_payments' ), true );
		$flagged = is_array( $flagged ) ? $flagged : array();
		if ( isset( $flagged[ $address ] ) ) {
			return;
		}
		$flagged[ $address ] = array(
			'amount_out' => (string) ( $details['amountOut'] ?? '' ),
			'near_tx'    => (string) ( $details['nearTxHashes'][0] ?? '' ),
			'at'         => gmdate( 'c' ),
		);
		$order->update_meta_data( '_recurra_extra_payments', wp_json_encode( $flagged ) );
		$order->add_order_note(
			sprintf(
				/* translators: 1: amount, 2: deposit address, 3: order status, 4: NEAR tx. */
				__( 'Recurra: EXTRA PAYMENT — %1$s arrived via deposit address %2$s while the order was already %3$s (NEAR tx %4$s). The merchant received it; refund the customer manually.', 'recurra' ),
				sanitize_text_field( (string) ( $details['amountOutFormatted'] ?? $details['amountOut'] ?? '?' ) ),
				esc_html( $address ),
				esc_html( $order->is_paid() ? __( 'paid', 'recurra' ) : $order->get_status() ),
				sanitize_text_field( (string) ( $details['nearTxHashes'][0] ?? '?' ) )
			)
		);
	}

	/**
	 * Verifies a SUCCESS against our own records. Returns null if OK, else a reason.
	 *
	 * @param WC_Order                  $order  Order.
	 * @param array<string, mixed>      $resp   Status response.
	 * @param array<string, mixed>|null $expect Expectations of a replaced quote (null = current quote).
	 */
	public static function verify_success( WC_Order $order, array $resp, ?array $expect = null ): ?string {
		$expect   = $expect ?? array(
			'expected_out'      => (string) $order->get_meta( '_recurra_expected_out' ),
			'recipient'         => (string) $order->get_meta( '_recurra_recipient' ),
			'destination_asset' => (string) $order->get_meta( '_recurra_destination_asset' ),
		);
		$details  = $resp['swapDetails'] ?? array();
		$request  = $resp['quoteResponse']['quoteRequest'] ?? array();
		$expected = (string) ( $expect['expected_out'] ?? '' );
		$out      = (string) ( $details['amountOut'] ?? '' );

		if ( ! Recurra_Amount::is_uint( $expected ) || ! Recurra_Amount::is_uint( $out ) ) {
			return 'missing amounts';
		}
		if ( Recurra_Amount::cmp( $out, $expected ) < 0 ) {
			return sprintf( 'amountOut %s < expected %s', $out, $expected );
		}
		if ( ( $request['recipient'] ?? '' ) !== (string) ( $expect['recipient'] ?? '' ) ) {
			return 'recipient mismatch';
		}
		if ( ( $request['destinationAsset'] ?? '' ) !== (string) ( $expect['destination_asset'] ?? '' ) ) {
			return 'destination asset mismatch';
		}
		return null;
	}

	// ------------------------------------------------------------------ locks

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
