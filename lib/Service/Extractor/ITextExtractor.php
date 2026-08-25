<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alain Lesage
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FullTextSearch_PostgreSQL\Service\Extractor;

/**
 * Turns one binary payload into searchable text.
 *
 * The content provider hands the platform raw bytes for everything it does not decode itself
 * (PDF, Office, zip) and lets the search platform deal with it. These extractors are that
 * step, in PHP — no external extraction service involved.
 *
 * Detection is by magic bytes, not by mimetype: `IIndexDocument` does not carry one, and
 * reaching for `FilesDocument::getMimetype()` would couple the platform to the files provider.
 */
interface ITextExtractor {
	/**
	 * Name used in logs, so a failing format is identifiable.
	 */
	public function getName(): string;

	/**
	 * Does this payload look like the format we handle? Reads the signature only.
	 */
	public function supports(string $content): bool;

	/**
	 * The extracted text, or an empty string when the document genuinely holds none.
	 * Throws on a broken payload — the caller logs it rather than swallowing it.
	 */
	public function extract(string $content): string;
}
