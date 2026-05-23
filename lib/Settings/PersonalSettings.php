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

		// Group storage grant sections
		$memberGroups = $uid !== '' ? $this->storageService->getUserMemberGroups($uid) : [];
		foreach ($memberGroups as &$mg) {
			$grantBytes = $this->storageService->parseQuotaToBytes($mg['storage_grant']);
			$mg['grant_bytes'] = $grantBytes;
			$mg['used_pct']    = ($grantBytes > 0)
				? min(100, (int)round(($mg['storage_used'] / $grantBytes) * 100))
				: 0;
		}
		unset($mg);

		$ownerGrants  = $uid !== '' ? $this->storageService->getOwnedStorageGrants($uid) : [];
		foreach ($ownerGrants as &$grant) {
			$grant['total_used'] = $this->storageService->getGroupTotalUsage($grant['gid']);
		}
		unset($grant);

		return new TemplateResponse('files_accounting', 'personal', [
			'userId'           => $uid,
			'years'            => $years,
			'year'             => $year,
			'bills'            => $bills,
			'gifts'            => $gifts,
			'freequota'        => $userFreequota,
			'defaultFreequota' => $defaultFreequota,
			'currency'         => $this->storageService->getBillingCurrency(),
			'memberGroups'     => $memberGroups,
			'ownerGrants'      => $ownerGrants,
		], 'blank');
	}

	public function getSection(): string {
		return 'files-accounting';
	}

	public function getPriority(): int {
		return 50;
	}
}
