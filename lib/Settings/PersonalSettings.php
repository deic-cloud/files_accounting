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
		// Effective free tier = personal baseline + institutional top-up (BILLING.md
		// Option B), shown as one figure — the user needn't know the split.
		$effectiveFree    = $uid !== '' ? $this->storageService->getEffectiveFreeQuota($uid) : $defaultFreequota;
		$freeBytes        = $uid !== '' ? $this->storageService->getEffectiveFreeBytes($uid) : 0;

		$currentUsage  = $uid !== '' ? $this->storageService->getLocalUsage($uid) : ['files_usage' => 0, 'trash_usage' => 0];
		$usedBytes     = $currentUsage['files_usage'] + $currentUsage['trash_usage'];
		$usedPct       = ($freeBytes > 0) ? min(100, (int)round($usedBytes / $freeBytes * 100)) : 0;

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

		// Home-directory top-ups this user sponsors (Option B), drawn from their quota.
		$ownerTopups = $uid !== '' ? $this->storageService->getOwnedTopupGroups($uid) : [];
		foreach ($ownerTopups as &$tu) {
			$tu['members']   = count($this->storageService->getGroupMemberIds((string)$tu['gid']));
			$tu['committed'] = (int)$tu['topup_bytes'] * max(0, (int)$tu['members']);
		}
		unset($tu);

		$pods = $uid !== '' ? $this->podsSummary($uid) : null;

		return new TemplateResponse('files_accounting', 'personal', [
			'userId'           => $uid,
			'years'            => $years,
			'year'             => $year,
			'bills'            => $bills,
			'gifts'            => $gifts,
			'freequota'        => $userFreequota,
			'defaultFreequota' => $defaultFreequota,
			'effectiveFree'    => $effectiveFree,
			'usedBytes'        => $usedBytes,
			'usedPct'          => $usedPct,
			'currency'         => $this->storageService->getBillingCurrency(),
			'memberGroups'     => $memberGroups,
			'ownerGrants'      => $ownerGrants,
			'ownerTopups'      => $ownerTopups,
			'pods'             => $pods,
		], 'blank');
	}

	/**
	 * Container (pod) running time: the current billing period pod by pod, and the
	 * totals of earlier periods as billed. Null when the user has never run a pod.
	 */
	private function podsSummary(string $uid): ?array {
		$months = $this->storageService->getPodsUsageMonths($uid);
		if (empty($months)) {
			return null;
		}
		[$curYear, $curMonth] = $this->storageService->getCurrentPodsMonth();
		$current = $this->storageService->getPodsUsage($uid, $curYear, $curMonth);
		$history = [];
		foreach ($months as [$year, $month]) {
			if ([$year, $month] === [$curYear, $curMonth] || count($history) >= 12) {
				continue;
			}
			$period = $this->storageService->getPodsPeriod($year, $month);
			$pods = $this->storageService->getPodsUsage($uid, $year, $month, $period['end']);
			$charges = $this->storageService->getPodsCharges($pods);
			$history[] = [
				'start'   => $period['start'],
				'end'     => $period['end'],
				'pods'    => count($pods),
				'seconds' => $charges['total_seconds'],
				'charge'  => $charges['total_charge'],
			];
		}
		$charges = $this->storageService->getPodsCharges($current);
		return [
			'periodStart'  => $this->storageService->getPodsPeriod($curYear, $curMonth)['start'],
			'current'      => $current,
			'seconds'      => $charges['total_seconds'],
			'charge'       => $charges['total_charge'],
			'freeSeconds'  => $charges['free_seconds'],
			'history'      => $history,
		];
	}

	public function getSection(): string {
		return 'files-accounting';
	}

	public function getPriority(): int {
		return 50;
	}
}
