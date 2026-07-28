<?php

declare(strict_types=1);

namespace OCA\FilesAccounting\BackgroundJob;

use OCA\FilesAccounting\Service\InvoiceService;
use OCA\FilesAccounting\Service\NotificationService;
use OCA\FilesAccounting\Service\StorageService;
use OCA\FilesSharding\Service\ShardingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class Stats extends TimedJob {

	public function __construct(
		ITimeFactory                $time,
		private StorageService      $storageService,
		private InvoiceService      $invoiceService,
		private NotificationService $notificationService,
		private IUserManager        $userManager,
		private IConfig             $config,
		private LoggerInterface     $logger,
		private ?ShardingService    $sharding = null,
	) {
		parent::__construct($time);
		$this->setInterval(6 * 3600);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run(mixed $argument): void {
		$dryRunList = (string)$this->config->getSystemValue('dryrunbillingusers', '');
		$dryRun     = $dryRunList !== '';
		$users      = $dryRun
			? array_filter(array_map('trim', explode(',', $dryRunList)))
			: $this->getAllUserIds();

		foreach ($users as $userId) {
			try {
				$this->processUser($userId, $dryRun);
			} catch (\Throwable $e) {
				$this->logger->error("files_accounting: error processing $userId: " . $e->getMessage());
			}
		}

		if (!$dryRun) {
			$this->runAdminOverdueAlerts();
		}
	}

	/**
	 * Email the admin about bills pending longer than the configured threshold
	 * (default 3 months). Master-only (the bills table is central there) and each
	 * bill is reported once — the admin_alerted flag makes repeated runs idempotent.
	 */
	private function runAdminOverdueAlerts(): void {
		if (!$this->storageService->isMaster()) {
			return;
		}
		$months = $this->storageService->getAdminAlertMonths();
		if ($months <= 0) {
			return;
		}
		$cutoff = time() - $months * 30 * 24 * 3600;
		$bills  = $this->storageService->getPendingBillsForAdminAlert($cutoff);
		if (empty($bills)) {
			return;
		}
		$this->invoiceService->sendAdminOverdueAlert($bills, $months);
		$this->storageService->markBillsAdminAlerted(array_column($bills, 'id'));
		$this->logger->info('files_accounting: admin alerted about ' . count($bills) . " bill(s) pending >$months months");
	}

	private function getAllUserIds(): array {
		$ids = [];
		$this->userManager->callForAllUsers(function (IUser $user) use (&$ids) {
			$ids[] = $user->getUID();
		});
		return $ids;
	}

	private function processUser(string $userId, bool $dryRun): void {
		// Log daily usage on this node (for users whose files live here)
		$this->storageService->logDailyUsage($userId);

		// Update per-member storage_used for any groups this user belongs to
		$memberGroups = $this->storageService->getUserMemberGroups($userId);
		if (!empty($memberGroups)) {
			$currentUsage = $this->storageService->getLocalUsage($userId);
			$usedBytes    = $currentUsage['files_usage'] + $currentUsage['trash_usage'];
			foreach ($memberGroups as $group) {
				$this->storageService->updateMemberUsage($group['gid'], $userId, $usedBytes);
			}
		}

		// Only run billing on master
		if (!$this->storageService->isMaster()) {
			return;
		}

		// Only bill on the configured day of month
		$billingDay = $this->storageService->getBillingDayOfMonth();
		if ((int)date('j') !== $billingDay && !$dryRun) {
			return;
		}

		$this->billUser($userId, $dryRun);
	}

	private function billUser(string $userId, bool $dryRun): void {
		if (!$this->userManager->userExists($userId)) {
			return;
		}

		$this->storageService->expireGifts($userId);

		$now          = time();
		$netDays      = $this->storageService->getBillingNetDays();
		$dueTimestamp = $now + 86400 * $netDays;
		$month        = (int)date('n', $now);
		$year         = (int)date('Y', $now);
		$monthName    = date('F', mktime(0, 0, 0, $month, 1));

		$refHash    = substr(md5($userId . $year . $month), 0, 8);
		$referenceId = $year . '-' . $month . '-' . $refHash;

		// Skip if already billed this month
		$invoiceDir = $this->storageService->getInvoiceDir($userId);
		if (!$dryRun && is_dir($invoiceDir)) {
			$files = scandir($invoiceDir) ?: [];
			$pattern = '/^' . $year . '-' . $month . '-.*\.pdf$/';
			if (!empty(preg_grep($pattern, $files))) {
				$this->logger->debug("files_accounting: already billed $userId for $monthName $year");
				return;
			}
		}

		$chargePerGb = $this->storageService->getChargePerGb();
		// Effective free quota = platform baseline + institutional top-up (BILLING.md
		// Option B). A member covered by a university top-up therefore has a higher
		// free tier, so their own bill below naturally drops to 0 in the sponsored
		// band; the university is billed for that band separately (owner loop).
		$freeQuota   = $this->storageService->getEffectiveFreeQuota($userId);
		$freeBytes   = $this->storageService->getEffectiveFreeBytes($userId);

		$usageAvg = $this->storageService->currentUsageAverage($userId, $year, $month);
		$homeAvg  = $usageAvg['home'] ?? [];

		$filesHome  = (float)($homeAvg['files_usage'] ?? 0);
		$trashHome  = (float)($homeAvg['trash_usage']  ?? 0);
		$filesBackup = 0.0; // backup servers not yet used in NC34 setup

		$homeGb   = round($filesHome / (1024 ** 3), 3);
		$trashGb  = round($trashHome / (1024 ** 3), 3);
		$backupGb = 0.0;

		// Subtract free tier before billing
		$billableHomeBytes = max(0.0, $filesHome + $trashHome - $freeBytes);
		$billableHomeGb    = round($billableHomeBytes / (1024 ** 3), 3);
		$homeDue           = round($billableHomeGb * $chargePerGb, 2);
		$backupDue         = 0.0;

		// Storage grant (group) billing — accounted to group owner
		$grantDue      = 0.0;
		$grantArticles = [];
		$grants = $this->storageService->getOwnedStorageGrants($userId);
		foreach ($grants as $grant) {
			$grantUsageBytes = $this->storageService->getStorageGrantUsage($grant['gid'], $year, $month);
			$grantUsageGb    = round($grantUsageBytes / (1024 ** 3), 3);
			$charge          = round($grantUsageGb * $chargePerGb, 2);
			if ($charge > 0.0 || $grantUsageGb > 0) {
				$grantArticles[] = [
					'item'  => "Storage grant '{$grant['gid']}': {$grantUsageGb} GB, $monthName $year",
					'price' => $charge,
				];
				$grantDue += $charge;
			}
		}

		// Home-directory top-up billing (BILLING.md Option B) — the university (group
		// owner) pays for its members' HOME usage in the sponsored band: above the
		// platform baseline B, capped at the per-member top-up. Members' own bills are
		// unaffected — their effective free already includes the top-up (see above), so
		// no double-counting.
		$baselineBytes = $this->storageService->getBaselineFreeBytes();
		foreach ($this->storageService->getOwnedTopupGroups($userId) as $tg) {
			$topupBytes     = (float)$tg['topup_bytes'];
			$sponsoredBytes = 0.0;
			foreach ($this->storageService->getGroupMemberIds((string)$tg['gid']) as $memberUid) {
				$mu = $this->storageService->currentUsageAverage((string)$memberUid, $year, $month);
				$homeBytes = (float)($mu['home']['files_usage'] ?? 0) + (float)($mu['home']['trash_usage'] ?? 0);
				$sponsoredBytes += min($topupBytes, max(0.0, $homeBytes - $baselineBytes));
			}
			$sponsoredGb = round($sponsoredBytes / (1024 ** 3), 3);
			$charge      = round($sponsoredGb * $chargePerGb, 2);
			if ($charge > 0.0 || $sponsoredGb > 0) {
				$grantArticles[] = [
					'item'  => "Home quota top-up for group '{$tg['gid']}': {$sponsoredGb} GB, $monthName $year",
					'price' => $charge,
				];
				$grantDue += $charge;
			}
		}

		// Pod usage — stub until user_pods is ported
		$podsDue      = 0.0;
		$podsArticles = [];
		$podsUsage    = $this->getPodsMonthlyUse($userId, $year, $month);
		foreach (($podsUsage['charges'] ?? []) as $image => $cost) {
			$cost = round((float)$cost, 2);
			$podsArticles[] = [
				'item'  => preg_replace('|^sciencedata/|', '', $image) . '  ' .
					self::secondsToTime((int)($podsUsage['seconds'][$image] ?? 0)),
				'price' => $cost,
			];
			$podsDue += $cost;
		}

		// Apply prepaid credits
		$sumDue    = $homeDue + $backupDue + $grantDue + $podsDue;
		$prePaid   = $this->storageService->getPrePaid($userId);
		if ($prePaid >= $sumDue) {
			$totalDue    = 0.0;
			$newPrePaid  = $prePaid - $sumDue;
		} elseif ($prePaid > 0) {
			$totalDue   = $sumDue - $prePaid;
			$newPrePaid = 0.0;
		} else {
			$totalDue   = $sumDue;
			$newPrePaid = -1.0; // sentinel: don't update
		}
		if ($newPrePaid >= 0.0 && !$dryRun) {
			$this->storageService->setPrePaid($userId, $newPrePaid);
		}

		// First-month pro-ration
		if ($month === (int)($homeAvg['first_month'] ?? 0)) {
			$days = (int)($homeAvg['days'] ?? 0);
			if ($billingDay < (int)($homeAvg['first_day'] ?? 0) && $days > 0) {
				$factor   = $days / 28;
				$homeDue  = round($factor * $homeDue, 2);
				$totalDue = round($factor * $totalDue, 2);
			}
		}

		// Build invoice articles
		$server  = $this->sharding?->getUserServer($userId);
		$siteName = $server?->getSite() ?: (string)$this->config->getSystemValue('url', '');
		$articles = [];
		$articles[] = ['item' => ($homeGb + $trashGb) . " GB storage, $monthName $year at $siteName", 'price' => $homeDue];
		if ($freeBytes > 0) {
			$articles[] = ['item' => 'Free tier: ' . $freeQuota, 'price' => 0.0];
		}
		foreach ($grantArticles as $a) {
			$articles[] = $a;
		}
		foreach ($podsArticles as $a) {
			$articles[] = $a;
		}

		$this->logger->info("files_accounting: billing $userId: home={$homeGb}GB due=$homeDue total=$totalDue");

		if (!$dryRun) {
			$this->storageService->updateMonth(
				$userId,
				($totalDue == 0.0) ? StorageService::PAYMENT_STATUS_PAID : StorageService::PAYMENT_STATUS_PENDING,
				$year, $month, $now, $dueTimestamp,
				$homeGb, $backupGb, $trashGb,
				$server?->getId() ?? 0, 0,
				$server?->getUrl() ?? '', '',
				$server?->getSite() ?? '', '',
				$totalDue, $referenceId,
			);
		}

		$issueDate = date('F j, Y', $now);
		$dueDate   = date('F j, Y', $dueTimestamp);
		$filename  = $this->invoiceService->generateInvoice(
			$userId, $referenceId, $articles, $totalDue, $issueDate, $dueDate, $dryRun,
		);

		if ($filename === null) {
			$this->logger->error("files_accounting: invoice generation failed for $userId");
			return;
		}

		if (!$dryRun) {
			if ($totalDue > 0.0) {
				// Unpaid bill: raise a persistent in-app notification that stays in
				// the bell dropdown until the bill is paid. Email is the fallback,
				// not the default — active users get the UI notification alone;
				// inactive users also get the invoice by mail (they're unlikely to
				// be over quota anyway, but shouldn't miss a real bill).
				$this->notificationService->notifyUnpaidBill(
					$userId, $year, $month, $totalDue, $this->storageService->getBillingCurrency()
				);
				if (!$this->isUserActive($userId)) {
					$this->invoiceService->sendInvoiceEmail($userId, $filename, $totalDue);
				}
			} else {
				// Settled (0 due): clear any stale unpaid notification for this period.
				$this->notificationService->dismissBillNotification($userId, $year, $month);
			}
		}
	}

	/** Active = logged in within roughly the last billing cycle (~35 days). */
	private function isUserActive(string $userId): bool {
		$user = $this->userManager->get($userId);
		if ($user === null) {
			return false;
		}
		$lastLogin = $user->getLastLogin();
		return $lastLogin > 0 && $lastLogin >= (time() - 35 * 24 * 3600);
	}

	private function getPodsMonthlyUse(string $userId, int $year, int $month): array {
		$chargePatterns = (array)$this->config->getSystemValue('pod_charge_per_second', ['.*' => 0.0]);
		$freeSeconds    = (int)$this->config->getSystemValue('pod_free_monthly_seconds', 0);
		$path           = $this->storageService->getPodsUsageFilePath($userId, $year, $month);

		$ret = ['total_charge' => 0.0, 'seconds' => [], 'charges' => []];
		if (!file_exists($path)) {
			return $ret;
		}

		$lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
		$accountedPods = [];
		$totalSeconds  = 0;
		$totalCharge   = 0.0;

		for ($i = count($lines) - 1; $i >= 0; $i--) {
			$row = explode('|', $lines[$i]);
			if (count($row) < 11 || $row[0] !== $userId) {
				continue;
			}
			$podName   = $row[4];
			$imageName = $row[3];
			$runSec    = (int)$row[8];
			$cycleDay  = (int)$row[9];
			$reportTs  = (int)$row[10];
			$billingTs = mktime(0, 0, 0, $month, $cycleDay, $year);
			if (isset($accountedPods[$podName]) || $reportTs >= $billingTs) {
				continue;
			}
			$accountedPods[$podName] = true;
			$ret['seconds'][$imageName] = ($ret['seconds'][$imageName] ?? 0) + $runSec;
			$ret['charges'][$imageName] = $ret['charges'][$imageName] ?? 0.0;
			foreach ($chargePatterns as $pattern => $price) {
				if (!preg_match('|' . $pattern . '|', $imageName)) {
					continue;
				}
				if ($totalSeconds >= $freeSeconds) {
					$charge = ((float)$price) * $runSec;
				} elseif ($totalSeconds + $runSec > $freeSeconds) {
					$charge = ((float)$price) * ($runSec - ($freeSeconds - $totalSeconds));
				} else {
					$charge = 0.0;
				}
				$ret['charges'][$imageName] += $charge;
				$totalCharge += $charge;
				$totalSeconds += $runSec;
				break;
			}
		}
		$ret['total_charge'] = $totalCharge;
		return $ret;
	}

	private static function secondsToTime(int $s): string {
		$parts = [];
		foreach (['day' => 86400, 'hour' => 3600, 'min' => 60, 's' => 1] as $unit => $div) {
			$val = intdiv($s, $div);
			$s  %= $div;
			if ($val > 0) {
				$parts[] = $val . ' ' . $unit . ($val === 1 || $unit === 'min' || $unit === 's' ? '' : 's');
			}
		}
		return $parts ? implode(', ', $parts) : '0 s';
	}
}
