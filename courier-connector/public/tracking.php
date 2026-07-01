<?php
/**
 * Customer tracking page template — used by [naya_setu_track] shortcode.
 *
 * Variables in scope:
 *   $atts  — shortcode attributes
 *   $awb   — sanitized AWB from ?awb=
 *   $track — array {status, location, scans, error?} or null
 *
 * @package CourierConnector
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$status_icons = array(
	'Delivered'        => '✅',
	'Out for Delivery' => '🚚',
	'In Transit'       => '📦',
	'Manifested'       => '📋',
	'RTO'              => '↩️',
	'Cancelled'        => '❌',
);
$icon = '📦';
if ( $track && ! empty( $track['status'] ) ) {
	foreach ( $status_icons as $k => $v ) {
		if ( false !== stripos( $track['status'], $k ) ) {
			$icon = $v;
			break;
		}
	}
}
?>
<div class="cc-track-wrap" style="max-width:700px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif">

	<div class="cc-track-header" style="text-align:center;padding:32px 0 24px">
		<h2 style="margin:0 0 4px;font-size:22px;font-weight:700"><?php echo esc_html( $atts['title'] ); ?></h2>
		<p style="margin:0;color:#6b7280;font-size:14px">Enter your AWB / waybill number to see live shipment status</p>
	</div>

	<form method="get" style="display:flex;gap:8px;margin-bottom:28px">
		<?php
		// Preserve all existing query params except awb.
		foreach ( $_GET as $k => $v ) {
			if ( 'awb' !== $k ) {
				echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
			}
		}
		?>
		<input
			type="text"
			name="awb"
			value="<?php echo esc_attr( $awb ); ?>"
			placeholder="e.g. 1234567890"
			required
			style="flex:1;padding:10px 14px;border:1px solid #d1d5db;border-radius:6px;font-size:14px"
		/>
		<button type="submit" style="padding:10px 22px;background:#2563eb;color:#fff;border:none;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer">Track</button>
	</form>

	<?php if ( $awb && null !== $track ) : ?>

		<?php if ( ! empty( $track['error'] ) ) : ?>
			<div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:16px 20px;color:#92400e;font-size:14px">
				<strong>AWB <?php echo esc_html( $awb ); ?>:</strong> <?php echo esc_html( $track['error'] ); ?>
			</div>

		<?php else : ?>

			<!-- Status card -->
			<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:20px 24px;margin-bottom:24px;display:flex;align-items:center;gap:16px">
				<span style="font-size:36px"><?php echo $icon; // phpcs:ignore ?></span>
				<div>
					<div style="font-size:18px;font-weight:700;color:#166534"><?php echo esc_html( $track['status'] ); ?></div>
					<?php if ( ! empty( $track['location'] ) ) : ?>
						<div style="color:#4b5563;font-size:13px;margin-top:2px">📍 <?php echo esc_html( $track['location'] ); ?></div>
					<?php endif; ?>
					<div style="color:#6b7280;font-size:12px;margin-top:4px">AWB: <code><?php echo esc_html( $awb ); ?></code></div>
				</div>
			</div>

			<!-- Scan timeline -->
			<?php if ( ! empty( $track['scans'] ) ) : ?>
				<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;margin-bottom:24px">
					<div style="padding:12px 20px;background:#f9fafb;border-bottom:1px solid #e5e7eb;font-size:13px;font-weight:600;color:#374151">
						Shipment Events
					</div>
					<table style="width:100%;border-collapse:collapse;font-size:13px">
						<thead>
							<tr style="background:#f3f4f6">
								<th style="text-align:left;padding:8px 16px;font-weight:600;color:#374151;border-bottom:1px solid #e5e7eb">Date &amp; Time</th>
								<th style="text-align:left;padding:8px 16px;font-weight:600;color:#374151;border-bottom:1px solid #e5e7eb">Activity</th>
								<th style="text-align:left;padding:8px 16px;font-weight:600;color:#374151;border-bottom:1px solid #e5e7eb">Location</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $track['scans'] as $i => $scan ) : ?>
								<tr style="<?php echo 0 === $i ? 'background:#f0fdf4' : ''; ?>">
									<td style="padding:9px 16px;border-bottom:1px solid #f3f4f6;color:#6b7280"><?php echo esc_html( $scan['time'] ?? '' ); ?></td>
									<td style="padding:9px 16px;border-bottom:1px solid #f3f4f6;<?php echo 0 === $i ? 'font-weight:600;color:#166534' : 'color:#374151'; ?>"><?php echo esc_html( $scan['status'] ?? '' ); ?></td>
									<td style="padding:9px 16px;border-bottom:1px solid #f3f4f6;color:#6b7280"><?php echo esc_html( $scan['location'] ?? '' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php else : ?>
				<p style="color:#6b7280;font-size:13px;text-align:center">No scan events recorded yet.</p>
			<?php endif; ?>

		<?php endif; ?>

	<?php endif; ?>

</div>
