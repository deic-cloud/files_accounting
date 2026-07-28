<?php

declare(strict_types=1);

namespace OCA\FilesAccounting\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Per-group home-directory quota top-up (BILLING.md Option B): the extra free
 * quota a university (as owner of its domain group) buys on its users' STANDARD
 * home directories. Kept in files_accounting — separate from user_group_admin's
 * uga_groups.storage_grant (the grant-FOLDER size, Option A) — so a university can
 * offer either or both. Keyed by group id; ownership/membership are read from the
 * uga tables at billing time.
 */
class Version003Date20260728000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('files_accounting_topup')) {
			$t = $schema->createTable('files_accounting_topup');
			$t->addColumn('gid',         Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('topup_bytes', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('updated_at',  Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['gid']);
		}

		return $schema;
	}
}
