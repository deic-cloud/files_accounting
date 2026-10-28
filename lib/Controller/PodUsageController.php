<?php

declare(strict_types=1);

namespace OCA\FilesAccounting\Controller;

use OCA\FilesAccounting\Service\StorageService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Pod usage reports from the container service, under the old service's URLs:
 *
 *   POST apps/files_accounting/report_pod_usage.php — the container service's
 *        pod_usage.sh (hourly, and on pod deletion) reports a pod's running time
 *        in the current billing period to the owner's home server. Appended as one
 *        line to <user>/files_accounting/pods/podsusage_<year>_<month>.txt, which
 *        the monthly billing run reads (Stats::getPodsMonthlyUse).
 *   GET  apps/files_accounting/billingday.php — the billing day of month, so the
 *        reporter counts the same period as the billing run.
 *
 * Reports are trusted by source address (system config 'trustednet', e.g. "10.0."),
 * as on the old service.
 */
class PodUsageController extends Controller {

	/** Line format, as on the old service. */
	private const FIELDS = ['user', 'node_name', 'node_ip', 'image_name', 'pod_name', 'pod_ip',
		'start_time', 'end_time', 'running_seconds', 'cycle_day'];

	public function __construct(
		string                   $appName,
		IRequest                 $request,
		private StorageService   $storageService,
		private IConfig          $config,
		private IUserManager     $userManager,
		private LoggerInterface  $logger,
	) {
		parent::__construct($appName, $request);
	}

	private function onTrustedNet(): bool {
		$ip = $this->request->getRemoteAddress();
		foreach (preg_split('/\s+/', trim((string)$this->config->getSystemValue('trustednet', ''))) ?: [] as $net) {
			if ($net !== '' && !str_starts_with($net, 'TRUSTED_') && str_starts_with($ip, $net)) {
				return true;
			}
		}
		return false;
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function billingDay(): DataDisplayResponse {
		return new DataDisplayResponse((string)$this->storageService->getBillingDayOfMonth(),
			Http::STATUS_OK, ['Content-Type' => 'text/plain']);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function report(): JSONResponse {
		if (!$this->onTrustedNet()) {
			return new JSONResponse(['status' => 'error', 'message' => 'Network not trusted'], Http::STATUS_UNAUTHORIZED);
		}
		$values = [];
		foreach (self::FIELDS as $field) {
			$value = (string)$this->request->getParam($field, '');
			// The fields are written |-separated, one report per line
			if (preg_match('/[|\r\n]/', $value)) {
				return new JSONResponse(['status' => 'error', 'message' => "Invalid $field"], Http::STATUS_BAD_REQUEST);
			}
			$values[$field] = $value;
		}
		$year  = (int)$this->request->getParam('year', 0);
		$month = (int)$this->request->getParam('month', 0);
		$day   = (int)$this->request->getParam('day', 0);
		if ($year < 2000 || $month < 1 || $month > 12 || !ctype_digit($values['running_seconds'])) {
			return new JSONResponse(['status' => 'error', 'message' => 'Invalid year, month or running_seconds'], Http::STATUS_BAD_REQUEST);
		}
		// The reporter sends to the pod's HOME_SERVER, where the billing run reads the file.
		// Strict uid match - get() also finds users by e-mail.
		$uid = $values['user'];
		$user = $uid === '' ? null : $this->userManager->get($uid);
		if ($user === null || $user->getUID() !== $uid) {
			return new JSONResponse(['status' => 'error', 'message' => 'No such user'], Http::STATUS_NOT_FOUND);
		}

		$path = $this->storageService->getPodsUsageFilePath($uid, $year, $month);
		$dir = dirname($path);
		if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
			$this->logger->error('Cannot create ' . $dir, ['app' => 'files_accounting']);
			return new JSONResponse(['status' => 'error', 'message' => 'Cannot write'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		// USER|NODE_NAME|NODE_IP|IMAGE_NAME|POD_NAME|POD_IP|START_TIME|END_TIME|RUNNING_SECONDS|CYCLE_DAY|TIMESTAMP|DAY
		$line = implode('|', array_values($values)) . '|' . time() . '|' . $day . "\n";
		if (file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === false) {
			$this->logger->error('Cannot write ' . $path, ['app' => 'files_accounting']);
			return new JSONResponse(['status' => 'error', 'message' => 'Cannot write'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		return new JSONResponse(['status' => 'success', 'message' => 'Wrote to file ' . basename($path)]);
	}
}
