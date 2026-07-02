<?php

if (!defined('ABSPATH')) {
	exit;
}

class CC_Courier_Registry
{

	public static function all()
	{
		return array(
			'delhivery' => 'Delhivery',
			'dtdc' => 'DTDC',
		);
	}

	public static function get($key)
	{
		switch ($key) {
			case 'dtdc':
				return new CC_Courier_DTDC();
			default:
				return new CC_Courier_Delhivery();
		}
	}

	public static function sanitize($key, $default = 'delhivery')
	{
		$key = sanitize_key((string) $key);
		return isset(self::all()[$key]) ? $key : $default;
	}
}
