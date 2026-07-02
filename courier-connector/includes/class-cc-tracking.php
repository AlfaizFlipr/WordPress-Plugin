<?php

if (!defined('ABSPATH')) {
	exit;
}

class CC_Tracking
{

	public static function run()
	{
		$order_ids = self::active_shipment_ids();
		if (empty($order_ids)) {
			return;
		}

		foreach ($order_ids as $order_id) {
			$order = new CC_Order($order_id);
			if (!$order->valid() || !$order->get_awb()) {
				continue;
			}

			$courier = CC_Courier_Registry::get(CC_Shipment::booked_courier_key($order));
			if (!$courier->is_configured()) {
				continue;
			}

			$tracking = $courier->track($order->get_awb());
			if (!$tracking['ok'] || '' === $tracking['status']) {
				continue;
			}

			$internal = CC_Shipment::map_status($tracking['status']);
			$changed = ($internal && $internal !== $order->get_ship_status());

			$order->set_courier_data(
				array(
					'ship_status' => $internal ? $internal : $order->get_ship_status(),
					'last_scan' => $tracking['status'],
				)
			);

			if ($changed) {
				$order->add_note(
					sprintf('Tracking update: %s (%s)', $tracking['status'], $tracking['location'])
				);
				CC_Shipment::reverse_sync($order, $order->get_awb(), $order->get_tracking_url(), $internal);

				if ('delivered' === $internal && $order->wc()->get_status() !== 'completed') {
					$order->wc()->update_status('completed', '[Courier] Auto-completed: shipment delivered.');
				}
			}
		}
	}

	protected static function active_shipment_ids()
	{
		$args = array(
			'limit' => 100,
			'return' => 'ids',
			'meta_query' => array(
				array(
					'key' => '_cc_awb',
					'compare' => 'EXISTS',
				),
				array(
					'key' => '_cc_ship_status',
					'value' => array('delivered', 'cancelled', 'rto'),
					'compare' => 'NOT IN',
				),
			),
		);
		return wc_get_orders($args);
	}
}
