<?php

if (!defined('ABSPATH')) {
	exit;
}

class CC_Settings
{

	const OPTION = 'cc_settings';

	public static function get($key, $default = '')
	{
		$settings = get_option(self::OPTION, array());
		return isset($settings[$key]) ? $settings[$key] : $default;
	}

	public static function all()
	{
		return get_option(self::OPTION, array());
	}

	public static function update(array $values)
	{
		$existing = get_option(self::OPTION, array());
		update_option(self::OPTION, array_merge($existing, $values));
	}

	public static function is_configured()
	{
		return '' !== trim((string) self::get('api_token'));
	}
}
