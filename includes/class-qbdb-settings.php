<?php
/**
 * Settings + admin screen (WooCommerce → QuickBooks Bridge) and the .qwc
 * file download that gets loaded into the QuickBooks Web Connector.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QBDB_Settings {

	const OPTION = 'qbdb_settings';
	const CAP    = 'manage_woocommerce';
	const PAGE   = 'qbdb-bridge';

	public static function defaults() {
		return array(
			'username'         => 'quickbooks',
			'password'         => '',
			// OwnerID/FileID identify this app to the Web Connector; generated
			// once on activation and then kept stable — changing them makes
			// QBWC treat the app as brand new.
			'owner_id'         => '',
			'file_id'          => '',
			'run_every'        => 30,
			'statuses'         => array( 'processing', 'ready-for-pickup', 'completed' ),
			'income_account'   => 'Sales',
			'item_mode'        => 'sku',
			'generic_item'     => 'Online Sale',
			'misc_item'        => 'Misc',
			'tax_item'         => 'GST',
			'customer_mode'    => 'billing',
			'generic_customer' => 'Web Customer',
		);
	}

	public static function get() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/** Fill in the generated-once values on first activation. */
	public static function seed_defaults() {
		$settings = self::get();
		$dirty    = false;

		if ( '' === $settings['password'] ) {
			$settings['password'] = wp_generate_password( 20, false, false );
			$dirty                = true;
		}
		foreach ( array( 'owner_id', 'file_id' ) as $key ) {
			if ( '' === $settings[ $key ] ) {
				$settings[ $key ] = '{' . strtoupper( wp_generate_uuid4() ) . '}';
				$dirty            = true;
			}
		}

		if ( $dirty ) {
			update_option( self::OPTION, $settings );
		}
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_qbdb_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_qbdb_qwc', array( __CLASS__, 'handle_qwc_download' ) );
		add_action( 'admin_post_qbdb_queue_action', array( __CLASS__, 'handle_queue_action' ) );
	}

	public static function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'QuickBooks Bridge', 'qbd-bridge' ),
			__( 'QuickBooks Bridge', 'qbd-bridge' ),
			self::CAP,
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/* ----------------------------- actions ----------------------------- */

	public static function handle_save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Nope.' );
		}
		check_admin_referer( 'qbdb_save' );

		$settings = self::get();
		$post     = wp_unslash( $_POST );

		foreach ( array( 'username', 'password', 'income_account', 'generic_item', 'misc_item', 'tax_item', 'generic_customer' ) as $key ) {
			if ( isset( $post[ $key ] ) ) {
				$settings[ $key ] = sanitize_text_field( $post[ $key ] );
			}
		}
		$settings['item_mode']     = ( 'single' === ( $post['item_mode'] ?? '' ) ) ? 'single' : 'sku';
		$settings['customer_mode'] = ( 'generic' === ( $post['customer_mode'] ?? '' ) ) ? 'generic' : 'billing';
		$settings['run_every']     = max( 1, min( 1440, absint( $post['run_every'] ?? 30 ) ) );

		$valid_statuses       = array_map( function ( $s ) {
			return preg_replace( '/^wc-/', '', $s );
		}, array_keys( wc_get_order_statuses() ) );
		$settings['statuses'] = array_values( array_intersect(
			array_map( 'sanitize_key', (array) ( $post['statuses'] ?? array() ) ),
			$valid_statuses
		) );

		update_option( self::OPTION, $settings );

		wp_safe_redirect( add_query_arg( 'qbdb-msg', 'saved', admin_url( 'admin.php?page=' . self::PAGE ) ) );
		exit;
	}

	public static function handle_qwc_download() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Nope.' );
		}
		check_admin_referer( 'qbdb_qwc' );

		self::seed_defaults(); // belt & braces if activation was skipped (e.g. file-sync deploy)
		$settings = self::get();

		$app_name = trim( substr( get_bloginfo( 'name' ), 0, 20 ) ) . ' QB Bridge';

		$xml = '<?xml version="1.0"?>' . "\n"
			. "<QBWCXML>\n"
			. '  <AppName>' . esc_html( $app_name ) . "</AppName>\n"
			. "  <AppID></AppID>\n"
			. '  <AppURL>' . esc_html( QBDB_SOAP::endpoint_url() ) . "</AppURL>\n"
			. '  <AppDescription>' . esc_html( 'Sends WooCommerce orders into QuickBooks Desktop.' ) . "</AppDescription>\n"
			. '  <AppSupport>' . esc_html( home_url( '/', 'https' ) ) . "</AppSupport>\n"
			. '  <UserName>' . esc_html( $settings['username'] ) . "</UserName>\n"
			. '  <OwnerID>' . esc_html( $settings['owner_id'] ) . "</OwnerID>\n"
			. '  <FileID>' . esc_html( $settings['file_id'] ) . "</FileID>\n"
			. "  <QBType>QBFS</QBType>\n"
			. "  <Style>Document</Style>\n"
			. "  <Scheduler>\n"
			. '    <RunEveryNMinutes>' . absint( $settings['run_every'] ) . "</RunEveryNMinutes>\n"
			. "  </Scheduler>\n"
			. "</QBWCXML>\n";

		header( 'Content-Type: application/xml; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="quickbooks-bridge.qwc"' );
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput -- XML file download.
		exit;
	}

	public static function handle_queue_action() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Nope.' );
		}
		check_admin_referer( 'qbdb_queue_action' );

		$post = wp_unslash( $_POST );
		$msg  = '';

		if ( ! empty( $post['requeue_errors'] ) ) {
			$n   = QBDB_Queue::requeue_errors();
			$msg = 'requeued-' . $n;
		} elseif ( ! empty( $post['requeue_id'] ) ) {
			QBDB_Queue::requeue( absint( $post['requeue_id'] ) );
			$msg = 'requeued-1';
		} elseif ( ! empty( $post['queue_order_id'] ) ) {
			$result = QBDB_Orders::queue_manually( absint( $post['queue_order_id'] ) );
			$msg    = is_wp_error( $result ) ? 'queue-failed' : 'queued';
		}

		wp_safe_redirect( add_query_arg( 'qbdb-msg', $msg, admin_url( 'admin.php?page=' . self::PAGE ) ) );
		exit;
	}

	/* ------------------------------ screen ----------------------------- */

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$settings = self::get();
		$counts   = QBDB_Queue::counts();
		$rows     = QBDB_Queue::recent( 50 );
		$last_run = get_option( 'qbdb_last_run' );
		$statuses = wc_get_order_statuses();
		$msg      = sanitize_text_field( wp_unslash( $_GET['qbdb-msg'] ?? '' ) );

		echo '<div class="wrap"><h1>' . esc_html__( 'QuickBooks Desktop Bridge', 'qbd-bridge' ) . '</h1>';

		if ( 'saved' === $msg ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'qbd-bridge' ) . '</p></div>';
		} elseif ( 'queued' === $msg ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Order queued for export.', 'qbd-bridge' ) . '</p></div>';
		} elseif ( 'queue-failed' === $msg ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Could not queue that order — check the ID, and whether it is already queued or exported.', 'qbd-bridge' ) . '</p></div>';
		} elseif ( 0 === strpos( $msg, 'requeued-' ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( __( 'Requeued %s row(s).', 'qbd-bridge' ), substr( $msg, 9 ) ) ) . '</p></div>';
		}

		/* --- Connection --- */
		echo '<h2>' . esc_html__( 'Web Connector connection', 'qbd-bridge' ) . '</h2>';
		echo '<p>' . esc_html__( 'On the PC where QuickBooks Desktop is installed: download the .qwc file below, open it with the QuickBooks Web Connector (File → Add an Application), and enter the password when prompted. The Web Connector will then pull queued orders on the schedule.', 'qbd-bridge' ) . '</p>';

		if ( is_array( $last_run ) && ! empty( $last_run['time'] ) ) {
			echo '<p><em>' . esc_html( sprintf(
				/* translators: 1: human time diff, 2: result summary */
				__( 'Last Web Connector contact: %1$s ago (%2$s).', 'qbd-bridge' ),
				human_time_diff( (int) $last_run['time'] ),
				(string) $last_run['result']
			) ) . '</em></p>';
		} else {
			echo '<p><em>' . esc_html__( 'The Web Connector has not contacted this site yet.', 'qbd-bridge' ) . '</em></p>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-bottom:1em">';
		wp_nonce_field( 'qbdb_qwc' );
		echo '<input type="hidden" name="action" value="qbdb_qwc">';
		submit_button( __( 'Download .qwc file', 'qbd-bridge' ), 'secondary', 'submit', false );
		echo '</form>';

		/* --- Settings form --- */
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'qbdb_save' );
		echo '<input type="hidden" name="action" value="qbdb_save">';
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row">' . esc_html__( 'Endpoint URL', 'qbd-bridge' ) . '</th><td><code>' . esc_html( QBDB_SOAP::endpoint_url() ) . '</code><p class="description">' . esc_html__( 'Baked into the .qwc file — nothing to configure, shown for reference.', 'qbd-bridge' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="qbdb-username">' . esc_html__( 'Username', 'qbd-bridge' ) . '</label></th><td><input name="username" id="qbdb-username" type="text" class="regular-text" value="' . esc_attr( $settings['username'] ) . '"></td></tr>';

		echo '<tr><th scope="row"><label for="qbdb-password">' . esc_html__( 'Password', 'qbd-bridge' ) . '</label></th><td><input name="password" id="qbdb-password" type="text" class="regular-text code" value="' . esc_attr( $settings['password'] ) . '"><p class="description">' . esc_html__( 'Enter this into the Web Connector when it asks. Generated automatically on activation.', 'qbd-bridge' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="qbdb-run-every">' . esc_html__( 'Run every (minutes)', 'qbd-bridge' ) . '</label></th><td><input name="run_every" id="qbdb-run-every" type="number" min="1" max="1440" value="' . esc_attr( $settings['run_every'] ) . '"><p class="description">' . esc_html__( 'How often the Web Connector polls. Takes effect next time the .qwc is re-added.', 'qbd-bridge' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Export orders on status', 'qbd-bridge' ) . '</th><td>';
		foreach ( $statuses as $slug => $label ) {
			$slug = preg_replace( '/^wc-/', '', $slug );
			echo '<label style="display:block"><input type="checkbox" name="statuses[]" value="' . esc_attr( $slug ) . '" ' . checked( in_array( $slug, (array) $settings['statuses'], true ), true, false ) . '> ' . esc_html( $label ) . '</label>';
		}
		echo '<p class="description">' . esc_html__( 'An order is queued once, the first time it reaches any ticked status.', 'qbd-bridge' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="qbdb-income-account">' . esc_html__( 'QuickBooks income account', 'qbd-bridge' ) . '</label></th><td><input name="income_account" id="qbdb-income-account" type="text" class="regular-text" value="' . esc_attr( $settings['income_account'] ) . '"><p class="description">' . esc_html__( 'Auto-created items post to this account. Must already exist in QuickBooks (exact name).', 'qbd-bridge' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Items', 'qbd-bridge' ) . '</th><td>';
		echo '<label style="display:block"><input type="radio" name="item_mode" value="sku" ' . checked( 'sku', $settings['item_mode'], false ) . '> ' . esc_html__( 'One QuickBooks item per product SKU (auto-created as non-inventory items)', 'qbd-bridge' ) . '</label>';
		echo '<label style="display:block"><input type="radio" name="item_mode" value="single" ' . checked( 'single', $settings['item_mode'], false ) . '> ' . esc_html__( 'Lump every product line under a single generic item:', 'qbd-bridge' ) . ' <input name="generic_item" type="text" value="' . esc_attr( $settings['generic_item'] ) . '"></label>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Customers', 'qbd-bridge' ) . '</th><td>';
		echo '<label style="display:block"><input type="radio" name="customer_mode" value="billing" ' . checked( 'billing', $settings['customer_mode'], false ) . '> ' . esc_html__( 'One QuickBooks customer per billing name (auto-created)', 'qbd-bridge' ) . '</label>';
		echo '<label style="display:block"><input type="radio" name="customer_mode" value="generic" ' . checked( 'generic', $settings['customer_mode'], false ) . '> ' . esc_html__( 'Book every sale under a single customer:', 'qbd-bridge' ) . ' <input name="generic_customer" type="text" value="' . esc_attr( $settings['generic_customer'] ) . '"></label>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="qbdb-misc-item">' . esc_html__( 'Item for fees/shipping', 'qbd-bridge' ) . '</label></th><td><input name="misc_item" id="qbdb-misc-item" type="text" value="' . esc_attr( $settings['misc_item'] ) . '"></td></tr>';

		echo '<tr><th scope="row"><label for="qbdb-tax-item">' . esc_html__( 'Item for tax/GST', 'qbd-bridge' ) . '</label></th><td><input name="tax_item" id="qbdb-tax-item" type="text" value="' . esc_attr( $settings['tax_item'] ) . '"><p class="description">' . esc_html__( 'Only used if an order carries tax; taxes are added as a plain line so the receipt total matches the order.', 'qbd-bridge' ) . '</p></td></tr>';

		echo '</table>';
		submit_button( __( 'Save settings', 'qbd-bridge' ) );
		echo '</form>';

		/* --- Queue --- */
		echo '<h2>' . esc_html__( 'Export queue', 'qbd-bridge' ) . '</h2>';
		echo '<p>' . esc_html( sprintf(
			/* translators: 1-4: counts */
			__( 'Pending: %1$d · In flight: %2$d · Exported: %3$d · Errors: %4$d', 'qbd-bridge' ),
			$counts['pending'], $counts['sent'], $counts['done'], $counts['error']
		) ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-bottom:1em">';
		wp_nonce_field( 'qbdb_queue_action' );
		echo '<input type="hidden" name="action" value="qbdb_queue_action">';
		echo '<input type="number" name="queue_order_id" placeholder="' . esc_attr__( 'Order ID', 'qbd-bridge' ) . '" min="1" style="width:8em"> ';
		submit_button( __( 'Queue order', 'qbd-bridge' ), 'secondary', 'queue_order', false );
		if ( $counts['error'] > 0 ) {
			echo ' <button type="submit" name="requeue_errors" value="1" class="button">' . esc_html__( 'Requeue all errors', 'qbd-bridge' ) . '</button>';
		}
		echo '</form>';

		echo '<table class="widefat striped"><thead><tr>'
			. '<th>' . esc_html__( 'Order', 'qbd-bridge' ) . '</th>'
			. '<th>' . esc_html__( 'State', 'qbd-bridge' ) . '</th>'
			. '<th>' . esc_html__( 'Attempts', 'qbd-bridge' ) . '</th>'
			. '<th>' . esc_html__( 'QB TxnID', 'qbd-bridge' ) . '</th>'
			. '<th>' . esc_html__( 'Last error', 'qbd-bridge' ) . '</th>'
			. '<th>' . esc_html__( 'Updated', 'qbd-bridge' ) . '</th>'
			. '<th></th>'
			. '</tr></thead><tbody>';

		if ( empty( $rows ) ) {
			echo '<tr><td colspan="7">' . esc_html__( 'Nothing queued yet. Orders join the queue when they reach a ticked status.', 'qbd-bridge' ) . '</td></tr>';
		}

		foreach ( $rows as $row ) {
			$order_url = admin_url( 'post.php?post=' . absint( $row->order_id ) . '&action=edit' );
			if ( function_exists( 'wc_get_order' ) ) {
				$order = wc_get_order( (int) $row->order_id );
				if ( $order ) {
					$order_url = $order->get_edit_order_url();
				}
			}
			echo '<tr>';
			echo '<td><a href="' . esc_url( $order_url ) . '">#' . absint( $row->order_id ) . '</a></td>';
			echo '<td>' . esc_html( $row->state ) . '</td>';
			echo '<td>' . absint( $row->attempts ) . '</td>';
			echo '<td>' . esc_html( $row->qb_txn_id ) . '</td>';
			echo '<td>' . esc_html( wp_trim_words( (string) $row->last_error, 25 ) ) . '</td>';
			echo '<td>' . esc_html( $row->updated_at ) . '</td>';
			echo '<td>';
			if ( in_array( $row->state, array( 'error', 'done' ), true ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( 'qbdb_queue_action' );
				echo '<input type="hidden" name="action" value="qbdb_queue_action">';
				echo '<input type="hidden" name="requeue_id" value="' . absint( $row->id ) . '">';
				echo '<button type="submit" class="button-link">' . esc_html__( 'Requeue', 'qbd-bridge' ) . '</button>';
				echo '</form>';
			}
			echo '</td></tr>';
		}

		echo '</tbody></table></div>';
	}
}
