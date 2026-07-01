<?php
/**
 * Public customer shipment tracking shortcode [naya_setu_track].
 *
 * Usage: add a WordPress page, paste [naya_setu_track] into the content.
 * Customers land on that page and enter their AWB to see live tracking.
 *
 * Also embeddable: GET /wp-json/courier/v1/track/{awb} returns JSON.
 *
 * @package CourierConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CC_Tracking_Page {

	public function register() {
		add_shortcode( 'naya_setu_track', array( $this, 'render' ) );
	}

	public function render( $atts ) {
		$atts  = shortcode_atts( array( 'title' => 'Track Your Shipment' ), $atts );
		$awb   = isset( $_GET['awb'] ) ? sanitize_text_field( wp_unslash( $_GET['awb'] ) ) : '';
		$track = null;

		if ( $awb && CC_Settings::is_configured() ) {
			$api   = new CC_Delhivery_API();
			$track = $api->track( $awb );
			if ( '' === $track['status'] ) {
				$body  = $track['body'] ?? array();
				$track = array(
					'status'   => '',
					'location' => '',
					'scans'    => array(),
					'error'    => is_array( $body ) ? ( $body['Error'] ?? 'Shipment is being processed. Tracking will be available within 30–60 minutes of first scan.' ) : 'Shipment is being processed.',
				);
			}
		}

		ob_start();
		include __DIR__ . '/tracking.php';
		return ob_get_clean();
	}
}
