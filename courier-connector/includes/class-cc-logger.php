<?php
/**
 * Lightweight DB logger for API calls and sync events.
 *
 * @package CourierConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CC_Logger {

	/**
	 * Write a log row.
	 *
	 * @param string $context Short context tag (delhivery, sync, rest...).
	 * @param string $message Human readable message.
	 * @param mixed  $data    Optional structured data.
	 * @param string $level   info|error|warning.
	 */
	public static function log( $context, $message, $data = null, $level = 'info' ) {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'cc_logs',
			array(
				'context'    => substr( $context, 0, 50 ),
				'level'      => $level,
				'message'    => is_scalar( $message ) ? (string) $message : wp_json_encode( $message ),
				'data'       => null === $data ? null : wp_json_encode( $data ),
				'created_at' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Convenience error logger.
	 */
	public static function error( $context, $message, $data = null ) {
		self::log( $context, $message, $data, 'error' );
	}

	/**
	 * Fetch recent logs.
	 *
	 * @param int $limit Rows.
	 * @return array
	 */
	public static function recent( $limit = 100 ) {
		global $wpdb;
		$limit = absint( $limit );
		return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}cc_logs ORDER BY id DESC LIMIT {$limit}" );
	}
}
