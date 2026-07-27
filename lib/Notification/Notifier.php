<?php

declare(strict_types=1);

namespace OCA\FilesAccounting\Notification;

use OCA\FilesAccounting\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Renders files_accounting notifications for the bell dropdown.
 *
 * The only notification so far is 'unpaid_bill': a persistent notification for a
 * pending (non-zero) storage invoice. It carries no dismiss action on purpose —
 * it stays in the dropdown until the bill is marked paid, at which point the
 * billing run (or a payment) calls NotificationService::dismissBillNotification().
 */
class Notifier implements INotifier {
	public function __construct(
		private IFactory      $l10nFactory,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return 'Storage Accounting';
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException('Wrong app');
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$p = $notification->getSubjectParameters();

		match ($notification->getSubject()) {
			'unpaid_bill' => $notification
				->setParsedSubject($l->t('Storage invoice due: %s %s', [$p['amount'] ?? '', $p['currency'] ?? '']))
				->setParsedMessage($l->t('Your storage invoice for %s is awaiting payment.', [$p['period'] ?? ''])),
			default => throw new UnknownNotificationException('Unknown subject'),
		};

		$notification->setLink($this->urlGenerator->linkToRouteAbsolute(
			'settings.PersonalSettings.index', ['section' => 'files-accounting']
		));
		return $notification;
	}
}
