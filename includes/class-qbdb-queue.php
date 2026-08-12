<?php
/**
 * Export queue — one row per order awaiting (or already through) export to QuickBooks.
 *
 * States: pending → sent (handed to the Web Connector, awaiting QB's response)
 *         → done (QB accepted it; TxnID recorded) or error (QB rejected it).
 * A 'sent' row whose session died is released back to 'pending' on the next run.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QBDB_Queue {

	const DB_VERSION        = '1';
	const DB_VERSION_OPTION = 'qbdb_queue_db_version';

	/** Rows stuck in 'sent' longer than this are considered abandoned. */
	const STALE_SENT_SECONDS = 900;

	/** Fully-qualified table name. */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'qbdb_queue';
	}

	/**
	 * Create/upgrade the table. Safe to call repeatedly (dbDelta is idempotent).
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			state VARCHAR(20) NOT NULL DEFAULT 'pending',
			attempts SMALLINT(6) NOT NULL DEFAULT 0,
			qb_txn_id VARCHAR(32) NOT NULL DEFAULT '',
			last_error TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY order_id (order_id),
			KEY state (state)
		) $charset_collate;";

		dbDelta( $sql );
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/** Run install() when the schema version has changed (upgrades without reactivation). */
	public static function maybe_install() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Queue an order for export. No-op if it already has a live (pending/sent)
	 * or completed (done) row — errored orders may be queued again.
	 *
	 * @return int|false Queue row ID, or false if skipped/failed.
	 */
	public static function add( $order_id ) {
		global $wpdb;
		$order_id = absint( $order_id );
		if ( ! $order_id ) {
			return false;
		}

		$existing = $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . self::table() . " WHERE order_id = %d AND state IN ('pending','sent','done') LIMIT 1",
			$order_id
		) );
		if ( $existing ) {
			return false;
		}

		$now = current_time( 'mysql', true );
		$ok  = $wpdb->insert( self::table(), array(
			'order_id'   => $order_id,
			'state'      => 'pending',
			'created_at' => $now,
			'updated_at' => $now,
		) );

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/** IDs of all rows currently waiting to go out, oldest first. */
	public static function pending_ids() {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col(
			'SELECT id FROM ' . self::table() . " WHERE state = 'pending' ORDER BY id ASC"
		) );
	}

	/** @return object|null Row for one queue entry. */
	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE id = %d', absint( $id )
		) );
	}

	/**
	 * Transition a row. 'sent' bumps the attempt counter.
	 */
	public static function mark( $id, $state, $error = '', $txn_id = '' ) {
		global $wpdb;

		$data = array(
			'state'      => $state,
			'updated_at' => current_time( 'mysql', true ),
		);
		if ( '' !== $error || 'done' === $state || 'pending' === $state ) {
			$data['last_error'] = $error;
		}
		if ( '' !== $txn_id ) {
			$data['qb_txn_id'] = $txn_id;
		}

		$wpdb->update( self::table(), $data, array( 'id' => absint( $id ) ) );

		if ( 'sent' === $state ) {
			$wpdb->query( $wpdb->prepare(
				'UPDATE ' . self::table() . ' SET attempts = attempts + 1 WHERE id = %d', absint( $id )
			) );
		}
	}

	/**
	 * Release rows abandoned mid-session (Web Connector crashed, PC shut down…)
	 * back to 'pending' so the next run picks them up again.
	 */
	public static function release_stale() {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::STALE_SENT_SECONDS );
		$wpdb->query( $wpdb->prepare(
			'UPDATE ' . self::table() . " SET state = 'pending', updated_at = %s WHERE state = 'sent' AND updated_at < %s",
			current_time( 'mysql', true ),
			$cutoff
		) );
	}

	/** Put every errored row back in the queue. @return int Rows requeued. */
	public static function requeue_errors() {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare(
			'UPDATE ' . self::table() . " SET state = 'pending', last_error = '', updated_at = %s WHERE state = 'error'",
			current_time( 'mysql', true )
		) );
	}

	/** Put one row (any state) back in the queue. */
	public static function requeue( $id ) {
		self::mark( absint( $id ), 'pending', '' );
	}

	/** @return array state => count. */
	public static function counts() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT state, COUNT(*) AS n FROM ' . self::table() . ' GROUP BY state' );
		$out  = array( 'pending' => 0, 'sent' => 0, 'done' => 0, 'error' => 0 );
		foreach ( (array) $rows as $row ) {
			$out[ $row->state ] = (int) $row->n;
		}
		return $out;
	}

	/** Most recent rows for the admin viewer. */
	public static function recent( $limit = 50 ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d', absint( $limit )
		) );
	}
}
