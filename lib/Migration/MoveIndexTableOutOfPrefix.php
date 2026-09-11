<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Alain Lesage
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\FullTextSearch_PostgreSQL\Migration;

use OCA\FullTextSearch_PostgreSQL\Db\FtsRequest;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Moves the index table out of the Nextcloud table prefix, and reconciles its index names.
 *
 * Up to 0.1.1 the table was created as `<prefix>fulltextsearch_pg`, which put it inside the
 * range `OC\DB\Migrator::createSchema()` hands to Doctrine, which has no mapping for `text[]`.
 * `occ upgrade` then died on “Unknown database type _text” at its first core migration, taking
 * every Nextcloud update with it, security ones included.
 *
 * Renaming keeps the generated column, the indexes and the rows, so nobody has to re-index.
 * A failure here is not worth blocking an update over: the table is a rebuildable cache, and
 * the next indexing run creates the new one from scratch.
 */
class MoveIndexTableOutOfPrefix implements IRepairStep {
	/**
	 * How long to wait for the exclusive lock the rename needs.
	 *
	 * Without it, an open transaction on the table suspends `occ upgrade` for as long as it
	 * lasts — measured at 45 seconds for a 45-second transaction — with the site sitting in
	 * maintenance mode, and the pending lock request blocking every new reader behind it.
	 * Giving up is cheap: the step runs again on the next update.
	 */
	private const LOCK_TIMEOUT = '5s';

	/** The suffixes initSchema() gives its indexes, plus the primary key. */
	private const SUFFIXES = [
		'_tsv_idx', '_users_idx', '_groups_idx', '_circles_idx', '_owner_idx',
		'_metatags_idx', '_subtags_idx', '_title_trgm_idx', '_share_trgm_idx', '_pkey',
	];

	public function __construct(
		private IDBConnection $db,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	public function getName(): string {
		return 'Move the full-text index table out of the Nextcloud table prefix';
	}

	public function run(IOutput $output): void {
		$prefixe = $this->config->getSystemValueString('dbtableprefix', 'oc_');
		$ancienne = $prefixe . 'fulltextsearch_pg';
		$nouvelle = FtsRequest::tableFor($prefixe);

		try {
			$ancienneLa = $this->tableExists($ancienne);
			$nouvelleLa = $this->tableExists($nouvelle);

			if (!$ancienneLa && !$nouvelleLa) {
				return;
			}

			if ($ancienneLa && $nouvelleLa) {
				// Renaming over the live table would lose it, so we stop — but the old one is
				// still inside the prefix, so schema migrations keep breaking on it. Said out
				// loud: silence here leaves an administrator blocked with nothing to go on.
				$this->logger->warning(
					'fulltextsearch_postgresql: ' . $ancienne . ' is still around next to '
					. $nouvelle . ', and Nextcloud schema migrations will keep failing on it. '
					. 'Drop it by hand — ' . $nouvelle . ' is the live index.'
				);
				$output->warning(
					$ancienne . ' left in place beside the live index ' . $nouvelle
					. '; drop ' . $ancienne . ' by hand or schema migrations will keep failing.'
				);

				return;
			}

			if ($ancienneLa) {
				$this->rename('TABLE', $ancienne, $nouvelle);
			}

			// Reached whether or not this run did the renaming, because the table can arrive
			// here already renamed and still carrying the old index names: an administrator
			// who ran the ALTER TABLE by hand, or a rename interrupted between the table and
			// its indexes. Left alone, the next indexing run builds a second set beside them —
			// two GIN indexes over the same tsvector, paid for on every write.
			$renommes = $this->renameIndexes($nouvelle);
		} catch (Throwable $e) {
			// Said out loud rather than rethrown: an update must not stop here, and losing the
			// table costs a re-index, not data.
			$this->logger->warning(
				'fulltextsearch_postgresql: could not finish moving the index table to '
				. $nouvelle . '. It is a cache: drop whichever copy is stale and re-index.',
				['exception' => $e]
			);
			$output->warning('Could not finish moving the index table: ' . $e->getMessage());

			return;
		}

		if ($ancienneLa) {
			$output->info(
				'Renamed ' . $ancienne . ' to ' . $nouvelle . ' and ' . $renommes
				. ' of its indexes.'
			);
		} elseif ($renommes > 0) {
			$output->info('Fixed ' . $renommes . ' stale index names on ' . $nouvelle . '.');
		}
	}

	/**
	 * Carries the indexes over to names derived from the table they sit on.
	 *
	 * Matching is done against the exact set of names initSchema() builds, never against a
	 * common prefix: with a Nextcloud prefix of `users_` the table is `fts_pg_users`, and the
	 * old `fts_pg_users_idx` does start with `fts_pg_users_` — a prefix test skips it, and the
	 * duplicate this method exists to prevent appears anyway.
	 */
	private function renameIndexes(string $table): int {
		$attendus = [];
		foreach (self::SUFFIXES as $suffixe) {
			$attendus[$table . $suffixe] = true;
		}

		$result = $this->db->executeQuery(
			'SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ?',
			[$table]
		);
		$noms = $result->fetchAll();
		$result->closeCursor();

		$presents = [];
		foreach ($noms as $ligne) {
			$presents[(string)$ligne['indexname']] = true;
		}

		$compte = 0;
		foreach ($noms as $ligne) {
			$vieux = (string)$ligne['indexname'];
			if (isset($attendus[$vieux])) {
				continue;
			}

			foreach (self::SUFFIXES as $suffixe) {
				if (!str_ends_with($vieux, $suffixe)) {
					continue;
				}

				// An instance that indexed between a hand-made ALTER TABLE and this update
				// already carries both names for the same column. Renaming onto the live one
				// raises “relation already exists” and strands the rest half done, so the
				// stale twin is dropped instead — same definition, one index kept.
				if (isset($presents[$table . $suffixe])) {
					$this->db->executeStatement('DROP INDEX IF EXISTS ' . $this->quote($vieux));
				} else {
					$this->rename('INDEX', $vieux, $table . $suffixe);
				}

				$compte++;
				break;
			}
		}

		return $compte;
	}

	private function rename(string $objet, string $de, string $vers): void {
		$this->db->executeStatement("SET lock_timeout = '" . self::LOCK_TIMEOUT . "'");

		try {
			$this->db->executeStatement(
				'ALTER ' . $objet . ' ' . $this->quote($de) . ' RENAME TO ' . $this->quote($vers)
			);
		} finally {
			$this->db->executeStatement('SET lock_timeout = 0');
		}
	}

	private function tableExists(string $nom): bool {
		$result = $this->db->executeQuery('SELECT to_regclass(?) IS NOT NULL AS present', [$nom]);
		$present = (bool)$result->fetchOne();
		$result->closeCursor();

		return $present;
	}

	/**
	 * Both names are derived from `dbtableprefix`, which an administrator controls — so they are
	 * filtered down to what a bare identifier may hold before reaching the statement.
	 */
	private function quote(string $identifiant): string {
		return '"' . preg_replace('/[^A-Za-z0-9_]/', '', $identifiant) . '"';
	}
}
