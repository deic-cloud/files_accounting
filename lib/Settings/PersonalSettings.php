<?php

declare(strict_types=1);

namespace OCA\FilesAccounting\Settings;

use OCA\FilesAccounting\Service\StorageService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IUserSession;
use OCP\Settings\ISettings;

class PersonalSettings implements ISettings {
	public function __construct(
		private StorageService $storageService,
		private IUserSession   $userSession,
	) {
	}

	public function getForm(): TemplateResponse {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		$years = $uid !== '' ? $this->storageService->getAccountedYears($uid) : [];
		$year  = !empty($years) ? (int)$years[0] : (int)date('Y');
		$bills = $uid !== '' ? $this->storageService->getBills($uid) : [];
		$gifts = $uid !== '' ? $this->storageService->getGifts(null, $uid) : [];

		$userFreequota    = $uid !== '' ? $this->storageService->getUserFreequota($uid) : '';
		$defaultFreequota = $this->storageService->getDefaultFreeQuota();

		return new TemplateResponse('files_accounting', 'personal', [
			'userId'           => $uid,
			'years'            => $years,
			'year'             => $year,
			'bills'            => $bills,
			'gifts'            => $gifts,
			'freequota'        => $userFreequota,
			'defaultFreequota' => $defaultFreequota,
			'currency'         => $this->storageService->getBillingCurrency(),
		], 'blank');
	}

	public function getSection(): string {
		return 'files-accounting';
	}

	public function getPriority(): int {
		return 50;
	}
}
