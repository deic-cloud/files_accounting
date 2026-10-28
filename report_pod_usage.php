<?php

declare(strict_types=1);

/**
 * POST apps/files_accounting/report_pod_usage.php — pod usage reports from the
 * container service's pod_usage.sh, at the old service's URL (see
 * PodUsageController::report).
 *
 * Why a file and not only the route of the same name: a POST to a .php path that
 * does not exist never reaches Nextcloud on Apache (only GETs do, through the
 * ErrorDocument 404 handler), so Apache answers 405. As on the old service, the
 * URL is a real script; it boots Nextcloud and hands over to the controller.
 */

if (!defined('OC_VERSION')) {
	require_once __DIR__ . '/../../lib/base.php';
	if (\OCP\Util::needUpgrade()) {
		http_response_code(503);
		exit('Service unavailable (upgrade in progress)');
	}
	// The user backends (e.g. user_saml) come with the authentication apps
	$appManager = \OCP\Server::get(\OCP\App\IAppManager::class);
	$appManager->loadApps(['authentication']);
	$appManager->loadApps(['extended_authentication']);
	$appManager->loadApp('files_accounting');
}

$controller = (new \OCA\FilesAccounting\AppInfo\Application())->getContainer()
	->get(\OCA\FilesAccounting\Controller\PodUsageController::class);
$response = $controller->report();
http_response_code($response->getStatus());
header('Content-Type: application/json; charset=utf-8');
echo json_encode($response->getData());
