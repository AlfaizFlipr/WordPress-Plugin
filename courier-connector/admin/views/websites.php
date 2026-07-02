<?php

if (!defined('ABSPATH')) {
	exit;
}
?>
<div class="wrap cc-wrap">
	<div class="cc-topbar">
		<h1 class="cc-title"><span class="dashicons dashicons-admin-site-alt3"></span> Connected Stores</h1>
	</div>

	<div class="cc-alert cc-alert-info">
		External WooCommerce stores connect by calling
		<code><?php echo esc_html(rest_url('courier/v1/connect-store')); ?></code>
		and then push orders to <code><?php echo esc_html(rest_url('courier/v1/orders')); ?></code>
		using the returned API key in the <code>X-CC-Api-Key</code> header.
	</div>
	<div class="cc-alert cc-alert-ok">
		<strong>Client Portal:</strong> create a WordPress Page and add the shortcode
		<code>[naya_setu_client_portal]</code>. Link a client email to a store below — that
		owner can then log in at that page and manage <em>only</em> their own shipments.
	</div>

	<div class="cc-grid-2">
		<div class="cc-panel">
			<h2>Add / generate a store key</h2>
			<form method="post">
				<?php wp_nonce_field('cc_stores'); ?>
				<input type="hidden" name="cc_action" value="store_action" />
				<input type="hidden" name="sub_action" value="add" />
				<p><label>Store name<br><input type="text" name="store_name" class="cc-input"
							placeholder="Fashion Store" /></label></p>
				<p><label>Store URL<br><input type="url" name="store_url" class="cc-input"
							placeholder="https://fashionstore.com" required /></label></p>
				<p><label>Callback base URL (for AWB push-back)<br><input type="url" name="callback_url"
							class="cc-input" placeholder="https://fashionstore.com/wp-json/courier/v1/" /></label></p>
				<p><label>Client login email (optional — creates a Client Portal account)<br><input type="email"
							name="client_email" class="cc-input" placeholder="owner@fashionstore.com" /></label></p>
				<button class="cc-btn cc-btn-primary" type="submit">Generate API Key</button>
			</form>
		</div>

		<div class="cc-panel">
			<h2>How it links</h2>
			<ol class="cc-steps">
				<li>Generate (or auto-create) a store key here.</li>
				<li>Install the companion connector plugin on the client store.</li>
				<li>Enter this dashboard URL + API key in the connector.</li>
				<li>Orders sync in automatically; AWB &amp; tracking sync back.</li>
			</ol>
		</div>
	</div>

	<div class="cc-tablewrap">
		<table class="cc-table">
			<thead>
				<tr>
					<th>Store</th>
					<th>URL</th>
					<th>API Key</th>
					<th>Client Login</th>
					<th>Status</th>
					<th>Synced</th>
					<th>Last Sync</th>
					<th>Actions</th>
				</tr>
			</thead>
			<tbody>
				<?php if (empty($stores)): ?>
					<tr>
						<td colspan="8" class="cc-empty">No stores connected yet.</td>
					</tr>
				<?php else: ?>
					<?php foreach ($stores as $s): ?>
						<tr>
							<td><strong><?php echo esc_html($s->store_name); ?></strong></td>
							<td><a href="<?php echo esc_url($s->store_url); ?>"
									target="_blank"><?php echo esc_html($s->store_url); ?></a></td>
							<td><code class="cc-key"><?php echo esc_html($s->api_key); ?></code></td>
							<td>
								<?php if (!empty($s->client_user_id)): ?>
									<?php echo esc_html(CC_Clients::label($s->client_user_id)); ?>
								<?php else: ?>
									<form method="post" style="display:flex;gap:4px;">
										<?php wp_nonce_field('cc_stores'); ?>
										<input type="hidden" name="cc_action" value="store_action" />
										<input type="hidden" name="sub_action" value="assign_client" />
										<input type="hidden" name="store_id" value="<?php echo esc_attr($s->id); ?>" />
										<input type="email" name="client_email" class="cc-input"
											style="padding:4px 6px;font-size:12px;" placeholder="client email" required />
										<button class="cc-btn cc-btn-sm" type="submit">Link</button>
									</form>
								<?php endif; ?>
							</td>
							<td><?php echo 'active' === $s->status ? '<span class="cc-badge cc-badge-delivered">Active</span>' : '<span class="cc-badge cc-badge-cancelled">Disabled</span>'; ?>
							</td>
							<td><?php echo esc_html(number_format_i18n($s->orders_synced)); ?></td>
							<td><?php echo esc_html($s->last_sync ?: '—'); ?></td>
							<td class="cc-actions">
								<form method="post" style="display:inline">
									<?php wp_nonce_field('cc_stores'); ?>
									<input type="hidden" name="cc_action" value="store_action" />
									<input type="hidden" name="store_id" value="<?php echo esc_attr($s->id); ?>" />
									<?php if ('active' === $s->status): ?>
										<button class="cc-btn cc-btn-sm" name="sub_action" value="disable">Disable</button>
									<?php else: ?>
										<button class="cc-btn cc-btn-sm" name="sub_action" value="enable">Enable</button>
									<?php endif; ?>
									<button class="cc-btn cc-btn-sm cc-btn-danger" name="sub_action" value="delete"
										onclick="return confirm('Delete this store?');">Delete</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
</div>