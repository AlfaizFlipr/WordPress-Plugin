<?php

if (!defined('ABSPATH')) {
	exit;
}

class CC_Dtdc_Api
{

	protected function base_url()
	{
		return 'https://pxapi.dtdc.in';
	}

	protected function tracking_url()
	{
		return 'https://blktracksvc.dtdc.com/dtdc-api/rest/JSONCnTrk/getTrackDetails';
	}

	protected function api_key()
	{
		return trim((string) CC_Settings::get('dtdc_api_key'));
	}

	protected function tracking_token()
	{
		$token = trim((string) CC_Settings::get('dtdc_tracking_token'));
		return '' !== $token ? $token : $this->api_key();
	}

	protected function customer_code()
	{
		return trim((string) CC_Settings::get('dtdc_customer_code'));
	}

	public function is_configured()
	{
		return '' !== $this->api_key() && '' !== $this->customer_code();
	}

	protected function request($method, $url, $body = null, array $headers = array())
	{
		$response = wp_remote_request(
			$url,
			array(
				'method' => $method,
				'timeout' => 45,
				'headers' => array_merge(
					array(
						'Content-Type' => 'application/json',
						'Accept' => 'application/json',
					),
					$headers
				),
				'body' => null === $body ? null : wp_json_encode($body),
			)
		);

		if (is_wp_error($response)) {
			CC_Logger::error('dtdc', $response->get_error_message(), array('url' => $url));
			return array('ok' => false, 'code' => 0, 'body' => array('error' => $response->get_error_message()), 'raw' => '');
		}

		$code = wp_remote_retrieve_response_code($response);
		$raw = wp_remote_retrieve_body($response);
		$json = json_decode($raw, true);
		$body_out = null === $json ? $raw : $json;

		$ok = ($code >= 200 && $code < 300);
		CC_Logger::log('dtdc', $method . ' ' . $url . ' -> ' . $code, $body_out, $ok ? 'info' : 'error');

		return array('ok' => $ok, 'code' => $code, 'body' => $body_out, 'raw' => $raw);
	}

	public function create_consignment(array $consignment)
	{
		$consignment['customer_code'] = $this->customer_code();

		$res = $this->request(
			'POST',
			$this->base_url() . '/api/customer/integration/consignment/softdata',
			array('consignments' => array($consignment)),
			array('api-key' => $this->api_key())
		);

		$awb = '';
		$remark = '';
		$row = array();

		if (is_array($res['body']) && !empty($res['body']['data'][0])) {
			$row = $res['body']['data'][0];
			$awb = $row['reference_number'] ?? ($row['awb_no'] ?? '');
			$remark = $row['message'] ?? '';
		} elseif (is_array($res['body']) && !empty($res['body']['message'])) {
			$remark = $res['body']['message'];
		}

		return array(
			'ok' => ($res['ok'] && '' !== $awb && false !== ($row['success'] ?? true)),
			'awb' => $awb,
			'remark' => $remark,
			'body' => $res['body'],
		);
	}

	public function track($awb)
	{
		$res = $this->request(
			'POST',
			$this->tracking_url(),
			array(
				'TrkType' => 'cnno',
				'strcnno' => $awb,
				'addtnlDtl' => 'Y',
			),
			array('x-access-token' => $this->tracking_token())
		);

		$status = '';
		$location = '';
		$scans = array();

		if (is_array($res['body']) && !empty($res['body']['trackDetails'])) {
			$details = $res['body']['trackDetails'];
			foreach ($details as $d) {
				$scans[] = array(
					'status' => $d['strAction'] ?? ($d['scanBrief'] ?? ''),
					'location' => $d['strOrigin'] ?? ($d['sPincode'] ?? ''),
					'time' => trim(($d['strActionDate'] ?? '') . ' ' . ($d['strActionTime'] ?? '')),
				);
			}
			$last = end($details);
			$status = $last['strAction'] ?? ($last['scanBrief'] ?? '');
			$location = $last['strOrigin'] ?? ($last['sPincode'] ?? '');
		}

		return array(
			'ok' => $res['ok'],
			'status' => $status,
			'location' => $location,
			'scans' => $scans,
			'data' => array(),
			'body' => $res['body'],
		);
	}

	public function cancel($awb)
	{
		return $this->request(
			'POST',
			$this->base_url() . '/api/customer/integration/consignment/cancel',
			array(
				'AWBNo' => array($awb),
				'customerCode' => $this->customer_code(),
			),
			array('api-key' => $this->api_key())
		);
	}

	public function label($reference_number)
	{
		$url = add_query_arg(
			array(
				'reference_number' => $reference_number,
				'label_code' => 'SHIP_LABEL_4X6',
				'label_format' => 'pdf',
			),
			$this->base_url() . '/api/customer/integration/consignment/shippinglabel/stream'
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 45,
				'headers' => array(
					'api-key' => $this->api_key(),
					'Accept' => 'application/pdf',
				),
			)
		);

		if (is_wp_error($response)) {
			CC_Logger::error('dtdc', $response->get_error_message(), array('url' => $url));
			return array('ok' => false, 'url' => '', 'raw' => $response->get_error_message());
		}

		$code = wp_remote_retrieve_response_code($response);
		$body = wp_remote_retrieve_body($response);
		$is_pdf = is_string($body) && '%PDF-' === substr($body, 0, 5);

		if ($code < 200 || $code >= 300 || !$is_pdf) {
			CC_Logger::error('dtdc', 'Label download failed (HTTP ' . $code . ').', array('body' => is_string($body) ? substr($body, 0, 500) : $body));
			return array('ok' => false, 'url' => '', 'raw' => is_string($body) ? $body : '');
		}

		$upload_dir = wp_upload_dir();
		$dir = trailingslashit($upload_dir['basedir']) . 'cc-labels';
		if (!file_exists($dir)) {
			wp_mkdir_p($dir);
		}

		$filename = 'dtdc-' . preg_replace('/[^A-Za-z0-9_-]/', '', $reference_number) . '.pdf';
		$path = trailingslashit($dir) . $filename;
		file_put_contents($path, $body);

		$url_out = trailingslashit($upload_dir['baseurl']) . 'cc-labels/' . $filename;

		CC_Logger::log('dtdc', 'Label saved for ' . $reference_number, array('url' => $url_out), 'info');

		return array('ok' => true, 'url' => $url_out, 'raw' => '');
	}
}
