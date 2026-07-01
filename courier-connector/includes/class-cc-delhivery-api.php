<?php
/**
 * Delhivery API client.
 *
 * Implements the endpoints needed for shipping:
 *  - waybill (AWB) generation
 *  - warehouse / pickup location registration
 *  - shipment (CMU) creation
 *  - tracking
 *  - packing slip / shipping label
 *  - shipment cancellation
 *  - pickup request
 *
 * Docs: https://track.delhivery.com (One API). Authentication is a static
 * "Token <api_token>" header issued from the Delhivery seller panel.
 *
 * @package CourierConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CC_Delhivery_API {

	/**
	 * Base URL by environment.
	 *
	 * @return string
	 */
	protected function base_url() {
		$env = CC_Settings::get( 'environment', 'production' );
		return 'staging' === $env
			? 'https://staging-express.delhivery.com'
			: 'https://track.delhivery.com';
	}

	/**
	 * Auth token.
	 *
	 * @return string
	 */
	protected function token() {
		return trim( (string) CC_Settings::get( 'api_token' ) );
	}

	/**
	 * Test/Demo mode — simulate Delhivery locally (no account/token needed).
	 *
	 * @return bool
	 */
	protected function is_test() {
		return 'test' === CC_Settings::get( 'environment', 'production' );
	}

	/**
	 * Standard auth headers.
	 *
	 * @param array $extra Extra headers.
	 * @return array
	 */
	protected function headers( $extra = array() ) {
		return array_merge(
			array(
				'Authorization' => 'Token ' . $this->token(),
				'Accept'        => 'application/json',
			),
			$extra
		);
	}

	/**
	 * Perform a request and normalise the response.
	 *
	 * @param string $method GET|POST.
	 * @param string $url    Full URL.
	 * @param array  $args   wp_remote_request args.
	 * @return array {ok:bool, code:int, body:mixed, raw:string}
	 */
	protected function request( $method, $url, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'method'  => $method,
				'timeout' => 45,
				'headers' => $this->headers(),
			)
		);

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			CC_Logger::error( 'delhivery', $response->get_error_message(), array( 'url' => $url ) );
			return array(
				'ok'   => false,
				'code' => 0,
				'body' => array( 'error' => $response->get_error_message() ),
				'raw'  => '',
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$body = json_decode( $raw, true );
		if ( null === $body ) {
			$body = $raw;
		}

		$ok = ( $code >= 200 && $code < 300 );
		CC_Logger::log( 'delhivery', $method . ' ' . $url . ' -> ' . $code, $body, $ok ? 'info' : 'error' );

		return array(
			'ok'   => $ok,
			'code' => $code,
			'body' => $body,
			'raw'  => $raw,
		);
	}

	/**
	 * Fetch a fresh waybill (AWB) number.
	 *
	 * @param int $count How many waybills.
	 * @return string|WP_Error Comma separated waybills or error.
	 */
	public function fetch_waybill( $count = 1 ) {
		$url = add_query_arg(
			array( 'count' => absint( $count ) ),
			$this->base_url() . '/waybill/api/bulk/json/'
		);
		$res = $this->request( 'GET', $url );

		if ( ! $res['ok'] ) {
			return new WP_Error( 'cc_waybill', 'Could not fetch waybill from Delhivery.', $res['body'] );
		}
		// Response is the waybill string (possibly JSON-encoded string).
		$wb = is_string( $res['body'] ) ? trim( $res['body'], "\" \n\r\t" ) : '';
		if ( '' === $wb && is_array( $res['body'] ) && isset( $res['body'][0] ) ) {
			$wb = $res['body'][0];
		}
		if ( '' === $wb ) {
			return new WP_Error( 'cc_waybill', 'Empty waybill response.', $res['body'] );
		}
		return $wb;
	}

	/**
	 * Register a pickup / warehouse location.
	 *
	 * @param array $data Warehouse fields.
	 * @return array Normalised response.
	 */
	public function create_warehouse( array $data ) {
		$payload = array(
			'name'        => $data['name'],
			'email'       => $data['email'] ?? get_option( 'admin_email' ),
			'phone'       => $data['phone'],
			'address'     => $data['address'],
			'city'        => $data['city'],
			'country'     => $data['country'] ?? 'India',
			'pin'         => $data['pincode'],
			'state'       => $data['state'],
			'registered_name' => $data['name'],
			'return_address'  => $data['address'],
			'return_pin'      => $data['pincode'],
			'return_city'     => $data['city'],
			'return_state'    => $data['state'],
			'return_country'  => $data['country'] ?? 'India',
		);

		return $this->request(
			'POST',
			$this->base_url() . '/api/backend/clientwarehouse/create/',
			array(
				'headers' => $this->headers( array( 'Content-Type' => 'application/json' ) ),
				'body'    => wp_json_encode( $payload ),
			)
		);
	}

	/**
	 * Create a shipment (CMU create.json).
	 *
	 * @param array  $shipment       Single shipment array (Delhivery format).
	 * @param string $pickup_location Registered warehouse name.
	 * @return array {ok, awb, body}
	 */
	public function create_shipment( array $shipment, $pickup_location ) {
		$data = array(
			'shipments'       => array( $shipment ),
			'pickup_location' => array( 'name' => $pickup_location ),
		);

		// Delhivery CMU expects form-encoded: format=json&data=<json>.
		$body = 'format=json&data=' . rawurlencode( wp_json_encode( $data ) );

		$res = $this->request(
			'POST',
			$this->base_url() . '/api/cmu/create.json',
			array(
				'headers' => $this->headers( array( 'Content-Type' => 'application/x-www-form-urlencoded' ) ),
				'body'    => $body,
			)
		);

		$awb         = '';
		$remark      = '';
		$success_all = false;

		if ( is_array( $res['body'] ) ) {
			$success_all = ! empty( $res['body']['success'] );
			if ( ! empty( $res['body']['packages'][0] ) ) {
				$pkg    = $res['body']['packages'][0];
				$awb    = $pkg['waybill'] ?? '';
				$remark = is_array( $pkg['remarks'] ?? '' ) ? implode( '; ', $pkg['remarks'] ) : (string) ( $pkg['remarks'] ?? '' );
				if ( empty( $pkg['status'] ) || in_array( strtolower( (string) ( $pkg['status'] ?? '' ) ), array( 'fail', 'failure' ), true ) ) {
					$success_all = $success_all && ! empty( $awb );
				}
			}
			if ( '' === $awb && ! empty( $res['body']['rmk'] ) ) {
				$remark = $res['body']['rmk'];
			}
		}

		return array(
			'ok'     => ( $res['ok'] && '' !== $awb ),
			'awb'    => $awb,
			'remark' => $remark,
			'body'   => $res['body'],
		);
	}

	/**
	 * Track a shipment by waybill.
	 *
	 * @param string $waybill AWB number.
	 * @return array Normalised tracking {ok, status, location, scans, data, body}.
	 */
	public function track( $waybill ) {
		$url = add_query_arg(
			array( 'waybill' => $waybill ),
			$this->base_url() . '/api/v1/packages/json/'
		);
		$res = $this->request( 'GET', $url );

		$status   = '';
		$location = '';
		$scans    = array();
		$data     = array();

		if ( is_array( $res['body'] ) && ! empty( $res['body']['ShipmentData'][0]['Shipment'] ) ) {
			$s = $res['body']['ShipmentData'][0]['Shipment'];

			$status   = $s['Status']['Status'] ?? '';
			$location = $s['Status']['StatusLocation'] ?? '';

			// Scan timeline.
			if ( ! empty( $s['Scans'] ) ) {
				foreach ( $s['Scans'] as $scan ) {
					$d       = $scan['ScanDetail'] ?? array();
					$scans[] = array(
						'status'   => $d['Scan'] ?? ( $d['Instructions'] ?? '' ),
						'location' => $d['ScannedLocation'] ?? '',
						'time'     => $d['ScanDateTime'] ?? '',
					);
				}
			}

			// Full rich data for the tracking page.
			$pkg       = ! empty( $s['Packages'][0] ) ? $s['Packages'][0] : array();
			$consignee = $s['Consignee'] ?? array();
			$shipper   = $s['Shipper']   ?? array();
			$cod       = (float) ( $pkg['CODAmount'] ?? 0 );

			$data = array(
				'awb'          => $s['AWB'] ?? $waybill,
				'pickup_date'  => $s['PickUpDate'] ?? '',
				'status'       => $status,
				'status_time'  => $s['Status']['StatusDateTime'] ?? '',
				'origin'       => $s['Origin'] ?? '',
				'destination'  => $s['Destination'] ?? '',
				'payment_mode' => $cod > 0 ? 'COD' : 'Pre-Paid',
				'cod_amount'   => $cod,
				'total'        => (float) ( $pkg['InvoiceAmount'] ?? $s['InvoiceAmount'] ?? 0 ),
				'package'      => array(
					'name'   => $pkg['ProductDesc'] ?? '',
					'qty'    => (int) ( $pkg['Quantity'] ?? 0 ),
					'weight' => (float) ( $pkg['Weight'] ?? 0 ),
					'price'  => (float) ( $pkg['InvoiceAmount'] ?? 0 ),
					'width'  => (float) ( $pkg['Width']  ?? 0 ),
					'height' => (float) ( $pkg['Height'] ?? 0 ),
					'length' => (float) ( $pkg['Length'] ?? 0 ),
				),
				'consignee'    => array(
					'name'    => $consignee['Name']    ?? '',
					'address' => trim( ( $consignee['Address1'] ?? '' ) . ' ' . ( $consignee['Address2'] ?? '' ) ),
					'city'    => $consignee['City']    ?? '',
					'state'   => $consignee['State']   ?? '',
					'pincode' => $consignee['Pincode'] ?? '',
				),
				'shipper'      => array(
					'name'    => $shipper['Name']       ?? '',
					'city'    => $shipper['OriginArea'] ?? '',
					'state'   => $shipper['State']      ?? '',
					'pincode' => $shipper['Pincode']    ?? '',
				),
			);
		}

		return array(
			'ok'       => $res['ok'],
			'status'   => $status,
			'location' => $location,
			'scans'    => $scans,
			'data'     => $data,
			'body'     => $res['body'],
		);
	}

	/**
	 * Get the packing slip / shipping label URL or HTML.
	 *
	 * @param string $waybill AWB number.
	 * @return array Normalised response.
	 */
	public function packing_slip( $waybill ) {
		$url = add_query_arg(
			array(
				'wbns' => $waybill,
				'pdf'  => 'true',
			),
			$this->base_url() . '/api/p/packing_slip'
		);
		return $this->request( 'GET', $url );
	}

	/**
	 * Cancel a shipment.
	 *
	 * @param string $waybill AWB number.
	 * @return array Normalised response.
	 */
	public function cancel( $waybill ) {
		return $this->request(
			'POST',
			$this->base_url() . '/api/p/edit',
			array(
				'headers' => $this->headers( array( 'Content-Type' => 'application/json' ) ),
				'body'    => wp_json_encode(
					array(
						'waybill' => $waybill,
						'cancellation' => 'true',
					)
				),
			)
		);
	}

	/**
	 * Raise a pickup request.
	 *
	 * @param array $data {pickup_location, date, time, count}.
	 * @return array Normalised response.
	 */
	public function create_pickup( array $data ) {
		$payload = array(
			'pickup_location' => $data['pickup_location'],
			'pickup_date'     => $data['date'],
			'pickup_time'     => $data['time'] ?? '12:00:00',
			'expected_package_count' => absint( $data['count'] ?? 1 ),
		);
		return $this->request(
			'POST',
			$this->base_url() . '/fm/request/new/',
			array(
				'headers' => $this->headers( array( 'Content-Type' => 'application/json' ) ),
				'body'    => wp_json_encode( $payload ),
			)
		);
	}
}
