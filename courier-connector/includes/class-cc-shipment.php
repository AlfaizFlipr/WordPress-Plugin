<?php

if (!defined('ABSPATH')) {
	exit;
}

class CC_Shipment
{

	public static function push($order_id, $courier_key = null)
	{
		$order = new CC_Order($order_id);
		if (!$order->valid()) {
			return new WP_Error('cc_no_order', 'Order not found.');
		}

		if ($order->get_awb()) {
			return new WP_Error('cc_already', 'Shipment already created. AWB: ' . $order->get_awb());
		}

		$pickup = $order->pickup();
		if ('' === trim((string) ($pickup['pickup_name'] ?? ''))) {
			return new WP_Error('cc_no_pickup', 'No pickup address for this client yet. The client sets it in their Courier Connector plugin (Pickup Address section).');
		}

		$key = self::resolve_courier($order, $courier_key);
		$courier = CC_Courier_Registry::get($key);

		if (!$courier->is_configured()) {
			return new WP_Error('cc_no_token', $courier->label() . ' is not configured. Add its credentials under Courier → Settings.');
		}

		$result = $courier->create_shipment($order);

		if (!$result['ok'] || empty($result['awb'])) {
			$msg = $result['remark'] ? $result['remark'] : $courier->label() . ' rejected the shipment.';
			$order->add_note('Shipment creation failed (' . $courier->label() . '): ' . $msg);
			// Surface the reason on the Orders page, not just in the logs.
			$order->wc()->update_meta_data('_cc_push_error', $msg);
			$order->wc()->save();
			return new WP_Error('cc_create_failed', $msg, $result['body']);
		}

		$awb = $result['awb'];
		$tracking_url = $courier->tracking_url($awb);

		$order->set_courier_data(
			array(
				'awb' => $awb,
				'courier' => $courier->label(),
				'ship_status' => 'booked',
				'tracking_url' => $tracking_url,
				'last_scan' => 'Manifested',
			)
		);
		$order->wc()->update_meta_data('_cc_courier_choice', $key);
		$order->wc()->delete_meta_data('_cc_push_error');
		$order->wc()->save();
		$order->add_note(sprintf('Shipment created with %s. AWB: %s', $courier->label(), $awb));

		self::reverse_sync($order, $awb, $tracking_url, 'booked', $courier->label());

		return array(
			'awb' => $awb,
			'tracking_url' => $tracking_url,
		);
	}

	public static function cancel($order_id)
	{
		$order = new CC_Order($order_id);
		if (!$order->valid() || !$order->get_awb()) {
			return new WP_Error('cc_no_awb', 'No shipment to cancel.');
		}
		$courier = CC_Courier_Registry::get(self::booked_courier_key($order));
		$res = $courier->cancel_shipment($order->get_awb());
		if (!$res['ok']) {
			return new WP_Error('cc_cancel_failed', $courier->label() . ' cancellation failed.', $res['body']);
		}
		$order->set_courier_data(array('ship_status' => 'cancelled', 'last_scan' => 'Cancelled'));
		$order->add_note('Shipment cancelled with ' . $courier->label() . '.');
		self::reverse_sync($order, $order->get_awb(), $order->get_tracking_url(), 'cancelled', $courier->label());
		return true;
	}

	public static function resolve_courier(CC_Order $order, $explicit = null)
	{
		if ($explicit) {
			return CC_Courier_Registry::sanitize($explicit);
		}
		$choice = (string) $order->wc()->get_meta('_cc_courier_choice');
		if ($choice) {
			return CC_Courier_Registry::sanitize($choice);
		}
		// Client-wise courier assignment from the Naya Setu panel.
		$store_id = $order->source_store_id();
		if ($store_id) {
			$store = CC_Website::get($store_id);
			if ($store && !empty($store->courier)) {
				return CC_Courier_Registry::sanitize($store->courier);
			}
		}
		return CC_Courier_Registry::sanitize(CC_Settings::get('default_courier', 'delhivery'));
	}

	public static function booked_courier_key(CC_Order $order)
	{
		$choice = (string) $order->wc()->get_meta('_cc_courier_choice');
		return CC_Courier_Registry::sanitize($choice);
	}

	public static function map_status($status)
	{
		$s = strtolower(trim((string) $status));
		if ('' === $s) {
			return '';
		}
		if (false !== strpos($s, 'delivered')) {
			return 'delivered';
		}
		if (false !== strpos($s, 'rto') || false !== strpos($s, 'returned')) {
			return 'rto';
		}
		if (false !== strpos($s, 'out for delivery') || false !== strpos($s, 'dispatched')) {
			return 'ofd';
		}
		if (false !== strpos($s, 'transit') || false !== strpos($s, 'in-transit')) {
			return 'in-transit';
		}
		if (false !== strpos($s, 'cancel')) {
			return 'cancelled';
		}
		if (false !== strpos($s, 'manifest') || false !== strpos($s, 'pending')) {
			return 'booked';
		}
		return 'in-transit';
	}

	public static function reverse_sync(CC_Order $order, $awb, $tracking_url, $status, $courier_name = 'Delhivery')
	{
		$store_id = (int) $order->wc()->get_meta('_cc_source_store');
		if (!$store_id) {
			return;
		}
		$store = CC_Website::get($store_id);
		if (!$store || empty($store->callback_url)) {
			return;
		}

		$external_id = $order->wc()->get_meta('_cc_external_order_id');

		$payload = array(
			'external_order_id' => $external_id ? $external_id : $order->id(),
			'awb' => $awb,
			'courier' => $courier_name,
			'tracking_url' => $tracking_url,
			'status' => $status,
		);

		wp_remote_post(
			trailingslashit($store->callback_url) . 'update-awb',
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type' => 'application/json',
					'X-CC-Api-Key' => $store->api_key,
				),
				'body' => wp_json_encode($payload),
			)
		);
		CC_Logger::log('reverse-sync', 'Pushed AWB to store #' . $store_id, $payload);
	}
}
