<?php

if (!defined('ABSPATH')) {
	exit;
}
$couriers = array();
foreach (CC_Courier_Registry::all() as $ck => $cl) {
	$couriers[$ck] = array('label' => $cl, 'adapter' => CC_Courier_Registry::get($ck));
}
$any_configured = false;
foreach ($couriers as $c) {
	if ($c['adapter']->is_configured()) {
		$any_configured = true;
		break;
	}
}
?>
<div class="wrap cc-wrap">
	<div class="cc-topbar">
		<h1 class="cc-title"><span class="dashicons dashicons-airplane"></span> Naya Setu Courier — Dashboard</h1>
		<a href="<?php echo esc_url(admin_url('admin.php?page=cc-orders')); ?>" class="cc-btn cc-btn-primary">Go to
			Orders</a>
	</div>

	<?php if (!$any_configured): ?>
		<div class="cc-alert cc-alert-warn">
			<strong>Setup needed.</strong> Add at least one courier's API credentials under
			<a href="<?php echo esc_url(admin_url('admin.php?page=cc-settings')); ?>">Settings</a> to start creating
			shipments. Pickup addresses come from each client's connector plugin.
		</div>
	<?php endif; ?>

	<div class="cc-cards">
		<div class="cc-card cc-card-total">
			<span class="dashicons dashicons-list-view cc-card-icon"></span>
			<div class="cc-card-label">Total Orders</div>
			<div class="cc-card-value"><?php echo esc_html(number_format_i18n($cards['total'])); ?></div>
		</div>
		<div class="cc-card cc-card-pending">
			<span class="dashicons dashicons-clock cc-card-icon"></span>
			<div class="cc-card-label">Pending Shipment</div>
			<div class="cc-card-value"><?php echo esc_html(number_format_i18n($cards['pending'])); ?></div>
		</div>
		<div class="cc-card cc-card-booked">
			<span class="dashicons dashicons-yes-alt cc-card-icon"></span>
			<div class="cc-card-label">Booked</div>
			<div class="cc-card-value"><?php echo esc_html(number_format_i18n($cards['booked'])); ?></div>
		</div>
		<div class="cc-card cc-card-transit">
			<span class="dashicons dashicons-airplane cc-card-icon"></span>
			<div class="cc-card-label">In Transit</div>
			<div class="cc-card-value"><?php echo esc_html(number_format_i18n($cards['in-transit'])); ?></div>
		</div>
		<div class="cc-card cc-card-delivered">
			<span class="dashicons dashicons-flag cc-card-icon"></span>
			<div class="cc-card-label">Delivered</div>
			<div class="cc-card-value"><?php echo esc_html(number_format_i18n($cards['delivered'])); ?></div>
		</div>
	</div>

	<div class="cc-grid-2">
		<div class="cc-panel">
			<h2>Quick actions</h2>
			<ul class="cc-quick">
				<li><a href="<?php echo esc_url(admin_url('admin.php?page=cc-orders&ship_status=pending')); ?>">View
						pending shipments</a></li>
				<li><a href="<?php echo esc_url(admin_url('admin.php?page=cc-orders&ship_status=in-transit')); ?>">Track
						in-transit orders</a></li>
				<li><a href="<?php echo esc_url(admin_url('admin.php?page=cc-websites')); ?>">Manage clients &amp;
						API keys</a></li>
				<li><a href="<?php echo esc_url(admin_url('admin.php?page=cc-settings')); ?>">Courier API
						settings</a></li>
			</ul>
		</div>
		<div class="cc-panel">
			<h2>Couriers</h2>
			<?php foreach ($couriers as $ck => $c): ?>
				<p>
					<span
						class="cc-courier-badge <?php echo 'dtdc' === $ck ? 'cc-courier-dtdc' : 'cc-courier-delhivery'; ?>"><?php echo esc_html($c['label']); ?></span>
					<?php echo $c['adapter']->is_configured() ? '<span class="cc-badge cc-badge-delivered">Connected</span>' : '<span class="cc-badge cc-badge-cancelled">Not configured</span>'; ?>
					<?php if (CC_Settings::get('default_courier', 'delhivery') === $ck): ?><span
							class="cc-tag">Default</span><?php endif; ?>
				</p>
			<?php endforeach; ?>
			<p><strong>Pickup locations:</strong> set per client in their connector plugin (see Clients page).</p>
			<p><strong style="margin-top: 10px;">Key handshake endpoint:</strong><br>
				<code><?php echo esc_html(rest_url('courier/v1/handshake')); ?></code>
			</p>
		</div>
	</div>
</div>