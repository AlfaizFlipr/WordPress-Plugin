<?php
/**
 * Shipment service — orchestrates pushing an order to Delhivery and
 * syncing the resulting AWB back to the source store (if external).
 *
 * @package CourierConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CC_Shipment {

	/**
	 * Push a WooCommerce order to Delhivery: register waybill (implicit via
	 * create.json), create shipment, save AWB, reverse-sync.
	 *
	 * @param int $order_id WC order id.
	 * @return array|WP_Error {awb, tracking_url}
	 */
	public static function push( $order_id ) {
		if ( ! CC_Settings::is_configured() ) {
			return new WP_Error( 'cc_no_token', 'Delhivery API token is not configured. Add it under Courier → Settings.' );
		}

		$order = new CC_Order( $order_id );
		if ( ! $order->valid() ) {
			return new WP_Error( 'cc_no_order', 'Order not found.' );
		}

		if ( $order->get_awb() ) {
			return new WP_Error( 'cc_already', 'Shipment already created. AWB: ' . $order->get_awb() );
		}

		$pickup = CC_Settings::get( 'pickup_name' );
		if ( '' === trim( (string) $pickup ) ) {
			return new WP_Error( 'cc_no_pickup', 'Pickup location is not set. Configure it under Courier → Settings.' );
		}

		$api      = new CC_Delhivery_API();
		$shipment = $order->to_delhivery_shipment();

		// Optionally pre-allocate a waybill so we control the AWB number.
		$waybill = $api->fetch_waybill( 1 );
		if ( ! is_wp_error( $waybill ) && $waybill ) {
			$shipment['waybill'] = $waybill;
		}

		$result = $api->create_shipment( $shipment, $pickup );

		if ( ! $result['ok'] || empty( $result['awb'] ) ) {
			$msg = $result['remark'] ? $result['remark'] : 'Delhivery rejected the shipment.';
			$order->add_note( 'Shipment creation failed: ' . $msg );
			return new WP_Error( 'cc_create_failed', $msg, $result['body'] );
		}

		$awb          = $result['awb'];
		$tracking_url = 'https://www.delhivery.com/track/package/' . rawurlencode( $awb );

		$order->set_courier_data(
			array(
				'awb'          => $awb,
				'courier'      => 'Delhivery',
				'ship_status'  => 'booked',
				'tracking_url' => $tracking_url,
				'last_scan'    => 'Manifested',
			)
		);
		$order->add_note( sprintf( 'Shipment created with Delhivery. AWB: %s', $awb ) );

		// Reverse sync to external store if this order originated from one.
		self::reverse_sync( $order, $awb, $tracking_url, 'booked' );

		return array(
			'awb'          => $awb,
			'tracking_url' => $tracking_url,
		);
	}

	/**
	 * Cancel a shipment.
	 *
	 * @param int $order_id WC order id.
	 * @return true|WP_Error
	 */
	public static function cancel( $order_id ) {
		$order = new CC_Order( $order_id );
		if ( ! $order->valid() || ! $order->get_awb() ) {
			return new WP_Error( 'cc_no_awb', 'No shipment to cancel.' );
		}
		$api = new CC_Delhivery_API();
		$res = $api->cancel( $order->get_awb() );
		if ( ! $res['ok'] ) {
			return new WP_Error( 'cc_cancel_failed', 'Delhivery cancellation failed.', $res['body'] );
		}
		$order->set_courier_data( array( 'ship_status' => 'cancelled', 'last_scan' => 'Cancelled' ) );
		$order->add_note( 'Shipment cancelled with Delhivery.' );
		self::reverse_sync( $order, $order->get_awb(), $order->get_tracking_url(), 'cancelled' );
		return true;
	}

	/**
	 * Map a Delhivery status string to internal shipment status.
	 *
	 * @param string $status Raw Delhivery status.
	 * @return string
	 */
	public static function map_status( $status ) {
		$s = strtolower( trim( (string) $status ) );
		if ( '' === $s ) {
			return '';
		}
		if ( false !== strpos( $s, 'delivered' ) ) {
			return 'delivered';
		}
		if ( false !== strpos( $s, 'rto' ) || false !== strpos( $s, 'returned' ) ) {
			return 'rto';
		}
		if ( false !== strpos( $s, 'out for delivery' ) || false !== strpos( $s, 'dispatched' ) ) {
			return 'ofd';
		}
		if ( false !== strpos( $s, 'transit' ) || false !== strpos( $s, 'in-transit' ) ) {
			return 'in-transit';
		}
		if ( false !== strpos( $s, 'cancel' ) ) {
			return 'cancelled';
		}
		if ( false !== strpos( $s, 'manifest' ) || false !== strpos( $s, 'pending' ) ) {
			return 'booked';
		}
		return 'in-transit';
	}

	/**
	 * Send AWB/tracking back to the originating external store via its callback.
	 *
	 * @param CC_Order $order        Order wrapper.
	 * @param string   $awb          AWB number.
	 * @param string   $tracking_url Tracking URL.
	 * @param string   $status       Internal status.
	 */
	public static function reverse_sync( CC_Order $order, $awb, $tracking_url, $status ) {
		$store_id = (int) $order->wc()->get_meta( '_cc_source_store' );
		if ( ! $store_id ) {
			return; // Local order, nothing to push back.
		}
		$store = CC_Website::get( $store_id );
		if ( ! $store || empty( $store->callback_url ) ) {
			return;
		}

		$external_id = $order->wc()->get_meta( '_cc_external_order_id' );

		$payload = array(
			'external_order_id' => $external_id ? $external_id : $order->id(),
			'awb'               => $awb,
			'courier'           => 'Delhivery',
			'tracking_url'      => $tracking_url,
			'status'            => $status,
		);

		wp_remote_post(
			trailingslashit( $store->callback_url ) . 'update-awb',
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'X-CC-Api-Key'  => $store->api_key,
				),
				'body'    => wp_json_encode( $payload ),
			)
		);
		CC_Logger::log( 'reverse-sync', 'Pushed AWB to store #' . $store_id, $payload );
	}
}
