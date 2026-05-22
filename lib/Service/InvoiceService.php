<?php

declare(strict_types=1);

namespace OCA\FilesAccounting\Service;

use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

class InvoiceService {

	public function __construct(
		private StorageService  $storage,
		private IConfig         $config,
		private IUserManager    $userManager,
		private IMailer         $mailer,
		private IURLGenerator   $urlGenerator,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Generate a PDF invoice and save it to the user's invoice directory.
	 * Returns the filename on success, null on failure.
	 *
	 * @param array $articles  Each entry: ['item' => string, 'price' => float]
	 */
	public function generateInvoice(
		string $userId,
		string $referenceId,
		array  $articles,
		float  $totalAmountDue,
		string $issueDate,
		string $dueDate,
		bool   $dryRun = false,
	): ?string {
		$user     = $this->userManager->get($userId);
		$realName = $user ? $user->getDisplayName() : $userId;
		$email    = $user ? $user->getEMailAddress() : '';

		$fromEmail   = $this->storage->getIssuerEmail();
		$fromAddress = $this->storage->getIssuerAddress();
		$currency    = $this->storage->getBillingCurrency();
		$vat         = $this->storage->getBillingVat();

		$filename = ($dryRun ? 'test-' : '') . $referenceId . '.pdf';
		$dir      = $this->storage->getInvoiceDir($userId);
		if (!is_dir($dir)) {
			mkdir($dir, 0750, true);
		}

		require_once __DIR__ . '/../Vendor/fpdf.php';

		$pdf = new \FPDF();
		$pdf->AliasNbPages();
		$pdf->AddPage();

		// Logo
		$logoUrl = (string)$this->config->getSystemValue('billinglogo', '');
		if ($logoUrl !== '' && filter_var($logoUrl, FILTER_VALIDATE_URL)) {
			$ext = strtoupper(pathinfo(parse_url($logoUrl, PHP_URL_PATH), PATHINFO_EXTENSION));
			try {
				$pdf->Image($logoUrl, 10, 10, 30, 0, $ext);
			} catch (\Throwable) {
			}
		}

		// Issuer address block (top right)
		$pdf->SetXY(130, 10);
		$pdf->SetFont('Arial', '', 11);
		$pdf->SetTextColor(32);
		$formattedAddress = str_replace(', ', "\n", $fromAddress);
		$pdf->MultiCell(70, 5, $formattedAddress, 0, 'L');
		$pdf->SetX(130);
		$pdf->Cell(70, 5, $fromEmail, 0, 1, 'L');

		$pdf->Ln(12);
		$pdf->SetFillColor(230, 230, 230);
		$this->tableRow($pdf, 'Email:', $email);
		$this->tableRow($pdf, 'Name:', $realName);
		$this->tableRow($pdf, 'Invoice Number:', $referenceId);
		$this->tableRow($pdf, 'Invoice Date:', $issueDate);
		$this->tableRow($pdf, 'Due Date:', $dueDate);

		$pdf->Ln(10);
		$pdf->SetFillColor(211, 211, 211);
		$pdf->SetDrawColor(192, 192, 192);
		$pdf->SetFont('Arial', 'B', 11);
		$pdf->Cell(150, 7, 'Item', 1, 0, 'L', true);
		$pdf->Cell(40, 7, 'Price', 1, 1, 'L', true);
		$pdf->SetFont('Arial', '', 11);
		foreach ($articles as $article) {
			$pdf->Cell(150, 7, (string)$article['item'], 1, 0, 'L');
			$pdf->Cell(40, 7, number_format((float)$article['price'], 2) . ' ' . $currency, 1, 1, 'R');
		}
		$pdf->Ln(2);
		$pdf->Cell(150, 7, 'VAT included', 1, 0, 'R');
		$pdf->Cell(40, 7, $vat . '%', 1, 1, 'R');
		$pdf->SetFont('Arial', 'B', 11);
		$pdf->Cell(150, 7, 'Total', 1, 0, 'R', true);
		$pdf->Cell(40, 7, number_format($totalAmountDue, 2) . ' ' . $currency, 1, 1, 'R', true);

		$pdf->Ln(15);
		$pdf->SetFont('Arial', '', 10);
		$pdf->MultiCell(0, 5, 'Thank you for using our services.', 0, 'C');

		$outPath = $dir . '/' . $filename;
		$pdf->Output($outPath, 'F');

		if (!file_exists($outPath)) {
			$this->logger->error("files_accounting: PDF not written for $userId/$referenceId");
			return null;
		}
		return $filename;
	}

	private function tableRow(\FPDF $pdf, string $label, string $value): void {
		$pdf->SetFont('Arial', 'B', 10);
		$pdf->Cell(50, 6, $label, 0, 0, 'L');
		$pdf->SetFont('Arial', '', 10);
		$pdf->Cell(0, 6, $value, 0, 1, 'L');
	}

	public function getInvoicePath(string $userId, string $filename): ?string {
		$path = $this->storage->getInvoiceDir($userId) . '/' . $filename;
		return file_exists($path) ? $path : null;
	}

	public function sendInvoiceEmail(string $userId, string $filename, float $amount): void {
		$user  = $this->userManager->get($userId);
		if ($user === null) {
			return;
		}
		$email = $user->getEMailAddress();
		if (empty($email)) {
			return;
		}

		$path = $this->getInvoicePath($userId, $filename);
		if ($path === null) {
			return;
		}

		$currency    = $this->storage->getBillingCurrency();
		$fromEmail   = $this->storage->getIssuerEmail();
		$fromAddress = $this->storage->getIssuerAddress();

		try {
			$message = $this->mailer->createMessage();
			$message->setTo([$email => $user->getDisplayName()]);
			if ($fromEmail !== '') {
				$message->setFrom([$fromEmail => $fromAddress ?: 'ScienceData']);
			}
			$message->setSubject('Invoice ' . pathinfo($filename, PATHINFO_FILENAME));
			$message->setPlainBody(
				"Dear " . $user->getDisplayName() . ",\n\n" .
				"Your invoice for " . number_format($amount, 2) . " $currency is attached.\n\n" .
				"Thank you for using our services."
			);
			$attachment = $this->mailer->createAttachment(
				file_get_contents($path), $filename, 'application/pdf'
			);
			$message->attach($attachment);
			$this->mailer->send($message);
		} catch (\Throwable $e) {
			$this->logger->error("files_accounting: failed to send invoice email to $email: " . $e->getMessage());
		}
	}
}
