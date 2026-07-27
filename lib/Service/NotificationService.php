<?php

declare(strict_types=1);

namespace OCA\FilesAccounting\Service;

use OCA\FilesAccounting\AppInfo\Application;
use OCP\Notification\IManager;

/**
 * Thin wrapper around the Nextcloud notification manager for billing.
 *
 * A pending (non-zero) bill raises a persistent in-app notification that stays in
 * the user's bell dropdown until the bill is settled. The notification is keyed by
 * year-month (not the reference id, which changes on re-billing) so re-runs update
 * the same entry and it can be dismissed reliably once paid.
 */
class NotificationService {
	public function __construct(
		private IManager $manager,
	) {
	}

	public function notifyUnpaidBill(string $userId, int $year, int $month, float $amount, string $currency): void {
		$period = date('F Y', (int)mktime(0, 0, 0, $month, 1, $year));

		$notification = $this->manager->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser($userId)
			->setDateTime(new \DateTime())
			->setObject('bill', $year . '-' . $month)
			->setSubject('unpaid_bill', [
				'amount'   => number_format($amount, 2),
				'currency' => $currency,
				'period'   => $period,
			]);
		$this->manager->notify($notification);
	}

	public function dismissBillNotification(string $userId, int $year, int $month): void {
		$notification = $this->manager->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser($userId)
			->setObject('bill', $year . '-' . $month);
		$this->manager->markProcessed($notification);
	}
}
