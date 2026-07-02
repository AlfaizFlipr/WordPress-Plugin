<?php

if (!defined('ABSPATH')) {
	exit;
}

define('CCC_VERSION', '1.0.0');

class CCC_Client
{

	const OPTION = 'ccc_settings';

	const COURIERS = array(
		'delhivery' => 'Delhivery',
		'dtdc' => 'DTDC',
	);

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
		return $this->get('dashboard_url') && $this->get('api_key');
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
		if ('save_courier' === $action) {
			check_admin_referer('ccc_connect');
			$mode = 'per_order' === ($_POST['courier_mode'] ?? '') ? 'per_order' : 'fixed';
			$courier = sanitize_key(wp_unslash($_POST['fixed_courier'] ?? ''));
			if (!isset(self::COURIERS[$courier])) {
				$courier = 'delhivery';
			}
			$this->set(array('courier_mode' => $mode, 'fixed_courier' => $courier));
			$this->redirect('courier_saved=1');
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
			'error' => 'No response from dashboard.',
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

	protected function probe_dashboard($dashboard)
	{
		$dashboard = untrailingslashit($dashboard);
		$out = array();

		foreach (array('/wp-json/', '/?rest_route=/') as $path) {
			$res = wp_remote_get(
				$dashboard . $path,
				array(
					'timeout' => 20,
					'sslverify' => false,
				)
			);

			if (is_wp_error($res)) {
				$out[] = $path . ' → cannot reach (' . $res->get_error_message() . ')';
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code($res);
			$json = json_decode(wp_remote_retrieve_body($res), true);

			if (is_array($json) && isset($json['namespaces'])) {
				$has = in_array('courier/v1', (array) $json['namespaces'], true) ? 'YES ✅' : 'NO ❌ (dashboard plugin not active here)';
				$out[] = $path . " → REST works (HTTP {$code}). courier/v1 present: {$has}.";

				break;
			}

			$out[] = $path . " → HTTP {$code}, not a REST index (permalinks/404).";
		}

		return implode('  |  ', $out);
	}

	protected function connect()
	{
		$dashboard = untrailingslashit(esc_url_raw($_POST['dashboard_url'] ?? ''));
		if (!$dashboard) {
			update_option('ccc_last_error', 'No dashboard URL was entered.');
			$this->redirect('error=url');
		}

		$res = $this->rest_request(
			$dashboard,
			'connect-store',
			'POST',
			array(
				'store_name' => get_bloginfo('name'),
				'store_url' => home_url(),
				'callback_url' => rest_url('courier/v1/'),
			)
		);

		if (!$res['ok'] || empty($res['body']['api_key'])) {
			$msg = $res['error'] ? $res['error'] : 'Unexpected response (HTTP ' . $res['code'] . ').';
			$msg .= ' — Diagnostic: ' . $this->probe_dashboard($dashboard);
			update_option('ccc_last_error', $msg);
			$this->redirect('error=connect');
		}

		$this->set(
			array(
				'dashboard_url' => $dashboard,
				'api_key' => $res['body']['api_key'],
				'store_id' => $res['body']['store_id'] ?? 0,
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
		?>
		<div class="wrap ccc-wrap">
			<h1 class="ccc-h1"><span class="ccc-logo">NS</span> Courier Connector</h1>

			<?php if (isset($_GET['connected'])): ?>
				<div class="notice notice-success">
					<p>Store connected successfully!</p>
				</div>
			<?php elseif (isset($_GET['disconnected'])): ?>
				<div class="notice notice-warning">
					<p>Store disconnected.</p>
				</div>
			<?php elseif (isset($_GET['synced'])): ?>
				<div class="notice notice-success">
					<p><?php echo (int) $_GET['synced']; ?> orders synced to the dashboard.</p>
				</div>
			<?php elseif (isset($_GET['courier_saved'])): ?>
				<div class="notice notice-success">
					<p>Delivery partner settings saved.</p>
				</div>
			<?php elseif (isset($_GET['error'])): ?>
				<div class="notice notice-error">
					<p><strong>Could not connect.</strong>
						<?php echo esc_html(get_option('ccc_last_error', 'Check the dashboard URL and that the dashboard plugin is active.')); ?>
					</p>
					<p>Tips: use the full URL like <code>http://localhost/wordpress</code> (no <code>/wp-admin</code>), and make
						sure the <em>Naya Setu Courier — Dashboard</em> plugin is active on that site.</p>
				</div>
			<?php endif; ?>

			<div class="ccc-panel">
				<?php if (!$connected): ?>
					<h2>Connect your store in 2 minutes</h2>
					<p class="ccc-sub">Enter your Naya Setu dashboard URL (the site root, not the admin page). We'll register this
						store and pull the API key automatically.</p>
					<form method="post">
						<?php wp_nonce_field('ccc_connect'); ?>
						<input type="hidden" name="ccc_action" value="connect" />
						<div class="ccc-field">
							<label for="ccc_dashboard_url">Dashboard URL</label>
							<input type="url" id="ccc_dashboard_url" name="dashboard_url" value="http://localhost/wordpress"
								required />
							<p class="ccc-help">Example: <code>http://localhost/wordpress</code> — the dashboard site's home URL.
							</p>
						</div>
						<button class="ccc-btn ccc-btn-primary">Connect Store</button>
					</form>
				<?php else: ?>
					<h2>Connection <span class="ccc-status ccc-status-ok">Connected</span></h2>
					<table class="ccc-kv">
						<tr>
							<th>Dashboard</th>
							<td><code><?php echo esc_html($this->get('dashboard_url')); ?></code></td>
						</tr>
						<tr>
							<th>Store ID</th>
							<td><?php echo esc_html($this->get('store_id')); ?></td>
						</tr>
						<tr>
							<th>API Key</th>
							<td><code><?php echo esc_html(substr($this->get('api_key'), 0, 12)); ?>…</code></td>
						</tr>
					</table>

					<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px">
						<form method="post" style="display:flex;gap:8px">
							<?php wp_nonce_field('ccc_connect'); ?>
							<input type="hidden" name="ccc_action" value="bulk_sync" />
							<input type="number" name="count" value="50" min="1" max="500" style="width:80px" />
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

			<?php if ($connected): ?>
				<div class="ccc-panel">
					<h2>Delivery Partner</h2>
					<p class="ccc-sub">Choose whether every order automatically ships with one fixed courier, or whether you pick
						the courier yourself on each order (from the order edit screen).</p>
					<form method="post">
						<?php wp_nonce_field('ccc_connect'); ?>
						<input type="hidden" name="ccc_action" value="save_courier" />
						<div class="ccc-mode-cards">
							<label class="ccc-mode-card">
								<input type="radio" name="courier_mode" value="fixed" <?php checked($this->get('courier_mode', 'fixed'), 'fixed'); ?> />
								<span><strong>Fixed partner</strong><span>Every order automatically ships with the partner
										below.</span></span>
							</label>
							<label class="ccc-mode-card">
								<input type="radio" name="courier_mode" value="per_order" <?php checked($this->get('courier_mode', 'fixed'), 'per_order'); ?> />
								<span><strong>Per-order selection</strong><span>Pick a courier on each order before sending it, from
										the order edit screen.</span></span>
							</label>
						</div>
						<div class="ccc-field">
							<label for="ccc_fixed_courier">Fixed / default partner</label>
							<select name="fixed_courier" id="ccc_fixed_courier">
								<?php foreach (self::COURIERS as $key => $label): ?>
									<option value="<?php echo esc_attr($key); ?>" <?php selected($this->get('fixed_courier', 'delhivery'), $key); ?>><?php echo esc_html($label); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<button class="ccc-btn ccc-btn-primary">Save</button>
					</form>
				</div>
			<?php endif; ?>
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

		$courier = $order->get_meta('_ccc_courier');
		if (!$courier || !isset(self::COURIERS[$courier])) {
			$courier = $this->get('fixed_courier', 'delhivery');
		}

		return array(
			'external_order_id' => (string) $order->get_id(),
			'customer' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
			'phone' => $order->get_billing_phone(),
			'email' => $order->get_billing_email(),
			'total' => (float) $order->get_total(),
			'payment_method' => $order->get_payment_method(),
			'status' => $order->get_status(),
			'courier' => $courier,
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
		if ('per_order' === $this->get('courier_mode', 'fixed')) {
			return;
		}
		$order = wc_get_order($order_id);
		if ($order && !$order->get_meta('_ccc_courier')) {
			$order->update_meta_data('_ccc_courier', $this->get('fixed_courier', 'delhivery'));
			$order->save();
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
			array('X-CC-Api-Key' => $this->get('api_key'))
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
			array('X-CC-Api-Key' => $this->get('api_key'))
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
		$order->add_order_note(sprintf('Shipment booked. AWB: %s (%s)', $p['awb'] ?? '', $p['courier'] ?? 'Delhivery'));
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
		echo '<div class="address ccc-wrap" style="margin-top:10px;">';
		echo '<p><span class="ccc-courier-badge ' . esc_attr($courier_cls) . '">' . esc_html($courier) . '</span></p>';
		echo '<p><strong>AWB:</strong> ' . esc_html($awb) . '</p>';
		echo '<p><strong>Status:</strong> ' . esc_html($order->get_meta('_shipment_status') ?: '—') . '</p>';
		$url = $order->get_meta('_tracking_url');
		if ($url) {
			echo '<p><a href="' . esc_url($url) . '" target="_blank" class="button">Track Shipment</a></p>';
		}
		echo '</div>';
	}

	public function add_courier_meta_box()
	{
		$screen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'shop_order';
		add_meta_box(
			'ccc_courier_box',
			'Courier Connector',
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
			echo '<p>Connect this store to the dashboard under <strong>WooCommerce → Courier Connector</strong> first.</p>';
			return;
		}

		$synced = $order->get_meta('_ccc_synced');
		$courier = $order->get_meta('_ccc_courier');
		if (!$courier || !isset(self::COURIERS[$courier])) {
			$courier = $this->get('fixed_courier', 'delhivery');
		}
		$mode = $this->get('courier_mode', 'fixed');
		$courier_cls = 'dtdc' === $courier ? 'ccc-courier-dtdc' : 'ccc-courier-delhivery';

		echo '<div class="ccc-box ccc-wrap">';

		if ($synced) {
			echo '<div class="ccc-box-synced">';
			echo '<p style="margin-top:0"><strong>✅ Sent to dashboard</strong></p>';
			echo '<p style="margin-bottom:0"><span class="ccc-courier-badge ' . esc_attr($courier_cls) . '">' . esc_html(self::COURIERS[$courier] ?? $courier) . '</span></p>';
			echo '</div>';
			echo '<p class="ccc-box-time">' . esc_html($synced) . '</p>';
		} else {
			echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
			wp_nonce_field('ccc_send_order_' . $order->get_id());
			echo '<input type="hidden" name="action" value="ccc_send_order" />';
			echo '<input type="hidden" name="order_id" value="' . esc_attr($order->get_id()) . '" />';
			echo '<div class="ccc-field"><label for="ccc_courier">Delivery Partner</label>';
			echo '<select name="courier" id="ccc_courier">';
			foreach (self::COURIERS as $key => $label) {
				echo '<option value="' . esc_attr($key) . '" ' . selected($courier, $key, false) . '>' . esc_html($label) . '</option>';
			}
			echo '</select></div>';
			if ('fixed' === $mode) {
				echo '<p class="ccc-box-note">Fixed-partner mode is on, so this order will auto-send shortly — you can also send it now.</p>';
			}
			echo '<button type="submit" class="ccc-btn ccc-btn-primary ccc-btn-block">Send to Dashboard</button>';
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
			$courier = sanitize_key(wp_unslash($_POST['courier'] ?? ''));
			if (!isset(self::COURIERS[$courier])) {
				$courier = $this->get('fixed_courier', 'delhivery');
			}
			$order->update_meta_data('_ccc_courier', $courier);
			$order->save();
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
