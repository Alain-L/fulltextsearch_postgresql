<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alain Lesage
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FullTextSearch_PostgreSQL;

use OCP\Config\Lexicon\Entry;
use OCP\Config\Lexicon\ILexicon;
use OCP\Config\Lexicon\Strictness;
use OCP\Config\ValueType;

/**
 * The platform's settings — there is only one.
 *
 * Nothing to configure on the connection side: the database is Nextcloud's own. What is left
 * is the indexing language, which PostgreSQL ships in some thirty variants; hard-coding it
 * would reserve the app for French speakers.
 */
class ConfigLexicon implements ILexicon {
	public const SEARCH_LANGUAGE = 'search_language';
	/** Default value: follow the language of the Nextcloud instance. */
	public const AUTO = 'auto';

	/** What we fall back to when the instance language has no PostgreSQL equivalent. */
	public const FALLBACK_LANGUAGE = 'english';

	/**
	 * Beyond that, the app warns — it does not forbid.
	 *
	 * The cost of one extra language has been measured, and it is moderate: on a real corpus,
	 * adding English to French grows the table by 17% and the GIN index by 21%, the tsvector
	 * merging the lexemes the two languages have in common. Nothing that justifies blocking a
	 * Swiss or Belgian administrator with good reasons to want four of them.
	 */
	public const RECOMMENDED_MAX_LANGUAGES = 3;

	public function getStrictness(): Strictness {
		return Strictness::NOTICE;
	}

	public function getAppConfigs(): array {
		return [
			new Entry(
				key: self::SEARCH_LANGUAGE,
				type: ValueType::STRING,
				defaultRaw: self::AUTO,
				definition: 'PostgreSQL text search configuration(s) used to index and search. '
					. '"auto" (default) follows the language of the Nextcloud instance; '
					. 'otherwise any of pg_ts_config: french, german, english, spanish… '
					. 'Several can be combined for a multilingual corpus, comma-separated '
					. '("french,english") — each one adds roughly 20% to the index. '
					. 'Changing this rebuilds the index: run "occ fulltextsearch:reset" '
					. 'then "occ fulltextsearch:index".',
				lazy: true
			),
		];
	}

	public function getUserConfigs(): array {
		return [];
	}
}
