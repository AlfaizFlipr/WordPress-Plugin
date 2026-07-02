<?php

if (!defined('ABSPATH')) {
	exit;
}

class CC_Dtdc_Api
{

	const TOKEN_TRANSIENT = 'cc_dtdc_token';

	protected function base_url()
	{
		return 'https://dtdcapi.shipsy.io';
	}

	protected function customer_code()
	{
		return trim((string) CC_Settings::get('dtdc_customer_code'));
	}

	protected function username()
	{
		return trim((string) CC_Settings::get('dtdc_username'));
	}

	protected function password()
	{
		return trim((string) CC_Settings::get('dtdc_password'));
	}

	public function is_configured()
	{
		return '' !== $this->customer_code() && '' !== $this->username() && '' !== $this->password();
	}

	protected function token($force = false)
	{
		if (!$force) {
			$cached = get_transient(self::TOKEN_TRANSIENT);
			if ($cached) {
				return $cached;
			}
		}

		$url = add_query_arg(
			array(
				'username' => rawurlencode($this->username()),
				'password' => rawurlencode($this->password()),
			),
			$this->base_url() . '/api/dtdc/authenticate'
		);

		$response = wp_remote_get($url, array('timeout' => 30));
		if (is_wp_error($response)) {
			CC_Logger::error('dtdc', 'Authentication failed: ' . $response->get_error_message());
			return $response;
		}

		$code = wp_remote_retrieve_response_code($response);
		$token = trim(wp_remote_retrieve_body($response), "\" \n\r\t");

		if ($code < 200 || $code >= 300 || '' === $token) {
			CC_Logger::error('dtdc', 'Authentication rejected (HTTP ' . $code . ').', array('body' => $token));
			return new WP_Error('cc_dtdc_auth', 'Could not authenticate with DTDC. Check the customer code / username / password in Settings.');
		}

		set_transient(self::TOKEN_TRANSIENT, $token, 50 * MINUTE_IN_SECONDS);
		return $token;
	}

	protected function request($method, $url, $body = null)
	{
		$token = $this->token();
		if (is_wp_error($token)) {
			return array('ok' => false, 'code' => 0, 'body' => array('error' => $token->get_error_message()), 'raw' => '');
		}

		$do_request = function ($tok) use ($method, $url, $body) {
			return wp_remote_request(
				$url,
				array(
					'method' => $method,
					'timeout' => 45,
					'headers' => array(
						'api-key' => $tok,
						'Content-Type' => 'application/json',
						'Accept' => 'application/json',
					),
					'body' => null === $body ? null : wp_json_encode($body),
				)
			);
		};

		$response = $do_request($token);

		if (!is_wp_error($response) && 401 === (int) wp_remote_retrieve_response_code($response)) {
			$token = $this->token(true);
			$response = is_wp_error($token) ? $response : $do_request($token);
		}

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
			array('consignments' => array($consignment))
		);

		$awb = '';
		$remark = '';
		$row = array();

		if (is_array($res['body']) && !empty($res['body']['data'][0])) {
			$row = $res['body']['data'][0];
			$awb = $row['awb_no'] ?? ($row['reference_number'] ?? '');
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
			$this->base_url() . '/api/customer/integration/consignment/track',
			array(
				'trkType' => 'cnno',
				'strcnno' => $awb,
			)
		);

		$status = '';
		$location = '';
		$scans = array();
		$details = array();

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
			array('AWBNo' => array($awb))
		);
	}
}
