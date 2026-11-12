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
	 * Polls every deposit address of recent Recurra orders (current and replaced)
	 * until max(our deadline, 1Click's deadline) + 2 h. Paid / cancelled orders are
	 * included so that a second payment is flagged for refund.
	 */
	public static function run(): void {
		update_option( 'recurra_last_poll', time(), false );
		$orders = wc_get_orders(
			array(
				'payment_method' => Recurra_Plugin::GATEWAY_ID,
				'status'         => array( 'pending', 'on-hold', 'failed', 'processing', 'completed', 'cancelled' ),
				'date_created'   => '>' . ( time() - 5 * DAY_IN_SECONDS ),
				'limit'          => self::BATCH,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		$now    = time();
		foreach ( $orders as $order ) {
			if ( Recurra_Order_Sync::needs_watch( $order, $now ) ) {
				Recurra_Order_Sync::poll_all( $order );
			}
		}
	}
}
