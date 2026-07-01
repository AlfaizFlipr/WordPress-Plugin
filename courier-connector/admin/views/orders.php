<?php
/**
 * Orders list view with filters + table.
 *
 * @var array $result
 * @var array $filters
 * @var array $stores
 * @package CourierConnector
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render a status badge.
 */
if ( ! function_exists( 'cc_badge' ) ) {
	function cc_badge( $status, $label ) {
		$cls = 'cc-badge cc-badge-' . sanitize_html_class( $status );
		return '<span class="' . esc_attr( $cls ) . '">' . esc_html( $label ) . '</span>';
	}
}

$base = admin_url( 'admin.php?page=cc-orders' );
?>
<div class="wrap cc-wrap">
	<div class="cc-topbar">
		<h1 class="cc-title"><span class="dashicons dashicons-list-view"></span> Orders</h1>
		<button class="cc-btn cc-btn-primary" id="cc-bulk-push" type="button">Push selected to Delhivery</button>
	</div>

	<?php if ( isset( $_GET['cc_notice'] ) ) : ?>
		<div class="cc-alert cc-alert-ok">Action completed.</div>
	<?php endif; ?>

	<form method="get" class="cc-filters">
		<input type="hidden" name="page" value="cc-orders" />
		<input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="Search order, customer, email…" />

		<select name="ship_status">
			<option value="">All statuses</option>
			<?php foreach ( CC_Order::SHIP_STATUSES as $k => $v ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $filters['ship_status'], $k ); ?>><?php echo esc_html( $v ); ?></option>
			<?php endforeach; ?>
		</select>

		<select name="payment">
			<option value="">All payments</option>
			<option value="cod" <?php selected( $filters['payment'], 'cod' ); ?>>COD</option>
			<option value="" disabled>──</option>
		</select>

		<select name="store">
			<option value="0">All stores</option>
			<?php foreach ( $stores as $s ) : ?>
				<option value="<?php echo esc_attr( $s->id ); ?>" <?php selected( $filters['store'], (int) $s->id ); ?>><?php echo esc_html( $s->store_name ); ?></option>
			<?php endforeach; ?>
		</select>

		<input type="date" name="date_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>" />
		<input type="date" name="date_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>" />

		<button class="cc-btn" type="submit">Filter</button>
		<a class="cc-btn cc-btn-ghost" href="<?php echo esc_url( $base ); ?>">Reset</a>
	</form>

	<div class="cc-tablewrap">
		<table class="cc-table">
			<thead>
				<tr>
					<th class="cc-col-check"><input type="checkbox" id="cc-check-all" /></th>
					<th>Order</th>
					<th>Store</th>
					<th>Customer</th>
					<th>City</th>
					<th>Amount</th>
					<th>Payment</th>
					<th>Order Status</th>
					<th>Shipment</th>
					<th>AWB</th>
					<th>Date</th>
					<th>Actions</th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $result['orders'] ) ) : ?>
				<tr><td colspan="12" class="cc-empty">No orders found.</td></tr>
			<?php else : ?>
				<?php
				foreach ( $result['orders'] as $wc_order ) :
					$o          = new CC_Order( $wc_order );
					$store_id   = (int) $wc_order->get_meta( '_cc_source_store' );
					$store_name = $store_id ? ( CC_Website::get( $store_id )->store_name ?? '—' ) : 'Local';
					$awb        = $o->get_awb();
					$detail_url = add_query_arg( 'cc_view', $o->id(), $base );
					?>
					<tr>
						<td><input type="checkbox" class="cc-row-check" value="<?php echo esc_attr( $o->id() ); ?>" /></td>
						<td><a href="<?php echo esc_url( $detail_url ); ?>"><strong>#<?php echo esc_html( $wc_order->get_order_number() ); ?></strong></a></td>
						<td><?php echo esc_html( $store_name ); ?></td>
						<td><?php echo esc_html( trim( $wc_order->get_billing_first_name() . ' ' . $wc_order->get_billing_last_name() ) ); ?></td>
						<td><?php echo esc_html( $wc_order->get_billing_city() ); ?></td>
						<td><?php echo wp_kses_post( $wc_order->get_formatted_order_total() ); ?></td>
						<td><?php echo $o->is_cod() ? '<span class="cc-tag cc-tag-cod">COD</span>' : '<span class="cc-tag cc-tag-prepaid">Prepaid</span>'; ?></td>
						<td><?php echo esc_html( wc_get_order_status_name( $wc_order->get_status() ) ); ?></td>
						<td><?php echo cc_badge( $o->get_ship_status(), $o->get_ship_status_label() ); ?></td>
						<td><?php echo $awb ? '<code>' . esc_html( $awb ) . '</code>' : '—'; ?></td>
						<td><?php echo esc_html( $wc_order->get_date_created() ? $wc_order->get_date_created()->date_i18n( 'd M, H:i' ) : '' ); ?></td>
						<td class="cc-actions">
							<?php if ( ! $awb ) : ?>
								<button class="cc-btn cc-btn-sm cc-btn-primary cc-push" data-order="<?php echo esc_attr( $o->id() ); ?>">Push</button>
							<?php else : ?>
								<button class="cc-btn cc-btn-sm cc-track" data-order="<?php echo esc_attr( $o->id() ); ?>">Track</button>
								<button class="cc-btn cc-btn-sm cc-label" data-order="<?php echo esc_attr( $o->id() ); ?>">Label</button>
							<?php endif; ?>
							<a class="cc-btn cc-btn-sm cc-btn-ghost" href="<?php echo esc_url( $detail_url ); ?>">View</a>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
	</div>

	<?php if ( $result['pages'] > 1 ) : ?>
		<div class="cc-pagination">
			<?php
			for ( $i = 1; $i <= $result['pages']; $i++ ) {
				$url = add_query_arg( array_merge( $filters, array( 'paged' => $i, 'page' => 'cc-orders' ) ), admin_url( 'admin.php' ) );
				$cls = $i === (int) $result['paged'] ? 'cc-page cc-page-active' : 'cc-page';
				echo '<a class="' . esc_attr( $cls ) . '" href="' . esc_url( $url ) . '">' . esc_html( $i ) . '</a>';
			}
			?>
		</div>
	<?php endif; ?>
</div>
