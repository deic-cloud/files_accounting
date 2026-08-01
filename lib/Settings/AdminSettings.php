<?php

declare(strict_types=1);

namespace OCA\FilesAccounting\Settings;

use OCA\FilesAccounting\Service\StorageService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings {
	public function __construct(
		private StorageService $storageService,
	) {
	}

	public function getForm(): TemplateResponse {
		$groupTopups = [];
		foreach ($this->storageService->getAllGroupTopups() as $gid => $bytes) {
			$groupTopups[] = [
				'gid'   => $gid,
				'bytes' => $bytes,
				'human' => \OCP\Util::humanFileSize($bytes),
				'owner' => $this->storageService->getGroupOwner($gid),
			];
		}

		return new TemplateResponse('files_accounting', 'admin', [
			'defaultFreeQuota' => $this->storageService->getDefaultFreeQuota(),
			'chargePerGb'      => $this->storageService->getChargePerGb(),
			'billingCurrency'  => $this->storageService->getBillingCurrency(),
			'billingDay'       => $this->storageService->getBillingDayOfMonth(),
			'billingNetDays'   => $this->storageService->getBillingNetDays(),
			'gifts'            => $this->storageService->getGifts(),
			'bills'            => $this->storageService->getBills(null, null, null, 200),
			'groupTopups'      => $groupTopups,
		], 'blank');
	}

	public function getSection(): string {
		return 'files-accounting';
	}

	public function getPriority(): int {
		return 50;
	}
}
