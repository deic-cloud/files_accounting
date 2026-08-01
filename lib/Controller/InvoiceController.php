<?php

declare(strict_types=1);

namespace OCA\FilesAccounting\Controller;

use OCA\FilesAccounting\Service\InvoiceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\Response;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Serves invoice PDFs to an authenticated admin, so the admin Billing page can
 * link each bill straight to its invoice. A plain (non-OCS) route so the PDF is
 * returned as-is and opens in the browser.
 */
class InvoiceController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private InvoiceService $invoiceService,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
	) {
		parent::__construct($appName, $request);
	}

	#[NoCSRFRequired]
	public function view(): Response {
		$user = $this->userSession->getUser();
		if ($user === null || !$this->groupManager->isAdmin($user->getUID())) {
			return new Response(Http::STATUS_FORBIDDEN);
		}
		$userId = (string)$this->request->getParam('user', '');
		$file   = basename((string)$this->request->getParam('file', ''));
		if ($userId === '' || $file === '') {
			return new Response(Http::STATUS_BAD_REQUEST);
		}
		$path = $this->invoiceService->getInvoicePath($userId, $file);
		if ($path === null) {
			return new NotFoundResponse();
		}
		$response = new DataDownloadResponse(file_get_contents($path), $file, 'application/pdf');
		// Show it in the browser tab rather than forcing a download.
		$response->addHeader('Content-Disposition', 'inline; filename="' . $file . '"');
		return $response;
	}
}
