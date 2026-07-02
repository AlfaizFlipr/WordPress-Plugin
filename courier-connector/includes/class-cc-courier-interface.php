<?php

if (!defined('ABSPATH')) {
	exit;
}

interface CC_Courier_Interface
{

	public function key();

	public function label();

	public function is_configured();

	public function create_shipment(CC_Order $order);

	public function cancel_shipment($awb);

	public function track($awb);

	public function packing_slip($awb);

	public function tracking_url($awb);
}
