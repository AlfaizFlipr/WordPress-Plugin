<?php
/**
 * REST API — the endpoints external WooCommerce stores (or a central panel)
 * call to connect and push orders. Namespace: courier/v1.
 *
 *   POST  /wp-json/courier/v1/connect-store     (open, returns api_key)
 *   POST  /wp-json/courier/v1/orders            (auth: X-CC-Api-Key)
 *   PUT   /wp-json/courier/v1/orders/(?P<id>)   (auth: X-CC-Api-Key)
 *   GET   /wp-json/courier/v1/ping              (auth)
 *   GET   /wp-json/courier/v1/track/(?P<awb>)   (public, no auth)
 *
 * @package CourierConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CC_REST {

	const NS = 'courier/v1';

	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes() {
		register_rest_route(
			self::NS,
			'/connect-store',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'connect_store' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/ping',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'ping' ),
				'permission_callback' => array( $this, 'auth' ),
			)
		);

		register_rest_route(
			self::NS,
			'/orders',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_order' ),
				'permission_callback' => array( $this, 'auth' ),
			)
		);

		register_rest_route(
			self::NS,
			'/orders/(?P<id>[\w\-]+)',
			array(
				'methods'             => array( 'PUT', 'PATCH' ),
				'callback'            => array( $this, 'update_order' ),
				'permission_callback' => array( $this, 'auth' ),
			)
		);

		// Public customer tracking — no auth needed.
		register_rest_route(
			self::NS,
			'/track/(?P<awb>[\w\-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'public_track' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/* --------------------------------------------------------------------- */
	/* Auth                                                                  */
	/* --------------------------------------------------------------------- */

	/**
	 * API-key auth via X-CC-Api-Key header. Stashes the store on the request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function auth( WP_REST_Request $request ) {
		$key = $request->get_header( 'x_cc_api_key' );
		if ( ! $key ) {
			$key = $request->get_param( 'api_key' );
		}
		if ( ! $key ) {
			return new WP_Error( 'cc_no_key', 'Missing API key.', array( 'status' => 401 ) );
		}
		$store = CC_Website::get_by_key( $key );
		if ( ! $store || 'active' !== $store->status ) {
			return new WP_Error( 'cc_bad_key', 'Invalid or disabled API key.', array( 'status' => 401 ) );
		}
		$request->set_param( '_cc_store', $store );
		return true;
	}

	/* --------------------------------------------------------------------- */
	/* Handlers                                                              */
	/* --------------------------------------------------------------------- */

	/**
	 * Connect / register a store. Returns the API key the connector must store.
	 */
	public function connect_store( WP_REST_Request $request ) {
		$store = CC_Website::connect(
			array(
				'store_name'   => $request->get_param( 'store_name' ),
				'store_url'    => $request->get_param( 'store_url' ),
				'callback_url' => $request->get_param( 'callback_url' ),
			)
		);

		if ( is_wp_error( $store ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $store->get_error_message(),
				),
				400
			);
		}

		return new WP_REST_Response(
			array(
				'success'  => true,
				'store_id' => (int) $store->id,
				'api_key'  => $store->api_key,
			),
			200
		);
	}

	public function ping( WP_REST_Request $request ) {
		$store = $request->get_param( '_cc_store' );
		return new WP_REST_Response(
			array(
				'success'    => true,
				'store_id'   => (int) $store->id,
				'store_name' => $store->store_name,
			),
			200
		);
	}

	/**
	 * Receive an order from an external store and create a local WC order
	 * tagged with the source store id. If auto_push_on_receive is enabled,
	 * immediately pushes to Delhivery and returns the AWB.
	 */
	public function create_order( WP_REST_Request $request ) {
		if ( ! function_exists( 'wc_create_order' ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'WooCommerce is not active on the dashboard site. Activate WooCommerce to import orders.' ), 503 );
		}
		$store = $request->get_param( '_cc_store' );
		$p     = $request->get_json_params();
		if ( empty( $p ) ) {
			$p = $request->get_params();
		}

		$external_id = sanitize_text_field( $p['external_order_id'] ?? '' );
		if ( '' === $external_id ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'external_order_id required.' ), 400 );
		}

		// Idempotency: don't duplicate.
		$existing = self::find_external_order( $store->id, $external_id );
		if ( $existing ) {
			return new WP_REST_Response(
				array(
					'success'  => true,
					'order_id' => $existing,
					'message'  => 'Order already imported.',
				),
				200
			);
		}

		$order = wc_create_order();

		// Customer / billing.
		$order->set_billing_first_name( sanitize_text_field( $p['customer'] ?? ( $p['billing']['first_name'] ?? '' ) ) );
		$order->set_billing_phone( sanitize_text_field( $p['phone'] ?? ( $p['billing']['phone'] ?? '' ) ) );
		$order->set_billing_email( sanitize_email( $p['email'] ?? ( $p['billing']['email'] ?? '' ) ) );

		$billing = $p['billing'] ?? array();
		$order->set_billing_address_1( sanitize_text_field( $billing['address_1'] ?? ( $p['address'] ?? '' ) ) );
		$order->set_billing_address_2( sanitize_text_field( $billing['address_2'] ?? '' ) );
		$order->set_billing_city( sanitize_text_field( $billing['city'] ?? ( $p['city'] ?? '' ) ) );
		$order->set_billing_state( sanitize_text_field( $billing['state'] ?? ( $p['state'] ?? '' ) ) );
		$order->set_billing_postcode( sanitize_text_field( $billing['postcode'] ?? ( $p['pincode'] ?? '' ) ) );
		$order->set_billing_country( sanitize_text_field( $billing['country'] ?? 'IN' ) );

		// Line items.
		if ( ! empty( $p['items'] ) && is_array( $p['items'] ) ) {
			foreach ( $p['items'] as $item ) {
				$line       = new WC_Order_Item_Product();
				$line_total = (float) ( $item['price'] ?? 0 ) * (int) ( $item['qty'] ?? 1 );
				$line->set_name( sanitize_text_field( $item['name'] ?? ( $item['sku'] ?? 'Item' ) ) );
				$line->set_quantity( (int) ( $item['qty'] ?? 1 ) );
				$line->set_subtotal( $line_total );
				$line->set_total( $line_total );
				$order->add_item( $line );
			}
			// Store raw items as JSON fallback for Delhivery payload building.
			$order->update_meta_data( '_cc_order_items_json', wp_json_encode( $p['items'] ) );
		}

		$payment = strtolower( sanitize_text_field( $p['payment_method'] ?? '' ) );
		if ( 'cod' === $payment ) {
			$order->set_payment_method( 'cod' );
			$order->set_payment_method_title( 'Cash on Delivery' );
		}

		if ( isset( $p['total'] ) ) {
			$order->set_total( (float) $p['total'] );
		} else {
			$order->calculate_totals();
		}

		$order->update_meta_data( '_cc_source_store', (int) $store->id );
		$order->update_meta_data( '_cc_external_order_id', $external_id );
		$order->update_meta_data( '_cc_ship_status', 'pending' );
		$order->set_status( sanitize_text_field( $p['status'] ?? 'processing' ) );
		$order->save();

		CC_Website::bump_sync( $store->id );
		CC_Logger::log( 'rest', 'Imported order from store #' . $store->id, array( 'external' => $external_id, 'order_id' => $order->get_id() ) );

		// Auto-push to Delhivery immediately if setting is enabled.
		if ( '1' === CC_Settings::get( 'auto_push_on_receive' ) && CC_Settings::is_configured() ) {
			$push = CC_Shipment::push( $order->get_id() );
			if ( ! is_wp_error( $push ) ) {
				return new WP_REST_Response(
					array(
						'success'     => true,
						'order_id'    => $order->get_id(),
						'awb'         => $push['awb'],
						'tracking_url'=> $push['tracking_url'],
						'auto_pushed' => true,
					),
					201
				);
			}
			CC_Logger::log( 'rest', 'Auto-push failed for order #' . $order->get_id(), $push->get_error_message(), 'error' );
		}

		return new WP_REST_Response(
			array(
				'success'  => true,
				'order_id' => $order->get_id(),
			),
			201
		);
	}

	/**
	 * Update an order pushed from an external store (status changes etc.).
	 */
	public function update_order( WP_REST_Request $request ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'WooCommerce is not active on the dashboard site.' ), 503 );
		}
		$store       = $request->get_param( '_cc_store' );
		$external_id = sanitize_text_field( $request['id'] );
		$order_id    = self::find_external_order( $store->id, $external_id );

		if ( ! $order_id ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Order not found.' ), 404 );
		}

		$p     = $request->get_json_params();
		$order = wc_get_order( $order_id );

		if ( ! empty( $p['status'] ) ) {
			$map = array(
				'cancelled'  => 'cancelled',
				'processing' => 'processing',
				'completed'  => 'completed',
				'refunded'   => 'refunded',
				'on-hold'    => 'on-hold',
			);
			$new = $map[ strtolower( $p['status'] ) ] ?? null;
			if ( $new ) {
				$order->update_status( $new, '[Courier] Updated from source store.' );
			}
		}

		return new WP_REST_Response( array( 'success' => true, 'order_id' => $order_id ), 200 );
	}

	/**
	 * Public customer tracking — no auth, returns JSON tracking data for any AWB.
	 * Also allows ?awb=XXX query param for form-based lookups.
	 */
	public function public_track( WP_REST_Request $request ) {
		$awb = sanitize_text_field( $request->get_param( 'awb' ) ?? '' );
		if ( '' === $awb ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'AWB is required.' ), 400 );
		}
		if ( ! CC_Settings::is_configured() ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Tracking service unavailable.' ), 503 );
		}

		$api      = new CC_Delhivery_API();
		$tracking = $api->track( $awb );

		if ( '' === $tracking['status'] ) {
			$body = $tracking['body'] ?? array();
			$err  = is_array( $body ) ? ( $body['Error'] ?? '' ) : '';
			return new WP_REST_Response(
				array(
					'success' => false,
					'awb'     => $awb,
					'message' => $err ?: 'Shipment is being processed. Tracking will be available within 30–60 minutes of first scan.',
				),
				200
			);
		}

		return new WP_REST_Response(
			array(
				'success'  => true,
				'awb'      => $awb,
				'status'   => $tracking['status'],
				'location' => $tracking['location'],
				'scans'    => $tracking['scans'],
				'data'     => $tracking['data'],
			),
			200
		);
	}

	/* --------------------------------------------------------------------- */
	/* Helpers                                                               */
	/* --------------------------------------------------------------------- */

	/**
	 * Find a local order id by source store + external id.
	 *
	 * @return int 0 if none.
	 */
	protected static function find_external_order( $store_id, $external_id ) {
		global $wpdb;
		// Direct DB query — wc_get_orders meta_query is unreliable in WC 8+/10+.
		$order_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT m1.post_id
			 FROM {$wpdb->postmeta} m1
			 INNER JOIN {$wpdb->postmeta} m2 ON m2.post_id = m1.post_id
			 WHERE m1.meta_key   = '_cc_external_order_id'
			   AND m1.meta_value = %s
			   AND m2.meta_key   = '_cc_source_store'
			   AND m2.meta_value = %d
			 LIMIT 1",
			(string) $external_id,
			(int) $store_id
		) );
		return $order_id ? (int) $order_id : 0;
	}
}
