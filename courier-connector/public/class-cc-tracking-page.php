<?php

if (!defined('ABSPATH')) {
	exit;
}

class CC_Tracking_Page
{

	public function register()
	{
		add_shortcode('naya_setu_track', array($this, 'render'));
	}

	public function render($atts)
	{
		$atts = shortcode_atts(array('title' => 'Track Your Shipment'), $atts);
		$awb = isset($_GET['awb']) ? sanitize_text_field(wp_unslash($_GET['awb'])) : '';
		$track = null;

		if ($awb) {
			$order_id = CC_REST::find_order_id_by_awb($awb);
			$courier_key = $order_id ? CC_Shipment::booked_courier_key(new CC_Order($order_id)) : CC_Settings::get('default_courier', 'delhivery');
			$courier = CC_Courier_Registry::get($courier_key);
			$track = $courier->is_configured() ? $courier->track($awb) : array('status' => '', 'body' => array());
			if ('' === $track['status']) {
				$body = $track['body'] ?? array();
				$track = array(
					'status' => '',
					'location' => '',
					'scans' => array(),
					'error' => is_array($body) ? ($body['Error'] ?? 'Shipment is being processed. Tracking will be available within 30–60 minutes of first scan.') : 'Shipment is being processed.',
				);
			}
		}

		ob_start();
		include __DIR__ . '/tracking.php';
		return ob_get_clean();
	}
}
