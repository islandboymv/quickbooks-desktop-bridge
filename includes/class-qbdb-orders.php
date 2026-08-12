<?php
/**
 * Watches order status changes and queues each order for export the first
 * time it reaches a "paid" status. One export per order — later status
 * changes (processing → ready-for-pickup → completed) don't re-queue it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QBDB_Orders {

	const QUEUED_META = '_qbdb_queued';

	public static function init() {
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'maybe_queue' ), 10, 4 );
	}

	/**
	 * @param int      $order_id Order ID.
	 * @param string   $from     Old status slug (no wc- prefix).
	 * @param string   $to       New status slug (no wc- prefix).
	 * @param WC_Order $order    Order object.
	 */
	public static function maybe_queue( $order_id, $from, $to, $order ) {
		$settings = QBDB_Settings::get();

		if ( ! in_array( $to, (array) $settings['statuses'], true ) ) {
			return;
		}
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return;
			}
		}
		if ( $order->get_meta( self::QUEUED_META ) ) {
			return;
		}

		if ( QBDB_Queue::add( $order_id ) ) {
			$order->update_meta_data( self::QUEUED_META, 1 );
			$order->save();
		}
	}

	/**
	 * Queue an order manually from the admin page (also used for backfilling
	 * orders that predate the plugin). Bypasses the once-only meta guard.
	 *
	 * @return true|WP_Error
	 */
	public static function queue_manually( $order_id ) {
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order ) {
			return new WP_Error( 'qbdb_no_order', __( 'No order with that ID exists.', 'qbd-bridge' ) );
		}

		if ( ! QBDB_Queue::add( $order->get_id() ) ) {
			return new WP_Error( 'qbdb_already_queued', __( 'That order is already queued or exported. Requeue its row from the table below instead.', 'qbd-bridge' ) );
		}

		$order->update_meta_data( self::QUEUED_META, 1 );
		$order->save();
		return true;
	}
}
