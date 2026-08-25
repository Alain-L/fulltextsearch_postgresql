<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alain Lesage
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FullTextSearch_PostgreSQL\Model;

use OCP\FullTextSearch\Model\ISearchRequest;

/**
 * The filters an ISearchRequest carries, unpacked once and for all.
 *
 * Nothing theoretical here: files_fulltextsearch sets some on every search —
 * a wildcard on the title every single time, plus the panel's checkboxes (file source,
 * extension, "search in"). Ignoring them means overriding what the user explicitly
 * asked for.
 */
class SearchFilters {
	/**
	 * @param string[] $words search words, for the fields matched as substrings
	 * @param string[] $wildcardFields fields to match on a substring (`title`)
	 * @param string[] $metaTags at least one must be carried by the document
	 * @param string[] $subTags all of them must be carried by the document
	 * @param array<int, array<string, string>> $regexFilters groups; within a group, one is enough
	 * @param string[] $limitFields restricts the search to these fields (`title`, `content`)
	 */
	public function __construct(
		public readonly array $words = [],
		public readonly array $wildcardFields = [],
		public readonly array $metaTags = [],
		public readonly array $subTags = [],
		public readonly array $regexFilters = [],
		public readonly array $limitFields = [],
		public readonly int $since = 0,
	) {
	}

	public static function fromRequest(ISearchRequest $request): self {
		return new self(
			words: self::significantWords($request->getSearch()),
			wildcardFields: $request->getWildcardFields(),
			metaTags: $request->getMetaTags(),
			subTags: $request->getSubTags(true),
			regexFilters: $request->getRegexFilters(),
			limitFields: $request->getLimitFields(),
			since: (int)$request->getOption('since'),
		);
	}

	/**
	 * Is a field ruled out by an `in:` restriction? No restriction means everything is allowed.
	 */
	public function allows(string $field): bool {
		if ($this->limitFields === [] || in_array($field, $this->limitFields, true)) {
			return true;
		}

		// The Files provider restricts to the file name by targeting both `title` and
		// `share_names.<user>`: targeting one amounts to targeting the other.
		if (str_starts_with($field, 'share_names.')) {
			return in_array('title', $this->limitFields, true);
		}

		if ($field === 'title') {
			foreach ($this->limitFields as $limite) {
				if (str_starts_with($limite, 'share_names.')) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * The search words, stripped of the websearch syntax: `-word` exclusions have no business
	 * in a substring search, they would invert it.
	 */
	private static function significantWords(string $search): array {
		$words = preg_split('/\s+/u', str_replace('"', ' ', $search)) ?: [];
		$kept = [];
		foreach ($words as $word) {
			$word = trim($word);
			if ($word === '' || str_starts_with($word, '-') || mb_strlen($word) < 3) {
				continue;
			}
			if (in_array(mb_strtolower($word), ['or', 'and'], true)) {
				continue;
			}
			$kept[] = $word;
		}

		return array_values(array_unique($kept));
	}
}
