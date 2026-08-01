<?php

declare(strict_types=1);

namespace OCA\FilesAccounting\Service;

use OCA\FilesSharding\Service\InterServerClient;
use OCA\FilesSharding\Service\ShardingService;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class StorageService {

	public const PAYMENT_STATUS_PAID    = 'paid';
	public const PAYMENT_STATUS_PENDING = 'pending';

	public function __construct(
		private IConfig         $config,
		private IDBConnection   $db,
		private IUserManager    $userManager,
		private LoggerInterface $logger,
		private ?ShardingService   $sharding = null,
		private ?InterServerClient $interServer = null,
	) {
	}

	// -------------------------------------------------------------------------
	// Configuration helpers
	// -------------------------------------------------------------------------

	public function getBillingDayOfMonth(): int {
		return (int)$this->config->getSystemValue('billingdayofmonth', 1);
	}

	public function getBillingNetDays(): int {
		return (int)$this->config->getSystemValue('billingnetdays', 120);
	}

	public function getBillingVat(): float {
		return (float)$this->config->getSystemValue('billingvat', 25);
	}

	public function getBillingCurrency(): string {
		return (string)$this->config->getSystemValue('billingcurrency', 'EUR');
	}

	public function getIssuerAddress(): string {
		return (string)$this->config->getSystemValue('fromaddress', '');
	}

	public function getIssuerEmail(): string {
		return (string)$this->config->getSystemValue('fromemail', '');
	}

	/**
	 * Bank/payment details rendered on the invoice PDF so an administration can pay
	 * by transfer (IBAN, account, bank name, etc.). Free text; use ', ' or newlines
	 * to separate lines. Empty = no payment block. (BILLING.md §7.)
	 */
	public function getBankDetails(): string {
		return (string)$this->config->getSystemValue('billing_bank_details', '');
	}

	public function getChargePerGb(): float {
		return (float)$this->config->getSystemValue('charge_per_gb', 0.0);
	}

	/** Months a bill may stay pending before the admin is emailed (see BILLING.md §6). */
	public function getAdminAlertMonths(): int {
		return (int)$this->config->getSystemValue('billing_admin_alert_months', 3);
	}

	// -------------------------------------------------------------------------
	// File paths for usage logs and invoices
	// -------------------------------------------------------------------------

	private function getDataDir(): string {
		return rtrim((string)$this->config->getSystemValue('datadirectory', ''), '/');
	}

	public function getUserAccountingDir(string $userId): string {
		return $this->getDataDir() . '/' . $userId . '/files_accounting';
	}

	public function getUsageFilePath(string $userId, int $year): string {
		return $this->getUserAccountingDir($userId) . '/usage-' . $year . '.txt';
	}

	public function getInvoiceDir(string $userId): string {
		return $this->getUserAccountingDir($userId) . '/bills';
	}

	public function getGroupAccountingDir(string $gid): string {
		return $this->getDataDir() . '/__groups/' . $gid . '/files_accounting';
	}

	public function getGrantUsageFilePath(string $gid, int $year): string {
		return $this->getGroupAccountingDir($gid) . '/usage-' . $year . '.txt';
	}

	public function getPodsUsageFilePath(string $userId, int $year, int $month): string {
		return $this->getUserAccountingDir($userId) . '/pods/podsusage_' . $year . '_' . $month . '.txt';
	}

	private function ensureDir(string $path): void {
		if (!is_dir($path)) {
			mkdir($path, 0750, true);
		}
	}

	// -------------------------------------------------------------------------
	// Local storage usage (reads from filecache)
	// -------------------------------------------------------------------------

	public function getLocalUsage(string $userId, bool $includeTrash = true): array {
		$numericId = $this->getStorageNumericId($userId);
		if ($numericId === null) {
			return ['files_usage' => 0, 'trash_usage' => 0];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('size')->from('filecache')
			->where($qb->expr()->eq('storage', $qb->createNamedParameter($numericId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('path', $qb->createNamedParameter('files')));
		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();
		$filesUsage = $row ? max(0, (int)$row['size']) : 0;

		// Subtract grant folder sizes — grant storage is billed to the group owner,
		// not to the member, so it must not count against the member's personal usage.
		$qb = $this->db->getQueryBuilder();
		$qb->select('size')->from('filecache')
			->where($qb->expr()->eq('storage', $qb->createNamedParameter($numericId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('path', $qb->createNamedParameter('files/.uga_grants')));
		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();
		$filesUsage = max(0, $filesUsage - ($row ? max(0, (int)$row['size']) : 0));

		$trashUsage = 0;
		if ($includeTrash) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('size')->from('filecache')
				->where($qb->expr()->eq('storage', $qb->createNamedParameter($numericId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('path', $qb->createNamedParameter('files_trashbin/files')));
			$cursor = $qb->executeQuery();
			$row = $cursor->fetch();
			$cursor->closeCursor();
			$trashUsage = $row ? max(0, (int)$row['size']) : 0;
		}

		return ['files_usage' => $filesUsage, 'trash_usage' => $trashUsage];
	}

	private function getStorageNumericId(string $userId): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('numeric_id')->from('storages')
			->where($qb->expr()->eq('id', $qb->createNamedParameter('home::' . $userId)));
		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();
		return $row ? (int)$row['numeric_id'] : null;
	}

	// -------------------------------------------------------------------------
	// Storage grant (group) usage — flat file per group, same format as user usage
	// -------------------------------------------------------------------------

	public function getStorageGrantUsage(string $gid, int $year, int $month): int {
		$path = $this->getGrantUsageFilePath($gid, $year);
		if (!file_exists($path)) {
			return 0;
		}
		$todayDay  = (int)date('j');
		$prevMonth = $month === 1 ? 12 : $month - 1;
		$total     = 0;
		$count     = 0;
		foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
			$row = explode(' ', trim($line));
			if (count($row) < 6 || $row[0] !== $gid) {
				continue;
			}
			[$g, $y, $m, $d, , $bytes] = $row;
			$rowYear = (int)$y; $rowMonth = (int)$m; $rowDay = (int)$d;
			if ($rowYear === $year && (
				($rowMonth === $month && $rowDay < $todayDay) ||
				($rowMonth === $prevMonth && $rowDay >= $todayDay)
			)) {
				$total += (int)$bytes;
				$count++;
			}
		}
		return $count > 0 ? (int)($total / $count) : 0;
	}

	public function logGrantUsage(string $gid, int $bytes, bool $overwrite = false): void {
		$timestamp = time();
		$year  = (int)date('Y', $timestamp);
		$month = (int)date('n', $timestamp);
		$day   = (int)date('j', $timestamp);
		$time  = date('H:i:s', $timestamp);
		$dir   = $this->getGroupAccountingDir($gid);
		$this->ensureDir($dir);
		$path = $this->getGrantUsageFilePath($gid, $year);
		if (!file_exists($path)) {
			touch($path);
		}
		$lines    = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
		$lastLine = !empty($lines) ? end($lines) : '';
		if ($lastLine !== '') {
			$parts = explode(' ', $lastLine);
			if (count($parts) >= 4 && $parts[0] === $gid
				&& (int)$parts[1] === $year && (int)$parts[2] === $month && (int)$parts[3] === $day
			) {
				if (!$overwrite) {
					return;
				}
				$lines = array_slice($lines, 0, -1);
				file_put_contents($path, implode("\n", $lines) . (count($lines) ? "\n" : ''), LOCK_EX);
			}
		}
		$line = implode(' ', [$gid, $year, $month, $day, $time, $bytes]) . "\n";
		file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
	}

	/** Groups the user is an accepted member of that have a storage_grant set. */
	public function getUserMemberGroups(string $userId): array {
		if (!$this->db->tableExists('uga_groups') || !$this->db->tableExists('uga_group_members')) {
			return [];
		}
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('g.gid', 'g.storage_grant', 'm.storage_used')
				->from('uga_groups', 'g')
				->innerJoin('g', 'uga_group_members', 'm', $qb->expr()->eq('g.gid', 'm.gid'))
				->where($qb->expr()->eq('m.uid', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->eq('m.status', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->neq('g.storage_grant', $qb->createNamedParameter('')));
			$cursor = $qb->executeQuery();
			$rows   = $cursor->fetchAll();
			$cursor->closeCursor();
			return $rows;
		} catch (\Throwable $e) {
			$this->logger->debug('files_accounting: getUserMemberGroups failed: ' . $e->getMessage());
			return [];
		}
	}

	/** Sum of storage_used across all accepted members of a group. */
	public function getGroupTotalUsage(string $gid): int {
		if (!$this->db->tableExists('uga_group_members')) {
			return 0;
		}
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->func()->sum('storage_used'))
				->from('uga_group_members')
				->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)))
				->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
			$cursor = $qb->executeQuery();
			$val    = $cursor->fetchOne();
			$cursor->closeCursor();
			return (int)$val;
		} catch (\Throwable $e) {
			return 0;
		}
	}

	/** Update per-member storage_used in uga_group_members. */
	public function updateMemberUsage(string $gid, string $userId, int $bytes): void {
		if (!$this->db->tableExists('uga_group_members')) {
			return;
		}
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->update('uga_group_members')
				->set('storage_used', $qb->createNamedParameter($bytes, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)))
				->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($userId)));
			$qb->executeStatement();
		} catch (\Throwable $e) {
			$this->logger->debug('files_accounting: updateMemberUsage failed: ' . $e->getMessage());
		}
	}

	/** Returns groups owned by $userId that have a storage_grant set. */
	public function getOwnedStorageGrants(string $userId): array {
		if (!$this->db->tableExists('uga_groups')) {
			return [];
		}
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('gid', 'storage_grant')
				->from('uga_groups')
				->where($qb->expr()->eq('owner', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->neq('storage_grant', $qb->createNamedParameter('')));
			$cursor = $qb->executeQuery();
			$rows = $cursor->fetchAll();
			$cursor->closeCursor();
			return $rows;
		} catch (\Throwable $e) {
			$this->logger->debug('files_accounting: getOwnedStorageGrants failed: ' . $e->getMessage());
			return [];
		}
	}

	// -------------------------------------------------------------------------
	// Daily usage logging
	// -------------------------------------------------------------------------

	public function logDailyUsage(string $userId, bool $overwrite = false): void {
		$timestamp = time();
		$year  = (int)date('Y', $timestamp);
		$month = (int)date('n', $timestamp);
		$day   = (int)date('j', $timestamp);
		$time  = date('H:i:s', $timestamp);

		$dir = $this->getUserAccountingDir($userId);
		$this->ensureDir($dir);
		$path = $this->getUsageFilePath($userId, $year);
		if (!file_exists($path)) {
			touch($path);
		}

		$lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
		$lastLine = !empty($lines) ? end($lines) : '';
		if ($lastLine !== '') {
			$parts = explode(' ', $lastLine);
			if (count($parts) >= 4 && $parts[0] === $userId
				&& (int)$parts[1] === $year && (int)$parts[2] === $month && (int)$parts[3] === $day
			) {
				if (!$overwrite) {
					$this->logger->debug("files_accounting: already logged $userId for $year-$month-$day");
					return;
				}
				// Rewrite without last line
				$lines = array_slice($lines, 0, -1);
				file_put_contents($path, implode("\n", $lines) . (count($lines) ? "\n" : ''), LOCK_EX);
			}
		}

		$usage = $this->getLocalUsage($userId, true);
		$line = implode(' ', [
			$userId, $year, $month, $day, $time,
			$usage['files_usage'], $usage['trash_usage'],
		]) . "\n";
		file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
	}

	// -------------------------------------------------------------------------
	// Usage averages (reads from local flat file)
	// -------------------------------------------------------------------------

	public function localCurrentUsageAverage(string $userId, int $year, int $month): array {
		$path = $this->getUsageFilePath($userId, $year);
		if (!file_exists($path)) {
			return ['files_usage' => 0, 'trash_usage' => 0, 'days' => 0, 'first_day' => 0, 'first_month' => 0];
		}

		$todayDay = (int)date('j');
		$lines    = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
		$entries  = [];
		$firstDay = 0;
		$firstMonth = 0;

		foreach ($lines as $line) {
			$row = explode(' ', trim($line));
			if (count($row) < 7 || $row[0] !== $userId) {
				continue;
			}
			[$uid, $y, $m, $d, , $files, $trash] = $row;
			if ($firstDay === 0) {
				$firstDay   = (int)$d;
				$firstMonth = (int)$m;
			}
			// Include rows from this month (before today) or same-day-or-later last month
			$rowYear  = (int)$y;
			$rowMonth = (int)$m;
			$rowDay   = (int)$d;
			$prevMonth = $month === 1 ? 12 : $month - 1;
			if ($rowYear === $year && (
				($rowMonth === $month && $rowDay < $todayDay) ||
				($rowMonth === $prevMonth && $rowDay >= $todayDay)
			)) {
				$entries[] = ['files_usage' => (int)$files, 'trash_usage' => (int)$trash];
			}
		}

		$count = count($entries);
		if ($count === 0) {
			return ['files_usage' => 0, 'trash_usage' => 0, 'days' => 0,
				'first_day' => $firstDay, 'first_month' => $firstMonth];
		}
		return [
			'files_usage' => (int)(array_sum(array_column($entries, 'files_usage')) / $count),
			'trash_usage' => (int)(array_sum(array_column($entries, 'trash_usage')) / $count),
			'days'        => $count,
			'first_day'   => $firstDay,
			'first_month' => $firstMonth,
		];
	}

	/** Returns raw daily usage rows for charting. */
	public function localUsageData(string $userId, int $year, ?int $month = null): array {
		$path = $this->getUsageFilePath($userId, $year);
		if (!file_exists($path)) {
			return [];
		}
		$rows = [];
		foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
			$row = explode(' ', trim($line));
			if (count($row) < 7 || $row[0] !== $userId) {
				continue;
			}
			if ((int)$row[1] !== $year) {
				continue;
			}
			if ($month !== null && (int)$row[2] !== $month) {
				continue;
			}
			$rows[] = [
				'year'        => (int)$row[1],
				'month'       => (int)$row[2],
				'day'         => (int)$row[3],
				'files_usage' => (int)$row[5],
				'trash_usage' => (int)$row[6],
			];
		}
		return $rows;
	}

	// -------------------------------------------------------------------------
	// Cross-silo usage average (calls silo if not local)
	// -------------------------------------------------------------------------

	public function currentUsageAverage(string $userId, int $year, int $month): array {
		if ($this->sharding === null || $this->sharding->isMaster() === false) {
			// On a silo: answer locally
			$home = $this->localCurrentUsageAverage($userId, $year, $month);
			return ['home' => $home, 'backup' => null];
		}

		$server = $this->sharding->getUserServer($userId);
		if ($server === null) {
			// User has no silo assigned — use local data (user is on master)
			$home = $this->localCurrentUsageAverage($userId, $year, $month);
			return ['home' => $home, 'backup' => null];
		}

		$base = $server->getInternalUrl() ?: $server->getUrl();
		$home = $this->interServer?->postDirect($base, 'internal/currentusageaverage',
			['userid' => $userId, 'year' => $year, 'month' => $month], 'files_accounting');
		if (!is_array($home)) {
			$home = ['files_usage' => 0, 'trash_usage' => 0, 'days' => 0];
		}
		return ['home' => $home, 'backup' => null];
	}

	// -------------------------------------------------------------------------
	// Quota / free-quota management
	// -------------------------------------------------------------------------

	public function getDefaultFreeQuota(): string {
		return (string)$this->config->getAppValue('files_accounting', 'default_freequota', '0');
	}

	public function setDefaultFreeQuota(string $quota): void {
		$this->config->setAppValue('files_accounting', 'default_freequota', $quota);
	}

	public function getUserFreequota(string $userId): string {
		return $this->config->getUserValue($userId, 'files_accounting', 'freequota', '');
	}

	public function getFreeQuota(string $userId): string {
		$val = $this->getUserFreequota($userId);
		return $val !== '' ? $val : $this->getDefaultFreeQuota();
	}

	public function setFreeQuota(string $userId, string $quota): void {
		$this->config->setUserValue($userId, 'files_accounting', 'freequota', $quota);

		// Propagate to the user's silo if we're master
		if ($this->sharding !== null && $this->sharding->isMaster()) {
			$server = $this->sharding->getUserServer($userId);
			if ($server !== null) {
				$base = $server->getInternalUrl() ?: $server->getUrl();
				$this->interServer?->postDirect($base, 'internal/setfreequota',
					['userid' => $userId, 'quota' => $quota], 'files_accounting');
			}
		}
	}

	public function getPrePaid(string $userId): float {
		return (float)$this->config->getUserValue($userId, 'files_accounting', 'prepaid', '0');
	}

	public function setPrePaid(string $userId, float $amount): void {
		$this->config->setUserValue($userId, 'files_accounting', 'prepaid', (string)$amount);
	}

	// -------------------------------------------------------------------------
	// Home-directory quota top-up (BILLING.md Option B)
	// -------------------------------------------------------------------------
	// A university (owner of its domain group) buys extra free quota on its users'
	// STANDARD home dirs. Stored per group here (not on uga_groups, which carries
	// the grant-FOLDER size). Effective home free = baseline B + Σ top-ups of the
	// user's groups; the university is billed for members' home usage in that band.

	public function getGroupTopup(string $gid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('topup_bytes')->from('files_accounting_topup')
			->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)));
		$cursor = $qb->executeQuery();
		$val = $cursor->fetchOne();
		$cursor->closeCursor();
		return $val === false ? 0 : (int)$val;
	}

	public function setGroupTopup(string $gid, int $bytes): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('files_accounting_topup')
			->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)));
		$qb->executeStatement();
		if ($bytes > 0) {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('files_accounting_topup')->values([
				'gid'         => $qb->createNamedParameter($gid),
				'topup_bytes' => $qb->createNamedParameter($bytes, IQueryBuilder::PARAM_INT),
				'updated_at'  => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
			]);
			$qb->executeStatement();
		}
	}

	/** All groups with a home top-up set: [gid => topup_bytes]. */
	public function getAllGroupTopups(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('gid', 'topup_bytes')->from('files_accounting_topup')
			->where($qb->expr()->gt('topup_bytes', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		$cursor = $qb->executeQuery();
		$rows = $cursor->fetchAll();
		$cursor->closeCursor();
		$out = [];
		foreach ($rows as $r) {
			$out[(string)$r['gid']] = (int)$r['topup_bytes'];
		}
		return $out;
	}

	/**
	 * Institutional top-up (T) for a user: the sum of the home top-ups of the groups
	 * (domains) they're an accepted member of.
	 */
	public function getUserTopupBytes(string $userId): int {
		$topups = $this->getAllGroupTopups();
		if (empty($topups)) {
			return 0;
		}
		$sum = 0;
		foreach ($this->getUserGroupIds($userId) as $gid) {
			$sum += $topups[$gid] ?? 0;
		}
		return $sum;
	}

	/**
	 * Effective free quota in bytes = personal baseline + institutional top-up.
	 * Baseline is the individual override if set, else the platform default (B); the
	 * institutional top-up (T) always adds on top. Shown to the user as one figure.
	 */
	public function getEffectiveFreeBytes(string $userId): int {
		$base = $this->getUserFreequota($userId);
		if ($base === '') {
			$base = $this->getDefaultFreeQuota();
		}
		return $this->parseQuotaToBytes($base) + $this->getUserTopupBytes($userId);
	}

	/** Human-readable effective free quota (baseline + institutional top-up). */
	public function getEffectiveFreeQuota(string $userId): string {
		$bytes = $this->getEffectiveFreeBytes($userId);
		return $bytes > 0 ? \OCP\Util::humanFileSize($bytes) : '0';
	}

	/** Platform baseline B in bytes (what WE sponsor; the top-up bills above this). */
	public function getBaselineFreeBytes(): int {
		return $this->parseQuotaToBytes($this->getDefaultFreeQuota());
	}

	/**
	 * Groups OWNED by $userId that have a home top-up set: [['gid'=>, 'topup_bytes'=>], ...].
	 * The owner (university) is billed for these. Returns [] if uga tables absent.
	 */
	public function getOwnedTopupGroups(string $userId): array {
		if (!$this->db->tableExists('uga_groups')) {
			return [];
		}
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('t.gid', 't.topup_bytes')
				->from('files_accounting_topup', 't')
				->innerJoin('t', 'uga_groups', 'g', $qb->expr()->eq('t.gid', 'g.gid'))
				->where($qb->expr()->eq('g.owner', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->gt('t.topup_bytes', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
			$cursor = $qb->executeQuery();
			$rows = $cursor->fetchAll();
			$cursor->closeCursor();
			return $rows;
		} catch (\Throwable $e) {
			$this->logger->debug('files_accounting: getOwnedTopupGroups failed: ' . $e->getMessage());
			return [];
		}
	}

	/** Accepted member uids of a group (from uga_group_members). [] if tables absent. */
	public function getGroupMemberIds(string $gid): array {
		if (!$this->db->tableExists('uga_group_members')) {
			return [];
		}
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('uid')->from('uga_group_members')
				->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)))
				->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
			$cursor = $qb->executeQuery();
			$rows = $cursor->fetchAll();
			$cursor->closeCursor();
			return array_column($rows, 'uid');
		} catch (\Throwable $e) {
			return [];
		}
	}

	/** Group ids a user is an accepted member of (from uga_group_members). */
	private function getUserGroupIds(string $userId): array {
		if (!$this->db->tableExists('uga_group_members')) {
			return [];
		}
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('gid')->from('uga_group_members')
				->where($qb->expr()->eq('uid', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
			$cursor = $qb->executeQuery();
			$rows = $cursor->fetchAll();
			$cursor->closeCursor();
			return array_column($rows, 'gid');
		} catch (\Throwable $e) {
			return [];
		}
	}

	// -------------------------------------------------------------------------
	// Billing records
	// -------------------------------------------------------------------------

	public function getBills(?string $userId = null, ?int $year = null, ?string $status = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('files_accounting')->where('1=1');
		if ($userId !== null) {
			$qb->andWhere($qb->expr()->eq('user', $qb->createNamedParameter($userId)));
		}
		if ($year !== null) {
			$qb->andWhere($qb->expr()->eq('year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)));
		}
		if ($status !== null) {
			$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)));
		}
		$qb->orderBy('year', 'DESC')->addOrderBy('month', 'DESC');
		$cursor = $qb->executeQuery();
		$rows = $cursor->fetchAll();
		$cursor->closeCursor();
		return $rows;
	}

	public function getBillByReference(string $referenceId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('files_accounting')
			->where($qb->expr()->eq('reference_id', $qb->createNamedParameter($referenceId)));
		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();
		return $row ?: null;
	}

	public function getCurrentMonthBill(string $userId, int $year, int $month): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('files_accounting')
			->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('month', $qb->createNamedParameter($month, IQueryBuilder::PARAM_INT)));
		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();
		return $row ?: null;
	}

	public function updateMonth(
		string $userId, string $status, int $year, int $month, int $timestamp, int $timeDue,
		float $homeGb, float $backupGb, float $trashGb,
		int $homeId, int $backupId, string $homeUrl, string $backupUrl,
		string $homeSite, string $backupSite, float $amountDue, string $referenceId,
	): void {
		// Upsert: delete existing row for this user/year/month, then insert fresh
		$qb = $this->db->getQueryBuilder();
		$qb->delete('files_accounting')
			->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('month', $qb->createNamedParameter($month, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();

		$qb = $this->db->getQueryBuilder();
		$qb->insert('files_accounting')->values([
			'user'               => $qb->createNamedParameter($userId),
			'status'             => $qb->createNamedParameter($status),
			'year'               => $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT),
			'month'              => $qb->createNamedParameter($month, IQueryBuilder::PARAM_INT),
			'timestamp'          => $qb->createNamedParameter($timestamp, IQueryBuilder::PARAM_INT),
			'time_due'           => $qb->createNamedParameter($timeDue, IQueryBuilder::PARAM_INT),
			'home_files_usage'   => $qb->createNamedParameter($homeGb),
			'home_trash_usage'   => $qb->createNamedParameter($trashGb),
			'backup_files_usage' => $qb->createNamedParameter($backupGb),
			'home_id'            => $qb->createNamedParameter($homeId, IQueryBuilder::PARAM_INT),
			'backup_id'          => $qb->createNamedParameter($backupId, IQueryBuilder::PARAM_INT),
			'home_url'           => $qb->createNamedParameter($homeUrl),
			'backup_url'         => $qb->createNamedParameter($backupUrl),
			'home_site'          => $qb->createNamedParameter($homeSite),
			'backup_site'        => $qb->createNamedParameter($backupSite),
			'amount_due'         => $qb->createNamedParameter($amountDue),
			'reference_id'       => $qb->createNamedParameter($referenceId),
		]);
		$qb->executeStatement();
	}

	public function updateStatus(string $referenceId, string $status): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update('files_accounting')
			->set('status', $qb->createNamedParameter($status))
			->where($qb->expr()->eq('reference_id', $qb->createNamedParameter($referenceId)));
		return $qb->executeStatement() > 0;
	}

	/**
	 * Mark bills (by row id) as paid, optionally stamping a payment reference.
	 * Returns the affected rows (id/user/year/month) so the caller can clear each
	 * user's pending-bill notification. Supports bulk (e.g. a university settling
	 * all of its domain's bills at once — BILLING.md §7).
	 *
	 * @param int[] $ids
	 * @return array<int, array{id:int, user:string, year:int, month:int}>
	 */
	public function markBillsPaid(array $ids, string $reference = ''): array {
		if (empty($ids)) {
			return [];
		}
		$ids = array_map('intval', $ids);

		// Fetch first — we need user/year/month to dismiss notifications afterwards.
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'user', 'year', 'month')->from('files_accounting')
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
		$cursor = $qb->executeQuery();
		$rows = $cursor->fetchAll();
		$cursor->closeCursor();

		$qb = $this->db->getQueryBuilder();
		$qb->update('files_accounting')
			->set('status', $qb->createNamedParameter(self::PAYMENT_STATUS_PAID))
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
		if ($reference !== '') {
			$qb->set('reference_id', $qb->createNamedParameter($reference));
		}
		$qb->executeStatement();

		return array_map(static fn (array $r): array => [
			'id'    => (int)$r['id'],
			'user'  => (string)$r['user'],
			'year'  => (int)$r['year'],
			'month' => (int)$r['month'],
		], $rows);
	}

	/**
	 * Pending bills issued before $issuedBefore that haven't yet triggered an admin
	 * alert. Used by the escalation scan; the admin_alerted flag makes it fire once
	 * per bill rather than on every run.
	 */
	public function getPendingBillsForAdminAlert(int $issuedBefore): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('files_accounting')
			->where($qb->expr()->eq('status', $qb->createNamedParameter(self::PAYMENT_STATUS_PENDING)))
			->andWhere($qb->expr()->eq('admin_alerted', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('amount_due', $qb->createNamedParameter(0)))
			->andWhere($qb->expr()->lt('timestamp', $qb->createNamedParameter($issuedBefore, IQueryBuilder::PARAM_INT)))
			->orderBy('timestamp', 'ASC');
		$cursor = $qb->executeQuery();
		$rows = $cursor->fetchAll();
		$cursor->closeCursor();
		return $rows;
	}

	/** Mark bills (by id) as having triggered their admin alert, so they don't re-fire. */
	public function markBillsAdminAlerted(array $ids): void {
		if (empty($ids)) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('files_accounting')
			->set('admin_alerted', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->in('id', $qb->createNamedParameter(
				array_map('intval', $ids), IQueryBuilder::PARAM_INT_ARRAY
			)));
		$qb->executeStatement();
	}

	public function getAccountedYears(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('year')->from('files_accounting')
			->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
			->orderBy('year', 'DESC');
		$cursor = $qb->executeQuery();
		$rows = $cursor->fetchAll();
		$cursor->closeCursor();
		return array_column($rows, 'year');
	}

	// -------------------------------------------------------------------------
	// Gift codes
	// -------------------------------------------------------------------------

	public function getGifts(?string $code = null, ?string $userId = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('files_accounting_gifts')->where('1=1');
		if ($code !== null) {
			$qb->andWhere($qb->expr()->eq('code', $qb->createNamedParameter($code)));
		}
		if ($userId !== null) {
			$qb->andWhere($qb->expr()->eq('user', $qb->createNamedParameter($userId)));
		}
		$qb->orderBy('creation_time', 'DESC');
		$cursor = $qb->executeQuery();
		$rows = $cursor->fetchAll();
		$cursor->closeCursor();
		return $rows;
	}

	public function createGift(float $amount, string $size, int $days, string $site = '', int $claimExpiresDays = 0): string {
		$code    = $this->generateGiftCode();
		$now     = time();
		$expires = $claimExpiresDays > 0 ? $now + $claimExpiresDays * 86400 : 0;

		$qb = $this->db->getQueryBuilder();
		$qb->insert('files_accounting_gifts')->values([
			'code'             => $qb->createNamedParameter($code),
			'amount'           => $qb->createNamedParameter($amount),
			'size'             => $qb->createNamedParameter($size),
			'site'             => $qb->createNamedParameter($site),
			'status'           => $qb->createNamedParameter('OPEN'),
			'creation_time'    => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'claim_expiration' => $qb->createNamedParameter($expires, IQueryBuilder::PARAM_INT),
			'days'             => $qb->createNamedParameter($days, IQueryBuilder::PARAM_INT),
			'user'             => $qb->createNamedParameter(''),
		]);
		$qb->executeStatement();
		return $code;
	}

	public function deleteGift(string $code): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('files_accounting_gifts')
			->where($qb->expr()->eq('code', $qb->createNamedParameter($code)));
		return $qb->executeStatement() > 0;
	}

	public function createGiftViaMaster(float $amount, string $size, int $days, string $site, int $claimExpiresDays): string {
		$masterUrl = rtrim((string)$this->config->getSystemValue('files_sharding_master_url', ''), '/');
		if ($masterUrl === '' || $this->interServer === null) {
			return $this->createGift($amount, $size, $days, $site, $claimExpiresDays);
		}
		$result = $this->interServer->postDirect(
			$masterUrl, 'internal/creategift',
			['amount' => $amount, 'size' => $size, 'days' => $days, 'site' => $site, 'claim_expires_days' => $claimExpiresDays],
			'files_accounting'
		);
		return (string)($result['code'] ?? $this->createGift($amount, $size, $days, $site, $claimExpiresDays));
	}

	public function redeemGiftViaMaster(string $code, string $userId): array {
		$masterUrl = rtrim((string)$this->config->getSystemValue('files_sharding_master_url', ''), '/');
		if ($masterUrl === '' || $this->interServer === null) {
			return ['success' => false, 'message' => 'Invalid gift code'];
		}
		$result = $this->interServer->postDirect(
			$masterUrl, 'internal/redeemgift',
			['code' => $code, 'userid' => $userId], 'files_accounting'
		);
		if (!is_array($result)) {
			return ['success' => false, 'message' => 'Invalid gift code'];
		}
		return $result;
	}

	public function redeemGift(string $code, string $userId): array {
		$gifts = $this->getGifts($code);
		if (empty($gifts)) {
			return ['success' => false, 'message' => 'Invalid gift code'];
		}
		$gift = $gifts[0];

		if ($gift['status'] !== 'OPEN') {
			return ['success' => false, 'message' => 'Gift already redeemed or expired'];
		}
		$now = time();
		if ($gift['claim_expiration'] > 0 && $gift['claim_expiration'] < $now) {
			$this->updateGiftStatus($code, 'EXPIRED', '');
			return ['success' => false, 'message' => 'Gift code has expired'];
		}

		// Apply storage size grant
		if (!empty($gift['size'])) {
			$current = $this->getFreeQuota($userId);
			$currentBytes = $this->parseQuotaToBytes($current);
			$giftBytes    = $this->parseQuotaToBytes($gift['size']);
			if ($giftBytes > 0 && $currentBytes < $giftBytes) {
				$this->setFreeQuota($userId, $gift['size']);
			}
		}

		$this->updateGiftStatus($code, 'REDEEMED', $userId, $now);
		return ['success' => true, 'gift' => $gift];
	}

	public function expireGifts(string $userId): void {
		$now  = time();
		$gifts = $this->getGifts(null, $userId);
		foreach ($gifts as $gift) {
			if ($gift['status'] === 'OPEN' && $gift['claim_expiration'] > 0 && $gift['claim_expiration'] < $now) {
				$this->updateGiftStatus($gift['code'], 'EXPIRED', '');
			}
		}
	}

	public function updateGiftStatus(string $code, string $status, string $userId, int $redemptionTime = 0): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('files_accounting_gifts')
			->set('status', $qb->createNamedParameter($status))
			->set('user',   $qb->createNamedParameter($userId));
		if ($redemptionTime > 0) {
			$qb->set('redemption_time', $qb->createNamedParameter($redemptionTime, IQueryBuilder::PARAM_INT));
		}
		$qb->where($qb->expr()->eq('code', $qb->createNamedParameter($code)));
		$qb->executeStatement();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function generateGiftCode(): string {
		$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$code  = '';
		for ($i = 0; $i < 16; $i++) {
			if ($i > 0 && $i % 4 === 0) {
				$code .= '-';
			}
			$code .= $chars[random_int(0, strlen($chars) - 1)];
		}
		return $code;
	}

	public function parseQuotaToBytes(string $quota): int {
		if ($quota === '' || $quota === '0') {
			return 0;
		}
		$quota = trim(strtolower($quota));
		if (is_numeric($quota)) {
			return (int)$quota;
		}
		preg_match('/^([\d.]+)\s*([kmgtpe]?b?)$/', $quota, $m);
		if (empty($m)) {
			return 0;
		}
		$val  = (float)$m[1];
		$unit = $m[2] ?? '';
		return (int)($val * match ($unit) {
			'kb', 'k' => 1024,
			'mb', 'm' => 1024 ** 2,
			'gb', 'g' => 1024 ** 3,
			'tb', 't' => 1024 ** 4,
			default   => 1,
		});
	}

	public function isMaster(): bool {
		return $this->sharding === null || $this->sharding->isMaster();
	}
}
