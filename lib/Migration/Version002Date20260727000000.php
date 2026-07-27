<?php

declare(strict_types=1);

namespace OCA\FilesAccounting\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds `admin_alerted` to files_accounting: a de-dup flag so the admin escalation
 * email (bills pending > N months) fires once per bill, not on every 6-hourly run.
 */
class Version002Date20260727000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('files_accounting')) {
			$t = $schema->getTable('files_accounting');
			if (!$t->hasColumn('admin_alerted')) {
				$t->addColumn('admin_alerted', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			}
		}

		return $schema;
	}
}
