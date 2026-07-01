<?php
/**
 * Admin AJAX handlers for dashboard actions.
 *
 * @package CourierConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CC_Ajax {

	public function register() {
		add_action( 'wp_ajax_cc_push_shipment', array( $this, 'push_shipment' ) );
		add_action( 'wp_ajax_cc_cancel_shipment', array( $this, 'cancel_shipment' ) );
		add_action( 'wp_ajax_cc_track', array( $this, 'track' ) );
		add_action( 'wp_ajax_cc_label', array( $this, 'label' ) );
		add_action( 'wp_ajax_cc_bulk_push', array( $this, 'bulk_push' ) );
	}

	/**
	 * Verify nonce and that the user is at least an admin or a client.
	 */
	protected function guard() {
		check_ajax_referer( 'cc_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) && ! CC_Clients::is_client() ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
		}
	}

	/**
	 * Verify the current user may act on this specific order (admins: any;
	 * clients: only orders from their own connected stores).
	 *
	 * @param int $order_id WC order id.
	 */
	protected function guard_order( $order_id ) {
		$this->guard();
		if ( ! CC_Clients::can_manage_order( $order_id ) ) {
			wp_send_json_error( array( 'message' => 'This order does not belong to your account.' ), 403 );
		}
	}

	public function push_shipment() {
		$order_id = (int) ( $_POST['order_id'] ?? 0 );
		$this->guard_order( $order_id );
		$result = CC_Shipment::push( $order_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success(
			array(
				'message'      => 'Shipment created. AWB: ' . $result['awb'],
				'awb'          => $result['awb'],
				'tracking_url' => $result['tracking_url'],
			)
		);
	}

	public function cancel_shipment() {
		$order_id = (int) ( $_POST['order_id'] ?? 0 );
		$this->guard_order( $order_id );
		$result = CC_Shipment::cancel( $order_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => 'Shipment cancelled.' ) );
	}

	public function track() {
		$order_id = (int) ( $_POST['order_id'] ?? 0 );
		$this->guard_order( $order_id );
		$order = new CC_Order( $order_id );
		if ( ! $order->valid() || ! $order->get_awb() ) {
			wp_send_json_error( array( 'message' => 'No AWB for this order.' ) );
			return;
		}
		$api      = new CC_Delhivery_API();
		$tracking = $api->track( $order->get_awb() );

		// Delhivery returns HTTP 200 even for "not yet scanned" errors.
		if ( '' === $tracking['status'] ) {
			$body = $tracking['body'] ?? array();
			$err  = is_array( $body ) ? ( $body['Error'] ?? '' ) : '';
			wp_send_json_error( array(
				'message' => $err
					? 'Delhivery: ' . $err
					: 'AWB ' . $order->get_awb() . ' is manifested but not yet scanned. Tracking activates within 30–60 minutes after courier pickup.',
			) );
			return;
		}

		// Persist the latest status.
		$internal = CC_Shipment::map_status( $tracking['status'] );
		if ( $internal ) {
			$order->set_courier_data( array( 'ship_status' => $internal, 'last_scan' => $tracking['status'] ) );
		}
		wp_send_json_success( array(
			'awb'      => $order->get_awb(),
			'status'   => $tracking['status'],
			'location' => $tracking['location'],
			'scans'    => $tracking['scans'],
			'data'     => $tracking['data'],
		) );
	}

	public function label() {
		$order_id = (int) ( $_POST['order_id'] ?? 0 );
		$this->guard_order( $order_id );
		$order = new CC_Order( $order_id );
		if ( ! $order->valid() || ! $order->get_awb() ) {
			wp_send_json_error( array( 'message' => 'No AWB for this order.' ) );
		}
		$api = new CC_Delhivery_API();
		$res = $api->packing_slip( $order->get_awb() );
		if ( ! $res['ok'] ) {
			wp_send_json_error( array( 'message' => 'Could not fetch label.' ) );
		}
		// Delhivery returns JSON with packages[].pdf_download_link in many accounts.
		$url = '';
		if ( is_array( $res['body'] ) ) {
			$url = $res['body']['packages'][0]['pdf_download_link']
				?? ( $res['body']['packages_found'][0]['pdf_download_link'] ?? '' );
		}
		wp_send_json_success(
			array(
				'label_url' => $url,
				'raw'       => $url ? '' : $res['raw'],
			)
		);
	}

	public function bulk_push() {
		$this->guard();
		$ids     = array_map( 'intval', (array) ( $_POST['order_ids'] ?? array() ) );
		$results = array( 'ok' => 0, 'failed' => 0, 'messages' => array() );
		foreach ( $ids as $id ) {
			if ( ! CC_Clients::can_manage_order( $id ) ) {
				$results['failed']++;
				$results['messages'][] = "#{$id}: not your order.";
				continue;
			}
			$r = CC_Shipment::push( $id );
			if ( is_wp_error( $r ) ) {
				$results['failed']++;
				$results['messages'][] = "#{$id}: " . $r->get_error_message();
			} else {
				$results['ok']++;
			}
		}
		wp_send_json_success( $results );
	}
}
