<?php
/**
 * Main plugin loader — wires up all components.
 *
 * @package CourierConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CC_Plugin {

	/** @var CC_Plugin */
	private static $instance;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->maybe_upgrade();

		// Front-of-house hooks.
		( new CC_Hooks() )->register();

		// REST API for external stores.
		( new CC_REST() )->register();

		// Public marketing landing page shortcode [naya_setu_landing].
		( new CC_Landing() )->register();

		// Front-end client portal shortcode [naya_setu_client_portal].
		( new CC_Portal() )->register();

		// Public customer shipment tracking shortcode [naya_setu_track].
		( new CC_Tracking_Page() )->register();

		// Cron tracking.
		add_action( 'cc_tracking_cron', array( 'CC_Tracking', 'run' ) );

		if ( is_admin() ) {
			( new CC_Ajax() )->register();
			( new CC_Admin() )->register();
		}
	}

	/**
	 * Run installer if DB version changed (e.g. plugin updated without reactivation).
	 */
	private function maybe_upgrade() {
		if ( get_option( 'cc_db_version' ) !== CC_DB_VERSION ) {
			CC_Install::run();
		}
	}
}
