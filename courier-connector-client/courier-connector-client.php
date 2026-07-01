<?php
/**
 * Plugin Name:       Naya Setu Courier — Store Connector
 * Description:        Installs on a client WooCommerce store. Connects to the central Naya Setu Courier dashboard, auto-syncs new/updated orders, and receives AWB + tracking back from Delhivery.
 * Version:           1.0.0
 * Author:            Naya Setu
 * License:           GPL-2.0+
 * Text Domain:       courier-connector-client
 * Requires PHP:      7.4
 *
 * @package CourierConnectorClient
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CCC_VERSION', '1.0.0' );

/**
 * Main client connector class.
 */
class CCC_Client {

	const OPTION = 'ccc_settings';

	/** @var CCC_Client */
	private static $instance;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'handle_post' ) );
		add_action( 'rest_api_init', array( $this, 'rest_routes' ) );

		// Auto order sync hooks.
		add_action( 'woocommerce_new_order', array( $this, 'sync_order' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'sync_status' ), 20, 4 );

		// Show AWB on the order screen.
		add_action( 'woocommerce_admin_order_data_after_shipping_address', array( $this, 'show_awb' ) );

		// Force IPv4 for all dashboard HTTP calls — fixes IPv6 loopback timeout on Windows/XAMPP.
		add_filter( 'http_request_args', array( $this, 'force_ipv4' ), 10, 2 );
	}

	/**
	 * Force IPv4 resolution for calls to the courier dashboard.
	 * Prevents IPv6 loopback timeout on localhost Windows setups.
	 */
	public function force_ipv4( $args, $url ) {
		$dashboard = $this->get( 'dashboard_url' );
		if ( $dashboard && strpos( $url, parse_url( $dashboard, PHP_URL_HOST ) ) !== false ) {
			$args['curl'][ CURLOPT_IPRESOLVE ] = CURL_IPRESOLVE_V4;
		}
		return $args;
	}

	/* --------------------------------------------------------------------- */
	/* Settings helpers                                                      */
	/* --------------------------------------------------------------------- */

	public function get( $key, $default = '' ) {
		$s = get_option( self::OPTION, array() );
		return isset( $s[ $key ] ) ? $s[ $key ] : $default;
	}

	public function set( array $values ) {
		update_option( self::OPTION, array_merge( get_option( self::OPTION, array() ), $values ) );
	}

	public function is_connected() {
		return $this->get( 'dashboard_url' ) && $this->get( 'api_key' );
	}

	/* --------------------------------------------------------------------- */
	/* Admin UI                                                              */
	/* --------------------------------------------------------------------- */

	public function menu() {
		add_submenu_page(
			'woocommerce',
			'Courier Connector',
			'Courier Connector',
			'manage_woocommerce',
			'ccc-settings',
			array( $this, 'settings_page' )
		);
	}

	public function handle_post() {
		if ( empty( $_POST['ccc_action'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$action = sanitize_key( $_POST['ccc_action'] );

		if ( 'connect' === $action ) {
			check_admin_referer( 'ccc_connect' );
			$this->connect();
		}
		if ( 'bulk_sync' === $action ) {
			check_admin_referer( 'ccc_connect' );
			$count = $this->bulk_sync( (int) ( $_POST['count'] ?? 50 ) );
			$this->redirect( 'synced=' . $count );
		}
		if ( 'disconnect' === $action ) {
			check_admin_referer( 'ccc_connect' );
			delete_option( self::OPTION );
			$this->redirect( 'disconnected=1' );
		}
	}

	/**
	 * Call a REST route on the remote dashboard. Works with ANY permalink
	 * setting: tries pretty /wp-json/ first, falls back to ?rest_route= (which
	 * works even when the dashboard uses "Plain" permalinks, e.g. fresh XAMPP).
	 *
	 * @param string $base   Dashboard base URL (no trailing slash).
	 * @param string $route  Route after the namespace, e.g. 'connect-store' or 'orders/12'.
	 * @param string $method HTTP method.
	 * @param array  $body   Body data (will be JSON encoded).
	 * @param array  $extra_headers Extra headers.
	 * @return array {ok:bool, code:int, body:array|null, error:string}
	 */
	protected function rest_request( $base, $route, $method, $body, $extra_headers = array() ) {
		$base     = untrailingslashit( $base );
		$endpoints = array(
			$base . '/wp-json/courier/v1/' . $route,                 // pretty permalinks
			$base . '/?rest_route=/courier/v1/' . $route,            // plain permalinks
		);

		$headers = array_merge( array( 'Content-Type' => 'application/json' ), $extra_headers );
		$args    = array(
			'method'      => $method,
			'timeout'     => 30,
			'redirection' => 5,
			'sslverify'   => false, // localhost / self-signed friendly
			'headers'     => $headers,
			'body'        => null === $body ? null : wp_json_encode( $body ),
		);

		$last = array(
			'ok'    => false,
			'code'  => 0,
			'body'  => null,
			'error' => 'No response from dashboard.',
		);

		foreach ( $endpoints as $url ) {
			$res = wp_remote_request( $url, $args );

			if ( is_wp_error( $res ) ) {
				$last['error'] = $res->get_error_message();
				continue; // try next endpoint form
			}

			$code = (int) wp_remote_retrieve_response_code( $res );
			$raw  = wp_remote_retrieve_body( $res );
			$json = json_decode( $raw, true );

			$result = array(
				'ok'    => ( $code >= 200 && $code < 300 ),
				'code'  => $code,
				'body'  => $json,
				'error' => is_array( $json ) && ! empty( $json['message'] ) ? $json['message'] : ( 'HTTP ' . $code ),
			);

			// Success — return immediately.
			if ( $result['ok'] ) {
				return $result;
			}

			// Otherwise remember it and try the next endpoint form (handles 404
			// pages, rest_no_route from a POST→GET redirect, etc.).
			$last = $result;
		}

		return $last;
	}

	/**
	 * Probe the dashboard's REST API and return a human-readable diagnostic so
	 * the admin can see exactly why a connection failed.
	 *
	 * @param string $dashboard Dashboard base URL.
	 * @return string
	 */
	protected function probe_dashboard( $dashboard ) {
		$dashboard = untrailingslashit( $dashboard );
		$out       = array();

		foreach ( array( '/wp-json/', '/?rest_route=/' ) as $path ) {
			$res = wp_remote_get(
				$dashboard . $path,
				array(
					'timeout'   => 20,
					'sslverify' => false,
				)
			);

			if ( is_wp_error( $res ) ) {
				$out[] = $path . ' → cannot reach (' . $res->get_error_message() . ')';
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $res );
			$json = json_decode( wp_remote_retrieve_body( $res ), true );

			if ( is_array( $json ) && isset( $json['namespaces'] ) ) {
				$has   = in_array( 'courier/v1', (array) $json['namespaces'], true ) ? 'YES ✅' : 'NO ❌ (dashboard plugin not active here)';
				$out[] = $path . " → REST works (HTTP {$code}). courier/v1 present: {$has}.";
				// Found a working REST index; that's enough.
				break;
			}

			$out[] = $path . " → HTTP {$code}, not a REST index (permalinks/404).";
		}

		return implode( '  |  ', $out );
	}

	protected function connect() {
		$dashboard = untrailingslashit( esc_url_raw( $_POST['dashboard_url'] ?? '' ) );
		if ( ! $dashboard ) {
			update_option( 'ccc_last_error', 'No dashboard URL was entered.' );
			$this->redirect( 'error=url' );
		}

		$res = $this->rest_request(
			$dashboard,
			'connect-store',
			'POST',
			array(
				'store_name'   => get_bloginfo( 'name' ),
				'store_url'    => home_url(),
				'callback_url' => rest_url( 'courier/v1/' ),
			)
		);

		if ( ! $res['ok'] || empty( $res['body']['api_key'] ) ) {
			$msg = $res['error'] ? $res['error'] : 'Unexpected response (HTTP ' . $res['code'] . ').';
			$msg .= ' — Diagnostic: ' . $this->probe_dashboard( $dashboard );
			update_option( 'ccc_last_error', $msg );
			$this->redirect( 'error=connect' );
		}

		$this->set(
			array(
				'dashboard_url' => $dashboard,
				'api_key'       => $res['body']['api_key'],
				'store_id'      => $res['body']['store_id'] ?? 0,
			)
		);
		delete_option( 'ccc_last_error' );
		$this->redirect( 'connected=1' );
	}

	protected function redirect( $query ) {
		wp_safe_redirect( admin_url( 'admin.php?page=ccc-settings&' . $query ) );
		exit;
	}

	public function settings_page() {
		$connected = $this->is_connected();
		?>
		<div class="wrap">
			<h1>Courier Connector</h1>

			<?php if ( isset( $_GET['connected'] ) ) : ?>
				<div class="notice notice-success"><p>Store connected successfully!</p></div>
			<?php elseif ( isset( $_GET['disconnected'] ) ) : ?>
				<div class="notice notice-warning"><p>Store disconnected.</p></div>
			<?php elseif ( isset( $_GET['synced'] ) ) : ?>
				<div class="notice notice-success"><p><?php echo (int) $_GET['synced']; ?> orders synced to the dashboard.</p></div>
			<?php elseif ( isset( $_GET['error'] ) ) : ?>
				<div class="notice notice-error">
					<p><strong>Could not connect.</strong> <?php echo esc_html( get_option( 'ccc_last_error', 'Check the dashboard URL and that the dashboard plugin is active.' ) ); ?></p>
					<p>Tips: use the full URL like <code>http://localhost/wordpress</code> (no <code>/wp-admin</code>), and make sure the <em>Naya Setu Courier — Dashboard</em> plugin is active on that site.</p>
				</div>
			<?php endif; ?>

			<div style="max-width:640px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px;margin-top:16px;">
				<?php if ( ! $connected ) : ?>
					<h2 style="margin-top:0;">Connect your store in 2 minutes</h2>
					<p>Enter your Naya Setu dashboard URL (the site root, not the admin page). We'll register this store and pull the API key automatically.</p>
					<form method="post">
						<?php wp_nonce_field( 'ccc_connect' ); ?>
						<input type="hidden" name="ccc_action" value="connect" />
						<p>
							<label><strong>Dashboard URL</strong></label><br>
							<input type="url" name="dashboard_url" class="regular-text" value="http://localhost/wordpress" required style="width:100%;" />
							<br><small>Example: <code>http://localhost/wordpress</code> — the dashboard site's home URL.</small>
						</p>
						<button class="button button-primary button-hero">Connect Store</button>
					</form>
				<?php else : ?>
					<h2 style="margin-top:0;">✅ Connected</h2>
					<table class="form-table">
						<tr><th>Dashboard</th><td><code><?php echo esc_html( $this->get( 'dashboard_url' ) ); ?></code></td></tr>
						<tr><th>Store ID</th><td><?php echo esc_html( $this->get( 'store_id' ) ); ?></td></tr>
						<tr><th>API Key</th><td><code><?php echo esc_html( substr( $this->get( 'api_key' ), 0, 12 ) ); ?>…</code></td></tr>
					</table>

					<form method="post" style="display:inline-block;margin-right:8px;">
						<?php wp_nonce_field( 'ccc_connect' ); ?>
						<input type="hidden" name="ccc_action" value="bulk_sync" />
						<input type="number" name="count" value="50" min="1" max="500" style="width:80px;" />
						<button class="button button-primary">Sync recent orders</button>
					</form>

					<form method="post" style="display:inline-block;">
						<?php wp_nonce_field( 'ccc_connect' ); ?>
						<input type="hidden" name="ccc_action" value="disconnect" />
						<button class="button" onclick="return confirm('Disconnect this store?');">Disconnect</button>
					</form>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/* --------------------------------------------------------------------- */
	/* Order sync (store -> dashboard)                                       */
	/* --------------------------------------------------------------------- */

	/**
	 * Build the payload for an order.
	 */
	protected function order_payload( WC_Order $order ) {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			$items[] = array(
				'name'  => $item->get_name(),
				'sku'   => $product ? $product->get_sku() : '',
				'qty'   => $item->get_quantity(),
				'price' => $order->get_item_total( $item, false, false ),
			);
		}

		return array(
			'external_order_id' => (string) $order->get_id(),
			'customer'          => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'phone'             => $order->get_billing_phone(),
			'email'             => $order->get_billing_email(),
			'total'             => (float) $order->get_total(),
			'payment_method'    => $order->get_payment_method(),
			'status'            => $order->get_status(),
			'billing'           => array(
				'first_name' => $order->get_billing_first_name(),
				'address_1'  => $order->get_billing_address_1(),
				'address_2'  => $order->get_billing_address_2(),
				'city'       => $order->get_billing_city(),
				'state'      => $order->get_billing_state(),
				'postcode'   => $order->get_billing_postcode(),
				'country'    => $order->get_billing_country(),
				'phone'      => $order->get_billing_phone(),
				'email'      => $order->get_billing_email(),
			),
			'items'             => $items,
		);
	}

	/**
	 * Push a single order to the dashboard.
	 */
	public function sync_order( $order_id ) {
		if ( ! $this->is_connected() ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$res = $this->rest_request(
			$this->get( 'dashboard_url' ),
			'orders',
			'POST',
			$this->order_payload( $order ),
			array( 'X-CC-Api-Key' => $this->get( 'api_key' ) )
		);

		if ( $res['ok'] ) {
			$order->update_meta_data( '_ccc_synced', current_time( 'mysql' ) );
			$order->save();
		} else {
			update_option( 'ccc_last_error', 'Order sync failed: ' . ( $res['error'] ? $res['error'] : 'HTTP ' . $res['code'] ) );
		}
	}

	/**
	 * Push status change to the dashboard.
	 */
	public function sync_status( $order_id, $from, $to, $order ) {
		if ( ! $this->is_connected() ) {
			return;
		}
		$this->rest_request(
			$this->get( 'dashboard_url' ),
			'orders/' . $order_id,
			'PUT',
			array( 'status' => $to ),
			array( 'X-CC-Api-Key' => $this->get( 'api_key' ) )
		);
	}

	/**
	 * Bulk-sync the most recent N orders.
	 *
	 * @return int Number queued.
	 */
	public function bulk_sync( $count ) {
		$ids = wc_get_orders(
			array(
				'limit'   => max( 1, min( 500, $count ) ),
				'orderby' => 'date',
				'order'   => 'DESC',
				'return'  => 'ids',
				'status'  => array_keys( wc_get_order_statuses() ),
			)
		);
		foreach ( $ids as $id ) {
			$this->sync_order( $id );
		}
		return count( $ids );
	}

	/* --------------------------------------------------------------------- */
	/* Reverse sync (dashboard -> store): receive AWB & tracking             */
	/* --------------------------------------------------------------------- */

	public function rest_routes() {
		$perm = array( $this, 'rest_auth' );

		register_rest_route(
			'courier/v1',
			'/update-awb',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_update_awb' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			'courier/v1',
			'/update-tracking',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_update_tracking' ),
				'permission_callback' => $perm,
			)
		);
	}

	/**
	 * Authenticate callbacks using the same API key.
	 */
	public function rest_auth( WP_REST_Request $request ) {
		$key = $request->get_header( 'x_cc_api_key' );
		return $key && hash_equals( (string) $this->get( 'api_key' ), (string) $key );
	}

	public function rest_update_awb( WP_REST_Request $request ) {
		$p     = $request->get_json_params();
		$order = wc_get_order( (int) ( $p['external_order_id'] ?? 0 ) );
		if ( ! $order ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Order not found.' ), 404 );
		}
		$order->update_meta_data( '_awb_number', sanitize_text_field( $p['awb'] ?? '' ) );
		$order->update_meta_data( '_courier_name', sanitize_text_field( $p['courier'] ?? 'Delhivery' ) );
		$order->update_meta_data( '_tracking_url', esc_url_raw( $p['tracking_url'] ?? '' ) );
		$order->update_meta_data( '_shipment_status', sanitize_text_field( $p['status'] ?? '' ) );
		$order->add_order_note( sprintf( 'Shipment booked. AWB: %s (%s)', $p['awb'] ?? '', $p['courier'] ?? 'Delhivery' ) );
		$order->save();
		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	public function rest_update_tracking( WP_REST_Request $request ) {
		$p     = $request->get_json_params();
		$order = wc_get_order( (int) ( $p['external_order_id'] ?? 0 ) );
		if ( ! $order ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Order not found.' ), 404 );
		}
		$status = sanitize_text_field( $p['status'] ?? '' );
		$order->update_meta_data( '_shipment_status', $status );
		$order->add_order_note( 'Shipment status: ' . $status );
		$order->save();
		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/* --------------------------------------------------------------------- */
	/* Order screen display                                                  */
	/* --------------------------------------------------------------------- */

	public function show_awb( $order ) {
		$awb = $order->get_meta( '_awb_number' );
		if ( ! $awb ) {
			return;
		}
		echo '<div class="address" style="margin-top:10px;">';
		echo '<p><strong>AWB:</strong> ' . esc_html( $awb ) . '</p>';
		echo '<p><strong>Courier:</strong> ' . esc_html( $order->get_meta( '_courier_name' ) ?: 'Delhivery' ) . '</p>';
		echo '<p><strong>Status:</strong> ' . esc_html( $order->get_meta( '_shipment_status' ) ?: '—' ) . '</p>';
		$url = $order->get_meta( '_tracking_url' );
		if ( $url ) {
			echo '<p><a href="' . esc_url( $url ) . '" target="_blank" class="button">Track Shipment</a></p>';
		}
		echo '</div>';
	}
}

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p><strong>Courier Connector — Client</strong> requires WooCommerce.</p></div>';
				}
			);
			return;
		}
		CCC_Client::instance();
	}
);

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);
