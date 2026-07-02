<?php

if (!defined('ABSPATH')) {
	exit;
}

if (!function_exists('cc_badge')) {
	function cc_badge($status, $label)
	{
		return '<span class="cc-badge cc-badge-' . sanitize_html_class($status) . '">' . esc_html($label) . '</span>';
	}
}

$self = get_permalink();
$user = wp_get_current_user();
$is_admin = current_user_can('manage_woocommerce');
?>
<div class="ns-portal">
	<div class="ns-portal-bar">
		<div class="ns-portal-brand">
			<span class="ns-logo">NS</span>
			<div>
				<strong>Naya Setu Courier</strong>
				<small><?php echo esc_html($is_admin ? 'Admin view — all stores' : 'Client Portal'); ?></small>
			</div>
		</div>
		<div class="ns-portal-user">
			<span>👤 <?php echo esc_html($user->display_name); ?></span>
			<a class="cc-btn cc-btn-sm" href="<?php echo esc_url(wp_logout_url($self)); ?>">Logout</a>
		</div>
	</div>

	<div class="cc-wrap">
		<div class="cc-cards">
			<div class="cc-card cc-card-total">
				<div class="cc-card-label">Total Orders</div>
				<div class="cc-card-value"><?php echo esc_html(number_format_i18n($cards['total'])); ?></div>
			</div>
			<div class="cc-card cc-card-pending">
				<div class="cc-card-label">Pending</div>
				<div class="cc-card-value"><?php echo esc_html(number_format_i18n($cards['pending'])); ?></div>
			</div>
			<div class="cc-card cc-card-booked">
				<div class="cc-card-label">Booked</div>
				<div class="cc-card-value"><?php echo esc_html(number_format_i18n($cards['booked'])); ?></div>
			</div>
			<div class="cc-card cc-card-transit">
				<div class="cc-card-label">In Transit</div>
				<div class="cc-card-value"><?php echo esc_html(number_format_i18n($cards['in-transit'])); ?></div>
			</div>
			<div class="cc-card cc-card-delivered">
				<div class="cc-card-label">Delivered</div>
				<div class="cc-card-value"><?php echo esc_html(number_format_i18n($cards['delivered'])); ?></div>
			</div>
		</div>

		<div class="cc-topbar">
			<h1 class="cc-title">My Shipments</h1>
			<button class="cc-btn cc-btn-primary" id="cc-bulk-push" type="button">Push selected to Delhivery</button>
		</div>

		<form method="get" class="cc-filters" action="<?php echo esc_url($self); ?>">
			<input type="search" name="s" value="<?php echo esc_attr($filters['search']); ?>"
				placeholder="Search order, customer…" />
			<select name="ship_status">
				<option value="">All statuses</option>
				<?php foreach (CC_Order::SHIP_STATUSES as $k => $v): ?>
					<option value="<?php echo esc_attr($k); ?>" <?php selected($filters['ship_status'], $k); ?>>
						<?php echo esc_html($v); ?></option>
				<?php endforeach; ?>
			</select>
			<button class="cc-btn" type="submit">Filter</button>
			<a class="cc-btn cc-btn-ghost" href="<?php echo esc_url($self); ?>">Reset</a>
		</form>

		<div class="cc-tablewrap">
			<table class="cc-table">
				<thead>
					<tr>
						<th class="cc-col-check"><input type="checkbox" id="cc-check-all" /></th>
						<th>Order</th>
						<th>Customer</th>
						<th>City</th>
						<th>Amount</th>
						<th>Payment</th>
						<th>Shipment</th>
						<th>AWB</th>
						<th>Date</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php if (empty($result['orders'])): ?>
						<tr>
							<td colspan="10" class="cc-empty">No orders found.</td>
						</tr>
					<?php else: ?>
						<?php
						foreach ($result['orders'] as $wc_order):
							$o = new CC_Order($wc_order);
							$awb = $o->get_awb();
							$durl = add_query_arg('ns_order', $o->id(), $self);
							?>
							<tr>
								<td><input type="checkbox" class="cc-row-check" value="<?php echo esc_attr($o->id()); ?>" />
								</td>
								<td><a
										href="<?php echo esc_url($durl); ?>"><strong>#<?php echo esc_html($wc_order->get_order_number()); ?></strong></a>
								</td>
								<td><?php echo esc_html(trim($wc_order->get_billing_first_name() . ' ' . $wc_order->get_billing_last_name())); ?>
								</td>
								<td><?php echo esc_html($wc_order->get_billing_city()); ?></td>
								<td><?php echo wp_kses_post($wc_order->get_formatted_order_total()); ?></td>
								<td><?php echo $o->is_cod() ? '<span class="cc-tag cc-tag-cod">COD</span>' : '<span class="cc-tag cc-tag-prepaid">Prepaid</span>'; ?>
								</td>
								<td><?php echo cc_badge($o->get_ship_status(), $o->get_ship_status_label()); ?></td>
								<td><?php echo $awb ? '<code>' . esc_html($awb) . '</code>' : '—'; ?></td>
								<td><?php echo esc_html($wc_order->get_date_created() ? $wc_order->get_date_created()->date_i18n('d M, H:i') : ''); ?>
								</td>
								<td class="cc-actions">
									<?php if (!$awb): ?>
										<button class="cc-btn cc-btn-sm cc-btn-primary cc-push"
											data-order="<?php echo esc_attr($o->id()); ?>">Push</button>
									<?php else: ?>
										<button class="cc-btn cc-btn-sm cc-track"
											data-order="<?php echo esc_attr($o->id()); ?>">Track</button>
										<button class="cc-btn cc-btn-sm cc-label"
											data-order="<?php echo esc_attr($o->id()); ?>">Label</button>
									<?php endif; ?>
									<a class="cc-btn cc-btn-sm cc-btn-ghost" href="<?php echo esc_url($durl); ?>">View</a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>

		<?php if ($result['pages'] > 1): ?>
			<div class="cc-pagination">
				<?php for ($i = 1; $i <= $result['pages']; $i++): ?>
					<a class="cc-page <?php echo $i === (int) $result['paged'] ? 'cc-page-active' : ''; ?>"
						href="<?php echo esc_url(add_query_arg(array('paged' => $i), $self)); ?>"><?php echo esc_html($i); ?></a>
				<?php endfor; ?>
			</div>
		<?php endif; ?>
	</div>
</div>