<?php

declare(strict_types=1);

namespace OCA\FilesAccounting\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001Date20260522000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('files_accounting')) {
			$t = $schema->createTable('files_accounting');
			$t->addColumn('id',                  Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('user',                Types::STRING,  ['notnull' => true,  'length' => 255]);
			$t->addColumn('status',              Types::STRING,  ['notnull' => true,  'length' => 32, 'default' => 'pending']);
			$t->addColumn('year',                Types::INTEGER, ['notnull' => true]);
			$t->addColumn('month',               Types::INTEGER, ['notnull' => true]);
			$t->addColumn('timestamp',           Types::BIGINT,  ['notnull' => true,  'default' => 0]);
			$t->addColumn('time_due',            Types::BIGINT,  ['notnull' => false, 'default' => 0]);
			$t->addColumn('home_files_usage',    Types::FLOAT,   ['notnull' => false, 'default' => 0]);
			$t->addColumn('home_trash_usage',    Types::FLOAT,   ['notnull' => false, 'default' => 0]);
			$t->addColumn('backup_files_usage',  Types::FLOAT,   ['notnull' => false, 'default' => 0]);
			$t->addColumn('home_id',             Types::INTEGER, ['notnull' => false, 'default' => 0]);
			$t->addColumn('backup_id',           Types::INTEGER, ['notnull' => false, 'default' => 0]);
			$t->addColumn('home_url',            Types::STRING,  ['notnull' => false, 'length' => 512, 'default' => '']);
			$t->addColumn('backup_url',          Types::STRING,  ['notnull' => false, 'length' => 512, 'default' => '']);
			$t->addColumn('home_site',           Types::STRING,  ['notnull' => false, 'length' => 128, 'default' => '']);
			$t->addColumn('backup_site',         Types::STRING,  ['notnull' => false, 'length' => 128, 'default' => '']);
			$t->addColumn('amount_due',          Types::FLOAT,   ['notnull' => false, 'default' => 0]);
			$t->addColumn('reference_id',        Types::STRING,  ['notnull' => false, 'length' => 128, 'default' => '']);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['user', 'year', 'month'], 'fa_user_year_month');
			$t->addIndex(['user'],   'fa_user');
			$t->addIndex(['status'], 'fa_status');
		}

		if (!$schema->hasTable('files_accounting_gifts')) {
			$t = $schema->createTable('files_accounting_gifts');
			$t->addColumn('code',               Types::STRING,  ['notnull' => true, 'length' => 64]);
			$t->addColumn('amount',             Types::FLOAT,   ['notnull' => false, 'default' => 0]);
			$t->addColumn('size',               Types::STRING,  ['notnull' => false, 'length' => 64, 'default' => '']);
			$t->addColumn('site',               Types::STRING,  ['notnull' => false, 'length' => 128, 'default' => '']);
			$t->addColumn('status',             Types::STRING,  ['notnull' => true, 'length' => 32, 'default' => 'OPEN']);
			$t->addColumn('creation_time',      Types::BIGINT,  ['notnull' => true, 'default' => 0]);
			$t->addColumn('claim_expiration',   Types::BIGINT,  ['notnull' => false, 'default' => 0]);
			$t->addColumn('redemption_time',    Types::BIGINT,  ['notnull' => false, 'default' => 0]);
			$t->addColumn('days',               Types::INTEGER, ['notnull' => false, 'default' => 0]);
			$t->addColumn('user',               Types::STRING,  ['notnull' => false, 'length' => 255, 'default' => '']);
			$t->setPrimaryKey(['code']);
			$t->addIndex(['user'],   'fag_user');
			$t->addIndex(['status'], 'fag_status');
		}

		return $schema;
	}
}
