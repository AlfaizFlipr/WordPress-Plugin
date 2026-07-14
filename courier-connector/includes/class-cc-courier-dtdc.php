<?php

if (!defined('ABSPATH')) {
	exit;
}

class CC_Courier_DTDC implements CC_Courier_Interface
{

	protected $api;

	public function __construct()
	{
		$this->api = new CC_Dtdc_Api();
	}

	public function key()
	{
		return 'dtdc';
	}

	public function label()
	{
		return 'DTDC';
	}

	public function is_configured()
	{
		return $this->api->is_configured();
	}

	public function create_shipment(CC_Order $order)
	{
		return $this->api->create_consignment($order->to_dtdc_shipment());
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
		$res = $this->api->label($awb);
		return array(
			'ok' => $res['ok'],
			'label_url' => $res['ok'] ? $res['url'] : '',
			'raw' => $res['ok'] ? '' : $res['raw'],
		);
	}

	public function tracking_url($awb)
	{
		return 'https://www.dtdc.in/tracking/tracking_results.asp?strCnno=' . rawurlencode($awb);
	}
}
