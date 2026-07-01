<?php
/**
 * Order helper — wraps a WC_Order with courier meta + WooCommerce hooks.
 *
 * Courier state is stored in order meta:
 *   _cc_awb            AWB / waybill number
 *   _cc_courier        Courier name (Delhivery)
 *   _cc_ship_status    Internal shipment status (pending|booked|in-transit|delivered|rto|cancelled)
 *   _cc_tracking_url   Public tracking URL
 *   _cc_last_scan      Last raw scan status from courier
 *   _cc_label_url      Stored label URL (if any)
 *   _cc_source_store   For orders pushed in via REST (external store id)
 *
 * @package CourierConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CC_Order {

	const SHIP_STATUSES = array(
		'pending'    => 'Pending Shipment',
		'booked'     => 'Booked',
		'in-transit' => 'In Transit',
		'ofd'        => 'Out for Delivery',
		'delivered'  => 'Delivered',
		'rto'        => 'RTO',
		'cancelled'  => 'Cancelled',
	);

	/** @var WC_Order */
	protected $order;

	public function __construct( $order ) {
		$this->order = $order instanceof WC_Order ? $order : wc_get_order( $order );
	}

	public function valid() {
		return $this->order instanceof WC_Order;
	}

	public function wc() {
		return $this->order;
	}

	public function id() {
		return $this->order->get_id();
	}

	/* --------------------------------------------------------------------- */
	/* Courier meta getters/setters                                          */
	/* --------------------------------------------------------------------- */

	public function get_awb() {
		return (string) $this->order->get_meta( '_cc_awb' );
	}

	public function get_ship_status() {
		$s = (string) $this->order->get_meta( '_cc_ship_status' );
		return $s ? $s : 'pending';
	}

	public function get_ship_status_label() {
		$s = $this->get_ship_status();
		return self::SHIP_STATUSES[ $s ] ?? ucfirst( $s );
	}

	public function get_tracking_url() {
		$url = (string) $this->order->get_meta( '_cc_tracking_url' );
		if ( $url ) {
			return $url;
		}
		$awb = $this->get_awb();
		return $awb ? 'https://www.delhivery.com/track/package/' . rawurlencode( $awb ) : '';
	}

	public function get_last_scan() {
		return (string) $this->order->get_meta( '_cc_last_scan' );
	}

	/**
	 * Persist courier data and add an order note.
	 *
	 * @param array $data Keyed meta updates.
	 */
	public function set_courier_data( array $data ) {
		$map = array(
			'awb'          => '_cc_awb',
			'courier'      => '_cc_courier',
			'ship_status'  => '_cc_ship_status',
			'tracking_url' => '_cc_tracking_url',
			'last_scan'    => '_cc_last_scan',
			'label_url'    => '_cc_label_url',
		);
		foreach ( $map as $key => $meta ) {
			if ( array_key_exists( $key, $data ) ) {
				$this->order->update_meta_data( $meta, $data[ $key ] );
			}
		}
		$this->order->save();
	}

	public function add_note( $note ) {
		$this->order->add_order_note( '[Courier] ' . $note );
	}

	/* --------------------------------------------------------------------- */
	/* Address / payment helpers                                             */
	/* --------------------------------------------------------------------- */

	public function is_cod() {
		return 'cod' === $this->order->get_payment_method();
	}

	public function cod_amount() {
		return $this->is_cod() ? (float) $this->order->get_total() : 0;
	}

	/**
	 * Build the Delhivery shipment payload from this WooCommerce order.
	 *
	 * @return array
	 */
	public function to_delhivery_shipment() {
		$o = $this->order;

		// Build products list and quantity from WC line items.
		$products   = array();
		$quantity   = 0;
		foreach ( $o->get_items() as $item ) {
			$products[] = $item->get_name();
			$quantity  += (int) $item->get_quantity();
		}

		// Fallback: use JSON meta stored at REST import time (handles cases where
		// WC item cache is cold or items didn't save correctly).
		if ( empty( $products ) ) {
			$raw = $o->get_meta( '_cc_order_items_json' );
			if ( $raw ) {
				$raw_items = json_decode( $raw, true );
				if ( is_array( $raw_items ) ) {
					foreach ( $raw_items as $ri ) {
						$products[] = sanitize_text_field( $ri['name'] ?? 'Item' );
						$quantity  += max( 1, (int) ( $ri['qty'] ?? 1 ) );
					}
				}
			}
		}

		// Final fallbacks so we never send empty / zero to Delhivery.
		if ( empty( $products ) ) {
			$products = array( 'Shipment' );
		}
		$quantity = max( 1, $quantity );

		// Weight: setting is stored in kg, Delhivery CMU expects GRAMS.
		$weight_grams = max( 1, (int) round( (float) CC_Settings::get( 'default_weight', '0.5' ) * 1000 ) );

		$name = trim( $o->get_shipping_first_name() . ' ' . $o->get_shipping_last_name() );
		if ( '' === $name ) {
			$name = trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() );
		}

		$address = trim( $o->get_shipping_address_1() . ' ' . $o->get_shipping_address_2() );
		if ( '' === $address ) {
			$address = trim( $o->get_billing_address_1() . ' ' . $o->get_billing_address_2() );
		}

		$pickup_name    = CC_Settings::get( 'pickup_name' );
		$pickup_address = CC_Settings::get( 'pickup_address' );
		$pickup_city    = CC_Settings::get( 'pickup_city' );
		$pickup_state   = CC_Settings::get( 'pickup_state' );
		$pickup_pincode = CC_Settings::get( 'pickup_pincode' );
		$pickup_phone   = CC_Settings::get( 'pickup_phone' );

		return array(
			'name'            => $name,
			'add'             => $address,
			'city'            => $o->get_shipping_city() ?: $o->get_billing_city(),
			'state'           => $o->get_shipping_state() ?: $o->get_billing_state(),
			'country'         => 'India',
			'pin'             => $o->get_shipping_postcode() ?: $o->get_billing_postcode(),
			'phone'           => $o->get_billing_phone(),
			'order'           => (string) $o->get_order_number(),
			'payment_mode'    => $this->is_cod() ? 'COD' : 'Prepaid',
			'cod_amount'      => $this->cod_amount(),
			'total_amount'    => (float) $o->get_total(),
			'products_desc'   => implode( ', ', $products ),
			'quantity'        => $quantity,
			'weight'          => $weight_grams,
			'shipment_width'  => (float) CC_Settings::get( 'default_breadth', '10' ),
			'shipment_height' => (float) CC_Settings::get( 'default_height', '10' ),
			'shipment_length' => (float) CC_Settings::get( 'default_length', '10' ),
			'seller_name'     => $pickup_name,
			'seller_add'      => $pickup_address,
			'seller_pin'      => $pickup_pincode,
			'seller_city'     => $pickup_city,
			'seller_state'    => $pickup_state,
			'return_name'     => $pickup_name,
			'return_add'      => $pickup_address,
			'return_pin'      => $pickup_pincode,
			'return_city'     => $pickup_city,
			'return_state'    => $pickup_state,
			'return_country'  => 'India',
			'return_phone'    => $pickup_phone,
		);
	}
}
