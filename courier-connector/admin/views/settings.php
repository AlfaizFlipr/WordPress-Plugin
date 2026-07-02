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

	<div class="cc-settings-shell">
		<nav class="cc-tabs-nav">
			<button type="button" class="cc-tab-btn active" data-tab="couriers"><span
					class="dashicons dashicons-admin-generic"></span> Couriers</button>
			<button type="button" class="cc-tab-btn" data-tab="delhivery"><span
					class="dashicons dashicons-airplane"></span> Delhivery</button>
			<button type="button" class="cc-tab-btn" data-tab="dtdc"><span class="dashicons dashicons-car"></span>
				DTDC</button>
			<button type="button" class="cc-tab-btn" data-tab="pickup"><span
					class="dashicons dashicons-location"></span> Pickup Address</button>
			<button type="button" class="cc-tab-btn" data-tab="package"><span
					class="dashicons dashicons-archive"></span> Default Package</button>
			<button type="button" class="cc-tab-btn" data-tab="advanced"><span
					class="dashicons dashicons-admin-links"></span> Webhooks</button>
		</nav>

		<div class="cc-tabs-content">
			<form method="post">
				<?php wp_nonce_field('cc_settings'); ?>
				<input type="hidden" name="cc_action" value="save_settings" />

				<div class="cc-tab-panel active" data-tab-panel="couriers">
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
						<p class="cc-help">Used whenever an order arrives with no explicit courier choice (e.g. an older
							client
							plugin version, or an order created locally).</p>
						<p style="margin-top:12px">
							<label style="display:flex;align-items:center;gap:8px;cursor:pointer">
								<input type="checkbox" name="auto_push_on_receive" value="1" <?php checked($g('auto_push_on_receive'), '1'); ?> />
								<span><strong>Auto-push new orders</strong> — every order arriving from a connected
									store is
									automatically shipped (AWB generated) with its resolved courier within seconds of
									arrival.
									Uncheck to push manually.</span>
							</label>
						</p>
					</div>
				</div>

				<div class="cc-tab-panel" data-tab-panel="delhivery">
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
									<option value="production" <?php selected($g('environment'), 'production'); ?>>
										Production
									</option>
									<option value="staging" <?php selected($g('environment'), 'staging'); ?>>Staging
									</option>
								</select>
							</label>
						</div>
					</div>
				</div>

				<div class="cc-tab-panel" data-tab-panel="dtdc">
					<div class="cc-panel">
						<h2>DTDC API</h2>
						<div class="cc-form-grid">
							<label>Customer Code
								<input type="text" name="dtdc_customer_code" class="cc-input"
									value="<?php echo esc_attr($g('dtdc_customer_code')); ?>"
									placeholder="From DTDC onboarding" />
								<span class="cc-field-hint">Given by DTDC when your account is onboarded.</span>
							</label>
							<label>API Username
								<input type="text" name="dtdc_username" class="cc-input"
									value="<?php echo esc_attr($g('dtdc_username')); ?>" placeholder="DTDC API user ID" />
								<span class="cc-field-hint">From your DTDC API access mail, or ask your DTDC account
									manager.</span>
							</label>
							<label>API Password
								<input type="password" name="dtdc_password" class="cc-input"
									value="<?php echo esc_attr($g('dtdc_password')); ?>" autocomplete="new-password"
									placeholder="DTDC API password" />
								<span class="cc-field-hint">Issued together with the API username — not your DTDC portal
									login password.</span>
							</label>
						</div>
						<p class="cc-help">DTDC's exact field names can vary by onboarding product — if bookings fail, check
							the Logs page and see the notes in <code>includes/class-cc-dtdc-api.php</code>.</p>
					</div>
				</div>

				<div class="cc-tab-panel" data-tab-panel="pickup">
					<div class="cc-panel">
						<h2>Pickup Address</h2>
						<p class="cc-help">The pickup <em>name</em> must match a warehouse registered in Delhivery. Use
							the button
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
				</div>

				<div class="cc-tab-panel" data-tab-panel="package">
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
				</div>

				<div class="cc-save-bar">
					<button class="cc-btn cc-btn-primary" type="submit">Save Settings</button>
				</div>
			</form>

			<div class="cc-tab-panel" data-tab-panel="pickup">
				<form method="post" class="cc-panel">
					<?php wp_nonce_field('cc_settings'); ?>
					<input type="hidden" name="cc_action" value="register_pickup" />
					<h2>Register Pickup with Delhivery</h2>
					<p class="cc-help">Save settings first, then register the warehouse so shipment creation can use it.
					</p>
					<button class="cc-btn" type="submit">Register / Update Warehouse</button>
				</form>
			</div>

			<div class="cc-tab-panel" data-tab-panel="advanced">
				<div class="cc-panel">
					<h2><span class="dashicons dashicons-admin-links"></span> Webhooks &amp; Endpoints</h2>
					<p class="cc-help">Read-only reference — these are generated automatically, so there's nothing to save
						here.</p>
					<div class="cc-kv-list">
						<div class="cc-kv-row">
							<span class="cc-kv-label">Connect store</span>
							<span
								class="cc-kv-value"><code><?php echo esc_html(rest_url('courier/v1/connect-store')); ?></code></span>
						</div>
						<div class="cc-kv-row">
							<span class="cc-kv-label">Push order</span>
							<span class="cc-kv-value"><code><?php echo esc_html(rest_url('courier/v1/orders')); ?></code></span>
						</div>
						<div class="cc-kv-row">
							<span class="cc-kv-label">Webhook secret</span>
							<span class="cc-kv-value"><code><?php echo esc_html($g('webhook_secret') ?: '—'); ?></code></span>
						</div>
						<div class="cc-kv-row">
							<span class="cc-kv-label">Tracking cron</span>
							<span class="cc-kv-value">Every 15 min (<code>cc_tracking_cron</code>)</span>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>