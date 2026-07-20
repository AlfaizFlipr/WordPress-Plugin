<?php

if (!defined('ABSPATH')) {
	exit;
}
$notice = sanitize_key($_GET['cc_notice'] ?? '');
$new_store_id = (int) ($_GET['new_store'] ?? 0);
$couriers = CC_Courier_Registry::all();
?>
<div class="wrap cc-wrap">
	<div class="cc-topbar">
		<h1 class="cc-title"><span class="dashicons dashicons-groups"></span> Clients</h1>
	</div>

	<?php if ('keys_generated' === $notice): ?>
		<div class="cc-alert cc-alert-ok">
			<strong>API keys generated.</strong> Copy the API Key and Secret Key from the highlighted client below
			and paste them into the client's <em>Courier Connector</em> plugin. That's all they need — no URL.
		</div>
	<?php elseif ('courier_saved' === $notice): ?>
		<div class="cc-alert cc-alert-ok">Client courier assignment saved.</div>
	<?php elseif ('store_error' === $notice): ?>
		<div class="cc-alert cc-alert-warn">Could not create the client — a client / company name is required.</div>
	<?php elseif ('store_done' === $notice): ?>
		<div class="cc-alert cc-alert-ok">Done.</div>
	<?php endif; ?>

	<div class="cc-grid-2">
		<div class="cc-panel">
			<h2><span class="dashicons dashicons-plus-alt"></span> Add a client &amp; generate keys</h2>
			<form method="post">
				<?php wp_nonce_field('cc_stores'); ?>
				<input type="hidden" name="cc_action" value="store_action" />
				<input type="hidden" name="sub_action" value="generate" />
				<div class="cc-form-grid">
					<label>Client / company name
						<input type="text" name="store_name" class="cc-input" placeholder="Fashion Store" required />
					</label>
					<label>Client login email <span class="cc-optional">(optional)</span>
						<input type="email" name="client_email" class="cc-input" placeholder="owner@fashionstore.com" />
					</label>
					<label>Courier for this client
						<select name="courier" class="cc-input">
							<?php foreach ($couriers as $key => $label): ?>
								<option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label>Booking mode
						<select name="courier_mode" class="cc-input">
							<option value="manual">Manual — book each order yourself</option>
							<option value="auto">Automated — book instantly on arrival</option>
						</select>
					</label>
				</div>
				<div class="cc-save-bar">
					<button class="cc-btn cc-btn-primary" type="submit"><span class="dashicons dashicons-admin-network"></span> Generate API Key + Secret</button>
				</div>
			</form>
		</div>

		<div class="cc-panel">
			<h2><span class="dashicons dashicons-info-outline"></span> How it links</h2>
			<ol class="cc-steps">
				<li>Add the client here — an <strong>API Key</strong> and <strong>Secret Key</strong> are generated.</li>
				<li>Install the connector plugin on the client's store.</li>
				<li>The client pastes the two keys — the store connects automatically (no URL needed).</li>
				<li>The client sets their <strong>pickup address &amp; parcel size</strong> in the connector.</li>
				<li>Orders flow in; you choose (or automate) Delhivery / DTDC <em>per client</em> here.</li>
			</ol>
		</div>
	</div>

	<?php if (empty($stores)): ?>
		<div class="cc-panel">
			<p class="cc-empty">No clients yet. Generate keys above to add one.</p>
		</div>
	<?php else: ?>
		<div class="cc-client-list">
			<?php foreach ($stores as $s): ?>
				<?php
				$pickup = array();
				if (!empty($s->pickup_json)) {
					$decoded = json_decode($s->pickup_json, true);
					if (is_array($decoded)) {
						$pickup = $decoded;
					}
				}
				$is_new = $new_store_id && (int) $s->id === $new_store_id;
				$initials = strtoupper(substr(trim((string) $s->store_name), 0, 2));
				$is_active = 'active' === $s->status;
				?>
				<div class="cc-client-card<?php echo $is_new ? ' cc-client-card-new' : ''; ?><?php echo $is_active ? '' : ' cc-client-card-disabled'; ?>">

					<div class="cc-client-head">
						<div class="cc-client-who">
							<span class="cc-client-avatar"><?php echo esc_html($initials); ?></span>
							<div class="cc-client-title">
								<span class="cc-client-name"><?php echo esc_html($s->store_name); ?></span>
								<span class="cc-client-sub">
									<?php if ($s->store_url): ?>
										<a href="<?php echo esc_url($s->store_url); ?>" target="_blank"><?php echo esc_html(preg_replace('#^https?://#', '', $s->store_url)); ?></a>
									<?php else: ?>
										Not connected yet
									<?php endif; ?>
								</span>
							</div>
						</div>
						<div class="cc-client-flags">
							<?php if ($s->store_url): ?>
								<span class="cc-badge cc-badge-delivered">Connected</span>
							<?php else: ?>
								<span class="cc-badge cc-badge-pending">Waiting for client</span>
							<?php endif; ?>
							<?php echo $is_active ? '<span class="cc-badge cc-badge-booked">Active</span>' : '<span class="cc-badge cc-badge-cancelled">Disabled</span>'; ?>
						</div>
						<div class="cc-client-head-actions">
							<form method="post" class="cc-inline-form">
								<?php wp_nonce_field('cc_stores'); ?>
								<input type="hidden" name="cc_action" value="store_action" />
								<input type="hidden" name="store_id" value="<?php echo esc_attr($s->id); ?>" />
								<?php if ($is_active): ?>
									<button class="cc-btn cc-btn-sm" name="sub_action" value="disable">Disable</button>
								<?php else: ?>
									<button class="cc-btn cc-btn-sm" name="sub_action" value="enable">Enable</button>
								<?php endif; ?>
								<button class="cc-btn cc-btn-sm" name="sub_action" value="regen_keys"
									onclick="return confirm('Regenerate keys? The client must re-connect with the new keys.');">New Keys</button>
								<button class="cc-btn cc-btn-sm cc-btn-danger" name="sub_action" value="delete"
									onclick="return confirm('Delete this client?');">Delete</button>
							</form>
						</div>
					</div>

					<div class="cc-client-keys">
						<div class="cc-keyrow">
							<span class="cc-keyrow-label">API Key</span>
							<code class="cc-keyrow-value"><?php echo esc_html($s->api_key); ?></code>
							<button type="button" class="cc-btn cc-btn-sm cc-copy" data-copy="<?php echo esc_attr($s->api_key); ?>">
								<span class="dashicons dashicons-clipboard"></span> Copy
							</button>
						</div>
						<div class="cc-keyrow">
							<span class="cc-keyrow-label">Secret Key</span>
							<code class="cc-keyrow-value"><?php echo esc_html($s->secret_key); ?></code>
							<button type="button" class="cc-btn cc-btn-sm cc-copy" data-copy="<?php echo esc_attr($s->secret_key); ?>">
								<span class="dashicons dashicons-clipboard"></span> Copy
							</button>
						</div>
					</div>

					<div class="cc-client-body">
						<div class="cc-client-cell">
							<span class="cc-cell-label">Courier &amp; booking mode</span>
							<form method="post" class="cc-courier-form">
								<?php wp_nonce_field('cc_stores'); ?>
								<input type="hidden" name="cc_action" value="store_action" />
								<input type="hidden" name="sub_action" value="set_courier" />
								<input type="hidden" name="store_id" value="<?php echo esc_attr($s->id); ?>" />
								<select name="courier" class="cc-input">
									<?php foreach ($couriers as $key => $label): ?>
										<option value="<?php echo esc_attr($key); ?>" <?php selected($s->courier ?: 'delhivery', $key); ?>><?php echo esc_html($label); ?></option>
									<?php endforeach; ?>
								</select>
								<select name="courier_mode" class="cc-input">
									<option value="manual" <?php selected($s->courier_mode ?: 'manual', 'manual'); ?>>Manual booking</option>
									<option value="auto" <?php selected($s->courier_mode, 'auto'); ?>>Automated booking</option>
								</select>
								<button class="cc-btn cc-btn-sm cc-btn-primary" type="submit">Save</button>
							</form>
							<span class="cc-cell-hint">
								<?php echo 'auto' === $s->courier_mode
									? 'Orders are booked instantly on arrival with ' . esc_html($couriers[$s->courier] ?? 'Delhivery') . '.'
									: 'Orders wait as Pending — push them from the Orders page.'; ?>
							</span>
						</div>

						<div class="cc-client-cell">
							<span class="cc-cell-label">Pickup address</span>
							<?php if (!empty($pickup['pickup_name'])): ?>
								<strong class="cc-cell-strong"><?php echo esc_html($pickup['pickup_name']); ?></strong>
								<span class="cc-cell-text">
									<?php echo esc_html(trim(($pickup['pickup_address'] ?? '') . ', ' . ($pickup['pickup_city'] ?? '') . ' ' . ($pickup['pickup_pincode'] ?? ''), ', ')); ?>
								</span>
								<?php if (!empty($pickup['pickup_phone'])): ?>
									<span class="cc-cell-text">📞 <?php echo esc_html($pickup['pickup_phone']); ?></span>
								<?php endif; ?>
								<?php if (!empty($pickup['default_weight'])): ?>
									<span class="cc-cell-hint">Parcel: <?php echo esc_html($pickup['default_weight']); ?> kg,
										<?php echo esc_html(($pickup['default_length'] ?? '10') . '×' . ($pickup['default_breadth'] ?? '10') . '×' . ($pickup['default_height'] ?? '10')); ?> cm</span>
								<?php endif; ?>
							<?php else: ?>
								<span class="cc-cell-text cc-cell-muted">Not set yet — the client sets it in their connector plugin.</span>
							<?php endif; ?>
						</div>

						<div class="cc-client-cell">
							<span class="cc-cell-label">Activity &amp; portal login</span>
							<span class="cc-cell-text"><strong><?php echo esc_html(number_format_i18n($s->orders_synced)); ?></strong> orders synced</span>
							<span class="cc-cell-text cc-cell-muted">Last sync: <?php echo esc_html($s->last_sync ?: '—'); ?></span>
							<?php if (!empty($s->client_user_id)): ?>
								<span class="cc-cell-text">👤 <?php echo esc_html(CC_Clients::label($s->client_user_id)); ?></span>
							<?php else: ?>
								<form method="post" class="cc-inline-form cc-linkform">
									<?php wp_nonce_field('cc_stores'); ?>
									<input type="hidden" name="cc_action" value="store_action" />
									<input type="hidden" name="sub_action" value="assign_client" />
									<input type="hidden" name="store_id" value="<?php echo esc_attr($s->id); ?>" />
									<input type="email" name="client_email" class="cc-input cc-input-sm"
										placeholder="client email" required />
									<button class="cc-btn cc-btn-sm" type="submit">Link</button>
								</form>
							<?php endif; ?>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
