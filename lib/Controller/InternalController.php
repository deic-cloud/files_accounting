<?php

declare(strict_types=1);

namespace OCA\FilesAccounting\Controller;

use OCA\FilesAccounting\Service\StorageService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;

/**
 * Inter-silo endpoints secured with the files_sharding shared secret.
 * All routes are public (no NC session required) but validate Bearer token.
 */
class InternalController extends Controller {

	public function __construct(
		string                  $appName,
		IRequest                $request,
		private StorageService  $storageService,
		private IConfig         $config,
	) {
		parent::__construct($appName, $request);
	}

	private function checkSecret(): bool {
		$secret = (string)$this->config->getSystemValue('files_sharding_shared_secret', '');
		if ($secret === '') {
			return false;
		}
		$auth = $this->request->getHeader('Authorization');
		return hash_equals('Bearer ' . $secret, $auth);
	}

	private function unauthorized(): JSONResponse {
		return new JSONResponse(['error' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function currentUsageAverage(): JSONResponse {
		if (!$this->checkSecret()) {
			return $this->unauthorized();
		}
		$userId = (string)$this->request->getParam('userid', '');
		$year   = (int)$this->request->getParam('year', date('Y'));
		$month  = (int)$this->request->getParam('month', date('n'));
		if ($userId === '') {
			return new JSONResponse(['error' => 'Missing userid'], Http::STATUS_BAD_REQUEST);
		}
		$avg = $this->storageService->localCurrentUsageAverage($userId, $year, $month);
		return new JSONResponse($avg);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function personalStorage(): JSONResponse {
		if (!$this->checkSecret()) {
			return $this->unauthorized();
		}
		$userId    = (string)$this->request->getParam('userid', '');
		$trashbin  = filter_var($this->request->getParam('trashbin', true), FILTER_VALIDATE_BOOLEAN);
		if ($userId === '') {
			return new JSONResponse(['error' => 'Missing userid'], Http::STATUS_BAD_REQUEST);
		}
		$usage     = $this->storageService->getLocalUsage($userId, $trashbin);
		$freeQuota = $this->storageService->getFreeQuota($userId);
		return new JSONResponse(array_merge($usage, ['freequota' => $freeQuota]));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function setFreeQuota(): JSONResponse {
		if (!$this->checkSecret()) {
			return $this->unauthorized();
		}
		$userId = (string)$this->request->getParam('userid', '');
		$quota  = (string)$this->request->getParam('quota', '');
		if ($userId === '') {
			return new JSONResponse(['error' => 'Missing userid'], Http::STATUS_BAD_REQUEST);
		}
		// Set locally only (master already updated its own record before calling)
		$this->config->setUserValue($userId, 'files_accounting', 'freequota', $quota);
		return new JSONResponse(['status' => 'ok']);
	}

	/** Master-side write of a group top-up forwarded by a silo (owner self-service). */
	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function setGroupTopup(): JSONResponse {
		if (!$this->checkSecret()) {
			return $this->unauthorized();
		}
		$gid   = (string)$this->request->getParam('gid', '');
		$bytes = (int)$this->request->getParam('bytes', 0);
		if ($gid === '') {
			return new JSONResponse(['error' => 'Missing gid'], Http::STATUS_BAD_REQUEST);
		}
		$this->storageService->applyGroupTopupLocal($gid, $bytes);
		return new JSONResponse(['status' => 'ok']);
	}

	/** Master-side read of a group top-up (silo reads the authoritative value). */
	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function getGroupTopup(): JSONResponse {
		if (!$this->checkSecret()) {
			return $this->unauthorized();
		}
		$gid = (string)$this->request->getParam('gid', '');
		if ($gid === '') {
			return new JSONResponse(['error' => 'Missing gid'], Http::STATUS_BAD_REQUEST);
		}
		return new JSONResponse(['bytes' => $this->storageService->readGroupTopupLocal($gid)]);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function getPrepaid(): JSONResponse {
		if (!$this->checkSecret()) {
			return $this->unauthorized();
		}
		$userId = (string)$this->request->getParam('userid', '');
		if ($userId === '') {
			return new JSONResponse(['error' => 'Missing userid'], Http::STATUS_BAD_REQUEST);
		}
		return new JSONResponse(['prepaid' => $this->storageService->getPrePaid($userId)]);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function setPrepaid(): JSONResponse {
		if (!$this->checkSecret()) {
			return $this->unauthorized();
		}
		$userId = (string)$this->request->getParam('userid', '');
		$amount = (float)$this->request->getParam('amount', 0);
		if ($userId === '') {
			return new JSONResponse(['error' => 'Missing userid'], Http::STATUS_BAD_REQUEST);
		}
		$this->storageService->setPrePaid($userId, $amount);
		return new JSONResponse(['status' => 'ok']);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function expireGifts(): JSONResponse {
		if (!$this->checkSecret()) {
			return $this->unauthorized();
		}
		$userId = (string)$this->request->getParam('userid', '');
		if ($userId === '') {
			return new JSONResponse(['error' => 'Missing userid'], Http::STATUS_BAD_REQUEST);
		}
		$this->storageService->expireGifts($userId);
		return new JSONResponse(['status' => 'ok']);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function redeemGift(): JSONResponse {
		if (!$this->checkSecret()) {
			return $this->unauthorized();
		}
		$code   = (string)$this->request->getParam('code', '');
		$userId = (string)$this->request->getParam('userid', '');
		if ($code === '' || $userId === '') {
			return new JSONResponse(['error' => 'Missing code or userid'], Http::STATUS_BAD_REQUEST);
		}
		$result = $this->storageService->redeemGift($code, $userId);
		return new JSONResponse($result);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function createGift(): JSONResponse {
		if (!$this->checkSecret()) {
			return $this->unauthorized();
		}
		$amount       = (float)$this->request->getParam('amount', 0);
		$size         = (string)$this->request->getParam('size', '');
		$days         = (int)$this->request->getParam('days', 0);
		$site         = (string)$this->request->getParam('site', '');
		$claimExpires = (int)$this->request->getParam('claim_expires_days', 0);
		$code = $this->storageService->createGift($amount, $size, $days, $site, $claimExpires);
		return new JSONResponse(['code' => $code]);
	}
}
