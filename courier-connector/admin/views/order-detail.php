<?php

if (!defined('ABSPATH')) {
	exit;
}
if (!$order->valid()) {
	echo '<div class="wrap cc-wrap"><p>Order not found.</p></div>';
	return;
}
$wc = $order->wc();
$awb = $order->get_awb();
$back = admin_url('admin.php?page=cc-orders');
?>
<div class="wrap cc-wrap">
	<div class="cc-topbar">
		<h1 class="cc-title">Order #<?php echo esc_html($wc->get_order_number()); ?></h1>
		<div>
			<a href="<?php echo esc_url($back); ?>" class="cc-btn cc-btn-ghost">← Back</a>
			<a href="<?php echo esc_url($wc->get_edit_order_url()); ?>" class="cc-btn">Open in WooCommerce</a>
			<?php if (!$awb): ?>
				<span class="cc-push-group">
					<select class="cc-courier-select" data-order="<?php echo esc_attr($order->id()); ?>">
						<?php foreach (CC_Courier_Registry::all() as $ck => $cl): ?>
							<option value="<?php echo esc_attr($ck); ?>" <?php selected(CC_Shipment::resolve_courier($order), $ck); ?>><?php echo esc_html($cl); ?></option>
						<?php endforeach; ?>
					</select>
					<button class="cc-btn cc-btn-primary cc-push" data-order="<?php echo esc_attr($order->id()); ?>">Push
						Shipment</button>
				</span>
			<?php else: ?>
				<button class="cc-btn cc-track" data-order="<?php echo esc_attr($order->id()); ?>">Track</button>
				<button class="cc-btn cc-label" data-order="<?php echo esc_attr($order->id()); ?>">Print Label</button>
				<button class="cc-btn cc-btn-danger cc-cancel" data-order="<?php echo esc_attr($order->id()); ?>">Cancel
					Shipment</button>
			<?php endif; ?>
		</div>
	</div>

	<div class="cc-grid-3">
		<div class="cc-panel">
			<h2>Customer</h2>
			<p><strong><?php echo esc_html(trim($wc->get_billing_first_name() . ' ' . $wc->get_billing_last_name())); ?></strong>
			</p>
			<p><?php echo esc_html($wc->get_billing_phone()); ?></p>
			<p><?php echo esc_html($wc->get_billing_email()); ?></p>
		</div>
		<div class="cc-panel">
			<h2>Shipping Address</h2>
			<p>
				<?php echo esc_html($wc->get_shipping_address_1() ?: $wc->get_billing_address_1()); ?><br>
				<?php echo esc_html($wc->get_shipping_city() ?: $wc->get_billing_city()); ?>,
				<?php echo esc_html($wc->get_shipping_state() ?: $wc->get_billing_state()); ?><br>
				<?php echo esc_html($wc->get_shipping_postcode() ?: $wc->get_billing_postcode()); ?>
			</p>
		</div>
		<div class="cc-panel">
			<h2>Shipment</h2>
			<p><strong>Status:</strong> <span
					class="cc-badge cc-badge-<?php echo esc_attr($order->get_ship_status()); ?>"><?php echo esc_html($order->get_ship_status_label()); ?></span>
			</p>
			<p><strong>Courier:</strong>
				<?php
				$courier_name = $wc->get_meta('_cc_courier');
				if ($courier_name) {
					$courier_cls = 'DTDC' === $courier_name ? 'cc-courier-dtdc' : ('Delhivery' === $courier_name ? 'cc-courier-delhivery' : 'cc-courier-muted');
					echo '<span class="cc-courier-badge ' . esc_attr($courier_cls) . '">' . esc_html($courier_name) . '</span>';
				} else {
					echo '—';
				}
				?>
			</p>
			<p><strong>AWB:</strong> <?php echo $awb ? '<code>' . esc_html($awb) . '</code>' : '—'; ?></p>
			<p><strong>Payment:</strong>
				<?php echo $order->is_cod() ? 'COD (₹' . esc_html($order->cod_amount()) . ')' : 'Prepaid'; ?></p>
			<?php if ($awb): ?>
				<p><a class="cc-btn cc-btn-sm" target="_blank"
						href="<?php echo esc_url($order->get_tracking_url()); ?>">Open tracking page</a></p>
			<?php endif; ?>
		</div>
	</div>

	<div class="cc-grid-2">
		<div class="cc-panel">
			<h2>Products</h2>
			<table class="cc-table cc-table-inner">
				<thead>
					<tr>
						<th>Item</th>
						<th>Qty</th>
						<th>Total</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($wc->get_items() as $item): ?>
						<tr>
							<td><?php echo esc_html($item->get_name()); ?></td>
							<td><?php echo esc_html($item->get_quantity()); ?></td>
							<td><?php echo wp_kses_post(wc_price($item->get_total())); ?></td>
						</tr>
					<?php endforeach; ?>
					<tr class="cc-row-total">
						<td colspan="2">Order Total</td>
						<td><?php echo wp_kses_post($wc->get_formatted_order_total()); ?></td>
					</tr>
				</tbody>
			</table>
		</div>

		<div class="cc-panel">
			<h2>Timeline</h2>
			<div id="cc-timeline" class="cc-timeline">
				<?php
				$notes = wc_get_order_notes(array('order_id' => $order->id(), 'order_by' => 'date_created', 'order' => 'ASC'));
				if ($notes):
					foreach ($notes as $note):
						?>
						<div class="cc-tl-item">
							<div class="cc-tl-dot"></div>
							<div class="cc-tl-body">
								<div class="cc-tl-text"><?php echo wp_kses_post($note->content); ?></div>
								<div class="cc-tl-time">
									<?php echo esc_html($note->date_created ? $note->date_created->date_i18n('d M Y, H:i') : ''); ?>
								</div>
							</div>
						</div>
						<?php
					endforeach;
				else:
					echo '<p>No events yet.</p>';
				endif;
				?>
			</div>
		</div>
	</div>
</div>