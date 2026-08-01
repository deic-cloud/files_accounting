<?php

declare(strict_types=1);

namespace OCA\FilesAccounting\Controller;

use OCA\FilesAccounting\Service\InvoiceService;
use OCA\FilesAccounting\Service\NotificationService;
use OCA\FilesAccounting\Service\StatisticsService;
use OCA\FilesAccounting\Service\StorageService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

class ApiController extends OCSController {

	public function __construct(
		string                  $appName,
		IRequest                $request,
		private StorageService     $storageService,
		private InvoiceService     $invoiceService,
		private StatisticsService  $statisticsService,
		private NotificationService $notificationService,
		private IUserSession       $userSession,
		private IGroupManager      $groupManager,
		private IConfig            $config,
	) {
		parent::__construct($appName, $request);
	}

	// -------------------------------------------------------------------------
	// Authorization helpers
	// -------------------------------------------------------------------------

	private function isAdmin(): bool {
		$user = $this->userSession->getUser();
		return $user !== null && $this->groupManager->isAdmin($user->getUID());
	}

	private function currentUserId(): string {
		return $this->userSession->getUser()?->getUID() ?? '';
	}

	/** Admin or bearer secret may query any user. */
	private function resolveUserId(?string $requested): ?string {
		$self = $this->currentUserId();
		if ($requested === null || $requested === '') {
			return $self ?: null;
		}
		if ($requested === $self) {
			return $self;
		}
		// Only admin may query other users
		if ($this->isAdmin()) {
			return $requested;
		}
		return null; // forbidden
	}

	// -------------------------------------------------------------------------
	// Admin API — bills & invoices
	// -------------------------------------------------------------------------

	public function getBills(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}
		$userId = $this->request->getParam('user');
		$year   = $this->request->getParam('year') !== null ? (int)$this->request->getParam('year') : null;
		$status = $this->request->getParam('status');
		$bills  = $this->storageService->getBills($userId, $year, $status);
		return new DataResponse($bills);
	}

	/**
	 * Aggregate statistics for the admin dashboard: monthly usage/billing summary,
	 * top users by storage, and collaboration metrics. Admin only.
	 */
	public function getStatistics(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}
		$year = $this->request->getParam('year') !== null ? (int)$this->request->getParam('year') : null;
		return new DataResponse([
			'summary'       => $this->statisticsService->usageSummaryByMonth($year),
			'topUsers'      => $this->statisticsService->topUsersByUsage(10),
			'collaboration' => $this->statisticsService->collaborationMetrics(),
		]);
	}

	/**
	 * Mark bills paid (single or bulk) and clear each user's pending notification.
	 * Body: {ids: [int,...]} (or a single id). Admin only. Stamps a payment
	 * reference (default "manual") so the origin is recorded.
	 */
	public function markBillsPaid(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}
		$ids = $this->request->getParam('ids');
		if (!is_array($ids)) {
			$ids = ($ids !== null && $ids !== '') ? [$ids] : [];
		}
		// Optional bank/payment reference, stored in payment_ref (kept separate from
		// reference_id, the invoice number). Accept legacy 'reference' as a fallback.
		$paymentRef = (string)$this->request->getParam('payment_ref',
			(string)$this->request->getParam('reference', ''));
		$rows = $this->storageService->markBillsPaid($ids, $paymentRef);
		foreach ($rows as $r) {
			$this->notificationService->dismissBillNotification($r['user'], $r['year'], $r['month']);
		}
		return new DataResponse(['status' => 'ok', 'paid' => count($rows)]);
	}

	/** Edit a single bill's bank/payment reference (fix a typo). */
	public function setBillPaymentRef(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}
		$id = (int)$this->request->getParam('id', 0);
		if ($id <= 0) {
			return new DataResponse(['error' => 'id required'], Http::STATUS_BAD_REQUEST);
		}
		$ref = (string)$this->request->getParam('payment_ref', '');
		$this->storageService->setBillPaymentRef($id, $ref);
		return new DataResponse(['status' => 'ok', 'id' => $id, 'payment_ref' => $ref]);
	}

	/** Get a group's home-directory quota top-up (BILLING.md Option B). Admin only. */
	public function getGroupTopup(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}
		$gid = (string)$this->request->getParam('gid', '');
		if ($gid === '') {
			return new DataResponse(['error' => 'gid required'], Http::STATUS_BAD_REQUEST);
		}
		$bytes = $this->storageService->getGroupTopup($gid);
		return new DataResponse([
			'gid'   => $gid,
			'bytes' => $bytes,
			'human' => $bytes > 0 ? \OCP\Util::humanFileSize($bytes) : '0',
		]);
	}

	/** Set a group's home-directory quota top-up. Body: {gid, quota}. Admin only. */
	public function setGroupTopup(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}
		$gid   = (string)$this->request->getParam('gid', '');
		$quota = (string)$this->request->getParam('quota', '');
		if ($gid === '') {
			return new DataResponse(['error' => 'gid required'], Http::STATUS_BAD_REQUEST);
		}
		$bytes = $this->storageService->parseQuotaToBytes($quota);
		$this->storageService->setGroupTopup($gid, $bytes);
		return new DataResponse([
			'status' => 'ok',
			'gid'    => $gid,
			'bytes'  => $bytes,
			'owner'  => $this->storageService->getGroupOwner($gid),
		]);
	}

	public function getUsage(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}
		$userId = (string)$this->request->getParam('user', '');
		$year   = (int)$this->request->getParam('year', date('Y'));
		$month  = $this->request->getParam('month') !== null ? (int)$this->request->getParam('month') : null;
		if ($userId === '') {
			return new DataResponse(['error' => 'user required'], Http::STATUS_BAD_REQUEST);
		}
		$data = $this->storageService->localUsageData($userId, $year, $month);
		return new DataResponse($data);
	}

	public function getInvoice(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}
		$userId   = (string)$this->request->getParam('user', '');
		$filename = (string)$this->request->getParam('filename', '');
		if ($userId === '' || $filename === '') {
			return new DataResponse(['error' => 'user and filename required'], Http::STATUS_BAD_REQUEST);
		}
		$path = $this->invoiceService->getInvoicePath($userId, basename($filename));
		if ($path === null) {
			return new DataResponse(['error' => 'Invoice not found'], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse(['data' => base64_encode(file_get_contents($path)), 'filename' => basename($path)]);
	}

	public function setFreeQuota(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}
		$userId  = (string)$this->request->getParam('user', '');
		$quota   = (string)$this->request->getParam('quota', '');
		$default = filter_var($this->request->getParam('default', false), FILTER_VALIDATE_BOOLEAN);

		if ($default || $userId === '') {
			$this->storageService->setDefaultFreeQuota($quota);
			return new DataResponse(['status' => 'ok']);
		}
		$this->storageService->setFreeQuota($userId, $quota);
		return new DataResponse(['status' => 'ok']);
	}

	public function getFreeQuota(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}
		$userId = (string)$this->request->getParam('user', '');
		if ($userId === '') {
			return new DataResponse(['error' => 'user required'], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse([
			'user'       => $userId,
			'freequota'  => $this->storageService->getFreeQuota($userId),
			'default'    => $this->storageService->getDefaultFreeQuota(),
		]);
	}

	// -------------------------------------------------------------------------
	// Admin API — gifts
	// -------------------------------------------------------------------------

	public function listGifts(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}
		return new DataResponse($this->storageService->getGifts());
	}

	public function createGift(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}
		$amount       = (float)$this->request->getParam('amount', 0);
		$size         = (string)$this->request->getParam('size', '');
		$days         = (int)$this->request->getParam('days', 0);
		$site         = (string)$this->request->getParam('site', '');
		$claimExpires = (int)$this->request->getParam('claim_expires_days', 0);
		// Always store on master so any silo can redeem
		if (!$this->storageService->isMaster()) {
			$code = $this->storageService->createGiftViaMaster($amount, $size, $days, $site, $claimExpires);
		} else {
			$code = $this->storageService->createGift($amount, $size, $days, $site, $claimExpires);
		}
		return new DataResponse(['code' => $code]);
	}

	public function deleteGift(string $code): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}
		$ok = $this->storageService->deleteGift($code);
		return new DataResponse(['status' => $ok ? 'ok' : 'not_found']);
	}

	public function redeemGift(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}
		$code   = (string)$this->request->getParam('code', '');
		$userId = (string)$this->request->getParam('user', '');
		if ($code === '' || $userId === '') {
			return new DataResponse(['error' => 'code and user required'], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse($this->storageService->redeemGift($code, $userId));
	}

	// -------------------------------------------------------------------------
	// Personal (logged-in user) API
	// -------------------------------------------------------------------------

	#[NoAdminRequired]
	public function myBills(): DataResponse {
		$uid = $this->currentUserId();
		if ($uid === '') {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}
		$year   = $this->request->getParam('year') !== null ? (int)$this->request->getParam('year') : null;
		$status = $this->request->getParam('status');
		return new DataResponse($this->storageService->getBills($uid, $year, $status));
	}

	#[NoAdminRequired]
	public function myUsage(): DataResponse {
		$uid = $this->currentUserId();
		if ($uid === '') {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}
		$year  = (int)$this->request->getParam('year', date('Y'));
		$month = $this->request->getParam('month') !== null ? (int)$this->request->getParam('month') : null;
		return new DataResponse($this->storageService->localUsageData($uid, $year, $month));
	}

	#[NoAdminRequired]
	public function myInvoice(): DataResponse {
		$uid      = $this->currentUserId();
		$filename = (string)$this->request->getParam('filename', '');
		if ($uid === '' || $filename === '') {
			return new DataResponse(['error' => 'filename required'], Http::STATUS_BAD_REQUEST);
		}
		$path = $this->invoiceService->getInvoicePath($uid, basename($filename));
		if ($path === null) {
			return new DataResponse(['error' => 'Invoice not found'], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse(['data' => base64_encode(file_get_contents($path)), 'filename' => basename($path)]);
	}

	#[NoAdminRequired]
	public function myGifts(): DataResponse {
		$uid = $this->currentUserId();
		if ($uid === '') {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}
		return new DataResponse($this->storageService->getGifts(null, $uid));
	}

	#[NoAdminRequired]
	public function myRedeemGift(): DataResponse {
		$uid  = $this->currentUserId();
		$code = (string)$this->request->getParam('code', '');
		if ($uid === '' || $code === '') {
			return new DataResponse(['error' => 'code required'], Http::STATUS_BAD_REQUEST);
		}
		$result = $this->storageService->redeemGift($code, $uid);
		// If not found locally and we're not master, try master (canonical gift store)
		if (!($result['success'] ?? false) && !$this->storageService->isMaster()) {
			$result = $this->storageService->redeemGiftViaMaster($code, $uid);
		}
		return new DataResponse($result);
	}
}
