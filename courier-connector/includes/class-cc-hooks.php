<?php

if (!defined('ABSPATH')) {
	exit;
}

class CC_Hooks
{

	public function register()
	{
		add_action('woocommerce_new_order', array($this, 'on_new_order'), 10, 1);
		add_action('woocommerce_order_status_changed', array($this, 'on_status_changed'), 10, 4);

		add_action('add_meta_boxes', array($this, 'add_meta_box'));
	}

	public function on_new_order($order_id)
	{
		$order = wc_get_order($order_id);
		if (!$order) {
			return;
		}
		if ('' === (string) $order->get_meta('_cc_ship_status')) {
			$order->update_meta_data('_cc_ship_status', 'pending');
			$order->save();
		}
	}

	public function on_status_changed($order_id, $from, $to, $order)
	{
		if ('cancelled' === $to) {
			$cc = new CC_Order($order);
			if ($cc->valid() && 'cancelled' !== $cc->get_ship_status() && '' === $cc->get_awb()) {
				$cc->set_courier_data(array('ship_status' => 'cancelled'));
			}
		}
	}

	public function add_meta_box()
	{
		$screen = class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController') && wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id('shop-order')
			: 'shop_order';

		add_meta_box(
			'cc_courier_box',
			'Courier / Delhivery',
			array($this, 'render_meta_box'),
			$screen,
			'side',
			'high'
		);
	}

	public function render_meta_box($post_or_order)
	{
		$order_id = $post_or_order instanceof WP_Post ? $post_or_order->ID : $post_or_order->get_id();
		$cc = new CC_Order($order_id);
		if (!$cc->valid()) {
			return;
		}
		$awb = $cc->get_awb();
		echo '<div class="cc-meta-box">';
		echo '<p><strong>Status:</strong> ' . esc_html($cc->get_ship_status_label()) . '</p>';
		if ($awb) {
			echo '<p><strong>AWB:</strong> ' . esc_html($awb) . '</p>';
			echo '<p><a href="' . esc_url($cc->get_tracking_url()) . '" target="_blank" class="button">Track</a></p>';
		} else {
			$url = admin_url('admin.php?page=cc-orders&cc_view=' . $order_id);
			echo '<p>No shipment yet.</p>';
			echo '<p><a href="' . esc_url($url) . '" class="button button-primary">Open in Courier Dashboard</a></p>';
		}
		echo '</div>';
	}
}
