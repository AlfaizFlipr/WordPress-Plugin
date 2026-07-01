<?php
/**
 * Plugin Name:       Naya Setu Courier — Dashboard (WooCommerce + Delhivery)
 * Description:        Shiprocket-style courier management dashboard by Naya Setu. Auto-syncs orders, pushes shipments to Delhivery, generates AWB, syncs tracking back to the store, and exposes REST endpoints so external stores / a central panel can connect. Includes a [naya_setu_landing] marketing landing page shortcode.
 * Version:           1.0.0
 * Author:            Naya Setu
 * License:           GPL-2.0+
 * Text Domain:       courier-connector
 * Requires PHP:      7.4
 * WC requires at least: 6.0
 *
 * @package CourierConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; 
}

define( 'CC_VERSION', '1.0.0' );
define( 'CC_PLUGIN_FILE', __FILE__ );
define( 'CC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CC_DB_VERSION', '1.1.0' );

/**
 * Class autoloader for CC_* classes living in /includes and /admin.
 */
spl_autoload_register(
	function ( $class ) {
		if ( strpos( $class, 'CC_' ) !== 0 ) {
			return;
		}
		$slug = 'class-' . str_replace( '_', '-', strtolower( $class ) ) . '.php';
		foreach ( array( 'includes/', 'admin/', 'public/' ) as $dir ) {
			$file = CC_PLUGIN_DIR . $dir . $slug;
			if ( file_exists( $file ) ) {
				require_once $file;
				return;
			}
		}
	}
);

/**
 * Activation: create tables, default settings, schedule cron.
 */
function cc_activate() {
	require_once CC_PLUGIN_DIR . 'includes/class-cc-install.php';
	CC_Install::run();

	if ( ! wp_next_scheduled( 'cc_tracking_cron' ) ) {
		wp_schedule_event( time() + 300, 'cc_fifteen_minutes', 'cc_tracking_cron' );
	}
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'cc_activate' );

/**
 * Deactivation: clear scheduled cron.
 */
function cc_deactivate() {
	wp_clear_scheduled_hook( 'cc_tracking_cron' );
	flush_rewrite_rules();
	
	
}
register_deactivation_hook( __FILE__, 'cc_deactivate' );

/**
 * Custom cron schedule (every 15 minutes).
 */
add_filter(
	'cron_schedules',
	function ( $schedules ) {
		$schedules['cc_fifteen_minutes'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 Minutes (Courier Connector)', 'courier-connector' ),
		);
		return $schedules;
	}
);

/**
 * Boot the plugin once all plugins are loaded.
 */
add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			// Still expose the REST API so stores can connect, and warn the admin.
			// Order import itself needs WooCommerce and will return a clear error.
			( new CC_REST() )->register();
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p><strong>Naya Setu Courier — Dashboard</strong> needs <strong>WooCommerce</strong> active on this site to store and manage orders. Stores can connect, but order import is disabled until WooCommerce is active.</p></div>';
				}
			);
			return;
		}
		CC_Plugin::instance();
	}
);

/**
 * Declare HPOS (High-Performance Order Storage) compatibility.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);
