<?php

if (!defined('ABSPATH')) {
	exit;
}

class CC_Install
{

	public static function run()
	{
		self::create_tables();
		self::seed_settings();
		self::register_role();
		update_option('cc_db_version', CC_DB_VERSION);
	}

	public static function register_role()
	{
		add_role(
			'ns_client',
			'Naya Setu Client',
			array(
				'read' => true,
				'ns_client' => true,
			)
		);

		$admin = get_role('administrator');
		if ($admin && !$admin->has_cap('ns_client')) {
			$admin->add_cap('ns_client');
		}
	}

	public static function create_tables()
	{
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$websites = $wpdb->prefix . 'cc_websites';
		$logs = $wpdb->prefix . 'cc_logs';

		$sql_websites = "CREATE TABLE {$websites} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			store_name VARCHAR(191) NOT NULL DEFAULT '',
			store_url VARCHAR(255) NOT NULL DEFAULT '',
			api_key VARCHAR(191) NOT NULL DEFAULT '',
			secret_key VARCHAR(64) NOT NULL DEFAULT '',
			courier VARCHAR(20) NOT NULL DEFAULT 'delhivery',
			courier_mode VARCHAR(20) NOT NULL DEFAULT 'manual',
			pickup_json LONGTEXT NULL,
			callback_url VARCHAR(255) NOT NULL DEFAULT '',
			client_user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			orders_synced BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			last_sync DATETIME NULL DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY api_key (api_key),
			KEY client_user_id (client_user_id)
		) {$charset_collate};";

		$sql_logs = "CREATE TABLE {$logs} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			context VARCHAR(50) NOT NULL DEFAULT '',
			level VARCHAR(20) NOT NULL DEFAULT 'info',
			message TEXT NULL,
			data LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY context (context)
		) {$charset_collate};";

		dbDelta($sql_websites);
		dbDelta($sql_logs);
	}

	public static function seed_settings()
	{
		$defaults = array(
			'default_courier' => 'delhivery',
			'api_token' => '',
			'environment' => 'production',
			'dtdc_customer_code' => '',
			'dtdc_username' => '',
			'dtdc_password' => '',
			'pickup_name' => '',
			'pickup_phone' => '',
			'pickup_address' => '',
			'pickup_city' => '',
			'pickup_state' => '',
			'pickup_pincode' => '',
			'pickup_country' => 'India',
			'default_weight' => '0.5',
			'default_length' => '10',
			'default_breadth' => '10',
			'default_height' => '10',
			'payment_default' => 'Prepaid',
			'webhook_secret' => wp_generate_password(24, false),
		);
		$existing = get_option('cc_settings', array());
		update_option('cc_settings', wp_parse_args($existing, $defaults));
	}
}
