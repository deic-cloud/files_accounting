<?php
/** @var array $_ */
$defaultFreeQuota = $_['defaultFreeQuota'];
$chargePerGb      = $_['chargePerGb'];
$billingCurrency  = $_['billingCurrency'];
$billingDay       = $_['billingDay'];
$billingNetDays   = $_['billingNetDays'];
$gifts            = $_['gifts'];
$bills            = $_['bills'] ?? [];
$groupTopups      = $_['groupTopups'] ?? [];
?>
<div id="files-accounting-stats" class="section" style="margin-top:22px;">
<h2><?php p($l->t('Usage statistics')); ?></h2>

<div id="fa-collab-cards" style="display:flex;flex-wrap:wrap;gap:12px;margin:8px 0 20px;"></div>

<div id="fa-stats-charts" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:24px 32px;">
	<div><h3 style="margin:0 0 4px;"><?php p($l->t('Users over time')); ?></h3><div class="fa-cw"><canvas id="fa-c-users" height="200"></canvas></div></div>
	<div><h3 style="margin:0 0 4px;"><?php p($l->t('Total storage over time')); ?></h3><div class="fa-cw"><canvas id="fa-a-storage" height="200"></canvas></div></div>
	<div><h3 style="margin:0 0 4px;"><?php p($l->t('Top users by storage')); ?></h3><div class="fa-cw"><canvas id="fa-d-top" height="200"></canvas></div></div>
	<div><h3 style="margin:0 0 4px;"><?php p($l->t('Amount billed per month')); ?></h3><div class="fa-cw"><canvas id="fa-b-billed" height="200"></canvas></div></div>
</div>
<p id="fa-stats-empty" style="display:none;color:var(--color-text-maxcontrast,#888)"><em><?php p($l->t('No accounting data yet — statistics will appear after the first billing run.')); ?></em></p>
</div>

<script nonce="<?php p($_['cspNonce']); ?>">
(function() {
	var OCS_BASE = '/ocs/v2.php/apps/files_accounting/api/v1';
	var CURRENCY = <?php echo json_encode($billingCurrency); ?>;
	var MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

	function theme() {
		var cs = getComputedStyle(document.documentElement);
		return {
			text:    cs.getPropertyValue('--color-text-maxcontrast').trim() || '#767676',
			grid:    cs.getPropertyValue('--color-border').trim()           || '#ddd',
			primary: cs.getPropertyValue('--color-primary-element').trim()
			      || cs.getPropertyValue('--color-primary').trim()          || '#0082c9'
		};
	}
	// Round a max value up to a "nice" axis maximum.
	function niceMax(v) {
		if (v <= 0) return 1;
		var mag = Math.pow(10, Math.floor(Math.log10(v)));
		return Math.ceil(v / mag) * mag;
	}
	function fmt(n) { return (n % 1 === 0) ? String(n) : n.toFixed(n < 10 ? 1 : 0); }
	function mlabel(r) { return MONTHS[r.month - 1] + " '" + String(r.year).slice(2); }

	// Prepare a canvas sized to its container; returns drawing context + geometry.
	function prep(id, margins) {
		var canvas = document.getElementById(id);
		if (!canvas) return null;
		var wrap = canvas.parentNode;
		var W = Math.max(280, wrap.offsetWidth || 340), H = canvas.height;
		canvas.width = W; canvas.style.width = '100%';
		var ctx = canvas.getContext && canvas.getContext('2d');
		if (!ctx) return null;
		var m = margins;
		return { ctx: ctx, W: W, H: H, ml: m[0], mr: m[1], mt: m[2], mb: m[3],
		         cw: W - m[0] - m[1], ch: H - m[2] - m[3] };
	}
	function yGrid(g, max, unit, t) {
		g.ctx.font = '11px sans-serif'; g.ctx.lineWidth = 1;
		for (var i = 0; i <= 4; i++) {
			var val = max * i / 4, y = g.mt + g.ch - (i / 4) * g.ch;
			g.ctx.strokeStyle = t.grid;
			g.ctx.beginPath(); g.ctx.moveTo(g.ml, y); g.ctx.lineTo(g.ml + g.cw, y); g.ctx.stroke();
			g.ctx.fillStyle = t.text; g.ctx.textAlign = 'right';
			g.ctx.fillText(fmt(val) + (unit || ''), g.ml - 5, y + 4);
		}
	}
	function xLabels(g, rows, t) {
		g.ctx.fillStyle = t.text; g.ctx.textAlign = 'center'; g.ctx.font = '10px sans-serif';
		var step = Math.ceil(rows.length / Math.max(1, Math.floor(g.cw / 46)));
		rows.forEach(function(r, i) {
			if (i % step !== 0 && i !== rows.length - 1) return;
			var x = g.ml + (rows.length === 1 ? g.cw / 2 : (i / (rows.length - 1)) * g.cw);
			g.ctx.fillText(mlabel(r), x, g.mt + g.ch + 15);
		});
	}

	// c) Users over time — line
	function chartUsers(rows) {
		var g = prep('fa-c-users', [44, 12, 14, 28]); if (!g) return;
		var t = theme(), max = niceMax(Math.max.apply(null, rows.map(function(r){return r.users;})));
		yGrid(g, max, '', t);
		g.ctx.strokeStyle = t.primary; g.ctx.lineWidth = 2; g.ctx.beginPath();
		rows.forEach(function(r, i) {
			var x = g.ml + (rows.length === 1 ? g.cw / 2 : (i / (rows.length - 1)) * g.cw);
			var y = g.mt + g.ch - (r.users / max) * g.ch;
			i ? g.ctx.lineTo(x, y) : g.ctx.moveTo(x, y);
		});
		g.ctx.stroke();
		g.ctx.fillStyle = t.primary;
		rows.forEach(function(r, i) {
			var x = g.ml + (rows.length === 1 ? g.cw / 2 : (i / (rows.length - 1)) * g.cw);
			var y = g.mt + g.ch - (r.users / max) * g.ch;
			g.ctx.beginPath(); g.ctx.arc(x, y, 2.5, 0, 2 * Math.PI); g.ctx.fill();
		});
		xLabels(g, rows, t);
	}

	// a) Total storage over time — stacked area (home files / trash / backup)
	function chartStorage(rows) {
		var g = prep('fa-a-storage', [50, 12, 14, 28]); if (!g) return;
		var t = theme();
		var totals = rows.map(function(r){ return r.home_gb + r.trash_gb + r.backup_gb; });
		var maxGb = Math.max.apply(null, totals);
		var toTb = maxGb >= 1024, div = toTb ? 1024 : 1, unit = toTb ? ' TB' : ' GB';
		var max = niceMax(maxGb / div);
		yGrid(g, max, unit, t);
		var layers = [
			{ key: ['home_gb'], color: 'rgba(0,130,201,0.80)' },
			{ key: ['home_gb','trash_gb'], color: 'rgba(150,150,150,0.45)' },
			{ key: ['home_gb','trash_gb','backup_gb'], color: 'rgba(70,160,90,0.55)' }
		];
		// draw from top layer (largest cumulative) down so lower layers overpaint
		for (var li = layers.length - 1; li >= 0; li--) {
			var lay = layers[li];
			g.ctx.fillStyle = lay.color; g.ctx.beginPath();
			rows.forEach(function(r, i) {
				var cum = lay.key.reduce(function(s,k){ return s + r[k]; }, 0) / div;
				var x = g.ml + (rows.length === 1 ? g.cw / 2 : (i / (rows.length - 1)) * g.cw);
				var y = g.mt + g.ch - (cum / max) * g.ch;
				i ? g.ctx.lineTo(x, y) : g.ctx.moveTo(x, y);
			});
			g.ctx.lineTo(g.ml + g.cw, g.mt + g.ch);
			g.ctx.lineTo(g.ml, g.mt + g.ch);
			g.ctx.closePath(); g.ctx.fill();
		}
		xLabels(g, rows, t);
		// legend
		var items = [['Files','rgba(0,130,201,0.80)'],['Trash','rgba(150,150,150,0.45)'],['Backup','rgba(70,160,90,0.55)']];
		var lx = g.ml + 4, ly = g.mt + 2;
		g.ctx.font = '10px sans-serif'; g.ctx.textAlign = 'left';
		items.forEach(function(it, i) {
			g.ctx.fillStyle = it[1]; g.ctx.fillRect(lx + i * 60, ly, 10, 9);
			g.ctx.fillStyle = t.text; g.ctx.fillText(it[0], lx + i * 60 + 13, ly + 8);
		});
	}

	// d) Top users by storage — horizontal bars
	function chartTop(rows) {
		var canvas = document.getElementById('fa-d-top');
		if (canvas) canvas.height = Math.max(120, 22 * rows.length + 20);
		var g = prep('fa-d-top', [110, 46, 8, 8]); if (!g) return;
		var t = theme();
		var max = niceMax(Math.max.apply(null, rows.map(function(r){return r.usage_gb;})) || 1);
		var toTb = max >= 1024, div = toTb ? 1024 : 1, unit = toTb ? ' TB' : ' GB';
		var mx = max / div;
		var bh = Math.min(18, g.ch / rows.length - 4);
		g.ctx.font = '11px sans-serif';
		rows.forEach(function(r, i) {
			var y = g.mt + i * (g.ch / rows.length);
			var w = (r.usage_gb / div / mx) * g.cw;
			g.ctx.fillStyle = t.primary; g.ctx.fillRect(g.ml, y + 2, Math.max(1, w), bh);
			g.ctx.fillStyle = t.text; g.ctx.textAlign = 'right';
			g.ctx.fillText(r.user.length > 16 ? r.user.slice(0, 15) + '…' : r.user, g.ml - 6, y + bh - 1);
			g.ctx.textAlign = 'left';
			g.ctx.fillText(fmt(r.usage_gb / div) + unit, g.ml + Math.max(1, w) + 4, y + bh - 1);
		});
	}

	// b) Amount billed per month — vertical bars
	function chartBilled(rows) {
		var g = prep('fa-b-billed', [52, 12, 14, 28]); if (!g) return;
		var t = theme();
		var max = niceMax(Math.max.apply(null, rows.map(function(r){return r.billed;})) || 1);
		yGrid(g, max, '', t);
		var bw = Math.max(2, Math.min(26, g.cw / rows.length - 6));
		g.ctx.fillStyle = t.primary;
		rows.forEach(function(r, i) {
			var xc = g.ml + (rows.length === 1 ? g.cw / 2 : (i / (rows.length - 1)) * g.cw);
			var h = (r.billed / max) * g.ch;
			g.ctx.fillRect(xc - bw / 2, g.mt + g.ch - h, bw, h);
		});
		xLabels(g, rows, t);
		g.ctx.fillStyle = t.text; g.ctx.font = '10px sans-serif'; g.ctx.textAlign = 'left';
		g.ctx.fillText(CURRENCY, g.ml - 44, g.mt + 9);
	}

	function card(label, value, sub) {
		var v = (value < 0) ? '—' : value;
		return '<div style="flex:1 1 128px;min-width:128px;background:var(--color-background-hover);border-radius:8px;padding:10px 14px;">'
			+ '<div style="font-size:22px;font-weight:700;line-height:1.1;">' + v + '</div>'
			+ '<div style="font-size:12px;color:var(--color-text-maxcontrast,#888);">' + label + '</div>'
			+ (sub ? '<div style="font-size:11px;color:var(--color-text-maxcontrast,#888);">' + sub + '</div>' : '')
			+ '</div>';
	}
	function ofN(n, total) { return (n >= 0 && total > 0) ? ('of ' + total + ' users') : ''; }

	function renderCollab(c) {
		var el = document.getElementById('fa-collab-cards');
		el.innerHTML =
			card('Total users', c.total_users) +
			card('Sharing with others', c.sharers, ofN(c.sharers, c.total_users)) +
			card('Sharing publicly', c.public_sharers, ofN(c.public_sharers, c.total_users)) +
			card('Total shares', c.total_shares) +
			card('Users shared with', c.recipients) +
			card('Using a client/app', c.client_users, ofN(c.client_users, c.total_users));
	}

	var _data = null;
	function renderAll() {
		if (!_data) return;
		var rows = _data.summary || [];
		if (_data.collaboration) renderCollab(_data.collaboration);
		if (!rows.length) {
			document.getElementById('fa-stats-charts').style.display = 'none';
			document.getElementById('fa-stats-empty').style.display = '';
			return;
		}
		chartUsers(rows); chartStorage(rows);
		chartTop(_data.topUsers || []); chartBilled(rows);
	}

	fetch(OCS_BASE + '/statistics?format=json', { headers: {'OCS-APIRequest': 'true'} })
		.then(function(r){ return r.ok ? r.json() : null; })
		.then(function(j){
			_data = j && j.ocs && j.ocs.data ? j.ocs.data : null;
			renderAll();
		}).catch(function(){});

	var _t;
	window.addEventListener('resize', function(){ clearTimeout(_t); _t = setTimeout(renderAll, 300); });
})();
</script>

<div id="files-accounting-admin" class="section">
<h2><?php p($l->t('Billing')); ?></h2>

<p class="settings-hint"><?php p($l->t('Billing settings are configured in config.php (charge_per_gb, billingcurrency, billingdayofmonth, billingnetdays, billingvat, fromaddress, fromemail, billinglogo).')); ?></p>

<table style="margin-bottom:8px;">
	<tr><th style="text-align:left; padding-right:16px;"><?php p($l->t('Charge per GB')); ?></th><td><?php p($chargePerGb . ' ' . $billingCurrency); ?></td></tr>
	<tr><th style="text-align:left; padding-right:16px;"><?php p($l->t('Billing day of month')); ?></th><td><?php p($billingDay); ?></td></tr>
	<tr><th style="text-align:left; padding-right:16px;"><?php p($l->t('Payment net days')); ?></th><td><?php p($billingNetDays); ?></td></tr>
</table>

<h3><?php p($l->t('Default free tier')); ?></h3>
<div>
	<input id="fa-default-freequota" type="text" value="<?php p($defaultFreeQuota); ?>" style="width:100px;">
	<button id="fa-save-default-fq"><?php p($l->t('Save')); ?></button>
	<em><?php p($l->t('e.g. 50 GB — users below this threshold are not billed')); ?></em>
</div>

<h3><?php p($l->t('Set free tier for a user')); ?></h3>
<div>
	<input id="fa-user-input"  type="text" placeholder="<?php p($l->t('Username')); ?>" style="width:180px;">
	<input id="fa-quota-input" type="text" placeholder="<?php p($l->t('e.g. 50 GB')); ?>" style="width:100px;">
	<button id="fa-set-fq"><?php p($l->t('Set')); ?></button>
	<span id="fa-fq-msg"></span>
</div>

<h3><?php p($l->t('Institutional home-quota top-up')); ?></h3>
<p class="settings-hint"><?php p($l->t('Extra free quota a university (as owner of its domain group) buys on its users\' standard home directories. Billed to the group owner for members\' home usage above the platform free tier. Independent of grant folders.')); ?></p>
<div style="margin-bottom:8px;">
	<input id="fa-topup-gid"   type="text" placeholder="<?php p($l->t('Group / domain, e.g. dtu.dk')); ?>" style="width:220px;">
	<input id="fa-topup-quota" type="text" placeholder="<?php p($l->t('e.g. 500 GB (0 = remove)')); ?>" style="width:140px;">
	<button id="fa-set-topup"><?php p($l->t('Set')); ?></button>
	<span id="fa-topup-msg"></span>
</div>
<table id="fa-topup-table" style="width:100%; border-collapse:collapse; margin-bottom:8px;">
	<thead><tr>
		<th style="text-align:left;"><?php p($l->t('Group / domain')); ?></th>
		<th style="text-align:left;"><?php p($l->t('Owner (billed)')); ?></th>
		<th style="text-align:left;"><?php p($l->t('Home top-up')); ?></th>
		<th></th>
	</tr></thead>
	<tbody id="fa-topup-tbody">
	<?php foreach ($groupTopups as $tu): ?>
		<tr data-gid="<?php p($tu['gid']); ?>">
			<td><?php p($tu['gid']); ?></td>
			<td><?php if ($tu['owner'] !== '') { p($tu['owner']); } else { ?><em style="color:var(--color-error-text,#8A0000)"><?php p($l->t('no owner set')); ?></em><?php } ?></td>
			<td><?php p($tu['human']); ?></td>
			<td><button class="fa-del-topup"><?php p($l->t('Remove')); ?></button></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>

<h3><?php p($l->t('Bills')); ?></h3>
<?php
$outstanding = 0.0; $pendingCount = 0;
foreach ($bills as $b) {
	if (($b['status'] ?? '') === 'pending') { $outstanding += (float)$b['amount_due']; $pendingCount++; }
}
// Current month as a period index (year*12+month); each row carries how many
// months ago it is, so the list can widen 3 months at a time.
$nowIdx = ((int)date('Y')) * 12 + ((int)date('n'));
?>
<p class="settings-hint"><?php p($l->t('%1$d outstanding, totalling %2$s. Payment arrives per university — tick a domain owner\'s bills, optionally enter the bank payment reference, and mark them paid together. By default only the last 3 months of chargeable, unpaid bills are shown; use the toggles, or "Show 3 more months" to widen the range incrementally.', [$pendingCount, number_format($outstanding, 2) . ' ' . $billingCurrency])); ?></p>
<?php if (empty($bills)): ?>
<p style="color:var(--color-text-maxcontrast,#888)"><em><?php p($l->t('No bills yet.')); ?></em></p>
<?php else: ?>
<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;flex-wrap:wrap;">
	<label style="display:inline-flex;align-items:center;gap:4px;margin:0;" title="<?php p($l->t('Hide bills with nothing due (usage stayed within the free tier). Most monthly bills are these.')); ?>"><input type="checkbox" id="fa-hide-empty" checked style="margin:0;"> <?php p($l->t('Hide non-chargeable bills')); ?></label>
	<label style="display:inline-flex;align-items:center;gap:4px;margin:0;"><input type="checkbox" id="fa-hide-paid" checked style="margin:0;"> <?php p($l->t('Hide paid bills')); ?></label>
	<span id="fa-bills-hidden-note" style="color:var(--color-text-maxcontrast,#888);"></span>
	<input id="fa-payment-ref" type="text" placeholder="<?php p($l->t('Bank payment reference')); ?>" title="<?php p($l->t('Optional — the reference the payer quotes on their bank transfer. Applied to the ticked bills when you mark them paid. You can also edit it later in the Payment ref column.')); ?>" style="width:220px;margin-left:16px;">
	<button id="fa-markpaid-selected" style="margin:0;"><?php p($l->t('Mark selected paid')); ?></button>
	<span id="fa-markpaid-msg"></span>
</div>
<table id="fa-bills-table" style="width:100%; border-collapse:collapse; margin-bottom:12px;">
	<thead><tr>
		<th><input type="checkbox" id="fa-bills-all"></th>
		<th style="text-align:left;"><?php p($l->t('User')); ?></th>
		<th style="text-align:left;"><?php p($l->t('Issued')); ?></th>
		<th style="text-align:left;"><?php p($l->t('Amount')); ?></th>
		<th style="text-align:left;"><?php p($l->t('Status')); ?></th>
		<th style="text-align:left;"><?php p($l->t('Due')); ?></th>
		<th style="text-align:left;"><?php p($l->t('Paid on')); ?></th>
		<th style="text-align:left;"><?php p($l->t('Invoice #')); ?></th>
		<th style="text-align:left;"><?php p($l->t('Payment ref')); ?></th>
		<th></th>
	</tr></thead>
	<tbody id="fa-bills-tbody">
	<?php foreach ($bills as $b): $paid = (($b['status'] ?? '') === 'paid'); $zero = (abs((float)$b['amount_due']) < 0.005); $monthsAgo = $nowIdx - ((int)$b['year'] * 12 + (int)$b['month']); ?>
		<tr data-id="<?php p($b['id']); ?>" data-monthsago="<?php p($monthsAgo); ?>" class="fa-bill-row<?php echo $paid ? ' fa-paid-row' : ''; ?><?php echo $zero ? ' fa-zero-row' : ''; ?>">
			<td><?php if (!$paid): ?><input type="checkbox" class="fa-bill-cb" value="<?php p($b['id']); ?>"><?php endif; ?></td>
			<td><?php p($b['user']); ?></td>
			<td><?php p(date('M Y', (int)mktime(0,0,0,(int)$b['month'],1,(int)$b['year']))); ?></td>
			<td><?php p(number_format((float)$b['amount_due'], 2) . ' ' . $billingCurrency); ?></td>
			<td><?php if ($paid): ?><span style="color:#2d7d46;font-weight:bold;">&#9679; <?php p($l->t('Paid')); ?></span><?php else: ?><span style="color:#c9302c;font-weight:bold;">&#9679; <?php p($l->t('Pending')); ?></span><?php endif; ?></td>
			<td><?php p(!empty($b['time_due']) ? date('Y-m-d', (int)$b['time_due']) : '—'); ?></td>
			<td><?php p(($paid && !empty($b['time_paid'])) ? date('Y-m-d', (int)$b['time_paid']) : '—'); ?></td>
			<td><?php $ref = (string)($b['reference_id'] ?? ''); if ($ref !== '' && !empty($b['invoice_url'])): ?><a href="<?php p($b['invoice_url']); ?>" target="_blank" rel="noopener" title="<?php p($l->t('View invoice PDF')); ?>"><?php p($ref); ?></a><?php else: p($ref); endif; ?></td>
			<td class="fa-payref-cell" data-id="<?php p($b['id']); ?>" title="<?php p($l->t('Click to edit')); ?>" style="cursor:pointer;"><?php $pr = (string)($b['payment_ref'] ?? ''); if ($pr !== '') { p($pr); } else { ?><span style="color:var(--color-text-maxcontrast,#888);">&mdash;</span><?php } ?></td>
			<td><?php if (!$paid): ?><button class="fa-markpaid-one"><?php p($l->t('Mark paid')); ?></button><?php endif; ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
<button id="fa-show-older" style="display:none;margin-top:2px;"></button>
<?php endif; ?>

<h3><?php p($l->t('Gift codes')); ?></h3>
<div style="margin-bottom:12px;">
	<label><?php p($l->t('Storage size')); ?>: <input id="fa-gift-size" type="text" placeholder="e.g. 10 GB" style="width:120px;"></label>
	<label><?php p($l->t('Days')); ?>: <input id="fa-gift-days" type="number" value="365" style="width:60px;"></label>
	<label><?php p($l->t('Claim expires (days, 0=never)')); ?>: <input id="fa-gift-expires" type="number" value="0" style="width:60px;"></label>
	<button id="fa-create-gift"><?php p($l->t('Create gift code')); ?></button>
	<span id="fa-gift-msg"></span>
</div>

<table id="fa-gifts-table" style="width:100%; border-collapse:collapse;">
	<thead>
		<tr>
			<th><?php p($l->t('Code')); ?></th>
			<th><?php p($l->t('Size')); ?></th>
			<th><?php p($l->t('Days')); ?></th>
			<th><?php p($l->t('Status')); ?></th>
			<th><?php p($l->t('User')); ?></th>
			<th><?php p($l->t('Created')); ?></th>
			<th></th>
		</tr>
	</thead>
	<tbody id="fa-gifts-tbody">
	<?php foreach ($gifts as $gift): ?>
		<tr data-code="<?php p($gift['code']); ?>">
			<td><code><?php p($gift['code']); ?></code></td>
			<td><?php p($gift['size']); ?></td>
			<td><?php p($gift['days']); ?></td>
			<td><?php p($gift['status']); ?></td>
			<td><?php p($gift['user']); ?></td>
			<td><?php p(date('Y-m-d', (int)$gift['creation_time'])); ?></td>
			<td><button class="fa-delete-gift"><?php p($l->t('Delete')); ?></button></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
</div>

<script nonce="<?php p($_['cspNonce']); ?>">
(function() {
	var OCS_BASE = '/ocs/v2.php/apps/files_accounting/api/v1';
	function ocsPost(path, body) {
		return fetch(OCS_BASE + '/' + path + '?format=json', {
			method: 'POST',
			headers: {'OCS-APIRequest': 'true', 'Content-Type': 'application/json'},
			body: JSON.stringify(body)
		}).then(function(r){ return r.json(); });
	}
	function ocsGet(path) {
		return fetch(OCS_BASE + '/' + path + '?format=json', {
			headers: {'OCS-APIRequest': 'true'}
		}).then(function(r){ return r.json(); });
	}
	function ocsDelete(path) {
		return fetch(OCS_BASE + '/' + path + '?format=json', {
			method: 'DELETE', headers: {'OCS-APIRequest': 'true'}
		}).then(function(r){ return r.json(); });
	}

	document.getElementById('fa-save-default-fq').addEventListener('click', function() {
		var q = document.getElementById('fa-default-freequota').value.trim();
		ocsPost('freequota', {quota: q, default: true}).then(function(data) {
			var ok = data && data.ocs && data.ocs.data && data.ocs.data.status === 'ok';
			document.getElementById('fa-fq-msg').textContent = ok ? '✓' : '✗';
		});
	});

	document.getElementById('fa-set-fq').addEventListener('click', function() {
		var user  = document.getElementById('fa-user-input').value.trim();
		var quota = document.getElementById('fa-quota-input').value.trim();
		var msg   = document.getElementById('fa-fq-msg');
		if (!user) return;
		ocsPost('freequota', {user: user, quota: quota}).then(function(data) {
			var ok = data && data.ocs && data.ocs.data && data.ocs.data.status === 'ok';
			msg.textContent = ok ? '✓' : '✗';
			msg.style.color = ok ? 'green' : 'red';
		});
	});

	document.getElementById('fa-create-gift').addEventListener('click', function() {
		var size    = document.getElementById('fa-gift-size').value.trim();
		var days    = parseInt(document.getElementById('fa-gift-days').value) || 365;
		var expires = parseInt(document.getElementById('fa-gift-expires').value) || 0;
		var msg     = document.getElementById('fa-gift-msg');
		if (!size) return;
		ocsPost('gifts', {size: size, days: days, claim_expires_days: expires}).then(function(data) {
			var d = data && data.ocs && data.ocs.data ? data.ocs.data : {};
			if (d.code) {
				msg.textContent = '✓ ' + d.code;
				msg.style.color = 'green';
				var tbody = document.getElementById('fa-gifts-tbody');
				var tr = document.createElement('tr');
				tr.dataset.code = d.code;
				tr.innerHTML = '<td><code>' + d.code + '</code></td><td>' + size + '</td><td>' + days + '</td><td>OPEN</td><td></td><td>' + (new Date().toISOString().slice(0,10)) + '</td><td><button class="fa-delete-gift">Delete</button></td>';
				tbody.insertBefore(tr, tbody.firstChild);
				bindDelete(tr.querySelector('.fa-delete-gift'));
			} else {
				msg.textContent = '✗ Failed';
				msg.style.color = 'red';
			}
		});
	});

	function bindDelete(btn) {
		btn.addEventListener('click', function() {
			var tr   = this.closest('tr');
			var code = tr.dataset.code;
			if (!confirm('Delete gift code ' + code + '?')) return;
			ocsDelete('gifts/' + encodeURIComponent(code)).then(function() {
				tr.remove();
			});
		});
	}
	document.querySelectorAll('.fa-delete-gift').forEach(bindDelete);

	// --- Institutional home-quota top-up (Option B) ---
	function bindDelTopup(btn) {
		btn.addEventListener('click', function() {
			var tr  = this.closest('tr');
			var gid = tr.dataset.gid;
			if (!confirm('Remove home top-up for ' + gid + '?')) return;
			ocsPost('grouptopup', {gid: gid, quota: '0'}).then(function() { tr.remove(); });
		});
	}
	document.querySelectorAll('.fa-del-topup').forEach(bindDelTopup);

	var setTopupBtn = document.getElementById('fa-set-topup');
	if (setTopupBtn) setTopupBtn.addEventListener('click', function() {
		var gid   = document.getElementById('fa-topup-gid').value.trim();
		var quota = document.getElementById('fa-topup-quota').value.trim();
		var msg   = document.getElementById('fa-topup-msg');
		if (!gid) return;
		ocsPost('grouptopup', {gid: gid, quota: quota}).then(function(data) {
			var d = data && data.ocs && data.ocs.data ? data.ocs.data : {};
			if (d.status !== 'ok') { msg.textContent = '✗'; msg.style.color = 'red'; return; }
			msg.textContent = '✓'; msg.style.color = 'green';
			var tbody = document.getElementById('fa-topup-tbody');
			var row = tbody.querySelector('tr[data-gid="' + (window.CSS && CSS.escape ? CSS.escape(gid) : gid) + '"]');
			if (d.bytes > 0) {
				var human = (d.bytes / (1024*1024*1024)).toFixed(2) + ' GB';
				var owner = d.owner || '';
				if (row) { row.children[2].textContent = human; }
				else {
					row = document.createElement('tr'); row.dataset.gid = gid;
					row.innerHTML = '<td></td><td></td><td></td><td><button class="fa-del-topup">Remove</button></td>';
					row.children[0].textContent = gid;
					if (owner) { row.children[1].textContent = owner; }
					else { row.children[1].innerHTML = '<em style="color:var(--color-error-text,#8A0000)">no owner set</em>'; }
					row.children[2].textContent = human;
					tbody.appendChild(row); bindDelTopup(row.querySelector('.fa-del-topup'));
				}
			} else if (row) { row.remove(); }
		});
	});

	// --- Bills: filter (hide non-chargeable / paid / older than 3 months) + mark paid ---
	var hidePaidCb = document.getElementById('fa-hide-paid');
	var hideEmptyCb = document.getElementById('fa-hide-empty');
	var hiddenNote = document.getElementById('fa-bills-hidden-note');
	var showOlderBtn = document.getElementById('fa-show-older');
	var WINDOW_STEP = 3;      // months revealed per click
	var visibleMonths = 3;    // start with the last 3 months
	function applyBillFilters() {
		var hp = hidePaidCb && hidePaidCb.checked;
		var he = hideEmptyCb && hideEmptyCb.checked;
		var hidden = 0, olderRevealable = 0;
		document.querySelectorAll('#fa-bills-tbody tr').forEach(function(tr) {
			var monthsAgo = parseInt(tr.dataset.monthsago, 10) || 0;
			var filteredOut = (hp && tr.classList.contains('fa-paid-row'))
			               || (he && tr.classList.contains('fa-zero-row'));
			var beyondWindow = monthsAgo >= visibleMonths;
			if (beyondWindow && !filteredOut) olderRevealable++;
			var hide = filteredOut || beyondWindow;
			tr.style.display = hide ? 'none' : '';
			if (hide) hidden++;
		});
		if (hiddenNote) hiddenNote.textContent = hidden ? ('(' + hidden + ' hidden)') : '';
		if (showOlderBtn) {
			if (olderRevealable > 0) {
				showOlderBtn.style.display = '';
				showOlderBtn.textContent = '▼ ' + <?php echo json_encode($l->t('Show 3 more months')); ?>
					+ ' (' + olderRevealable + ' ' + <?php echo json_encode($l->t('older')); ?> + ')';
			} else {
				showOlderBtn.style.display = 'none';
			}
		}
	}
	if (hidePaidCb) hidePaidCb.addEventListener('change', applyBillFilters);
	if (hideEmptyCb) hideEmptyCb.addEventListener('change', applyBillFilters);
	if (showOlderBtn) showOlderBtn.addEventListener('click', function() { visibleMonths += WINDOW_STEP; applyBillFilters(); });
	applyBillFilters(); // apply the default state on load (last 3 months, checked filters)
	function markPaid(ids, done) {
		if (!ids.length) return;
		var refEl = document.getElementById('fa-payment-ref');
		var ref = refEl ? refEl.value.trim() : '';
		ocsPost('bills/markpaid', {ids: ids, payment_ref: ref}).then(function(data) {
			var d = data && data.ocs && data.ocs.data ? data.ocs.data : {};
			if (d.status === 'ok') done();
		});
	}
	document.querySelectorAll('.fa-markpaid-one').forEach(function(btn) {
		btn.addEventListener('click', function() {
			var tr = this.closest('tr');
			// Reload so the row flips to Paid (with its paid date + any payment ref) and the total refreshes.
			markPaid([tr.dataset.id], function() { window.location.reload(); });
		});
	});
	var allCb = document.getElementById('fa-bills-all');
	if (allCb) allCb.addEventListener('change', function() {
		document.querySelectorAll('.fa-bill-cb').forEach(function(cb) {
			var tr = cb.closest('tr');
			if (tr && tr.style.display === 'none') return; // don't select hidden rows
			cb.checked = allCb.checked;
		});
	});
	var selBtn = document.getElementById('fa-markpaid-selected');
	if (selBtn) selBtn.addEventListener('click', function() {
		var ids = Array.prototype.slice.call(document.querySelectorAll('.fa-bill-cb:checked')).map(function(cb) { return cb.value; });
		var msg = document.getElementById('fa-markpaid-msg');
		if (!ids.length) { msg.textContent = 'Select bills first'; return; }
		markPaid(ids, function() {
			msg.textContent = '✓ ' + ids.length + ' marked paid'; msg.style.color = 'green';
			window.location.reload();
		});
	});

	// --- Payment ref: click a cell to edit inline (fix typos) ---
	function showRef(cell, val) {
		if (val) { cell.textContent = val; }
		else { cell.innerHTML = '<span style="color:var(--color-text-maxcontrast,#888);">—</span>'; }
	}
	document.querySelectorAll('.fa-payref-cell').forEach(function(cell) {
		cell.addEventListener('click', function() {
			if (cell.querySelector('input')) return; // already editing
			var cur = cell.textContent.trim();
			if (cur === '—') cur = '';
			cell.innerHTML = '';
			var inp = document.createElement('input');
			inp.type = 'text'; inp.value = cur; inp.style.width = '150px';
			cell.appendChild(inp); inp.focus(); inp.select();
			var done = false;
			function save() {
				if (done) return; done = true;
				var v = inp.value.trim();
				ocsPost('bills/paymentref', {id: cell.dataset.id, payment_ref: v}).then(function(data) {
					var d = data && data.ocs && data.ocs.data ? data.ocs.data : {};
					showRef(cell, d.status === 'ok' ? v : cur);
				}, function() { showRef(cell, cur); });
			}
			inp.addEventListener('blur', save);
			inp.addEventListener('keydown', function(e) {
				if (e.key === 'Enter') { inp.blur(); }
				else if (e.key === 'Escape') { done = true; showRef(cell, cur); }
			});
		});
	});
})();
</script>
