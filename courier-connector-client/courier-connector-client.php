<?php

if (!defined('ABSPATH')) {
	exit;
}

define('CCC_VERSION', '2.0.0');

class CCC_Client
{

	const OPTION = 'ccc_settings';

	private static $instance;

	public static function instance()
	{
		if (!self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct()
	{
		add_action('admin_menu', array($this, 'menu'));
		add_action('admin_init', array($this, 'handle_post'));
		add_action('admin_enqueue_scripts', array($this, 'assets'));
		add_action('rest_api_init', array($this, 'rest_routes'));

		add_action('woocommerce_new_order', array($this, 'on_new_order'), 20, 1);
		add_action('woocommerce_order_status_changed', array($this, 'sync_status'), 20, 4);

		add_action('add_meta_boxes', array($this, 'add_courier_meta_box'));
		add_action('admin_post_ccc_send_order', array($this, 'handle_send_order'));

		add_action('woocommerce_admin_order_data_after_shipping_address', array($this, 'show_awb'));

		add_filter('http_request_args', array($this, 'force_ipv4'), 10, 2);
	}

	public function assets($hook)
	{
		$order_screen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'shop_order';
		$screen = get_current_screen();
		$on_order_screen = $screen && $order_screen === $screen->id;
		if ('woocommerce_page_ccc-settings' !== $hook && !$on_order_screen) {
			return;
		}
		wp_enqueue_style('ccc-admin', plugins_url('assets/ccc-admin.css', __FILE__), array(), CCC_VERSION);
	}

	public function force_ipv4($args, $url)
	{
		$dashboard = $this->get('dashboard_url');
		if ($dashboard && strpos($url, parse_url($dashboard, PHP_URL_HOST)) !== false) {
			$args['curl'][CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
		}
		return $args;
	}

	public function get($key, $default = '')
	{
		$s = get_option(self::OPTION, array());
		return isset($s[$key]) ? $s[$key] : $default;
	}

	public function set(array $values)
	{
		update_option(self::OPTION, array_merge(get_option(self::OPTION, array()), $values));
	}

	public function is_connected()
	{
		return $this->get('dashboard_url') && $this->get('api_key') && $this->get('secret_key');
	}

	public function menu()
	{
		add_submenu_page(
			'woocommerce',
			'Courier Connector',
			'Courier Connector',
			'manage_woocommerce',
			'ccc-settings',
			array($this, 'settings_page')
		);
	}

	/**
	 * The Naya Setu API key carries the dashboard address inside it:
	 * cc1.<base64url(dashboard URL)>.<random>
	 * so connecting only ever needs the two keys — no URL field.
	 */
	public static function decode_dashboard_url($api_key)
	{
		$parts = explode('.', trim((string) $api_key));
		if (3 !== count($parts) || 'cc1' !== $parts[0]) {
			return '';
		}
		$url = base64_decode(strtr($parts[1], '-_', '+/'));
		if (!$url || !preg_match('#^https?://#i', $url)) {
			return '';
		}
		return untrailingslashit(esc_url_raw($url));
	}

	public function handle_post()
	{
		if (empty($_POST['ccc_action']) || !current_user_can('manage_woocommerce')) {
			return;
		}
		$action = sanitize_key($_POST['ccc_action']);

		if ('connect' === $action) {
			check_admin_referer('ccc_connect');
			$this->connect();
		}
		if ('bulk_sync' === $action) {
			check_admin_referer('ccc_connect');
			$count = $this->bulk_sync((int) ($_POST['count'] ?? 50));
			$this->redirect('synced=' . $count);
		}
		if ('disconnect' === $action) {
			check_admin_referer('ccc_connect');
			delete_option(self::OPTION);
			$this->redirect('disconnected=1');
		}
		if ('save_pickup' === $action) {
			check_admin_referer('ccc_connect');
			$this->save_pickup();
		}
	}

	protected function rest_request($base, $route, $method, $body, $extra_headers = array())
	{
		$base = untrailingslashit($base);
		$endpoints = array(
			$base . '/wp-json/courier/v1/' . $route,
			$base . '/?rest_route=/courier/v1/' . $route,
		);

		$headers = array_merge(array('Content-Type' => 'application/json'), $extra_headers);
		$args = array(
			'method' => $method,
			'timeout' => 30,
			'redirection' => 5,
			'sslverify' => false,
			'headers' => $headers,
			'body' => null === $body ? null : wp_json_encode($body),
		);

		$last = array(
			'ok' => false,
			'code' => 0,
			'body' => null,
			'error' => 'No response from Naya Setu.',
		);

		foreach ($endpoints as $url) {
			$res = wp_remote_request($url, $args);

			if (is_wp_error($res)) {
				$last['error'] = $res->get_error_message();
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code($res);
			$raw = wp_remote_retrieve_body($res);
			$json = json_decode($raw, true);

			$result = array(
				'ok' => ($code >= 200 && $code < 300),
				'code' => $code,
				'body' => $json,
				'error' => is_array($json) && !empty($json['message']) ? $json['message'] : ('HTTP ' . $code),
			);

			if ($result['ok']) {
				return $result;
			}

			$last = $result;
		}

		return $last;
	}

	protected function auth_headers()
	{
		return array(
			'X-CC-Api-Key' => $this->get('api_key'),
			'X-CC-Api-Secret' => $this->get('secret_key'),
		);
	}

	/**
	 * Pickup profile: where Naya Setu picks parcels up from this store,
	 * plus the default parcel size. Prefills from the WooCommerce store
	 * address until the merchant saves their own values.
	 */
	public function pickup_profile()
	{
		return array(
			'pickup_name' => $this->get('pickup_name', get_bloginfo('name')),
			'pickup_phone' => $this->get('pickup_phone'),
			'pickup_address' => $this->get('pickup_address', get_option('woocommerce_store_address', '')),
			'pickup_city' => $this->get('pickup_city', get_option('woocommerce_store_city', '')),
			'pickup_state' => $this->get('pickup_state'),
			'pickup_pincode' => $this->get('pickup_pincode', get_option('woocommerce_store_postcode', '')),
			'pickup_country' => $this->get('pickup_country', 'India'),
			'default_weight' => $this->get('default_weight', '0.5'),
			'default_length' => $this->get('default_length', '10'),
			'default_breadth' => $this->get('default_breadth', '10'),
			'default_height' => $this->get('default_height', '10'),
		);
	}

	protected function save_pickup()
	{
		$fields = array(
			'pickup_name', 'pickup_phone', 'pickup_address', 'pickup_city',
			'pickup_state', 'pickup_pincode', 'pickup_country',
			'default_weight', 'default_length', 'default_breadth', 'default_height',
		);
		$values = array();
		foreach ($fields as $f) {
			$values[$f] = sanitize_text_field(wp_unslash($_POST[$f] ?? ''));
		}
		$this->set($values);

		// Push the fresh pickup profile to Naya Setu right away.
		if ($this->is_connected()) {
			$res = $this->rest_request(
				$this->get('dashboard_url'),
				'profile',
				'POST',
				array('pickup' => $this->pickup_profile()),
				$this->auth_headers()
			);
			if (!$res['ok']) {
				update_option('ccc_last_error', 'Pickup saved locally, but syncing to Naya Setu failed: ' . $res['error']);
				$this->redirect('pickup_saved=1&pickup_sync=0');
			}
		}
		delete_option('ccc_last_error');
		$this->redirect('pickup_saved=1');
	}

	protected function connect()
	{
		$api_key = sanitize_text_field(wp_unslash($_POST['api_key'] ?? ''));
		$secret_key = sanitize_text_field(wp_unslash($_POST['secret_key'] ?? ''));

		$dashboard = self::decode_dashboard_url($api_key);
		if (!$dashboard) {
			update_option('ccc_last_error', 'That API key does not look like a Naya Setu key (expected format: cc1.xxxx.xxxx). Copy it exactly from the Naya Setu panel → Clients.');
			$this->redirect('error=key');
		}
		if (!$secret_key) {
			update_option('ccc_last_error', 'The Secret Key is required.');
			$this->redirect('error=key');
		}

		$res = $this->rest_request(
			$dashboard,
			'handshake',
			'POST',
			array(
				'store_name' => get_bloginfo('name'),
				'store_url' => home_url(),
				'callback_url' => rest_url('courier/v1/'),
				'pickup' => $this->pickup_profile(),
			),
			array(
				'X-CC-Api-Key' => $api_key,
				'X-CC-Api-Secret' => $secret_key,
			)
		);

		if (!$res['ok'] || empty($res['body']['store_id'])) {
			update_option('ccc_last_error', $res['error'] ? $res['error'] : 'Unexpected response (HTTP ' . $res['code'] . ').');
			$this->redirect('error=connect');
		}

		$this->set(
			array(
				'dashboard_url' => $dashboard,
				'api_key' => $api_key,
				'secret_key' => $secret_key,
				'store_id' => $res['body']['store_id'],
			)
		);
		delete_option('ccc_last_error');
		$this->redirect('connected=1');
	}

	protected function redirect($query)
	{
		wp_safe_redirect(admin_url('admin.php?page=ccc-settings&' . $query));
		exit;
	}

	public function settings_page()
	{
		$connected = $this->is_connected();
		$pickup = $this->pickup_profile();
		?>
		<div class="wrap ccc-wrap">
			<h1 class="ccc-h1"><span class="ccc-logo">NS</span> Courier Connector — Client</h1>

			<?php if (isset($_GET['connected'])): ?>
				<div class="notice notice-success">
					<p>Store connected to Naya Setu successfully!</p>
				</div>
			<?php elseif (isset($_GET['disconnected'])): ?>
				<div class="notice notice-warning">
					<p>Store disconnected.</p>
				</div>
			<?php elseif (isset($_GET['synced'])): ?>
				<div class="notice notice-success">
					<p><?php echo (int) $_GET['synced']; ?> orders synced to Naya Setu.</p>
				</div>
			<?php elseif (isset($_GET['pickup_saved'])): ?>
				<?php if (isset($_GET['pickup_sync']) && '0' === $_GET['pickup_sync']): ?>
					<div class="notice notice-warning">
						<p><?php echo esc_html(get_option('ccc_last_error', 'Pickup saved, but could not sync to Naya Setu.')); ?></p>
					</div>
				<?php else: ?>
					<div class="notice notice-success">
						<p>Pickup address &amp; parcel settings saved<?php echo $connected ? ' and synced to Naya Setu' : ''; ?>.</p>
					</div>
				<?php endif; ?>
			<?php elseif (isset($_GET['error'])): ?>
				<div class="notice notice-error">
					<p><strong>Could not connect.</strong>
						<?php echo esc_html(get_option('ccc_last_error', 'Check the API key and secret key.')); ?>
					</p>
					<p>Get your keys from the Naya Setu panel → <strong>Clients</strong> → your store row. Paste both
						exactly — the connection is automatic, no URL needed.</p>
				</div>
			<?php endif; ?>

			<div class="ccc-panel">
				<?php if (!$connected): ?>
					<h2><span class="dashicons dashicons-admin-network"></span> Connect with your Naya Setu keys</h2>
					<p class="ccc-sub">Paste the <strong>API Key</strong> and <strong>Secret Key</strong> given to you by
						Naya Setu. That's all — the connection is automatic.</p>
					<form method="post">
						<?php wp_nonce_field('ccc_connect'); ?>
						<input type="hidden" name="ccc_action" value="connect" />
						<div class="ccc-field">
							<label for="ccc_api_key">API Key</label>
							<input type="text" id="ccc_api_key" name="api_key" placeholder="cc1.xxxxxxxx.xxxxxxxx" required />
						</div>
						<div class="ccc-field">
							<label for="ccc_secret_key">Secret Key</label>
							<input type="password" id="ccc_secret_key" name="secret_key" placeholder="ccs_xxxxxxxx"
								autocomplete="new-password" required />
						</div>
						<button class="ccc-btn ccc-btn-primary">Connect to Naya Setu</button>
					</form>
				<?php else: ?>
					<h2><span class="dashicons dashicons-admin-network"></span> Connection <span
							class="ccc-status ccc-status-ok">Connected</span></h2>
					<table class="ccc-kv">
						<tr>
							<th>Client ID</th>
							<td><?php echo esc_html($this->get('store_id')); ?></td>
						</tr>
						<tr>
							<th>API Key</th>
							<td><code><?php echo esc_html(substr($this->get('api_key'), 0, 16)); ?>…</code></td>
						</tr>
						<tr>
							<th>Delivery partner</th>
							<td>Assigned &amp; managed by Naya Setu for every order.</td>
						</tr>
					</table>

					<div class="ccc-actions-row">
						<form method="post">
							<?php wp_nonce_field('ccc_connect'); ?>
							<input type="hidden" name="ccc_action" value="bulk_sync" />
							<label for="ccc_sync_count" class="screen-reader-text">Number of orders to sync</label>
							<input type="number" id="ccc_sync_count" name="count" value="50" min="1" max="500"
								class="ccc-num-input" />
							<button class="ccc-btn ccc-btn-primary">Sync recent orders</button>
						</form>

						<form method="post">
							<?php wp_nonce_field('ccc_connect'); ?>
							<input type="hidden" name="ccc_action" value="disconnect" />
							<button class="ccc-btn ccc-btn-danger"
								onclick="return confirm('Disconnect this store?');">Disconnect</button>
						</form>
					</div>
				<?php endif; ?>
			</div>

			<div class="ccc-panel">
				<h2><span class="dashicons dashicons-location"></span> Pickup Address &amp; Parcel</h2>
				<p class="ccc-sub">Naya Setu picks your parcels up at this address and delivers them to your customers.
					Set the address and your usual parcel size here — it syncs to Naya Setu automatically.</p>
				<form method="post">
					<?php wp_nonce_field('ccc_connect'); ?>
					<input type="hidden" name="ccc_action" value="save_pickup" />
					<div class="ccc-grid-2">
						<div class="ccc-field">
							<label for="ccc_pickup_name">Pickup / business name</label>
							<input type="text" id="ccc_pickup_name" name="pickup_name"
								value="<?php echo esc_attr($pickup['pickup_name']); ?>" required />
						</div>
						<div class="ccc-field">
							<label for="ccc_pickup_phone">Phone</label>
							<input type="text" id="ccc_pickup_phone" name="pickup_phone"
								value="<?php echo esc_attr($pickup['pickup_phone']); ?>" required />
						</div>
					</div>
					<div class="ccc-field">
						<label for="ccc_pickup_address">Address</label>
						<input type="text" id="ccc_pickup_address" name="pickup_address"
							value="<?php echo esc_attr($pickup['pickup_address']); ?>" required />
					</div>
					<div class="ccc-grid-2">
						<div class="ccc-field">
							<label for="ccc_pickup_city">City</label>
							<input type="text" id="ccc_pickup_city" name="pickup_city"
								value="<?php echo esc_attr($pickup['pickup_city']); ?>" required />
						</div>
						<div class="ccc-field">
							<label for="ccc_pickup_state">State</label>
							<input type="text" id="ccc_pickup_state" name="pickup_state"
								value="<?php echo esc_attr($pickup['pickup_state']); ?>" required />
						</div>
					</div>
					<div class="ccc-grid-2">
						<div class="ccc-field">
							<label for="ccc_pickup_pincode">Pincode</label>
							<input type="text" id="ccc_pickup_pincode" name="pickup_pincode"
								value="<?php echo esc_attr($pickup['pickup_pincode']); ?>" required />
						</div>
						<div class="ccc-field">
							<label for="ccc_pickup_country">Country</label>
							<input type="text" id="ccc_pickup_country" name="pickup_country"
								value="<?php echo esc_attr($pickup['pickup_country']); ?>" />
						</div>
					</div>
					<h3 style="margin:16px 0 4px">Default parcel size</h3>
					<div class="ccc-grid-4">
						<div class="ccc-field">
							<label for="ccc_default_weight">Weight (kg)</label>
							<input type="text" id="ccc_default_weight" name="default_weight"
								value="<?php echo esc_attr($pickup['default_weight']); ?>" />
						</div>
						<div class="ccc-field">
							<label for="ccc_default_length">Length (cm)</label>
							<input type="text" id="ccc_default_length" name="default_length"
								value="<?php echo esc_attr($pickup['default_length']); ?>" />
						</div>
						<div class="ccc-field">
							<label for="ccc_default_breadth">Breadth (cm)</label>
							<input type="text" id="ccc_default_breadth" name="default_breadth"
								value="<?php echo esc_attr($pickup['default_breadth']); ?>" />
						</div>
						<div class="ccc-field">
							<label for="ccc_default_height">Height (cm)</label>
							<input type="text" id="ccc_default_height" name="default_height"
								value="<?php echo esc_attr($pickup['default_height']); ?>" />
						</div>
					</div>
					<button class="ccc-btn ccc-btn-primary">Save &amp; Sync to Naya Setu</button>
				</form>
			</div>
		</div>
		<?php
	}

	protected function order_payload(WC_Order $order)
	{
		$items = array();
		foreach ($order->get_items() as $item) {
			$product = $item->get_product();
			$items[] = array(
				'name' => $item->get_name(),
				'sku' => $product ? $product->get_sku() : '',
				'qty' => $item->get_quantity(),
				'price' => $order->get_item_total($item, false, false),
			);
		}

		// No courier field — the delivery partner is assigned per client
		// inside the Naya Setu panel, never from this store.
		return array(
			'external_order_id' => (string) $order->get_id(),
			'customer' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
			'phone' => $order->get_billing_phone(),
			'email' => $order->get_billing_email(),
			'total' => (float) $order->get_total(),
			'payment_method' => $order->get_payment_method(),
			'status' => $order->get_status(),
			'billing' => array(
				'first_name' => $order->get_billing_first_name(),
				'address_1' => $order->get_billing_address_1(),
				'address_2' => $order->get_billing_address_2(),
				'city' => $order->get_billing_city(),
				'state' => $order->get_billing_state(),
				'postcode' => $order->get_billing_postcode(),
				'country' => $order->get_billing_country(),
				'phone' => $order->get_billing_phone(),
				'email' => $order->get_billing_email(),
			),
			'items' => $items,
		);
	}

	public function on_new_order($order_id)
	{
		if (!$this->is_connected()) {
			return;
		}
		$this->sync_order($order_id);
	}

	public function sync_order($order_id)
	{
		if (!$this->is_connected()) {
			return;
		}
		$order = wc_get_order($order_id);
		if (!$order) {
			return;
		}

		$res = $this->rest_request(
			$this->get('dashboard_url'),
			'orders',
			'POST',
			$this->order_payload($order),
			$this->auth_headers()
		);

		if ($res['ok']) {
			$order->update_meta_data('_ccc_synced', current_time('mysql'));
			$order->save();
		} else {
			update_option('ccc_last_error', 'Order sync failed: ' . ($res['error'] ? $res['error'] : 'HTTP ' . $res['code']));
		}
	}

	public function sync_status($order_id, $from, $to, $order)
	{
		if (!$this->is_connected()) {
			return;
		}
		$this->rest_request(
			$this->get('dashboard_url'),
			'orders/' . $order_id,
			'PUT',
			array('status' => $to),
			$this->auth_headers()
		);
	}

	public function bulk_sync($count)
	{
		$ids = wc_get_orders(
			array(
				'limit' => max(1, min(500, $count)),
				'orderby' => 'date',
				'order' => 'DESC',
				'return' => 'ids',
				'status' => array_keys(wc_get_order_statuses()),
			)
		);
		foreach ($ids as $id) {
			$this->sync_order($id);
		}
		return count($ids);
	}

	public function rest_routes()
	{
		$perm = array($this, 'rest_auth');

		register_rest_route(
			'courier/v1',
			'/update-awb',
			array(
				'methods' => 'POST',
				'callback' => array($this, 'rest_update_awb'),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			'courier/v1',
			'/update-tracking',
			array(
				'methods' => 'POST',
				'callback' => array($this, 'rest_update_tracking'),
				'permission_callback' => $perm,
			)
		);
	}

	public function rest_auth(WP_REST_Request $request)
	{
		$key = $request->get_header('x_cc_api_key');
		return $key && hash_equals((string) $this->get('api_key'), (string) $key);
	}

	public function rest_update_awb(WP_REST_Request $request)
	{
		$p = $request->get_json_params();
		$order = wc_get_order((int) ($p['external_order_id'] ?? 0));
		if (!$order) {
			return new WP_REST_Response(array('success' => false, 'message' => 'Order not found.'), 404);
		}
		$order->update_meta_data('_awb_number', sanitize_text_field($p['awb'] ?? ''));
		$order->update_meta_data('_courier_name', sanitize_text_field($p['courier'] ?? 'Delhivery'));
		$order->update_meta_data('_tracking_url', esc_url_raw($p['tracking_url'] ?? ''));
		$order->update_meta_data('_shipment_status', sanitize_text_field($p['status'] ?? ''));
		$order->add_order_note(sprintf('Shipment booked by Naya Setu. AWB: %s (%s)', $p['awb'] ?? '', $p['courier'] ?? 'Delhivery'));
		$order->save();
		return new WP_REST_Response(array('success' => true), 200);
	}

	public function rest_update_tracking(WP_REST_Request $request)
	{
		$p = $request->get_json_params();
		$order = wc_get_order((int) ($p['external_order_id'] ?? 0));
		if (!$order) {
			return new WP_REST_Response(array('success' => false, 'message' => 'Order not found.'), 404);
		}
		$status = sanitize_text_field($p['status'] ?? '');
		$order->update_meta_data('_shipment_status', $status);
		$order->add_order_note('Shipment status: ' . $status);
		$order->save();
		return new WP_REST_Response(array('success' => true), 200);
	}

	public function show_awb($order)
	{
		$awb = $order->get_meta('_awb_number');
		if (!$awb) {
			return;
		}
		$courier = $order->get_meta('_courier_name') ?: 'Delhivery';
		$courier_cls = 'DTDC' === $courier ? 'ccc-courier-dtdc' : 'ccc-courier-delhivery';
		echo '<div class="address ccc-wrap ccc-awb-block">';
		echo '<p><span class="ccc-courier-badge ' . esc_attr($courier_cls) . '">' . esc_html($courier) . '</span></p>';
		echo '<div class="ccc-kv-list">';
		echo '<div class="ccc-kv-row"><span class="ccc-kv-label">AWB</span><span class="ccc-kv-value">' . esc_html($awb) . '</span></div>';
		echo '<div class="ccc-kv-row"><span class="ccc-kv-label">Status</span><span class="ccc-kv-value">' . esc_html($order->get_meta('_shipment_status') ?: '—') . '</span></div>';
		echo '</div>';
		$url = $order->get_meta('_tracking_url');
		if ($url) {
			echo '<p style="margin-top:10px"><a href="' . esc_url($url) . '" target="_blank" class="button">Track Shipment</a></p>';
		}
		echo '</div>';
	}

	public function add_courier_meta_box()
	{
		$screen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'shop_order';
		add_meta_box(
			'ccc_courier_box',
			'Naya Setu Shipping',
			array($this, 'render_courier_box'),
			$screen,
			'side',
			'high'
		);
	}

	public function render_courier_box($post_or_order)
	{
		$order = $post_or_order instanceof WP_Post ? wc_get_order($post_or_order->ID) : $post_or_order;
		if (!$order) {
			return;
		}

		if (!$this->is_connected()) {
			echo '<p>Connect this store to Naya Setu under <strong>WooCommerce → Courier Connector</strong> first.</p>';
			return;
		}

		$synced = $order->get_meta('_ccc_synced');

		echo '<div class="ccc-box ccc-wrap">';

		if ($synced) {
			echo '<div class="ccc-box-synced">';
			echo '<p><strong>✅ Sent to Naya Setu</strong></p>';
			echo '<p class="ccc-box-note">The delivery partner is chosen by Naya Setu for your account.</p>';
			echo '</div>';
			echo '<p class="ccc-box-time">' . esc_html($synced) . '</p>';
		} else {
			echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
			wp_nonce_field('ccc_send_order_' . $order->get_id());
			echo '<input type="hidden" name="action" value="ccc_send_order" />';
			echo '<input type="hidden" name="order_id" value="' . esc_attr($order->get_id()) . '" />';
			echo '<p class="ccc-box-note">New orders send automatically. The delivery partner (Delhivery / DTDC) is assigned by Naya Setu.</p>';
			echo '<button type="submit" class="ccc-btn ccc-btn-primary ccc-btn-block">Send to Naya Setu</button>';
			echo '</form>';
		}

		echo '</div>';
	}

	public function handle_send_order()
	{
		if (!current_user_can('manage_woocommerce')) {
			wp_die('Permission denied.');
		}
		$order_id = (int) ($_POST['order_id'] ?? 0);
		check_admin_referer('ccc_send_order_' . $order_id);

		$order = wc_get_order($order_id);
		if ($order) {
			$this->sync_order($order_id);
		}

		wp_safe_redirect($order ? $order->get_edit_order_url() : admin_url());
		exit;
	}
}

add_action(
	'plugins_loaded',
	function () {
		if (!class_exists('WooCommerce')) {
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
		if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
		}
	}
);
