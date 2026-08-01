<?php

declare(strict_types=1);

namespace OCA\FilesAccounting\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * A dedicated bank/payment reference recorded at settlement time, kept SEPARATE
 * from reference_id (the invoice number, which is also the invoice PDF filename).
 * Lets the admin note "paid by bank transfer, ref X" without clobbering the
 * invoice link — useful for university reconciliation.
 */
class Version005Date20260801100000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('files_accounting')) {
			$table = $schema->getTable('files_accounting');
			if (!$table->hasColumn('payment_ref')) {
				$table->addColumn('payment_ref', Types::STRING, ['notnull' => false, 'default' => '', 'length' => 128]);
			}
		}

		return $schema;
	}
}
