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

	/**
	 * Generate a new client (API key + secret) from the Naya Setu panel.
	 * No store URL is needed here — the client plugin reports it during the
	 * key-based handshake. The dashboard URL is embedded in the API key so the
	 * client only ever needs to paste the two keys.
	 */
	public static function generate(array $data)
	{
		global $wpdb;

		$name = sanitize_text_field($data['store_name'] ?? '');
		if ('' === $name) {
			return new WP_Error('cc_bad_name', 'A client / company name is required.');
		}

		$row = array(
			'store_name' => $name,
			'store_url' => '',
			'api_key' => self::make_api_key(),
			'secret_key' => 'ccs_' . wp_generate_password(40, false),
			'courier' => CC_Courier_Registry::sanitize($data['courier'] ?? 'delhivery'),
			'courier_mode' => 'auto' === ($data['courier_mode'] ?? '') ? 'auto' : 'manual',
			'callback_url' => '',
			'status' => 'active',
			'created_at' => current_time('mysql'),
			'orders_synced' => 0,
		);

		$wpdb->insert(self::table(), $row);
		return self::get($wpdb->insert_id);
	}

	/**
	 * API key format: cc1.<base64url(dashboard home_url)>.<random>
	 * The client plugin decodes the middle segment to find this dashboard —
	 * no URL field on the client side.
	 */
	protected static function make_api_key()
	{
		$encoded = rtrim(strtr(base64_encode(home_url()), '+/', '-_'), '=');
		return 'cc1.' . $encoded . '.' . wp_generate_password(24, false);
	}

	/**
	 * Key + secret based connection from the client plugin.
	 * Verifies both keys, then records the store's URL / callback / pickup profile.
	 */
	public static function handshake($api_key, $secret_key, array $data)
	{
		global $wpdb;

		$store = self::get_by_key((string) $api_key);
		if (!$store || 'active' !== $store->status) {
			return new WP_Error('cc_bad_key', 'Invalid or disabled API key.');
		}
		if (!$secret_key || !hash_equals((string) $store->secret_key, (string) $secret_key)) {
			return new WP_Error('cc_bad_secret', 'Secret key does not match.');
		}

		$row = array(
			'store_url' => esc_url_raw($data['store_url'] ?? ''),
			'callback_url' => esc_url_raw($data['callback_url'] ?? ''),
		);
		if (!empty($data['store_name']) && '' === trim((string) $store->store_url)) {
			// Keep the panel-given company name once set; only fill it from the
			// site on the very first connection if the admin left it generic.
			$row['store_name'] = $store->store_name ?: sanitize_text_field($data['store_name']);
		}
		if (isset($data['pickup']) && is_array($data['pickup'])) {
			$row['pickup_json'] = wp_json_encode(self::sanitize_pickup($data['pickup']));
		}

		$wpdb->update(self::table(), $row, array('id' => $store->id));
		return self::get($store->id);
	}

	public static function sanitize_pickup(array $pickup)
	{
		$fields = array(
			'pickup_name', 'pickup_phone', 'pickup_address', 'pickup_city',
			'pickup_state', 'pickup_pincode', 'pickup_country',
			'default_weight', 'default_length', 'default_breadth', 'default_height',
		);
		$out = array();
		foreach ($fields as $f) {
			$out[$f] = sanitize_text_field($pickup[$f] ?? '');
		}
		if ('' === $out['pickup_country']) {
			$out['pickup_country'] = 'India';
		}
		return $out;
	}

	public static function update_pickup($id, array $pickup)
	{
		global $wpdb;
		$wpdb->update(
			self::table(),
			array('pickup_json' => wp_json_encode(self::sanitize_pickup($pickup))),
			array('id' => $id)
		);
	}

	/**
	 * The client's pickup profile (address + parcel size), set from the
	 * connector plugin on the client site. Falls back to legacy global
	 * settings for stores that never sent one.
	 */
	public static function pickup($store)
	{
		$store = is_object($store) ? $store : self::get((int) $store);
		$pickup = array();
		if ($store && !empty($store->pickup_json)) {
			$decoded = json_decode($store->pickup_json, true);
			if (is_array($decoded)) {
				$pickup = $decoded;
			}
		}
		$legacy = array(
			'pickup_name' => CC_Settings::get('pickup_name'),
			'pickup_phone' => CC_Settings::get('pickup_phone'),
			'pickup_address' => CC_Settings::get('pickup_address'),
			'pickup_city' => CC_Settings::get('pickup_city'),
			'pickup_state' => CC_Settings::get('pickup_state'),
			'pickup_pincode' => CC_Settings::get('pickup_pincode'),
			'pickup_country' => CC_Settings::get('pickup_country', 'India'),
			'default_weight' => CC_Settings::get('default_weight', '0.5'),
			'default_length' => CC_Settings::get('default_length', '10'),
			'default_breadth' => CC_Settings::get('default_breadth', '10'),
			'default_height' => CC_Settings::get('default_height', '10'),
		);
		foreach ($legacy as $key => $value) {
			if (empty($pickup[$key])) {
				$pickup[$key] = $value;
			}
		}
		return $pickup;
	}

	/**
	 * Unique Delhivery warehouse name for this client's pickup point.
	 * Warehouse names are global per Delhivery account, and every client
	 * registers their own pickup address — suffixing the store id keeps two
	 * clients with the same shop name from overwriting each other's warehouse.
	 */
	public static function warehouse_name($store)
	{
		$store = is_object($store) ? $store : self::get((int) $store);
		if (!$store) {
			return (string) CC_Settings::get('pickup_name');
		}
		$pickup = self::pickup($store);
		$name = trim((string) ($pickup['pickup_name'] ?? ''));
		if ('' === $name) {
			return '';
		}
		return $name . ' NS' . (int) $store->id;
	}

	public static function update_courier($id, $courier, $mode)
	{
		global $wpdb;
		$wpdb->update(
			self::table(),
			array(
				'courier' => CC_Courier_Registry::sanitize($courier),
				'courier_mode' => 'auto' === $mode ? 'auto' : 'manual',
			),
			array('id' => $id)
		);
	}

	public static function regenerate_keys($id)
	{
		global $wpdb;
		$wpdb->update(
			self::table(),
			array(
				'api_key' => self::make_api_key(),
				'secret_key' => 'ccs_' . wp_generate_password(40, false),
			),
			array('id' => $id)
		);
		return self::get($id);
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
