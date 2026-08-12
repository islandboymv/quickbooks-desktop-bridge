<?php
/**
 * Plugin Name: QuickBooks Desktop Bridge for WooCommerce
 * Plugin URI:  https://github.com/islandboymv/quickbooks-desktop-bridge
 * Description: Feeds paid WooCommerce orders into QuickBooks Desktop as sales receipts via the QuickBooks
 *              Web Connector — no third-party subscription. Queues orders as they're paid, then the Web
 *              Connector on the QuickBooks PC pulls them in on a schedule. Self-updates from GitHub Releases.
 * Author:      Islandboy
 * Version:     0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 10.8
 * Text Domain: qbd-bridge
 * License:     GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'QBDB_VERSION', '0.1.0' );
define( 'QBDB_FILE', __FILE__ );
define( 'QBDB_PATH', plugin_dir_path( __FILE__ ) );
define( 'QBDB_URL', plugin_dir_url( __FILE__ ) );
define( 'QBDB_TELEMETRY_URL', 'https://plugin-telemetry.islandboy.workers.dev/ping' );

/**
 * Declare HPOS (custom order tables) + Blocks compatibility.
 */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', QBDB_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', QBDB_FILE, true );
	}
} );

/**
 * GitHub-powered automatic updates.
 *
 * To ship an update: bump the Version header, commit, and publish a GitHub Release
 * (e.g. tag v0.2.0). PUC ignores releases marked "pre-release".
 */
add_action( 'plugins_loaded', function () {
	require_once QBDB_PATH . 'lib/plugin-update-checker/plugin-update-checker.php';

	\YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/islandboymv/quickbooks-desktop-bridge/',
		QBDB_FILE,
		'quickbooks-desktop-bridge'
	);
} );

/* ---------------------------------------------------------------------------
 * Anonymous active-install telemetry.
 *
 * Sends a daily ping containing ONLY the plugin slug, a one-way SHA-256 hash of
 * the site URL (never the URL itself), and the version. It cannot identify the site.
 *
 * Opt out entirely with:
 *   add_filter( 'qbdb_telemetry_enabled', '__return_false' );
 * ------------------------------------------------------------------------- */
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'qbdb_telemetry_ping' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'qbdb_telemetry_ping' );
	}
} );

add_action( 'qbdb_telemetry_ping', 'qbdb_send_telemetry' );

function qbdb_send_telemetry() {
	if ( ! apply_filters( 'qbdb_telemetry_enabled', true ) ) {
		return;
	}
	$home = home_url();
	foreach ( array( 'localhost', '127.0.0.1', '.test', '.local', '.localhost', '.example' ) as $needle ) {
		if ( false !== strpos( $home, $needle ) ) {
			return; // don't count local/dev sites
		}
	}
	wp_remote_post( QBDB_TELEMETRY_URL, array(
		'timeout'  => 5,
		'blocking' => false,
		'headers'  => array( 'Content-Type' => 'application/json' ),
		'body'     => wp_json_encode( array(
			'slug'    => 'quickbooks-desktop-bridge',
			'site'    => hash( 'sha256', $home ),
			'version' => QBDB_VERSION,
		) ),
	) );
}

register_activation_hook( __FILE__, function () {
	wp_schedule_single_event( time() + 30, 'qbdb_telemetry_ping' );

	require_once QBDB_PATH . 'includes/class-qbdb-queue.php';
	QBDB_Queue::install();

	require_once QBDB_PATH . 'includes/class-qbdb-settings.php';
	QBDB_Settings::seed_defaults();
} );
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'qbdb_telemetry_ping' );
} );

/**
 * Boot once WooCommerce is available.
 */
add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="error"><p>'
				. esc_html__( 'QuickBooks Desktop Bridge requires WooCommerce to be installed and active.', 'qbd-bridge' )
				. '</p></div>';
		} );
		return;
	}

	require_once QBDB_PATH . 'includes/class-qbdb-settings.php';
	require_once QBDB_PATH . 'includes/class-qbdb-queue.php';
	require_once QBDB_PATH . 'includes/class-qbdb-orders.php';
	require_once QBDB_PATH . 'includes/class-qbdb-qbxml.php';
	require_once QBDB_PATH . 'includes/class-qbdb-soap.php';

	QBDB_Queue::maybe_install(); // create/upgrade the queue table if needed.
	QBDB_Settings::init();
	QBDB_Orders::init();
	QBDB_SOAP::init();
}, 11 );

/**
 * Settings shortcut on the Plugins screen.
 */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	$url = admin_url( 'admin.php?page=qbdb-bridge' );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'qbd-bridge' ) . '</a>' );
	return $links;
} );
