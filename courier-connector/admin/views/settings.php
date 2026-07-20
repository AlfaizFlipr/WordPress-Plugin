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
	<?php endif; ?>

	<div class="cc-alert cc-alert-info">
		<strong>Naya Setu is the service provider</strong> — pickup addresses and parcel sizes are set by each
		client in <em>their</em> connector plugin (see the Clients page). Courier assignment (Delhivery / DTDC,
		manual or automated) is also per client, on the Clients page. Only courier API credentials live here.
	</div>

	<div class="cc-settings-shell">
		<nav class="cc-tabs-nav">
			<button type="button" class="cc-tab-btn active" data-tab="couriers"><span
					class="dashicons dashicons-admin-generic"></span> Couriers</button>
			<button type="button" class="cc-tab-btn" data-tab="delhivery"><span
					class="dashicons dashicons-airplane"></span> Delhivery</button>
			<button type="button" class="cc-tab-btn" data-tab="dtdc"><span class="dashicons dashicons-car"></span>
				DTDC</button>
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
						<p class="cc-help">Fallback only — used when a client has no courier assigned. The real
							courier choice and Manual / Automated booking mode are set <strong>per client</strong> on
							the <a href="<?php echo esc_url(admin_url('admin.php?page=cc-websites')); ?>">Clients</a>
							page.</p>
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
							<label>API Key
								<input type="password" name="dtdc_api_key" class="cc-input"
									value="<?php echo esc_attr($g('dtdc_api_key')); ?>" autocomplete="new-password"
									placeholder="DTDC api-key" />
								<span class="cc-field-hint">Sent as the <code>api-key</code> header on
									pxapi.dtdc.in for booking / cancel / label. From your DTDC API access mail or
									account manager.</span>
							</label>
							<label>Tracking Token (optional)
								<input type="password" name="dtdc_tracking_token" class="cc-input"
									value="<?php echo esc_attr($g('dtdc_tracking_token')); ?>" autocomplete="new-password"
									placeholder="Defaults to API Key above" />
								<span class="cc-field-hint">Sent as <code>x-access-token</code> to DTDC's bulk
									tracking service (blktracksvc.dtdc.com), which can be issued separately from the
									booking api-key. Leave blank to reuse the API Key above.</span>
							</label>
							<label>Service Type ID
								<input type="text" name="dtdc_service_type_id" class="cc-input"
									value="<?php echo esc_attr($g('dtdc_service_type_id', 'GROUND EXPRESS')); ?>"
									placeholder="GROUND EXPRESS" />
								<span class="cc-field-hint">The DTDC product code approved for your account
									(verified working for IO1740: <code>GROUND EXPRESS</code>).</span>
							</label>
							<label>Consignment Type
								<select name="dtdc_consignment_type" class="cc-input">
									<option value="Reverse" <?php selected($g('dtdc_consignment_type', 'Reverse'), 'Reverse'); ?>>Reverse</option>
									<option value="Forward" <?php selected($g('dtdc_consignment_type', 'Reverse'), 'Forward'); ?>>Forward</option>
								</select>
								<span class="cc-field-hint">DTDC accounts are provisioned as either Forward or
									Reverse customers; sending the wrong one is rejected outright. IO1740 is a
									Reverse customer.</span>
							</label>
						</div>
						<p class="cc-help">DTDC's exact field names can vary by onboarding product — if bookings fail,
							check
							the Logs page and see the notes in <code>includes/class-cc-dtdc-api.php</code>.</p>
					</div>
				</div>

				<div class="cc-save-bar">
					<button class="cc-btn cc-btn-primary" type="submit">Save Settings</button>
				</div>
			</form>

			<div class="cc-tab-panel" data-tab-panel="advanced">
				<div class="cc-panel">
					<h2><span class="dashicons dashicons-admin-links"></span> Webhooks &amp; Endpoints</h2>
					<p class="cc-help">Read-only reference — these are generated automatically, so there's nothing to
						save
						here.</p>
					<div class="cc-kv-list">
						<div class="cc-kv-row">
							<span class="cc-kv-label">Key handshake</span>
							<span
								class="cc-kv-value"><code><?php echo esc_html(rest_url('courier/v1/handshake')); ?></code></span>
						</div>
						<div class="cc-kv-row">
							<span class="cc-kv-label">Push order</span>
							<span
								class="cc-kv-value"><code><?php echo esc_html(rest_url('courier/v1/orders')); ?></code></span>
						</div>
						<div class="cc-kv-row">
							<span class="cc-kv-label">Webhook secret</span>
							<span
								class="cc-kv-value"><code><?php echo esc_html($g('webhook_secret') ?: '—'); ?></code></span>
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