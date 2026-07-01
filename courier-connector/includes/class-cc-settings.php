<?php
/**
 * Settings accessor — thin wrapper around the cc_settings option.
 *
 * @package CourierConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CC_Settings {

	const OPTION = 'cc_settings';

	/**
	 * Get a single setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( $key, $default = '' ) {
		$settings = get_option( self::OPTION, array() );
		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	/**
	 * Get all settings.
	 *
	 * @return array
	 */
	public static function all() {
		return get_option( self::OPTION, array() );
	}

	/**
	 * Update settings (merges with existing).
	 *
	 * @param array $values Key/value pairs.
	 */
	public static function update( array $values ) {
		$existing = get_option( self::OPTION, array() );
		update_option( self::OPTION, array_merge( $existing, $values ) );
	}

	/**
	 * Whether the Delhivery API token is configured.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== trim( (string) self::get( 'api_token' ) );
	}
}
