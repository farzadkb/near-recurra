<?php
/**
 * Backup status poller (Action Scheduler, every 60 s). The pay page polls
 * while the customer waits; this catches customers who closed the tab.
 *
 * @package Recurra
 */

defined( 'ABSPATH' ) || exit;

/**
 * Poller.
 */
final class Recurra_Poller {

	const HOOK  = 'recurra_poll';
	const GROUP = 'recurra';
	const BATCH = 50;

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Ensures the recurring action exists.
	 */
	public static function schedule(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || as_has_scheduled_action( self::HOOK, array(), self::GROUP ) ) {
			return;
		}
		as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, MINUTE_IN_SECONDS, self::HOOK, array(), self::GROUP, true );
	}

	/**
	 * Polls open Recurra orders that have a quote, until deadline + 2 h.
	 */
	public static function run(): void {
		update_option( 'recurra_last_poll', time(), false );
		$orders = wc_get_orders(
			array(
				'payment_method' => Recurra_Plugin::GATEWAY_ID,
				'status'         => array( 'pending', 'on-hold' ),
				'date_created'   => '>' . ( time() - 3 * DAY_IN_SECONDS ),
				'limit'          => self::BATCH,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		foreach ( $orders as $order ) {
			if ( '' === (string) $order->get_meta( '_recurra_deposit_address' ) ) {
				continue;
			}
			$deadline = strtotime( (string) $order->get_meta( '_recurra_deadline' ) );
			$last     = (string) $order->get_meta( '_recurra_last_status' );
			// Past deadline + 2 h with no deposit ever seen: nothing can arrive any more.
			if ( $deadline && time() > $deadline + 2 * HOUR_IN_SECONDS && 'PENDING_DEPOSIT' === $last ) {
				continue;
			}
			Recurra_Order_Sync::refresh( $order, false );
		}
	}
}
