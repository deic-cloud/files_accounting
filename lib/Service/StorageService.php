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

	public function getChargePerGb(): float {
		return (float)$this->config->getSystemValue('charge_per_gb', 0.0);
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
	// Storage grant (group) usage
	// TODO: once user_group_admin defines a shared folder structure per grant,
	// query the filecache for that path. For now returns 0.
	// -------------------------------------------------------------------------

	public function getStorageGrantUsage(string $gid): int {
		return 0;
	}

	/** Returns groups owned by $userId that have a storage_grant set. */
	public function getOwnedStorageGrants(string $userId): array {
		if (!$this->db->tableExists('user_group_admin_groups')) {
			return [];
		}
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('gid', 'storage_grant')
				->from('user_group_admin_groups')
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
