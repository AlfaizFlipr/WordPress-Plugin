<?php

if (!defined('ABSPATH')) {
	exit;
}

class CC_Courier_Delhivery implements CC_Courier_Interface
{

	protected $api;

	public function __construct()
	{
		$this->api = new CC_Delhivery_API();
	}

	public function key()
	{
		return 'delhivery';
	}

	public function label()
	{
		return 'Delhivery';
	}

	public function is_configured()
	{
		return CC_Settings::is_configured();
	}

	public function create_shipment(CC_Order $order)
	{
		$pickup = CC_Settings::get('pickup_name');
		$shipment = $order->to_delhivery_shipment();

		$waybill = $this->api->fetch_waybill(1);
		if (!is_wp_error($waybill) && $waybill) {
			$shipment['waybill'] = $waybill;
		}

		$result = $this->api->create_shipment($shipment, $pickup);

		return array(
			'ok' => $result['ok'],
			'awb' => $result['awb'],
			'remark' => $result['remark'],
			'body' => $result['body'],
		);
	}

	public function cancel_shipment($awb)
	{
		return $this->api->cancel($awb);
	}

	public function track($awb)
	{
		return $this->api->track($awb);
	}

	public function packing_slip($awb)
	{
		$res = $this->api->packing_slip($awb);
		$url = '';
		if (is_array($res['body'])) {
			$url = $res['body']['packages'][0]['pdf_download_link']
				?? ($res['body']['packages_found'][0]['pdf_download_link'] ?? '');
		}
		return array(
			'ok' => $res['ok'],
			'label_url' => $url,
			'raw' => $url ? '' : $res['raw'],
		);
	}

	public function tracking_url($awb)
	{
		return 'https://www.delhivery.com/track/package/' . rawurlencode($awb);
	}
}
