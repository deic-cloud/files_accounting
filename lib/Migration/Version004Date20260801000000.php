<?php

declare(strict_types=1);

namespace OCA\FilesAccounting\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Record when a bill was marked paid, so the admin Billing page can show a
 * payment history (not just the outstanding worklist). 0 = never paid.
 */
class Version004Date20260801000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('files_accounting')) {
			$table = $schema->getTable('files_accounting');
			if (!$table->hasColumn('time_paid')) {
				$table->addColumn('time_paid', Types::BIGINT, ['notnull' => false, 'default' => 0]);
			}
		}

		return $schema;
	}
}
