<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alain Lesage
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FullTextSearch_PostgreSQL\Db;

use OCA\FullTextSearch_PostgreSQL\Model\SearchFilters;
use OCP\DB\Exception as DBException;
use OCA\FullTextSearch_PostgreSQL\ConfigLexicon;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Every bit of SQL lives here.
 *
 * The schema uses PostgreSQL features that Nextcloud's Doctrine-based migrations cannot
 * express (a dedicated text search configuration, a generated `tsvector` column, GIN
 * indexes), so it is created as raw SQL from initSchema(), which the framework calls
 * through IFullTextSearchPlatform::initializeIndex().
 *
 * `*PREFIX*` is substituted by IDBConnection (see \OC\DB\Connection::finishQuery()).
 */
class FtsRequest {
	/**
	 * Deliberately outside the Nextcloud table prefix.
	 *
	 * `OC\DB\Migrator::createSchema()` filters introspection on `/^<dbtableprefix>/` and then
	 * hands every matching table to Doctrine. Doctrine maps `tsvector` and `jsonb`, but nothing
	 * to `text[]` — so a prefixed name stops `occ upgrade` at its first core migration with
	 * “Unknown database type _text”, and the server stays in maintenance mode. No Nextcloud
	 * migration manages this table; it has no business in that scan.
	 *
	 * The prefix still appears, at the end. Not for two Nextcloud instances sharing a schema —
	 * that cannot happen, as Nextcloud's own index names carry no prefix and the second install
	 * dies on `relation "ac_lazy_i" already exists` — but so that installations sharing a
	 * database through separate schemas, or moved between them, never meet over one index.
	 */
	public const TABLE_PREFIX = 'fts_pg';



	/**
	 * Per-document cap on indexed content.
	 *
	 * The hard limit is the `tsvector` itself, which cannot exceed 1 MiB once built. The ratio
	 * between text and tsvector depends entirely on the text: measured at 0.40 on real prose —
	 * so 2.6 MiB of text would fit — but at 1.23 on high-entropy content, which saturates as
	 * early as 850 KiB.
	 *
	 * Any fixed cap therefore has to sacrifice one or the other. This one is deliberately
	 * generous: when a document overflows anyway, the insert shrinks it and retries (see
	 * PostgresPlatform::indexDocument) rather than losing or rejecting it.
	 */
	public const MAX_CONTENT_SIZE = 4194304;

	/**
	 * Bumped whenever the generated column changes. A generated column cannot be altered in
	 * place, and the table is a rebuildable cache — so a version mismatch drops and recreates
	 * it, and the next `occ fulltextsearch:index` refills it.
	 */
	private const SCHEMA_VERSION = '6';

	/** What schemaDrift() answers when the generated column itself is gone. */
	public const DRIFT_NO_TSV = 'no tsv column';

	/**
	 * Below this, a word triggers no partial match: the substring would be too common to bring
	 * anything but noise.
	 */
	private const MIN_WILDCARD_LENGTH = 4;

	/**
	 * Below this, a lexeme gets no prefix wildcard: three letters matched dozens of stems
	 * unrelated to what was being looked for.
	 */
	private const MIN_PREFIX_LENGTH = 4;

	/**
	 * The fields a query may target, and the matching column. An allow-list: these names come
	 * from the content provider and end up in SQL.
	 */
	private const SEARCHABLE_COLUMNS = [
		'title' => 'title',
		'content' => 'content',
		'source' => 'source',
	];

	/**
	 * The Files provider targets the share name as `share_names.<user>` — for partial matching,
	 * for field restriction, and for the “within this folder” filter. A recipient does see a
	 * path that is not the owner's.
	 */
	private const SHARE_FIELD_PREFIX = 'share_names.';

	private readonly string $table;
	private readonly string $prefixNextcloud;

	public function __construct(
		private IDBConnection $db,
		private IAppConfig $appConfig,
		private IFactory $l10nFactory,
		private LoggerInterface $logger,
		IConfig $systemConfig,
	) {
		$this->prefixNextcloud = $systemConfig->getSystemValueString('dbtableprefix', 'oc_');
		$this->table = self::tableFor($this->prefixNextcloud);
	}

	/**
	 * Whether the index table falls inside the range Nextcloud hands to Doctrine anyway.
	 *
	 * Two prefixes defeat the naming rule. An empty one turns the filter into `/^/`, which
	 * matches everything — no name can escape it. And one starting with `fts_pg` catches the
	 * derived name itself. Both bring back the failure this table was moved to avoid, and
	 * nothing else would tell the administrator why.
	 */
	public function insidePrefixRange(): bool {
		return str_starts_with($this->table, $this->prefixNextcloud);
	}

	/**
	 * The index table for a given Nextcloud table prefix.
	 *
	 * Kept static and pure so the repair step can name the old table without a second copy of
	 * the rule. Non-word characters are dropped: the result goes into SQL as an identifier.
	 */
	public static function tableFor(string $prefixNextcloud): string {
		$suffixe = preg_replace('/[^a-z0-9]/', '', strtolower($prefixNextcloud)) ?? '';

		return self::TABLE_PREFIX . ($suffixe === '' ? '' : '_' . $suffixe);
	}

	/**
	 * Where the index lives. Used by the platform to report it in `occ fulltextsearch:check`.
	 */
	public function table(): string {
		return $this->table;
	}

	/**
	 * The PostgreSQL configurations, by Nextcloud language code.
	 *
	 * PostgreSQL ships about thirty of them; these are the ones with an equivalent. A language
	 * missing from this table — or whose configuration does not exist on this server — falls
	 * back to English, for lack of anything better: a mismatched stemmer beats no index at all.
	 */
	private const LANGUAGE_MAP = [
		'ar' => 'arabic', 'hy' => 'armenian', 'eu' => 'basque', 'ca' => 'catalan',
		'da' => 'danish', 'nl' => 'dutch', 'en' => 'english', 'et' => 'estonian',
		'fi' => 'finnish', 'fr' => 'french', 'de' => 'german', 'el' => 'greek',
		'hi' => 'hindi', 'hu' => 'hungarian', 'id' => 'indonesian', 'ga' => 'irish',
		'it' => 'italian', 'lt' => 'lithuanian', 'ne' => 'nepali', 'nb' => 'norwegian',
		'nn' => 'norwegian', 'no' => 'norwegian', 'pt' => 'portuguese', 'ro' => 'romanian',
		'ru' => 'russian', 'sr' => 'serbian', 'es' => 'spanish', 'sv' => 'swedish',
		'ta' => 'tamil', 'tr' => 'turkish', 'yi' => 'yiddish',
	];

	/**
	 * The indexing language, as the administrator set it.
	 *
	 * It ends up in SQL — configuration name, COPY clause — so it is validated twice: by its
	 * shape, then by its actual existence in pg_ts_config. An unknown value breaks nothing, it
	 * falls back to French.
	 */
	public function language(): string {
		return $this->languages()[0];
	}

	/**
	 * The indexing languages, the main one first.
	 *
	 * A bilingual corpus is common — documentation in English next to letters in French. Each
	 * added language costs roughly 20% more index: that is measured, that is moderate, and the
	 * app settles for warning beyond three.
	 *
	 * @return string[] never empty
	 */
	public function languages(): array {
		$brut = $this->appConfig->getValueString(
			'fulltextsearch_postgresql',
			ConfigLexicon::SEARCH_LANGUAGE,
			ConfigLexicon::AUTO,
			lazy: true
		);

		if (strtolower(trim($brut)) === ConfigLexicon::AUTO) {
			$brut = $this->detectLanguage();
		}

		$retenues = [];
		foreach (explode(',', $brut) as $candidate) {
			$langue = strtolower(trim($candidate));
			if ($langue === '' || in_array($langue, $retenues, true)) {
				continue;
			}

			if (preg_match('/^[a-z_]{2,32}$/', $langue) !== 1 || !$this->languageExists($langue)) {
				$this->logger->warning(
					'fulltextsearch_postgresql: unknown text search configuration "' . $langue
					. '", ignored. Available ones are listed by: SELECT cfgname FROM pg_ts_config;'
				);
				continue;
			}

			$retenues[] = $langue;
		}

		if ($retenues === []) {
			return [ConfigLexicon::FALLBACK_LANGUAGE];
		}

		// A warning, not a veto: it is the administrator's server, and the cost of one more
		// language is real but moderate (~20% of index). Silently truncating the list would be
		// worse — they would believe they were indexing in five languages.
		if (count($retenues) > ConfigLexicon::RECOMMENDED_MAX_LANGUAGES) {
			$this->logger->warning(
				'fulltextsearch_postgresql: ' . count($retenues) . ' languages configured ('
				. implode(', ', $retenues) . '). Each one adds roughly 20% to the index for a '
				. 'fraction of the added coverage; beyond '
				. ConfigLexicon::RECOMMENDED_MAX_LANGUAGES . ' the trade-off rarely pays off.'
			);
		}

		return $retenues;
	}

	/**
	 * The instance language, translated into a PostgreSQL configuration.
	 * “fr_CA” like “fr” gives french; whatever we cannot translate gives English.
	 */
	private function detectLanguage(): string {
		$code = strtolower(substr($this->l10nFactory->findLanguage(), 0, 2));
		$langue = self::LANGUAGE_MAP[$code] ?? ConfigLexicon::FALLBACK_LANGUAGE;

		return $this->languageExists($langue) ? $langue : ConfigLexicon::FALLBACK_LANGUAGE;
	}

	/**
	 * The search configurations installed on THIS server, ours excluded.
	 * What the admin panel offers: no point making anyone guess a name.
	 *
	 * @return string[]
	 */
	public function availableLanguages(): array {
		$result = $this->db->executeQuery(
			"SELECT cfgname FROM pg_ts_config WHERE cfgname NOT LIKE 'nc\_fts\_%' ORDER BY cfgname"
		);
		$rows = $result->fetchAll();
		$result->closeCursor();

		return array_map(static fn (array $r): string => (string)$r['cfgname'], $rows);
	}

	private function languageExists(string $langue): bool {
		$result = $this->db->executeQuery(
			'SELECT 1 FROM pg_ts_config WHERE cfgname = ?', [$langue]
		);
		$found = $result->fetchOne();
		$result->closeCursor();

		return $found !== false;
	}

	/**
	 * Our derived configuration: the one for the chosen language, plus accent folding.
	 */
	public function tsConfig(): string {
		return $this->tsConfigs()[0];
	}


	/**
	 * Creates the derived configuration for each selected language, if it is missing.
	 *
	 * Public because it is not only about initialization: changing the language from the admin
	 * panel must create it right away, without which every search fails until the next re-index.
	 *
	 * CREATE TEXT SEARCH CONFIGURATION takes no IF NOT EXISTS: we look first. The name stays the
	 * same whatever happens, so the generated column and the GIN index do not have to change —
	 * only the dictionary chain behind it evolves.
	 */
	public function ensureTextSearchConfigurations(?bool $unaccent = null): void {
		$unaccent ??= $this->hasExtension('unaccent');

		foreach ($this->languages() as $langue) {
			$config = 'nc_fts_' . $langue;

			// Only worth creating when unaccent is there to make it differ from the native one.
			// Without the extension it would be a byte-for-byte clone, and tsVector() — which
			// compares names, not behaviour — would emit both, storing every lexeme twice for
			// no benefit and halving the usable tsvector positions.
			if ($unaccent && !$this->configurationExists($config)) {
				$this->db->executeStatement(
					'CREATE TEXT SEARCH CONFIGURATION ' . $config . ' (COPY = ' . $langue . ')'
				);
			}

			if ($unaccent) {
				$this->db->executeStatement(
					'ALTER TEXT SEARCH CONFIGURATION ' . $config . '
						ALTER MAPPING FOR hword, hword_part, word
						WITH unaccent, ' . $langue . '_stem'
				);
			}
		}
	}

	private function configurationExists(string $config): bool {
		$result = $this->db->executeQuery(
			'SELECT 1 FROM pg_ts_config WHERE cfgname = ?', [$config]
		);
		$found = $result->fetchOne();
		$result->closeCursor();

		return $found !== false;
	}

	/**
	 * @return string[] one accent-folded configuration per selected language
	 */
	public function tsConfigs(): array {
		// Fall back to the native configuration when the derived one does not exist yet: a
		// search must never fail because a setting has just been changed.
		return array_map(function (string $langue): string {
			$config = 'nc_fts_' . $langue;

			return $this->configurationExists($config) ? $config : $langue;
		}, $this->languages());
	}

	/**
	 * The expression that indexes a column: for each language, its native configuration — which
	 * keeps accents, hence correct stemming — and our accent-folded variant, which tolerates
	 * their absence.
	 */
	private function tsVector(string $expression): string {
		$parties = [];
		foreach ($this->languages() as $index => $langue) {
			$derivee = $this->tsConfigs()[$index];
			$parties[] = "to_tsvector('" . $langue . "'::regconfig, " . $expression . ')';
			if ($derivee !== $langue) {
				$parties[] = "to_tsvector('" . $derivee . "'::regconfig, " . $expression . ')';
			}
		}

		return implode("\n\t\t\t\t\t\t|| ", $parties);
	}

	/**
	 * Its query-side counterpart: the same configurations, OR'ed together.
	 */
	private function tsQuery(string $param): string {
		$parties = [];
		foreach ($this->languages() as $index => $langue) {
			$derivee = $this->tsConfigs()[$index];
			$parties[] = "websearch_to_tsquery('" . $langue . "'::regconfig, " . $param . ')';
			if ($derivee !== $langue) {
				$parties[] = "websearch_to_tsquery('" . $derivee . "'::regconfig, " . $param . ')';
			}
		}

		return implode("\n\t\t\t\t\t|| ", $parties);
	}

	public function isPostgres(): bool {
		return $this->db->getDatabaseProvider() === IDBConnection::PLATFORM_POSTGRES;
	}

	/**
	 * The platform's liveness test: does the database answer? Knowingly independent of whether
	 * the table exists — the framework tests the platform BEFORE initializing it.
	 */
	public function ping(): bool {
		$result = $this->db->executeQuery('SELECT 1');
		$ok = $result->fetchOne();
		$result->closeCursor();

		return $ok !== false;
	}

	public function tableExists(): bool {
		// Asked of PostgreSQL directly, not through IDBConnection::tableExists(), which prepends
		// the Nextcloud prefix to whatever it is given: our table sits outside that prefix on
		// purpose, so it would look for `oc_fts_pg_oc` and never find anything.
		$result = $this->db->executeQuery('SELECT to_regclass(?) IS NOT NULL AS present', [$this->table]);
		$present = (bool)$result->fetchOne();
		$result->closeCursor();

		return $present;
	}

	/**
	 * Idempotent: safe to call on every `occ fulltextsearch:index`.
	 *
	 * @throws DBException
	 */
	public function initSchema(): void {
		$unaccent = $this->ensureUnaccent();
		$trigram = $this->ensureExtension('pg_trgm');
		if (!$trigram) {
			// Said out loud for the same reason as unaccent: nothing else reports it, and the
			// symptom — every search falling back to a full table scan — only shows up as
			// slowness once the corpus has grown.
			$this->logger->warning(
				'fulltextsearch_postgresql: the pg_trgm extension is missing and cannot be '
				. 'created (the database user lacks CREATE on the database). Partial matching '
				. 'on file names still works but forces a full table scan on every search. Ask '
				. 'an administrator to run "CREATE EXTENSION pg_trgm;" as superuser; '
				. 're-indexing is not needed.'
			);
		}

		$this->ensureTextSearchConfigurations($unaccent);

		$this->dropOnSchemaChange();

		$this->db->executeStatement(
			'CREATE TABLE IF NOT EXISTS ' . $this->table . ' (
				provider_id   varchar(64)   NOT NULL,
				document_id   varchar(128)  NOT NULL,
				owner_id      varchar(64)   NOT NULL DEFAULT \'\',
				acl_users     text[]        NOT NULL DEFAULT \'{}\',
				acl_groups    text[]        NOT NULL DEFAULT \'{}\',
				acl_circles   text[]        NOT NULL DEFAULT \'{}\',
				source        varchar(64)   NOT NULL DEFAULT \'\',
				title         text          NOT NULL DEFAULT \'\',
				content       text          NOT NULL DEFAULT \'\',
				parts         jsonb         NOT NULL DEFAULT \'{}\',
				parts_text    text          NOT NULL DEFAULT \'\',
				metatags      text[]        NOT NULL DEFAULT \'{}\',
				subtags       text[]        NOT NULL DEFAULT \'{}\',
				tags          text[]        NOT NULL DEFAULT \'{}\',
				links         text[]        NOT NULL DEFAULT \'{}\',
				info          jsonb         NOT NULL DEFAULT \'{}\',
				share_names   jsonb         NOT NULL DEFAULT \'{}\',
				share_text    text          NOT NULL DEFAULT \'\',
				hash          varchar(64)   NOT NULL DEFAULT \'\',
				modified_at   bigint        NOT NULL DEFAULT 0,
				indexed_at    timestamptz   NOT NULL DEFAULT now(),
				tsv tsvector GENERATED ALWAYS AS (
					setweight(
						' . $this->tsVector('coalesce(title, \'\')') . '
						|| ' . $this->tsVector('translate(coalesce(title, \'\'), \'/\\_-.\', \'     \')') . '
						|| ' . $this->tsVector('translate(coalesce(share_text, \'\'), \'/\\_-.\', \'     \')') . '
					, \'A\')
					|| setweight(' . $this->tsVector('coalesce(content, \'\')') . ', \'B\')
					|| setweight(' . $this->tsVector('coalesce(parts_text, \'\')') . ', \'C\')
				) STORED,
				PRIMARY KEY (provider_id, document_id)
			)'
		);

		$this->db->executeStatement(
			'COMMENT ON TABLE ' . $this->table . " IS '" . self::SCHEMA_VERSION . ':'
			. implode('+', $this->tsConfigs()) . "'"
		);

		foreach ([
			'CREATE INDEX IF NOT EXISTS ' . $this->table . '_tsv_idx ON ' . $this->table . ' USING gin (tsv)',
			'CREATE INDEX IF NOT EXISTS ' . $this->table . '_users_idx ON ' . $this->table . ' USING gin (acl_users)',
			'CREATE INDEX IF NOT EXISTS ' . $this->table . '_groups_idx ON ' . $this->table . ' USING gin (acl_groups)',
			'CREATE INDEX IF NOT EXISTS ' . $this->table . '_circles_idx ON ' . $this->table . ' USING gin (acl_circles)',
			'CREATE INDEX IF NOT EXISTS ' . $this->table . '_owner_idx ON ' . $this->table . ' (provider_id, owner_id)',
			'CREATE INDEX IF NOT EXISTS ' . $this->table . '_metatags_idx ON ' . $this->table . ' USING gin (metatags)',
			'CREATE INDEX IF NOT EXISTS ' . $this->table . '_subtags_idx ON ' . $this->table . ' USING gin (subtags)',
		] as $sql) {
			$this->db->executeStatement($sql);
		}

		// Without it, the `OR title ILIKE '%…%'` of partial matching keeps the planner from using
		// the GIN index: it falls back to a full table scan. Measured on 50,000 documents: 43 ms
		// with the full scan, 0.85 ms with the trigram index, the planner then combining the two
		// indexes in a BitmapOr.
		if ($trigram) {
			$this->db->executeStatement(
				'CREATE INDEX IF NOT EXISTS ' . $this->table . '_title_trgm_idx ON ' . $this->table
				. ' USING gin (title gin_trgm_ops)'
			);
			// The counterpart for share names: see wildcardConditions(), which uses it as a
			// pre-filter. Measured on 200,000 documents, searching for a rare term: 86 ms without
			// it, 0.2 ms with. It weighs 1.1 MB for 360 MB of table.
			$this->db->executeStatement(
				'CREATE INDEX IF NOT EXISTS ' . $this->table . '_share_trgm_idx ON ' . $this->table
				. ' USING gin (share_text gin_trgm_ops)'
			);
		}
	}

	/**
	 * The schema version is kept as the table's comment: no extra table, and it travels with
	 * the object it describes.
	 */
	/**
	 * What the generated column is compiled against — the configurations, not the languages.
	 * `unaccent` appearing after the table was built swaps `french` for `nc_fts_french` here,
	 * and that is precisely the change that has to force a rebuild: signing with the languages
	 * leaves the comment matching while the column goes on folding nothing.
	 */
	private function schemaSignature(): string {
		return $this->signature($this->tsConfigs());
	}

	/**
	 * The signature the settings call for, whether or not the derived configurations have been
	 * created yet. `tsConfigs()` reports what exists, and the derived ones are only created by
	 * `initSchema()` or by saving the language: between `CREATE EXTENSION unaccent` and the next
	 * indexing run, it still answers `french` and the drift below would see nothing to report —
	 * which is the one case it exists for.
	 */
	private function targetSignature(): string {
		$unaccent = $this->hasUnaccent();

		return $this->signature(array_map(
			static fn (string $langue): string => $unaccent ? 'nc_fts_' . $langue : $langue,
			$this->languages()
		));
	}

	/**
	 * Sorted, because the column ORs the configurations together: listing the same two languages
	 * the other way round builds the very same index, and must not ask for a rebuild.
	 *
	 * @param string[] $configs
	 */
	private function signature(array $configs): string {
		sort($configs);

		return self::SCHEMA_VERSION . ':' . implode('+', $configs);
	}

	/**
	 * The gap between the table as it stands and the table the current settings call for, or
	 * null when there is none. Only `fulltextsearch:index` rebuilds; `fulltextsearch:check` has
	 * to be able to say so without changing anything, or an administrator who installs an
	 * extension and checks reads a clean report over a stale index.
	 *
	 * @return string|null
	 */
	public function schemaDrift(): ?string {
		try {
			$result = $this->db->executeQuery(
				"SELECT to_regclass('" . $this->table . "') IS NOT NULL AS present,
						obj_description(to_regclass('" . $this->table . "'), 'pg_class') AS version,
						EXISTS (SELECT 1 FROM pg_attribute
								WHERE attrelid = to_regclass('" . $this->table . "')
								  AND attname = 'tsv' AND NOT attisdropped) AS has_tsv"
			);
			$row = $result->fetch();
			$result->closeCursor();
		} catch (Throwable) {
			return null;
		}

		// No table yet is not a drift: indexing will build it against the current settings. A
		// table with no comment is one, though — it predates versioning, and the next indexing
		// run drops it. Both answer null to obj_description, hence the separate check.
		if (!(bool)($row['present'] ?? false)) {
			return null;
		}

		// Reported before the signature: a table that lost its tsv column answers every search
		// with an SQL error, while its comment still matches and every other field reads clean.
		if (!(bool)($row['has_tsv'] ?? false)) {
			return self::DRIFT_NO_TSV;
		}

		$found = $row['version'] ?? 'unversioned';
		$cible = $this->targetSignature();

		return $found === $cible ? null : $found . ' → ' . $cible;
	}

	private function dropOnSchemaChange(): void {
		// The name goes through the SQL, not through a parameter: IDBConnection is the one that
		// substitutes *PREFIX*, and it only touches the query. Both facts are read together: a
		// table with no comment is a table from before versioning, hence to be rebuilt — not to be
		// confused with a missing table, where there is nothing to do.
		$result = $this->db->executeQuery(
			"SELECT to_regclass('" . $this->table . "') IS NOT NULL AS present,
					obj_description(to_regclass('" . $this->table . "'), 'pg_class') AS version,
					EXISTS (SELECT 1 FROM pg_attribute
							WHERE attrelid = to_regclass('" . $this->table . "')
							  AND attname = 'tsv' AND NOT attisdropped) AS has_tsv"
		);
		$row = $result->fetch();
		$result->closeCursor();

		$present = (bool)($row['present'] ?? false);
		$found = $row['version'] ?? null;
		$attendu = $this->schemaSignature();

		// Dropping the unaccent extension with CASCADE takes the derived configuration down,
		// and the generated column that depends on it along with it. The table survives, minus
		// the one column everything is built on, and the version comment still matches — so
		// nothing below would rebuild it, and every search would fail on a missing column.
		if ($present && !(bool)($row['has_tsv'] ?? false)) {
			$this->logger->warning(
				'fulltextsearch_postgresql: the index table lost its tsv column, which happens '
				. 'when a text search configuration it depends on is dropped with CASCADE. '
				. 'Rebuilding the table; run "occ fulltextsearch:reset" then '
				. '"occ fulltextsearch:index" to refill it.'
			);
			$this->db->executeStatement('DROP TABLE IF EXISTS ' . $this->table);

			return;
		}

		if ($present && $found !== $attendu) {
			$found = $found ?? 'unversioned';
			$this->logger->warning(
				'fulltextsearch_postgresql: index schema changed (' . $found . ' → ' . $attendu
				. '), the index table has been dropped. Nextcloud still believes the documents '
				. 'are indexed, so a plain re-index would do nothing: run '
				. '"occ fulltextsearch:reset" then "occ fulltextsearch:index".'
			);
			$this->db->executeStatement('DROP TABLE IF EXISTS ' . $this->table);
		}
	}

	public function hasExtension(string $name): bool {
		$result = $this->db->executeQuery('SELECT 1 FROM pg_extension WHERE extname = ?', [$name]);
		$found = $result->fetchOne();
		$result->closeCursor();

		return $found !== false;
	}

	public function hasUnaccent(): bool {
		return $this->hasExtension('unaccent');
	}

	/**
	 * Nextcloud connects as its own application role (`oc_<admin>`), which owns neither the
	 * database nor the CREATE privilege on it — so CREATE EXTENSION fails even though
	 * `unaccent` is a trusted extension. Rather than refuse to start, the platform falls back
	 * to plain `french`: stemming and stopwords still work, only accent folding is lost.
	 * An administrator restores it with a single `CREATE EXTENSION unaccent;`, or by granting
	 * CREATE on the database to the role Nextcloud connects with, followed by a re-index.
	 */
	private function ensureUnaccent(): bool {
		if ($this->ensureExtension('unaccent')) {
			return true;
		}

		$this->logger->warning(
			'fulltextsearch_postgresql: the unaccent extension is missing and cannot be created '
			. '(the database user lacks CREATE on the database). Search will be '
			. 'accent-sensitive. Ask an administrator for "GRANT CREATE ON DATABASE <db> TO '
			. '<the role in dbuser>;", which lets this app create it on the next indexing run, '
			. 'or to run "CREATE EXTENSION unaccent;" once. Then re-index.'
		);

		return false;
	}

	/**
	 * The extensions the platform relies on are *trusted*, so CREATE EXTENSION asks for the
	 * CREATE privilege on the database rather than superuser rights. Nextcloud does not
	 * necessarily connect as a role holding it: its installer often makes a dedicated `oc_…`
	 * role with CONNECT alone. We try, and a refusal degrades instead of failing: the
	 * administrator is told what is missing and what to run.
	 */
	private function ensureExtension(string $name): bool {
		if ($this->hasExtension($name)) {
			return true;
		}

		try {
			$this->db->executeStatement('CREATE EXTENSION IF NOT EXISTS ' . $name);

			return true;
		} catch (Throwable) {
			return false;
		}
	}

	/**
	 * @throws DBException
	 */
	public function reset(string $providerId): void {
		if (!$this->tableExists()) {
			return;
		}

		if ($providerId === 'all') {
			$this->db->executeStatement('TRUNCATE ' . $this->table);

			return;
		}

		$this->db->executeStatement(
			'DELETE FROM ' . $this->table . ' WHERE provider_id = ?', [$providerId]
		);
	}

	/**
	 * @throws DBException
	 */
	public function delete(string $providerId, string $documentId): void {
		$this->db->executeStatement(
			'DELETE FROM ' . $this->table . ' WHERE provider_id = ? AND document_id = ?',
			[$providerId, $documentId]
		);
	}

	/**
	 * Text arrays travel as JSON and are turned back into text[] in SQL: json_encode()
	 * handles the escaping that hand-built PostgreSQL array literals get wrong.
	 *
	 * When the framework only refreshes a document's access rights, it hands us a document
	 * with no content at all (IIndex::INDEX_CONTENT is unset). Overwriting the stored text
	 * with an empty string would silently un-index the document — so the content columns are
	 * left out of the UPDATE in that case.
	 *
	 * @param array<string, mixed> $row
	 *
	 * @throws DBException
	 */
	public function upsert(array $row, bool $updateContent = true): void {
		$sql = 'INSERT INTO ' . $this->table . ' (
				provider_id, document_id, owner_id,
				acl_users, acl_groups, acl_circles,
				source, title, content, parts, parts_text,
				metatags, subtags, tags, links, info, share_names, share_text,
				hash, modified_at, indexed_at
			) VALUES (
				:providerId, :documentId, :ownerId,
				' . $this->textArray(':aclUsers') . ',
				' . $this->textArray(':aclGroups') . ',
				' . $this->textArray(':aclCircles') . ',
				:source, :title, :content, CAST(:parts AS jsonb), :partsText,
				' . $this->textArray(':metatags') . ',
				' . $this->textArray(':subtags') . ',
				' . $this->textArray(':tags') . ',
				' . $this->textArray(':links') . ',
				CAST(:info AS jsonb), CAST(:shareNames AS jsonb), :shareText,
				:hash, :modifiedAt, now()
			)
			ON CONFLICT (provider_id, document_id) DO UPDATE SET
				owner_id = EXCLUDED.owner_id,
				acl_users = EXCLUDED.acl_users,
				acl_groups = EXCLUDED.acl_groups,
				acl_circles = EXCLUDED.acl_circles,
				source = EXCLUDED.source,
				title = EXCLUDED.title,'
			. ($updateContent ? '
				content = EXCLUDED.content,
				parts = EXCLUDED.parts,
				parts_text = EXCLUDED.parts_text,' : '') . '
				metatags = EXCLUDED.metatags,
				subtags = EXCLUDED.subtags,
				tags = EXCLUDED.tags,
				links = EXCLUDED.links,
				info = EXCLUDED.info,
				share_names = EXCLUDED.share_names,
				share_text = EXCLUDED.share_text,
				hash = EXCLUDED.hash,
				modified_at = EXCLUDED.modified_at,
				indexed_at = now()';

		$this->db->executeStatement($sql, $row, ['modifiedAt' => IQueryBuilder::PARAM_INT]);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws DBException
	 */
	public function search(
		string $providerId,
		string $query,
		string $viewerId,
		array $groups,
		array $circles,
		SearchFilters $filters,
		int $limit,
		int $offset,
	): array {
		$params = [
			'query' => $query,
			'providerId' => $providerId,
			'viewerId' => $viewerId,
			'viewerUsers' => json_encode([$viewerId, '__all']),
			'viewerGroups' => json_encode(array_values($groups)),
			'viewerCircles' => json_encode(array_values($circles)),
			'limit' => $limit,
			'offset' => $offset,
			// Only lexemes of at least MIN_PREFIX_LENGTH characters get the wildcard:
			// “the:*” matched theatr, their, them, then… nothing to do with the intent.
			'lexemePattern' => "'([^']{" . self::MIN_PREFIX_LENGTH . ",})'",
			'prefixReplacement' => "'\\1':*",
		];
		$types = [
			'limit' => IQueryBuilder::PARAM_INT,
			'offset' => IQueryBuilder::PARAM_INT,
		];

		$where = $this->buildConditions($filters, $params, $types);
		$where = array_merge($where, $this->mandatoryConditions($query, $params));

		$sql = 'WITH q AS (
				-- The same configurations as at indexing time: for each language, the native one
				-- keeps accents — hence correct stemming — and ours folds them, to tolerate their
				-- absence in whatever the user types.
				SELECT ' . $this->tsQuery(':query') . ' AS tsq
			), qs AS (
				-- Each clause is sorted by nature rather than cut at the first exclusion: a query
				-- may open with one (“-word rest…”), and splitting on the first “ & !” would then
				-- leave the negations sitting in the positive part, where turning & into | would
				-- read them as “or not this” — the exact opposite of the request.
				SELECT tsq,
					(SELECT string_agg(c, \' | \')
						FROM unnest(string_to_array(tsq::text, \' & \')) c
						WHERE c NOT LIKE \'!%\') AS positifs,
					(SELECT string_agg(c, \' & \')
						FROM unnest(string_to_array(tsq::text, \' & \')) c
						WHERE c LIKE \'!%\') AS exclusions
				FROM q
			), qq AS (
				-- Two queries are derived, and they do different jobs.
				--
				-- tsq_or drives the RANKING: juxtaposed terms become OR — the recall of a search
				-- engine — phrases relax into AND, and every lexeme gains a prefix (:*) so that
				-- “chauff” reaches “chauffage”. Documents carrying all the terms are sorted first.
				--
				-- tsq_filter decides WHICH ROWS COME BACK. Quotation marks are an instruction,
				-- not a hint: a query holding a phrase is filtered on the strict form, so
				-- “compte rendu” no longer returns a document where the two words merely appear
				-- apart. This costs the “comptes-rendus” case — a compound word tokenizes into
				-- \'compt\' \'rendus\', which <-> cannot reach — and the trade is deliberate: the
				-- previous behaviour made quotes do nothing at all, and failed the conformance
				-- test the framework ships (occ fulltextsearch:test).
				--
				-- The pattern and its replacement are passed as parameters: written literally,
				-- the “:*” of the prefix would be taken for a named parameter by the DBAL layer.
				SELECT tsq,
					CASE WHEN positifs IS NULL THEN tsq
						ELSE regexp_replace(
							\'(\' || regexp_replace(positifs, \'<[0-9-]+>\', \'&\', \'g\') || \')\'
								|| coalesce(\' & \' || exclusions, \'\'),
							:lexemePattern, :prefixReplacement, \'g\'
						)::tsquery END AS tsq_or,
					CASE WHEN positifs IS NULL OR tsq::text LIKE \'%<%\' THEN tsq
						ELSE regexp_replace(
							\'(\' || positifs || \')\' || coalesce(\' & \' || exclusions, \'\'),
							:lexemePattern, :prefixReplacement, \'g\'
						)::tsquery END AS tsq_filter
				FROM qs
			), page AS (
				-- The page is cut out BEFORE any highlighting, and without pulling the bulky
				-- columns along. Two reasons, measured on 50,000 documents:
				--   * count(*) OVER () forces a walk over every match; a ts_headline placed here
				--     would therefore be computed on all 50,000, not on the 25 displayed —
				--     365 ms instead of 95;
				--   * keeping `content` at this level forces the whole batch to be detoasted.
				SELECT provider_id, document_id, owner_id, source, title, hash, modified_at,
					ts_rank(tsv, qq.tsq_or) AS score,
					(tsv @@ qq.tsq) AS all_terms,
					count(*) OVER () AS total,
					-- Computed before the LIMIT, hence over every match and not over the single
					-- page rendered: the relative ranking depends on it.
					max(ts_rank(tsv, qq.tsq_or)) OVER () AS max_score
				FROM ' . $this->table . ', qq
				WHERE ' . implode("\n\t\t\t\t\tAND ", $where) . '
				-- Documents carrying ALL the terms come first: the recall of an OR, the precision
				-- of an AND in the ranking.
				ORDER BY all_terms DESC, score DESC, modified_at DESC
				LIMIT :limit OFFSET :offset
			)
			SELECT page.provider_id, page.document_id, page.owner_id, page.source, page.title,
				page.hash, page.modified_at, page.score, page.all_terms, page.total, page.max_score,
				ts_headline(
					\'' . $this->tsConfig() . '\'::regconfig, doc.content, qq.tsq_or,
					\'MaxFragments=3, MinWords=5, MaxWords=25, FragmentDelimiter= … , StartSel="", StopSel=""\'
				) AS excerpt,
				ts_headline(
					\'' . $this->tsConfig() . '\'::regconfig, doc.parts_text, qq.tsq_or,
					\'MaxFragments=1, MinWords=5, MaxWords=20, FragmentDelimiter= … , StartSel="", StopSel=""\'
				) AS excerpt_parts
			FROM page
				JOIN ' . $this->table . ' doc USING (provider_id, document_id),
				qq
			ORDER BY page.all_terms DESC, page.score DESC, page.modified_at DESC';

		$result = $this->db->executeQuery($sql, $params, $types);
		$rows = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}

	/**
	 * The total number of matches, regardless of pagination.
	 *
	 * The main query carries its own total, but that one travels in the rows: asking for a page
	 * beyond the last result returns none, and the total would drop to zero even though there
	 * are results. The interface would read that as “no results”.
	 *
	 * @throws DBException
	 */
	public function count(
		string $providerId,
		string $query,
		string $viewerId,
		array $groups,
		array $circles,
		SearchFilters $filters,
	): int {
		$params = [
			'query' => $query,
			'providerId' => $providerId,
			'viewerId' => $viewerId,
			'viewerUsers' => json_encode([$viewerId, '__all']),
			'viewerGroups' => json_encode(array_values($groups)),
			'viewerCircles' => json_encode(array_values($circles)),
			// Only lexemes of at least MIN_PREFIX_LENGTH characters get the wildcard:
			// “the:*” matched theatr, their, them, then… nothing to do with the intent.
			'lexemePattern' => "'([^']{" . self::MIN_PREFIX_LENGTH . ",})'",
			'prefixReplacement' => "'\\1':*",
		];
		$types = [];
		$where = $this->buildConditions($filters, $params, $types);
		$where = array_merge($where, $this->mandatoryConditions($query, $params));

		$sql = 'WITH q AS (
				SELECT ' . $this->tsQuery(':query') . ' AS tsq
			), qq AS (
				SELECT tsq,
					regexp_replace(
						CASE WHEN tsq::text LIKE \'%!%\' THEN tsq::text
							ELSE regexp_replace(
								replace(tsq::text, \'&\', \'|\'), \'<[0-9-]+>\', \'&\', \'g\'
							) END,
						:lexemePattern, :prefixReplacement, \'g\'
					)::tsquery AS tsq_or
				FROM q
			)
			SELECT count(*) FROM ' . $this->table . ', qq
			WHERE ' . implode("\n\t\t\t\tAND ", $where);

		$result = $this->db->executeQuery($sql, $params, $types);
		$total = (int)$result->fetchOne();
		$result->closeCursor();

		return $total;
	}

	/**
	 * The WHERE clauses, assembled from the filters carried by the query.
	 *
	 * @param array<string, mixed> $params
	 * @param array<string, mixed> $types
	 *
	 * @return string[]
	 */
	private function buildConditions(SearchFilters $filters, array &$params, array &$types): array {
		$where = ['provider_id = :providerId'];

		// The text, possibly restricted to certain fields, OR a partial match on a field declared
		// for partial matching. A partial match widens the result set, it does not narrow it —
		// hence the OR.
		$match = [$this->textMatch($filters)];
		foreach ($this->wildcardConditions($filters, $params) as $condition) {
			$match[] = $condition;
		}
		$where[] = '(' . implode(' OR ', $match) . ')';

		$where[] = '(
					owner_id = :viewerId
					OR acl_users && ' . $this->textArray(':viewerUsers') . '
					OR acl_groups && ' . $this->textArray(':viewerGroups') . '
					OR acl_circles && ' . $this->textArray(':viewerCircles') . '
				)';

		// metatags: at least one is enough.
		if ($filters->metaTags !== []) {
			$params['metatags'] = json_encode(array_values($filters->metaTags));
			$where[] = 'metatags && ' . $this->textArray(':metatags');
		}

		// subtags: all of them (its `must`).
		if ($filters->subTags !== []) {
			$params['subtags'] = json_encode(array_values($filters->subTags));
			$where[] = 'subtags @> ' . $this->textArray(':subtags');
		}

		foreach ($this->regexConditions($filters, $params) as $condition) {
			$where[] = $condition;
		}

		if ($filters->since > 0) {
			$params['since'] = $filters->since;
			$types['since'] = IQueryBuilder::PARAM_INT;
			$where[] = 'modified_at >= :since';
		}

		return $where;
	}

	/**
	 * Restricting the search to certain fields without an extra column: `ts_rank` with a
	 * selective weight array only yields a non-zero score if the match falls in a retained
	 * weight. The array order is {D, C, B, A} — title in A, content in B.
	 */
	/**
	 * The terms the user marked as mandatory with a leading “+”.
	 *
	 * PostgreSQL cannot help here: `websearch_to_tsquery` parses “+test” exactly like “test”,
	 * so the two are indistinguishable once converted. The raw query has to be read before
	 * that. Without this a “+term” silently behaves like an ordinary one, which the framework's
	 * own conformance test catches.
	 *
	 * @param array<string, mixed> $params
	 *
	 * @return string[] the SQL conditions to add, if any
	 */
	private function mandatoryConditions(string $query, array &$params): array {
		preg_match_all('/(?<![^\s])\+(\S+)/u', $query, $matches);
		$termes = array_values(array_filter(
			array_map(static fn (string $mot): string => trim($mot, '"\''), $matches[1]),
			static fn (string $mot): bool => $mot !== ''
		));

		if ($termes === []) {
			return [];
		}

		// Passed as a space-separated list, which websearch_to_tsquery turns into a conjunction
		// on its own.
		$params['mandatory'] = implode(' ', $termes);

		return ['tsv @@ (' . $this->tsQuery(':mandatory') . ')'];
	}

	private function textMatch(SearchFilters $filters): string {
		if ($filters->limitFields === []) {
			return 'tsv @@ qq.tsq_filter';
		}

		$weights = sprintf(
			'{0,0,%d,%d}',
			$filters->allows('content') ? 1 : 0,
			$filters->allows('title') ? 1 : 0
		);

		return "ts_rank('" . $weights . "'::float4[], tsv, qq.tsq_filter) > 0";
	}

	/**
	 * Partial match on a field: `chauff` must find `Chauffage.md`.
	 * files_fulltextsearch asks for one on the title at EVERY search.
	 *
	 * @param array<string, mixed> $params
	 *
	 * @return string[]
	 */
	private function wildcardConditions(SearchFilters $filters, array &$params): array {
		$conditions = [];
		$n = 0;
		$mots = $this->withoutStopWords($filters->words);

		foreach ($filters->wildcardFields as $field) {
			$column = $this->columnExpression($field, $params);
			if ($column === null || !$filters->allows($field)) {
				continue;
			}

			$prefiltre = $this->indexablePrefilter($field);

			foreach ($mots as $word) {
				$key = 'wc' . $n++;
				// LIKE wildcards present in the input are neutralized: they must not turn into
				// operators.
				$params[$key] = '%' . addcslashes($word, '%_\\') . '%';
				$test = $column . ' ILIKE :' . $key;
				$conditions[] = $prefiltre === null
					? $test
					: '(' . $prefiltre . ' ILIKE :' . $key . ' AND ' . $test . ')';
			}
		}

		return $conditions;
	}

	/**
	 * The SQL expression matching a requested field, or null if it is unknown.
	 *
	 * The user name is never interpolated: it becomes a bound parameter.
	 *
	 * @param array<string, mixed> $params
	 */
	private function columnExpression(string $field, array &$params): ?string {
		if (isset(self::SEARCHABLE_COLUMNS[$field])) {
			return self::SEARCHABLE_COLUMNS[$field];
		}

		if (str_starts_with($field, self::SHARE_FIELD_PREFIX)) {
			$utilisateur = substr($field, strlen(self::SHARE_FIELD_PREFIX));
			if ($utilisateur === '') {
				return null;
			}

			$params['shareViewer'] = $utilisateur;

			return "coalesce(share_names->>:shareViewer, '')";
		}

		return null;
	}

	/**
	 * A condition broader than the one asked for, but indexable, to place in front of it.
	 *
	 * The share name depends on who is searching: `share_names->>'bob'` therefore cannot be
	 * indexed ahead of time. And an `OR` is only worth its weakest link — one non-indexable
	 * branch is enough for the planner to fall back to a full scan, which incidentally wiped out
	 * the benefit of the trigram index on the title.
	 *
	 * `share_text` carries *all* of a document's share names. If the pattern is not in there, it
	 * is in none of them in particular: the broad condition can therefore be placed as an `AND`
	 * in front of the exact one without ever discarding a good result, and unlike the latter, it
	 * does get indexed. The `BitmapOr` re-forms.
	 *
	 * Reserved for `ILIKE`: for a regular expression, a pattern anchored by `^` would not behave
	 * the same way against the concatenation as against an isolated name.
	 */
	private function indexablePrefilter(string $field): ?string {
		return str_starts_with($field, self::SHARE_FIELD_PREFIX) ? 'share_text' : null;
	}

	/**
	 * Keeps out of partial matching what has no business being there.
	 *
	 * Two filters, for two distinct sources of noise:
	 *
	 * - **stop words**, which the `tsvector` already ignores but which the `ILIKE` took at face
	 *   value. PostgreSQL is the one that decides: a stop word yields an empty `tsvector`.
	 * - **words that are too short**, which make substrings too common. “the” brought back
	 *   thirty-eight documents by matching “thé”, “théorie”, “mathématique”… Four characters are
	 *   enough to rule out the bulk of it without crippling real searches — a shorter word stays
	 *   findable through the `tsvector`, which matches it whole.
	 *
	 * @param string[] $mots
	 *
	 * @return string[]
	 */
	private function withoutStopWords(array $mots): array {
		if ($mots === []) {
			return [];
		}

		$mots = array_values(array_filter(
			$mots,
			static fn (string $m): bool => mb_strlen($m) >= self::MIN_WILDCARD_LENGTH
		));
		if ($mots === []) {
			return [];
		}

		try {
			$result = $this->db->executeQuery(
				'SELECT m FROM unnest(' . $this->textArray(':mots') . ') AS m
					WHERE to_tsvector(:config::regconfig, m) <> \'\'',
				['mots' => json_encode(array_values($mots)), 'config' => $this->tsConfig()]
			);
			$gardes = array_map(static fn (array $r): string => (string)$r['m'], $result->fetchAll());
			$result->closeCursor();
		} catch (Throwable) {
			// When in doubt, noise beats silence: we keep everything.
			return $mots;
		}

		// The original order carries the user's intent; unnest does not guarantee it.
		return array_values(array_filter($mots, static fn (string $m): bool => in_array($m, $gardes, true)));
	}

	/**
	 * A group of regex filters means OR within it, AND between groups: that is the framework's
	 * convention. files_fulltextsearch uses it for the “extension” option.
	 *
	 * @param array<string, mixed> $params
	 *
	 * @return string[]
	 */
	private function regexConditions(SearchFilters $filters, array &$params): array {
		$conditions = [];
		$n = 0;

		// addRegexFilters() stacks the array it is handed: a group is therefore a LIST of
		// field => pattern pairs, not a pair. One level deeper than it looks.
		foreach ($filters->regexFilters as $group) {
			if (!is_array($group)) {
				continue;
			}

			$alternatives = [];
			foreach ($group as $entry) {
				foreach ((is_array($entry) ? $entry : []) as $field => $pattern) {
					$column = $this->columnExpression((string)$field, $params);
					if ($column === null || !is_string($pattern) || $pattern === '') {
						continue;
					}

					$key = 'rx' . $n++;
					$params[$key] = $pattern;
					$alternatives[] = $column . ' ~* :' . $key;
				}
			}

			if ($alternatives !== []) {
				$conditions[] = '(' . implode(' OR ', $alternatives) . ')';
			}
		}

		return $conditions;
	}

	/**
	 * @return array<string, mixed>|null
	 *
	 * @throws DBException
	 */
	public function get(string $providerId, string $documentId): ?array {
		$result = $this->db->executeQuery(
			'SELECT provider_id, document_id, owner_id, source, title, content, hash, modified_at,
					to_jsonb(acl_users) AS acl_users,
					to_jsonb(acl_groups) AS acl_groups,
					to_jsonb(acl_circles) AS acl_circles,
					to_jsonb(metatags) AS metatags,
					to_jsonb(subtags) AS subtags,
					to_jsonb(tags) AS tags,
					to_jsonb(links) AS links,
					parts, info, share_names
				FROM ' . $this->table . '
				WHERE provider_id = ? AND document_id = ?',
			[$providerId, $documentId]
		);

		$row = $result->fetch();
		$result->closeCursor();

		return ($row === false) ? null : $row;
	}

	/**
	 * @return array{documents: int, size: string}
	 *
	 * @throws DBException
	 */
	public function stats(): array {
		$result = $this->db->executeQuery(
			'SELECT count(*) AS documents, pg_size_pretty(pg_total_relation_size(\'' . $this->table . '\')) AS size
				FROM ' . $this->table
		);
		$row = $result->fetch();
		$result->closeCursor();

		return ['documents' => (int)$row['documents'], 'size' => (string)$row['size']];
	}

	/**
	 * Rebuilds a text[] from a JSON array parameter, avoiding hand-rolled array literals.
	 */
	private function textArray(string $param): string {
		return 'ARRAY(SELECT jsonb_array_elements_text(CAST(' . $param . ' AS jsonb)))::text[]';
	}
}
