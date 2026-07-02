<?php

if (!defined('ABSPATH')) {
	exit;
}
$g = function ($k, $d = '') use ($settings) {
	return isset($settings[$k]) ? $settings[$k] : $d;
};
$notice = $_GET['cc_notice'] ?? '';
?>
<div class="wrap cc-wrap">
	<div class="cc-topbar">
		<h1 class="cc-title"><span class="dashicons dashicons-admin-generic"></span> Settings</h1>
	</div>

	<?php if ('saved' === $notice): ?>
		<div class="cc-alert cc-alert-ok">Settings saved.</div>
	<?php elseif ('pickup_ok' === $notice): ?>
		<div class="cc-alert cc-alert-ok">Pickup location registered with Delhivery.</div>
	<?php elseif ('pickup_fail' === $notice): ?>
		<div class="cc-alert cc-alert-warn">Could not register pickup location — check the Logs page for the API response.
		</div>
	<?php endif; ?>

	<form method="post">
		<?php wp_nonce_field('cc_settings'); ?>
		<input type="hidden" name="cc_action" value="save_settings" />

		<div class="cc-panel">
			<h2>Couriers</h2>
			<div class="cc-form-grid">
				<label>Default Courier
					<select name="default_courier" class="cc-input">
						<?php foreach (CC_Courier_Registry::all() as $key => $label): ?>
							<option value="<?php echo esc_attr($key); ?>" <?php selected($g('default_courier', 'delhivery'), $key); ?>><?php echo esc_html($label); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</div>
			<p class="cc-help">Used whenever an order arrives with no explicit courier choice (e.g. an older client
				plugin version, or an order created locally).</p>
			<p style="margin-top:12px">
				<label style="display:flex;align-items:center;gap:8px;cursor:pointer">
					<input type="checkbox" name="auto_push_on_receive" value="1" <?php checked($g('auto_push_on_receive'), '1'); ?> />
					<span><strong>Auto-push new orders</strong> — every order arriving from a connected store is
						automatically shipped (AWB generated) with its resolved courier within seconds of arrival.
						Uncheck to push manually.</span>
				</label>
			</p>
		</div>

		<div class="cc-panel">
			<h2>Delhivery API</h2>
			<div class="cc-form-grid">
				<label>API Token
					<input type="text" name="api_token" class="cc-input"
						value="<?php echo esc_attr($g('api_token')); ?>"
						placeholder="Token from Delhivery seller panel" />
				</label>
				<label>Environment
					<select name="environment" class="cc-input">
						<option value="production" <?php selected($g('environment'), 'production'); ?>>Production
						</option>
						<option value="staging" <?php selected($g('environment'), 'staging'); ?>>Staging</option>
					</select>
				</label>
			</div>
		</div>

		<div class="cc-panel">
			<h2>DTDC API</h2>
			<div class="cc-form-grid">
				<label>Customer Code
					<input type="text" name="dtdc_customer_code" class="cc-input"
						value="<?php echo esc_attr($g('dtdc_customer_code')); ?>"
						placeholder="From DTDC onboarding" />
				</label>
				<label>API Username
					<input type="text" name="dtdc_username" class="cc-input"
						value="<?php echo esc_attr($g('dtdc_username')); ?>" />
				</label>
				<label>API Password
					<input type="password" name="dtdc_password" class="cc-input"
						value="<?php echo esc_attr($g('dtdc_password')); ?>" autocomplete="new-password" />
				</label>
			</div>
			<p class="cc-help">DTDC's exact field names can vary by onboarding product — if bookings fail, check the
				Logs page and see the notes in <code>includes/class-cc-dtdc-api.php</code>.</p>
		</div>

		<div class="cc-panel">
			<h2>Pickup Address</h2>
			<p class="cc-help">The pickup <em>name</em> must match a warehouse registered in Delhivery. Use the button
				below to register it.</p>
			<div class="cc-form-grid">
				<label>Pickup Name<input type="text" name="pickup_name" class="cc-input"
						value="<?php echo esc_attr($g('pickup_name')); ?>" /></label>
				<label>Phone<input type="text" name="pickup_phone" class="cc-input"
						value="<?php echo esc_attr($g('pickup_phone')); ?>" /></label>
				<label class="cc-col-span">Address<input type="text" name="pickup_address" class="cc-input"
						value="<?php echo esc_attr($g('pickup_address')); ?>" /></label>
				<label>City<input type="text" name="pickup_city" class="cc-input"
						value="<?php echo esc_attr($g('pickup_city')); ?>" /></label>
				<label>State<input type="text" name="pickup_state" class="cc-input"
						value="<?php echo esc_attr($g('pickup_state')); ?>" /></label>
				<label>Pincode<input type="text" name="pickup_pincode" class="cc-input"
						value="<?php echo esc_attr($g('pickup_pincode')); ?>" /></label>
				<label>Country<input type="text" name="pickup_country" class="cc-input"
						value="<?php echo esc_attr($g('pickup_country', 'India')); ?>" /></label>
			</div>
		</div>

		<div class="cc-panel">
			<h2>Default Package</h2>
			<div class="cc-form-grid">
				<label>Weight (kg)<input type="text" name="default_weight" class="cc-input"
						value="<?php echo esc_attr($g('default_weight', '0.5')); ?>" /></label>
				<label>Length (cm)<input type="text" name="default_length" class="cc-input"
						value="<?php echo esc_attr($g('default_length', '10')); ?>" /></label>
				<label>Breadth (cm)<input type="text" name="default_breadth" class="cc-input"
						value="<?php echo esc_attr($g('default_breadth', '10')); ?>" /></label>
				<label>Height (cm)<input type="text" name="default_height" class="cc-input"
						value="<?php echo esc_attr($g('default_height', '10')); ?>" /></label>
			</div>
		</div>

		<p>
			<button class="cc-btn cc-btn-primary" type="submit">Save Settings</button>
		</p>
	</form>

	<form method="post" class="cc-panel">
		<?php wp_nonce_field('cc_settings'); ?>
		<input type="hidden" name="cc_action" value="register_pickup" />
		<h2>Register Pickup with Delhivery</h2>
		<p class="cc-help">Save settings first, then register the warehouse so shipment creation can use it.</p>
		<button class="cc-btn" type="submit">Register / Update Warehouse</button>
	</form>

	<div class="cc-panel">
		<h2>Webhooks &amp; Endpoints</h2>
		<p><strong>Connect store:</strong>
			<code><?php echo esc_html(rest_url('courier/v1/connect-store')); ?></code></p>
		<p><strong>Push order:</strong> <code><?php echo esc_html(rest_url('courier/v1/orders')); ?></code></p>
		<p><strong>Webhook secret:</strong> <code><?php echo esc_html($g('webhook_secret')); ?></code></p>
		<p><strong>Tracking cron:</strong> runs every 15 minutes (<code>cc_tracking_cron</code>).</p>
	</div>
</div>