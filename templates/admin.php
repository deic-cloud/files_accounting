<?php
/** @var array $_ */
$defaultFreeQuota = $_['defaultFreeQuota'];
$chargePerGb      = $_['chargePerGb'];
$billingCurrency  = $_['billingCurrency'];
$billingDay       = $_['billingDay'];
$billingNetDays   = $_['billingNetDays'];
$gifts            = $_['gifts'];
?>
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
})();
</script>
