<?php
/** @var array $_ */
$userId    = $_['userId'];
$years     = $_['years'];
$year      = $_['year'];
$bills     = $_['bills'];
$gifts     = $_['gifts'];
$freequota        = $_['freequota'];
$defaultFreequota = $_['defaultFreequota'];
$currency         = $_['currency'];
?>
<div id="files-accounting-personal" class="section">
<h2><?php p($l->t('Accounting and billing')); ?></h2>

<p>
<?php if ($freequota && $freequota !== '0'): ?>
	<?php p($l->t('Free tier')); ?>: <strong id="fa-freequota"><?php p($freequota); ?></strong>
<?php else: ?>
	<?php p($l->t('Free tier')); ?>: <strong id="fa-freequota" title="<?php p($l->t('Default: %s', [$defaultFreequota && $defaultFreequota !== '0' ? $defaultFreequota : $l->t('none')])); ?>" style="cursor:help;border-bottom:1px dotted currentColor"><?php p($l->t('default')); ?></strong>
	<?php if ($defaultFreequota && $defaultFreequota !== '0'): ?>
	<span style="color:var(--color-text-maxcontrast,#888)">(<?php p($defaultFreequota); ?>)</span>
	<?php endif; ?>
<?php endif; ?>
</p>

<div style="margin-bottom:8px;">
	<label for="fa-year-select"><?php p($l->t('Year')); ?>:</label>
	<select id="fa-year-select">
		<?php if (!empty($years)): ?>
		<?php foreach ($years as $y): ?>
		<option value="<?php p($y); ?>"<?php if ((int)$y === $year) echo ' selected'; ?>><?php p($y); ?></option>
		<?php endforeach; ?>
		<?php else: ?>
		<option value="<?php p($year); ?>" selected><?php p($year); ?></option>
		<?php endif; ?>
	</select>
</div>

<div id="fa-chart-container" style="margin-bottom:16px;min-height:220px;"></div>

<?php if (!empty($years)): ?>
<table id="fa-bills-table" style="width:100%; border-collapse:collapse;">
	<thead>
		<tr>
			<th><?php p($l->t('Period')); ?></th>
			<th><?php p($l->t('Storage (GB)')); ?></th>
			<th><?php p($l->t('Amount')); ?></th>
			<th><?php p($l->t('Status')); ?></th>
			<th><?php p($l->t('Due date')); ?></th>
			<th><?php p($l->t('Invoice')); ?></th>
		</tr>
	</thead>
	<tbody>
	<?php foreach ($bills as $bill): ?>
		<tr>
			<td><?php p(date('F', mktime(0,0,0,(int)$bill['month'],1)) . ' ' . $bill['year']); ?></td>
			<td><?php p(round((float)$bill['home_files_usage'] + (float)$bill['home_trash_usage'], 2)); ?></td>
			<td><?php p(number_format((float)$bill['amount_due'], 2) . ' ' . $currency); ?></td>
			<td><?php p($bill['status']); ?></td>
			<td><?php p($bill['time_due'] ? date('Y-m-d', (int)$bill['time_due']) : ''); ?></td>
			<td>
				<?php if (!empty($bill['reference_id'])): ?>
				<a href="#" class="fa-invoice-link" data-ref="<?php p($bill['reference_id']); ?>"><?php p($l->t('Download')); ?></a>
				<?php endif; ?>
			</td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
<?php endif; ?>

<?php if (!empty($memberGroups)): ?>
<hr>
<h3><?php p($l->t('Group usage')); ?></h3>
<table style="width:100%; border-collapse:collapse; margin-bottom:8px;">
<?php foreach ($memberGroups as $mg):
	$usedHuman  = \OCP\Util::humanFileSize((int)$mg['storage_used']);
	$grantHuman = $mg['storage_grant'];
	$pct        = (int)$mg['used_pct'];
?>
<tr>
	<td style="padding:4px 8px 4px 0; width:160px;"><?php p($mg['gid']); ?></td>
	<td style="padding:4px 0;">
		<strong><?php p($usedHuman); ?></strong> <?php p($l->t('of')); ?> <?php p($grantHuman); ?>
		<div style="height:6px; background:var(--color-border); border-radius:3px; margin-top:4px;">
			<div style="height:6px; width:<?php p($pct); ?>%; background:var(--color-primary); border-radius:3px;"></div>
		</div>
	</td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<?php if (!empty($ownerGrants)): ?>
<hr>
<h3><?php p($l->t('Total usage of owned groups')); ?></h3>
<table style="width:100%; border-collapse:collapse; margin-bottom:8px;">
<?php foreach ($ownerGrants as $og): ?>
<tr>
	<td style="padding:4px 8px 4px 0; width:160px;"><?php p($og['gid']); ?></td>
	<td style="padding:4px 0;"><strong><?php p(\OCP\Util::humanFileSize((int)$og['total_used'])); ?></strong></td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<?php if (!empty($gifts)): ?>
<h3><?php p($l->t('Storage grants')); ?></h3>
<table style="width:100%; border-collapse:collapse;">
	<thead>
		<tr>
			<th><?php p($l->t('Code')); ?></th>
			<th><?php p($l->t('Size')); ?></th>
			<th><?php p($l->t('Status')); ?></th>
			<th><?php p($l->t('Redeemed')); ?></th>
		</tr>
	</thead>
	<tbody>
	<?php foreach ($gifts as $gift): ?>
		<tr>
			<td><?php p($gift['code']); ?></td>
			<td><?php p($gift['size'] ?: ($gift['amount'] . ' ' . $currency)); ?></td>
			<td><?php p($gift['status']); ?></td>
			<td><?php p($gift['redemption_time'] ? date('Y-m-d', (int)$gift['redemption_time']) : ''); ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
<?php endif; ?>

<div style="margin-top:16px;">
	<label for="fa-gift-input"><?php p($l->t('Redeem gift code')); ?>:</label>
	<input id="fa-gift-input" type="text" placeholder="XXXX-XXXX-XXXX-XXXX" style="width:200px;">
	<button id="fa-gift-redeem"><?php p($l->t('Redeem')); ?></button>
	<span id="fa-gift-msg"></span>
</div>
</div>

<script nonce="<?php p($_['cspNonce']); ?>">
(function() {
	var userId = <?php echo json_encode($userId); ?>;
	var currency = <?php echo json_encode($currency); ?>;
	var year = <?php echo (int)$year; ?>;

	var OCS_BASE = '/ocs/v2.php/apps/files_accounting/api/v1';
	function loadUsage(y) {
		var container = document.getElementById('fa-chart-container');
		fetch(OCS_BASE + '/my/usage?year=' + y + '&format=json', {
			headers: {'OCS-APIRequest': 'true'}
		}).then(function(r) {
			if (!r.ok) { container.textContent = 'HTTP ' + r.status; return null; }
			return r.json();
		}).then(function(data) {
			if (!data) return;
			var rows = data && data.ocs && data.ocs.data ? data.ocs.data : [];
			renderChart(rows, y);
		}).catch(function(e) {
			container.textContent = 'Error: ' + e.message;
		});
	}

	var _chartRows = [], _chartYear = <?php echo (int)$year; ?>;
	function renderChart(rows, y) {
		if (y !== undefined) _chartYear = y;
		_chartRows = rows;
		var container = document.getElementById('fa-chart-container');
		if (!rows.length) {
			container.innerHTML = '<em style="color:var(--color-text-maxcontrast,#888)">No usage data for this year.</em>';
			return;
		}

		var W = container.offsetWidth || 640, H = 220;
		container.innerHTML = '<canvas id="fa-canvas" width="' + W + '" height="' + H + '" style="max-width:100%"></canvas>';
		var canvas = document.getElementById('fa-canvas');
		if (!canvas || !canvas.getContext) return;
		var ctx = canvas.getContext('2d');

		// Theme-aware colours from NC CSS variables
		var cs       = getComputedStyle(document.documentElement);
		var textColor = cs.getPropertyValue('--color-text-maxcontrast').trim() || '#666';
		var gridColor = cs.getPropertyValue('--color-border').trim()           || '#ddd';

		// Layout
		var ml = 58, mr = 16, mt = 28, mb = 38;
		var cw = W - ml - mr, ch = H - mt - mb;

		// Calendar x-axis: Jan 1 → Dec 31 (or today for current year)
		var now      = new Date();
		var isCurYr  = (_chartYear === now.getFullYear());
		var xStart   = new Date(_chartYear, 0, 1);
		var xEnd     = isCurYr ? now : new Date(_chartYear, 11, 31);
		var spanMs   = xEnd - xStart || 1;
		function xAtDate(yr, mo, dy) {
			return ml + ((new Date(yr, mo - 1, dy) - xStart) / spanMs) * cw;
		}

		// Auto-scale unit
		var maxBytes = 0;
		rows.forEach(function(r) { if (r.files_usage + r.trash_usage > maxBytes) maxBytes = r.files_usage + r.trash_usage; });
		var usageUnit = maxBytes < 1024 * 1024 * 1024 ? 1024 * 1024 : 1024 * 1024 * 1024;
		var unitStr   = usageUnit === 1024 * 1024 ? 'MB' : 'GB';
		var maxVal    = maxBytes / usageUnit;
		var magnitude = Math.pow(10, Math.floor(Math.log10(maxVal || 1)));
		var niceMax   = Math.ceil(maxVal / magnitude) * magnitude || 1;
		function yAt(bytes) { return mt + ch - (bytes / usageUnit / niceMax) * ch; }

		// Grid + Y axis labels
		var MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
		ctx.lineWidth = 1; ctx.font = '11px sans-serif';
		for (var t = 0; t <= 4; t++) {
			var val = niceMax * t / 4;
			var yy  = yAt(val * usageUnit);
			ctx.strokeStyle = gridColor;
			ctx.beginPath(); ctx.moveTo(ml, yy); ctx.lineTo(ml + cw, yy); ctx.stroke();
			ctx.fillStyle = textColor; ctx.textAlign = 'right';
			ctx.fillText(val % 1 === 0 ? val : val.toFixed(1), ml - 5, yy + 4);
		}
		// Y unit label
		ctx.save(); ctx.translate(13, mt + ch / 2); ctx.rotate(-Math.PI / 2);
		ctx.textAlign = 'center'; ctx.fillStyle = textColor;
		ctx.fillText(unitStr, 0, 0); ctx.restore();

		// Step-fill areas (each day's value extends to the next day's x)
		function stepFill(getValue) {
			ctx.beginPath();
			rows.forEach(function(r, i) {
				var x1 = xAtDate(r.year, r.month, r.day);
				var x2 = i < rows.length - 1
					? xAtDate(rows[i+1].year, rows[i+1].month, rows[i+1].day)
					: ml + cw;
				var yv = yAt(getValue(r));
				if (i === 0) ctx.moveTo(x1, yv);
				else         ctx.lineTo(x1, yv);
				ctx.lineTo(x2, yv);
			});
			ctx.lineTo(ml + cw, mt + ch);
			ctx.lineTo(xAtDate(rows[0].year, rows[0].month, rows[0].day), mt + ch);
			ctx.closePath();
			ctx.fill();
		}

		// Trash layer (behind, muted)
		var hasTrash = rows.some(function(r) { return r.trash_usage > 0; });
		if (hasTrash) {
			ctx.fillStyle = 'rgba(150,150,150,0.35)';
			stepFill(function(r) { return r.files_usage + r.trash_usage; });
		}
		// Files layer (front, vivid NC blue)
		ctx.fillStyle = 'rgba(0,130,201,0.80)';
		stepFill(function(r) { return r.files_usage; });

		// Axes
		ctx.strokeStyle = gridColor; ctx.lineWidth = 1;
		ctx.beginPath(); ctx.moveTo(ml, mt); ctx.lineTo(ml, mt + ch); ctx.lineTo(ml + cw, mt + ch); ctx.stroke();

		// X axis: month ticks across the full calendar span
		ctx.fillStyle = textColor; ctx.textAlign = 'center'; ctx.font = '11px sans-serif';
		for (var m = 0; m < 12; m++) {
			var tickDate = new Date(_chartYear, m, 1);
			if (tickDate > xEnd) break;
			var tx = xAtDate(_chartYear, m + 1, 1);
			ctx.strokeStyle = gridColor;
			ctx.beginPath(); ctx.moveTo(tx, mt + ch); ctx.lineTo(tx, mt + ch + 4); ctx.stroke();
			ctx.fillText(MONTHS[m], tx, mt + ch + 16);
		}

		// Title
		ctx.fillStyle = textColor; ctx.font = 'bold 12px sans-serif'; ctx.textAlign = 'center';
		ctx.fillText('Storage history', ml + cw / 2, mt - 10);

		// Legend
		var lx = ml + cw - 130, ly = mt + 6;
		ctx.fillStyle = 'rgba(0,130,201,0.80)';    ctx.fillRect(lx,      ly, 12, 10);
		ctx.fillStyle = 'rgba(150,150,150,0.35)';  ctx.fillRect(lx + 60, ly, 12, 10);
		ctx.fillStyle = textColor; ctx.font = '11px sans-serif'; ctx.textAlign = 'left';
		ctx.fillText('Files', lx + 16, ly + 9);
		ctx.fillText('Trash', lx + 76, ly + 9);
	}

	var _resizeTimer;
	window.addEventListener('resize', function() {
		clearTimeout(_resizeTimer);
		_resizeTimer = setTimeout(function() { if (_chartRows.length) renderChart(_chartRows); }, 300);
	});

	document.getElementById('fa-year-select') && document.getElementById('fa-year-select').addEventListener('change', function() {
		loadUsage(parseInt(this.value));
	});
	loadUsage(year);

	document.querySelectorAll('.fa-invoice-link').forEach(function(a) {
		a.addEventListener('click', function(e) {
			e.preventDefault();
			var ref = this.dataset.ref;
			fetch(OCS_BASE + '/my/invoice?filename=' + encodeURIComponent(ref + '.pdf') + '&format=json', {
				headers: {'OCS-APIRequest': 'true'}
			}).then(function(r){ return r.json(); }).then(function(data) {
				var d = data && data.ocs && data.ocs.data ? data.ocs.data : null;
				if (!d || !d.data) return;
				var link = document.createElement('a');
				link.href = 'data:application/pdf;base64,' + d.data;
				link.download = d.filename || ref + '.pdf';
				link.click();
			});
		});
	});

	document.getElementById('fa-gift-redeem').addEventListener('click', function() {
		var code = document.getElementById('fa-gift-input').value.trim();
		var msg  = document.getElementById('fa-gift-msg');
		if (!code) return;
		fetch(OCS_BASE + '/my/gifts/redeem?format=json', {
			method: 'POST',
			headers: {'OCS-APIRequest': 'true', 'Content-Type': 'application/json'},
			body: JSON.stringify({code: code})
		}).then(function(r){ return r.json(); }).then(function(data) {
			var d = data && data.ocs && data.ocs.data ? data.ocs.data : {};
			msg.textContent = d.success ? '✓ Redeemed' : (d.message || 'Failed');
			msg.style.color = d.success ? 'green' : 'red';
		});
	});
})();
</script>
