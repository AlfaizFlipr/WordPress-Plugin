<?php

if (!defined('ABSPATH')) {
	exit;
}

class CC_Website
{

	protected static function table()
	{
		global $wpdb;
		return $wpdb->prefix . 'cc_websites';
	}

	public static function connect(array $data)
	{
		global $wpdb;

		$store_url = esc_url_raw($data['store_url'] ?? '');
		if ('' === $store_url) {
			return new WP_Error('cc_bad_url', 'A valid store URL is required.');
		}

		$existing = self::get_by_url($store_url);
		$api_key = $existing ? $existing->api_key : 'cc_' . wp_generate_password(40, false);

		$row = array(
			'store_name' => sanitize_text_field($data['store_name'] ?? wp_parse_url($store_url, PHP_URL_HOST)),
			'store_url' => $store_url,
			'api_key' => $api_key,
			'callback_url' => esc_url_raw($data['callback_url'] ?? ''),
			'status' => 'active',
		);

		if ($existing) {
			$wpdb->update(self::table(), $row, array('id' => $existing->id));
			return self::get($existing->id);
		}

		$row['created_at'] = current_time('mysql');
		$row['orders_synced'] = 0;
		$wpdb->insert(self::table(), $row);
		return self::get($wpdb->insert_id);
	}

	public static function get($id)
	{
		global $wpdb;
		return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $id));
	}

	public static function get_by_url($url)
	{
		global $wpdb;
		return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE store_url = %s', $url));
	}

	public static function get_by_key($api_key)
	{
		global $wpdb;
		return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE api_key = %s', $api_key));
	}

	public static function all()
	{
		global $wpdb;
		return $wpdb->get_results('SELECT * FROM ' . self::table() . ' ORDER BY id DESC');
	}

	public static function active()
	{
		global $wpdb;
		return $wpdb->get_results("SELECT * FROM " . self::table() . " WHERE status = 'active' ORDER BY id DESC");
	}

	public static function active_ids()
	{
		global $wpdb;
		$ids = $wpdb->get_col("SELECT id FROM " . self::table() . " WHERE status = 'active'");
		return array_map('intval', $ids);
	}

	public static function update_status($id, $status)
	{
		global $wpdb;
		$wpdb->update(self::table(), array('status' => $status), array('id' => $id));
	}

	public static function delete($id)
	{
		global $wpdb;
		$wpdb->delete(self::table(), array('id' => $id));
	}

	public static function bump_sync($id)
	{
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . ' SET orders_synced = orders_synced + 1, last_sync = %s WHERE id = %d',
				current_time('mysql'),
				$id
			)
		);
	}
}
