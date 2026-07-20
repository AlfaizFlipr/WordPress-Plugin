<?php

if (!defined('ABSPATH')) {
	exit;
}

class CC_Delhivery_API
{

	protected function base_url()
	{
		$env = CC_Settings::get('environment', 'production');
		return 'staging' === $env
			? 'https://staging-express.delhivery.com'
			: 'https://track.delhivery.com';
	}

	protected function token()
	{
		return trim((string) CC_Settings::get('api_token'));
	}

	protected function is_test()
	{
		return 'test' === CC_Settings::get('environment', 'production');
	}

	protected function headers($extra = array())
	{
		return array_merge(
			array(
				'Authorization' => 'Token ' . $this->token(),
				'Accept' => 'application/json',
			),
			$extra
		);
	}

	protected function request($method, $url, $args = array())
	{
		$args = wp_parse_args(
			$args,
			array(
				'method' => $method,
				'timeout' => 45,
				'headers' => $this->headers(),
			)
		);

		$response = wp_remote_request($url, $args);

		if (is_wp_error($response)) {
			CC_Logger::error('delhivery', $response->get_error_message(), array('url' => $url));
			return array(
				'ok' => false,
				'code' => 0,
				'body' => array('error' => $response->get_error_message()),
				'raw' => '',
			);
		}

		$code = wp_remote_retrieve_response_code($response);
		$raw = wp_remote_retrieve_body($response);
		$body = json_decode($raw, true);
		if (null === $body) {
			$body = $raw;
		}

		$ok = ($code >= 200 && $code < 300);
		CC_Logger::log('delhivery', $method . ' ' . $url . ' -> ' . $code, $body, $ok ? 'info' : 'error');

		return array(
			'ok' => $ok,
			'code' => $code,
			'body' => $body,
			'raw' => $raw,
		);
	}

	public function fetch_waybill($count = 1)
	{
		$url = add_query_arg(
			array('count' => absint($count)),
			$this->base_url() . '/waybill/api/bulk/json/'
		);
		$res = $this->request('GET', $url);

		if (!$res['ok']) {
			return new WP_Error('cc_waybill', 'Could not fetch waybill from Delhivery.', $res['body']);
		}

		$wb = is_string($res['body']) ? trim($res['body'], "\" \n\r\t") : '';
		if ('' === $wb && is_array($res['body']) && isset($res['body'][0])) {
			$wb = $res['body'][0];
		}
		if ('' === $wb) {
			return new WP_Error('cc_waybill', 'Empty waybill response.', $res['body']);
		}
		return $wb;
	}

	public function create_warehouse(array $data)
	{
		$payload = array(
			'name' => $data['name'],
			'email' => $data['email'] ?? get_option('admin_email'),
			'phone' => $data['phone'],
			'address' => $data['address'],
			'city' => $data['city'],
			'country' => $data['country'] ?? 'India',
			'pin' => $data['pincode'],
			'state' => $data['state'],
			'registered_name' => $data['name'],
			'return_address' => $data['address'],
			'return_pin' => $data['pincode'],
			'return_city' => $data['city'],
			'return_state' => $data['state'],
			'return_country' => $data['country'] ?? 'India',
		);

		$res = $this->request(
			'POST',
			$this->base_url() . '/api/backend/clientwarehouse/create/',
			array(
				'headers' => $this->headers(array('Content-Type' => 'application/json')),
				'body' => wp_json_encode($payload),
			)
		);

		// A warehouse with this name already exists → create/ rejects with 400.
		// Fall back to the edit endpoint so address changes still go through.
		if (!$res['ok']) {
			$edit_payload = array(
				'name' => $data['name'],
				'phone' => $data['phone'],
				'address' => $data['address'],
				'pin' => $data['pincode'],
				'registered_name' => $data['name'],
			);
			$edit = $this->request(
				'POST',
				$this->base_url() . '/api/backend/clientwarehouse/edit/',
				array(
					'headers' => $this->headers(array('Content-Type' => 'application/json')),
					'body' => wp_json_encode($edit_payload),
				)
			);
			if ($edit['ok']) {
				return $edit;
			}
		}

		return $res;
	}

	public function create_shipment(array $shipment, $pickup_location)
	{
		$data = array(
			'shipments' => array($shipment),
			'pickup_location' => array('name' => $pickup_location),
		);

		$body = 'format=json&data=' . rawurlencode(wp_json_encode($data));

		$res = $this->request(
			'POST',
			$this->base_url() . '/api/cmu/create.json',
			array(
				'headers' => $this->headers(array('Content-Type' => 'application/x-www-form-urlencoded')),
				'body' => $body,
			)
		);

		$awb = '';
		$remark = '';
		$pkg_ok = false;

		if (is_array($res['body'])) {
			if (!empty($res['body']['packages'][0])) {
				$pkg = $res['body']['packages'][0];
				$awb = $pkg['waybill'] ?? '';
				$remark = is_array($pkg['remarks'] ?? '') ? implode('; ', array_filter((array) $pkg['remarks'])) : (string) ($pkg['remarks'] ?? '');

				// Delhivery returns the pre-allocated waybill even when the package
				// FAILS (e.g. non-serviceable pincode) — an AWB alone is not success.
				// The package must not be marked Fail and must be serviceable.
				$status = strtolower((string) ($pkg['status'] ?? ''));
				$not_failed = !in_array($status, array('fail', 'failure'), true);
				$serviceable = !array_key_exists('serviceable', $pkg) || false !== $pkg['serviceable'];
				$pkg_ok = $not_failed && $serviceable;
			}
			if ('' === $remark && !empty($res['body']['rmk'])) {
				$remark = $res['body']['rmk'];
			}
		}

		return array(
			'ok' => ($res['ok'] && $pkg_ok && '' !== $awb),
			'awb' => $awb,
			'remark' => self::friendly_remark($remark),
			'body' => $res['body'],
		);
	}

	/**
	 * Translate Delhivery's raw rejection remarks into messages an admin can
	 * actually act on. The raw response stays in the Logs page.
	 */
	protected static function friendly_remark($remark)
	{
		$r = strtolower((string) $remark);
		if ('' === $r) {
			return $remark;
		}
		if (false !== strpos($r, 'insufficient balance')) {
			return 'Delhivery wallet balance is empty — recharge your Delhivery account (one.delhivery.com → Billing/Wallet), then push this order again.';
		}
		if (false !== strpos($r, 'non serviceable pincode') || false !== strpos($r, 'non-serviceable')) {
			if (preg_match('/(\d{6})/', (string) $remark, $m)) {
				return 'Delhivery does not deliver to pincode ' . $m[1] . ' — the customer\'s delivery pincode is not serviceable.';
			}
			return 'The customer\'s delivery pincode is not serviceable by Delhivery.';
		}
		if (false !== strpos($r, 'clientwarehouse') || false !== strpos($r, 'pickup location')) {
			return 'Pickup warehouse problem at Delhivery — ask the client to re-save their Pickup Address in the connector plugin, then push again. (' . $remark . ')';
		}
		return $remark;
	}

	public function track($waybill)
	{
		$url = add_query_arg(
			array('waybill' => $waybill),
			$this->base_url() . '/api/v1/packages/json/'
		);
		$res = $this->request('GET', $url);

		$status = '';
		$location = '';
		$scans = array();
		$data = array();

		if (is_array($res['body']) && !empty($res['body']['ShipmentData'][0]['Shipment'])) {
			$s = $res['body']['ShipmentData'][0]['Shipment'];

			$status = $s['Status']['Status'] ?? '';
			$location = $s['Status']['StatusLocation'] ?? '';

			if (!empty($s['Scans'])) {
				foreach ($s['Scans'] as $scan) {
					$d = $scan['ScanDetail'] ?? array();
					$scans[] = array(
						'status' => $d['Scan'] ?? ($d['Instructions'] ?? ''),
						'location' => $d['ScannedLocation'] ?? '',
						'time' => $d['ScanDateTime'] ?? '',
					);
				}
			}

			$pkg = !empty($s['Packages'][0]) ? $s['Packages'][0] : array();
			$consignee = $s['Consignee'] ?? array();
			$shipper = $s['Shipper'] ?? array();
			$cod = (float) ($pkg['CODAmount'] ?? 0);

			$data = array(
				'awb' => $s['AWB'] ?? $waybill,
				'pickup_date' => $s['PickUpDate'] ?? '',
				'status' => $status,
				'status_time' => $s['Status']['StatusDateTime'] ?? '',
				'origin' => $s['Origin'] ?? '',
				'destination' => $s['Destination'] ?? '',
				'payment_mode' => $cod > 0 ? 'COD' : 'Pre-Paid',
				'cod_amount' => $cod,
				'total' => (float) ($pkg['InvoiceAmount'] ?? $s['InvoiceAmount'] ?? 0),
				'package' => array(
					'name' => $pkg['ProductDesc'] ?? '',
					'qty' => (int) ($pkg['Quantity'] ?? 0),
					'weight' => (float) ($pkg['Weight'] ?? 0),
					'price' => (float) ($pkg['InvoiceAmount'] ?? 0),
					'width' => (float) ($pkg['Width'] ?? 0),
					'height' => (float) ($pkg['Height'] ?? 0),
					'length' => (float) ($pkg['Length'] ?? 0),
				),
				'consignee' => array(
					'name' => $consignee['Name'] ?? '',
					'address' => trim(($consignee['Address1'] ?? '') . ' ' . ($consignee['Address2'] ?? '')),
					'city' => $consignee['City'] ?? '',
					'state' => $consignee['State'] ?? '',
					'pincode' => $consignee['Pincode'] ?? '',
				),
				'shipper' => array(
					'name' => $shipper['Name'] ?? '',
					'city' => $shipper['OriginArea'] ?? '',
					'state' => $shipper['State'] ?? '',
					'pincode' => $shipper['Pincode'] ?? '',
				),
			);
		}

		return array(
			'ok' => $res['ok'],
			'status' => $status,
			'location' => $location,
			'scans' => $scans,
			'data' => $data,
			'body' => $res['body'],
		);
	}

	public function packing_slip($waybill)
	{
		$url = add_query_arg(
			array(
				'wbns' => $waybill,
				'pdf' => 'true',
			),
			$this->base_url() . '/api/p/packing_slip'
		);
		return $this->request('GET', $url);
	}

	public function cancel($waybill)
	{
		return $this->request(
			'POST',
			$this->base_url() . '/api/p/edit',
			array(
				'headers' => $this->headers(array('Content-Type' => 'application/json')),
				'body' => wp_json_encode(
					array(
						'waybill' => $waybill,
						'cancellation' => 'true',
					)
				),
			)
		);
	}

	public function create_pickup(array $data)
	{
		$payload = array(
			'pickup_location' => $data['pickup_location'],
			'pickup_date' => $data['date'],
			'pickup_time' => $data['time'] ?? '12:00:00',
			'expected_package_count' => absint($data['count'] ?? 1),
		);
		return $this->request(
			'POST',
			$this->base_url() . '/fm/request/new/',
			array(
				'headers' => $this->headers(array('Content-Type' => 'application/json')),
				'body' => wp_json_encode($payload),
			)
		);
	}
}
