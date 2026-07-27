<?php

declare(strict_types=1);

namespace OCA\FilesAccounting\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Read-only aggregate statistics for the admin (boss-facing) dashboard:
 *   - monthly usage/billing summary across all users (charts a, b, c)
 *   - top users by current storage (chart d)
 *   - collaboration metrics from the core share/authtoken tables
 *
 * All numbers are aggregates; no per-user data leaves this service except the
 * top-N list (admin-only endpoint). Storage figures are in GB (the billing table
 * stores usage as GB floats).
 */
class StatisticsService {
	public function __construct(
		private IDBConnection   $db,
		private IUserManager    $userManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Per-month totals across all users. One files_accounting row per user per month
	 * (unique index), so COUNT(*) is the accounted-user count for that month.
	 *
	 * @return array<int, array{year:int, month:int, users:int, home_gb:float, trash_gb:float, backup_gb:float, billed:float}>
	 */
	public function usageSummaryByMonth(?int $year = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('year', 'month')
			->selectAlias($qb->func()->count('*'), 'users')
			->selectAlias($qb->func()->sum('home_files_usage'), 'home_gb')
			->selectAlias($qb->func()->sum('home_trash_usage'), 'trash_gb')
			->selectAlias($qb->func()->sum('backup_files_usage'), 'backup_gb')
			->selectAlias($qb->func()->sum('amount_due'), 'billed')
			->from('files_accounting')
			->groupBy('year', 'month')
			->orderBy('year', 'ASC')->addOrderBy('month', 'ASC');
		if ($year !== null) {
			$qb->where($qb->expr()->eq('year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)));
		}
		$cursor = $qb->executeQuery();
		$rows = $cursor->fetchAll();
		$cursor->closeCursor();

		return array_map(static fn (array $r): array => [
			'year'      => (int)$r['year'],
			'month'     => (int)$r['month'],
			'users'     => (int)$r['users'],
			'home_gb'   => round((float)$r['home_gb'], 2),
			'trash_gb'  => round((float)$r['trash_gb'], 2),
			'backup_gb' => round((float)$r['backup_gb'], 2),
			'billed'    => round((float)$r['billed'], 2),
		], $rows);
	}

	/**
	 * Top users by storage in the most recently accounted month.
	 *
	 * @return array<int, array{user:string, usage_gb:float}>
	 */
	public function topUsersByUsage(int $limit = 10): array {
		$latest = $this->latestPeriod();
		if ($latest === null) {
			return [];
		}
		[$year, $month] = $latest;

		$qb = $this->db->getQueryBuilder();
		$qb->select('user')
			->selectAlias($qb->createFunction(
				$qb->getColumnName('home_files_usage') . ' + ' . $qb->getColumnName('home_trash_usage')
			), 'usage_gb')
			->from('files_accounting')
			->where($qb->expr()->eq('year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('month', $qb->createNamedParameter($month, IQueryBuilder::PARAM_INT)))
			->orderBy('usage_gb', 'DESC')
			->setMaxResults($limit);
		$cursor = $qb->executeQuery();
		$rows = $cursor->fetchAll();
		$cursor->closeCursor();

		return array_map(static fn (array $r): array => [
			'user'     => (string)$r['user'],
			'usage_gb' => round((float)$r['usage_gb'], 2),
		], $rows);
	}

	/**
	 * Collaboration/adoption metrics from core tables. Best-effort: any query that
	 * fails (e.g. an unusual DB) yields -1 for that metric rather than breaking the
	 * whole dashboard.
	 *
	 * @return array<string, int>
	 */
	public function collaborationMetrics(): array {
		return [
			// total configured users — the denominator for "X of N"
			'total_users'    => $this->safe(fn () => $this->userManager->countUsersTotal() ?: 0),
			// users who share anything with anyone
			'sharers'        => $this->safe(fn () => $this->distinctCount('share', 'uid_owner')),
			// users who share via a public link / email link (share_type 3, 4)
			'public_sharers' => $this->safe(fn () => $this->distinctCount('share', 'uid_owner', 'share_type', [3, 4])),
			// distinct recipients of direct user shares (share_type 0)
			'recipients'     => $this->safe(fn () => $this->distinctCount('share', 'share_with', 'share_type', [0])),
			// total shares of every kind
			'total_shares'   => $this->safe(fn () => $this->rowCount('share')),
			// users with a connected client / app-password (permanent auth token, type 1)
			'client_users'   => $this->safe(fn () => $this->distinctCount('authtoken', 'uid', 'type', [1])),
		];
	}

	// -------------------------------------------------------------------------

	/** @return array{0:int,1:int}|null [year, month] of the most recent bill row */
	private function latestPeriod(): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('year', 'month')->from('files_accounting')
			->orderBy('year', 'DESC')->addOrderBy('month', 'DESC')
			->setMaxResults(1);
		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();
		return $row ? [(int)$row['year'], (int)$row['month']] : null;
	}

	/** COUNT(DISTINCT $col) on $table, optionally filtered by $filterCol IN $vals. */
	private function distinctCount(string $table, string $col, ?string $filterCol = null, array $vals = []): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(DISTINCT ' . $qb->getColumnName($col) . ')'))
			->from($table);
		if ($filterCol !== null && !empty($vals)) {
			$qb->where($qb->expr()->in($filterCol, $qb->createNamedParameter($vals, IQueryBuilder::PARAM_INT_ARRAY)));
		}
		$cursor = $qb->executeQuery();
		$n = (int)$cursor->fetchOne();
		$cursor->closeCursor();
		return $n;
	}

	private function rowCount(string $table): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($table);
		$cursor = $qb->executeQuery();
		$n = (int)$cursor->fetchOne();
		$cursor->closeCursor();
		return $n;
	}

	/** @param callable():int $fn */
	private function safe(callable $fn): int {
		try {
			return $fn();
		} catch (\Throwable $e) {
			$this->logger->warning('files_accounting: statistics query failed: ' . $e->getMessage());
			return -1;
		}
	}
}
