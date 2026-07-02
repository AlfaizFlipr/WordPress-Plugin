<?php

if (!defined('ABSPATH')) {
	exit;
}
?>
<div class="wrap cc-wrap">
	<div class="cc-topbar">
		<h1 class="cc-title"><span class="dashicons dashicons-media-text"></span> Logs</h1>
	</div>
	<div class="cc-tablewrap">
		<table class="cc-table">
			<thead>
				<tr>
					<th>Time</th>
					<th>Context</th>
					<th>Level</th>
					<th>Message</th>
					<th>Data</th>
				</tr>
			</thead>
			<tbody>
				<?php if (empty($logs)): ?>
					<tr>
						<td colspan="5" class="cc-empty">No log entries.</td>
					</tr>
				<?php else: ?>
					<?php foreach ($logs as $row): ?>
						<tr>
							<td><?php echo esc_html($row->created_at); ?></td>
							<td><span class="cc-tag"><?php echo esc_html($row->context); ?></span></td>
							<td><?php echo 'error' === $row->level ? '<span class="cc-badge cc-badge-cancelled">error</span>' : '<span class="cc-badge cc-badge-booked">' . esc_html($row->level) . '</span>'; ?>
							</td>
							<td><?php echo esc_html($row->message); ?></td>
							<td><?php echo $row->data ? '<details><summary>view</summary><pre class="cc-pre">' . esc_html($row->data) . '</pre></details>' : '—'; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
</div>