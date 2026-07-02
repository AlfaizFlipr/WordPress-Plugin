<?php

if (!defined('ABSPATH')) {
	exit;
}

final class CC_Plugin
{

	private static $instance;

	public static function instance()
	{
		if (!self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct()
	{
		$this->maybe_upgrade();

		(new CC_Hooks())->register();

		(new CC_REST())->register();

		(new CC_Landing())->register();

		(new CC_Portal())->register();

		(new CC_Tracking_Page())->register();

		add_action('cc_tracking_cron', array('CC_Tracking', 'run'));

		if (is_admin()) {
			(new CC_Ajax())->register();
			(new CC_Admin())->register();
		}
	}

	private function maybe_upgrade()
	{
		if (get_option('cc_db_version') !== CC_DB_VERSION) {
			CC_Install::run();
		}
	}
}
