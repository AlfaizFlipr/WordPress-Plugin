<?php

if (!defined('ABSPATH')) {
	exit;
}

class CC_Order
{

	const SHIP_STATUSES = array(
		'pending' => 'Pending Shipment',
		'booked' => 'Booked',
		'in-transit' => 'In Transit',
		'ofd' => 'Out for Delivery',
		'delivered' => 'Delivered',
		'rto' => 'RTO',
		'cancelled' => 'Cancelled',
	);

	protected $order;

	public function __construct($order)
	{
		$this->order = $order instanceof WC_Order ? $order : wc_get_order($order);
	}

	public function valid()
	{
		return $this->order instanceof WC_Order;
	}

	public function wc()
	{
		return $this->order;
	}

	public function id()
	{
		return $this->order->get_id();
	}

	public function get_awb()
	{
		return (string) $this->order->get_meta('_cc_awb');
	}

	public function get_ship_status()
	{
		$s = (string) $this->order->get_meta('_cc_ship_status');
		return $s ? $s : 'pending';
	}

	public function get_ship_status_label()
	{
		$s = $this->get_ship_status();
		return self::SHIP_STATUSES[$s] ?? ucfirst($s);
	}

	public function get_tracking_url()
	{
		$url = (string) $this->order->get_meta('_cc_tracking_url');
		if ($url) {
			return $url;
		}
		$awb = $this->get_awb();
		return $awb ? 'https://www.delhivery.com/track/package/' . rawurlencode($awb) : '';
	}

	public function get_last_scan()
	{
		return (string) $this->order->get_meta('_cc_last_scan');
	}

	public function set_courier_data(array $data)
	{
		$map = array(
			'awb' => '_cc_awb',
			'courier' => '_cc_courier',
			'ship_status' => '_cc_ship_status',
			'tracking_url' => '_cc_tracking_url',
			'last_scan' => '_cc_last_scan',
			'label_url' => '_cc_label_url',
		);
		foreach ($map as $key => $meta) {
			if (array_key_exists($key, $data)) {
				$this->order->update_meta_data($meta, $data[$key]);
			}
		}
		$this->order->save();
	}

	public function add_note($note)
	{
		$this->order->add_order_note('[Courier] ' . $note);
	}

	/**
	 * Pickup address + parcel profile for this order's client store.
	 * Every client sets its own pickup point in the connector plugin;
	 * Naya Setu picks the parcel up there and ships to the customer.
	 */
	public function pickup()
	{
		$store_id = (int) $this->order->get_meta('_cc_source_store');
		return CC_Website::pickup($store_id);
	}

	public function source_store_id()
	{
		return (int) $this->order->get_meta('_cc_source_store');
	}

	/**
	 * The registered Delhivery warehouse name for this order's pickup —
	 * unique per client store (see CC_Website::warehouse_name()).
	 */
	public function pickup_location_name()
	{
		$store_id = $this->source_store_id();
		if ($store_id) {
			return CC_Website::warehouse_name($store_id);
		}
		return (string) CC_Settings::get('pickup_name');
	}

	public function is_cod()
	{
		return 'cod' === $this->order->get_payment_method();
	}

	public function cod_amount()
	{
		return $this->is_cod() ? (float) $this->order->get_total() : 0;
	}

	public function to_tracking_summary()
	{
		$o = $this->order;

		$products = array();
		$quantity = 0;
		foreach ($o->get_items() as $item) {
			$products[] = $item->get_name();
			$quantity += (int) $item->get_quantity();
		}
		if (empty($products)) {
			$raw = $o->get_meta('_cc_order_items_json');
			if ($raw) {
				$raw_items = json_decode($raw, true);
				if (is_array($raw_items)) {
					foreach ($raw_items as $ri) {
						$products[] = sanitize_text_field($ri['name'] ?? 'Item');
						$quantity += max(1, (int) ($ri['qty'] ?? 1));
					}
				}
			}
		}
		if (empty($products)) {
			$products = array('Shipment');
		}
		$quantity = max(1, $quantity);

		$name = trim($o->get_shipping_first_name() . ' ' . $o->get_shipping_last_name());
		if ('' === $name) {
			$name = trim($o->get_billing_first_name() . ' ' . $o->get_billing_last_name());
		}
		$address = trim($o->get_shipping_address_1() . ' ' . $o->get_shipping_address_2());
		if ('' === $address) {
			$address = trim($o->get_billing_address_1() . ' ' . $o->get_billing_address_2());
		}

		$pickup = $this->pickup();

		return array(
			'name' => $name,
			'add' => $address,
			'city' => $o->get_shipping_city() ?: $o->get_billing_city(),
			'state' => $o->get_shipping_state() ?: $o->get_billing_state(),
			'pin' => $o->get_shipping_postcode() ?: $o->get_billing_postcode(),
			'products_desc' => implode(', ', $products),
			'quantity' => $quantity,
			'weight' => max(1, (int) round((float) $pickup['default_weight'] * 1000)),
			'shipment_length' => (float) $pickup['default_length'],
			'shipment_width' => (float) $pickup['default_breadth'],
			'shipment_height' => (float) $pickup['default_height'],
			'payment_mode' => $this->is_cod() ? 'COD' : 'Prepaid',
			'total_amount' => (float) $o->get_total(),
			'seller_name' => $pickup['pickup_name'],
			'seller_city' => $pickup['pickup_city'],
			'seller_state' => $pickup['pickup_state'],
			'seller_pin' => $pickup['pickup_pincode'],
		);
	}

	public function to_delhivery_shipment()
	{
		$o = $this->order;

		$products = array();
		$quantity = 0;
		foreach ($o->get_items() as $item) {
			$products[] = $item->get_name();
			$quantity += (int) $item->get_quantity();
		}

		if (empty($products)) {
			$raw = $o->get_meta('_cc_order_items_json');
			if ($raw) {
				$raw_items = json_decode($raw, true);
				if (is_array($raw_items)) {
					foreach ($raw_items as $ri) {
						$products[] = sanitize_text_field($ri['name'] ?? 'Item');
						$quantity += max(1, (int) ($ri['qty'] ?? 1));
					}
				}
			}
		}

		if (empty($products)) {
			$products = array('Shipment');
		}
		$quantity = max(1, $quantity);

		$pickup = $this->pickup();
		$weight_grams = max(1, (int) round((float) $pickup['default_weight'] * 1000));

		$name = trim($o->get_shipping_first_name() . ' ' . $o->get_shipping_last_name());
		if ('' === $name) {
			$name = trim($o->get_billing_first_name() . ' ' . $o->get_billing_last_name());
		}

		$address = trim($o->get_shipping_address_1() . ' ' . $o->get_shipping_address_2());
		if ('' === $address) {
			$address = trim($o->get_billing_address_1() . ' ' . $o->get_billing_address_2());
		}

		$pickup_name = $pickup['pickup_name'];
		$pickup_address = $pickup['pickup_address'];
		$pickup_city = $pickup['pickup_city'];
		$pickup_state = $pickup['pickup_state'];
		$pickup_pincode = $pickup['pickup_pincode'];
		$pickup_phone = $pickup['pickup_phone'];

		return array(
			'name' => $name,
			'add' => $address,
			'city' => $o->get_shipping_city() ?: $o->get_billing_city(),
			'state' => $o->get_shipping_state() ?: $o->get_billing_state(),
			'country' => 'India',
			'pin' => $o->get_shipping_postcode() ?: $o->get_billing_postcode(),
			'phone' => $o->get_billing_phone(),
			'order' => (string) $o->get_order_number(),
			'payment_mode' => $this->is_cod() ? 'COD' : 'Prepaid',
			'cod_amount' => $this->cod_amount(),
			'total_amount' => (float) $o->get_total(),
			'products_desc' => implode(', ', $products),
			'quantity' => $quantity,
			'weight' => $weight_grams,
			'shipment_width' => (float) $pickup['default_breadth'],
			'shipment_height' => (float) $pickup['default_height'],
			'shipment_length' => (float) $pickup['default_length'],
			'seller_name' => $pickup_name,
			'seller_add' => $pickup_address,
			'seller_pin' => $pickup_pincode,
			'seller_city' => $pickup_city,
			'seller_state' => $pickup_state,
			'return_name' => $pickup_name,
			'return_add' => $pickup_address,
			'return_pin' => $pickup_pincode,
			'return_city' => $pickup_city,
			'return_state' => $pickup_state,
			'return_country' => 'India',
			'return_phone' => $pickup_phone,
		);
	}

	public function to_dtdc_shipment()
	{
		$o = $this->order;

		$products = array();
		$quantity = 0;
		foreach ($o->get_items() as $item) {
			$products[] = $item->get_name();
			$quantity += (int) $item->get_quantity();
		}
		if (empty($products)) {
			$raw = $o->get_meta('_cc_order_items_json');
			if ($raw) {
				$raw_items = json_decode($raw, true);
				if (is_array($raw_items)) {
					foreach ($raw_items as $ri) {
						$products[] = sanitize_text_field($ri['name'] ?? 'Item');
						$quantity += max(1, (int) ($ri['qty'] ?? 1));
					}
				}
			}
		}
		if (empty($products)) {
			$products = array('Shipment');
		}
		$quantity = max(1, $quantity);

		$name = trim($o->get_shipping_first_name() . ' ' . $o->get_shipping_last_name());
		if ('' === $name) {
			$name = trim($o->get_billing_first_name() . ' ' . $o->get_billing_last_name());
		}
		$address = trim($o->get_shipping_address_1() . ' ' . $o->get_shipping_address_2());
		if ('' === $address) {
			$address = trim($o->get_billing_address_1() . ' ' . $o->get_billing_address_2());
		}

		$pickup = $this->pickup();
		$pickup_name = $pickup['pickup_name'];
		$pickup_address = $pickup['pickup_address'];
		$pickup_city = $pickup['pickup_city'];
		$pickup_state = $pickup['pickup_state'];
		$pickup_pincode = $pickup['pickup_pincode'];
		$pickup_phone = $pickup['pickup_phone'];

		$is_cod = $this->is_cod();

		return array(
			'customer_reference_number' => (string) $o->get_order_number(),
			'service_type_id' => CC_Settings::get('dtdc_service_type_id', 'GROUND EXPRESS'),
			'load_type' => 'NON-DOCUMENT',
			'description' => implode(', ', $products),
			'cod_favor_of' => $is_cod ? $pickup_name : '',
			'cod_amount' => $this->cod_amount(),
			'cod_collection_mode' => $is_cod ? 'cash' : '',
			'consignment_type' => CC_Settings::get('dtdc_consignment_type', 'Reverse'),
			'dimension_unit' => 'cm',
			'length' => (float) $pickup['default_length'],
			'width' => (float) $pickup['default_breadth'],
			'height' => (float) $pickup['default_height'],
			'weight_unit' => 'kg',
			'weight' => (float) $pickup['default_weight'],
			'num_pieces' => $quantity,
			'declared_value' => (float) $o->get_total(),
			'commodity_id' => sanitize_title($products[0]) ?: 'general-goods',
			'origin_details' => array(
				'name' => $pickup_name,
				'phone' => $pickup_phone,
				'address_line_1' => $pickup_address,
				'pincode' => $pickup_pincode,
				'city' => $pickup_city,
				'state' => $pickup_state,
			),
			'destination_details' => array(
				'name' => $name,
				'phone' => $o->get_billing_phone(),
				'alternate_phone' => $o->get_billing_phone(),
				'address_line_1' => $address,
				'pincode' => $o->get_shipping_postcode() ?: $o->get_billing_postcode(),
				'city' => $o->get_shipping_city() ?: $o->get_billing_city(),
				'state' => $o->get_shipping_state() ?: $o->get_billing_state(),
			),
		);
	}
}
