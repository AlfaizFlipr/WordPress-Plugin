<?php
/**
 * Dashboard view — stat cards + quick links.
 *
 * @var array $cards
 * @package CourierConnector
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$configured = CC_Settings::is_configured();
?>
<div class="wrap cc-wrap">
	<div class="cc-topbar">
		<h1 class="cc-title"><span class="dashicons dashicons-airplane"></span> Naya Setu Courier — Dashboard</h1>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=cc-orders' ) ); ?>" class="cc-btn cc-btn-primary">Go to Orders</a>
	</div>

	<?php if ( ! $configured ) : ?>
		<div class="cc-alert cc-alert-warn">
			<strong>Setup needed.</strong> Add your Delhivery API token and pickup location under
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=cc-settings' ) ); ?>">Settings</a> to start creating shipments.
		</div>
	<?php endif; ?>

	<div class="cc-cards">
		<div class="cc-card cc-card-total">
			<div class="cc-card-label">Total Orders</div>
			<div class="cc-card-value"><?php echo esc_html( number_format_i18n( $cards['total'] ) ); ?></div>
		</div>
		<div class="cc-card cc-card-pending">
			<div class="cc-card-label">Pending Shipment</div>
			<div class="cc-card-value"><?php echo esc_html( number_format_i18n( $cards['pending'] ) ); ?></div>
		</div>
		<div class="cc-card cc-card-booked">
			<div class="cc-card-label">Booked</div>
			<div class="cc-card-value"><?php echo esc_html( number_format_i18n( $cards['booked'] ) ); ?></div>
		</div>
		<div class="cc-card cc-card-transit">
			<div class="cc-card-label">In Transit</div>
			<div class="cc-card-value"><?php echo esc_html( number_format_i18n( $cards['in-transit'] ) ); ?></div>
		</div>
		<div class="cc-card cc-card-delivered">
			<div class="cc-card-label">Delivered</div>
			<div class="cc-card-value"><?php echo esc_html( number_format_i18n( $cards['delivered'] ) ); ?></div>
		</div>
	</div>

	<div class="cc-grid-2">
		<div class="cc-panel">
			<h2>Quick actions</h2>
			<ul class="cc-quick">
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=cc-orders&ship_status=pending' ) ); ?>">View pending shipments</a></li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=cc-orders&ship_status=in-transit' ) ); ?>">Track in-transit orders</a></li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=cc-websites' ) ); ?>">Manage connected stores</a></li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=cc-settings' ) ); ?>">Delhivery API &amp; pickup settings</a></li>
			</ul>
		</div>
		<div class="cc-panel">
			<h2>Connection</h2>
			<p><strong>Delhivery API:</strong>
				<?php echo $configured ? '<span class="cc-badge cc-badge-delivered">Connected</span>' : '<span class="cc-badge cc-badge-cancelled">Not configured</span>'; ?>
			</p>
			<p><strong>Environment:</strong> <?php echo esc_html( ucfirst( CC_Settings::get( 'environment', 'production' ) ) ); ?></p>
			<p><strong>Pickup location:</strong> <?php echo esc_html( CC_Settings::get( 'pickup_name' ) ?: '—' ); ?></p>
			<p><strong>Connect-store endpoint:</strong><br>
				<code><?php echo esc_html( rest_url( 'courier/v1/connect-store' ) ); ?></code>
			</p>
		</div>
	</div>
</div>
