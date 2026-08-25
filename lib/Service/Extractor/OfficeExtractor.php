<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alain Lesage
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FullTextSearch_PostgreSQL\Service\Extractor;

use RuntimeException;
use ZipArchive;

/**
 * Reads the text of OOXML and ODF documents — docx, xlsx, pptx, odt, ods, odp.
 *
 * All of them are ZIP archives of XML, so no library is warranted: pull the few entries that
 * carry the body and strip the markup. Measured on a real-world sample: 19 of 19 extracted.
 * The document-editing libraries of the ecosystem (PhpWord, PhpSpreadsheet) parse for
 * round-tripping, which is megabytes of code for a need that fits in one class.
 */
class OfficeExtractor implements ITextExtractor {
	private const SIGNATURE = "PK\x03\x04";

	/**
	 * Cap on the text read out of one archive.
	 *
	 * A compressed archive can unfold to a size bearing no relation to its own: without a
	 * limit, one user-uploaded file would be enough to exhaust the server memory during
	 * indexing. Content truncation comes too late — it applies to an already built string.
	 */
	private const MAX_EXTRACTED = 8388608;

	/**
	 * The body is split across entries: one per sheet, one per slide, headers and footers apart.
	 */
	private const ENTRIES = [
		'#^word/document\.xml$#',
		'#^word/(header|footer)\d*\.xml$#',
		'#^xl/sharedStrings\.xml$#',
		'#^ppt/slides/slide\d+\.xml$#',
		'#^ppt/notesSlides/notesSlide\d+\.xml$#',
		'#^content\.xml$#',   // ODF
	];

	/**
	 * Spreadsheets are handled separately.
	 *
	 * Their XML mixes formulas, row numbers and style ids in with the text. Taking it all in
	 * bloated the index with bare numbers — for nothing: nobody searches for "B5*0.75". A
	 * workbook's text lives in `xl/sharedStrings.xml`; all that is left in the sheets are the
	 * inline strings, tagged `<is>`.
	 */
	private const SHEET_ENTRY = '#^xl/worksheets/sheet\d+\.xml$#';

	public function getName(): string {
		return 'office';
	}

	public function supports(string $content): bool {
		return str_starts_with($content, self::SIGNATURE);
	}

	public function extract(string $content): string {
		// ZipArchive only reads from a file, so the payload has to land on disk briefly.
		$handle = tmpfile();
		if ($handle === false) {
			throw new RuntimeException('could not create a temporary file');
		}

		try {
			$path = stream_get_meta_data($handle)['uri'];
			fwrite($handle, $content);

			$zip = new ZipArchive();
			if ($zip->open($path) !== true) {
				return '';
			}

			try {
				return $this->readEntries($zip);
			} finally {
				$zip->close();
			}
		} finally {
			fclose($handle);
		}
	}

	private function readEntries(ZipArchive $zip): string {
		$parts = [];
		$total = 0;
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = $zip->getNameIndex($i);
			if ($name === false || !$this->isBodyEntry($name)) {
				continue;
			}

			$xml = $zip->getFromIndex($i);
			if ($xml === false) {
				continue;
			}

			$texte = $this->toText($xml);
			$total += strlen($texte);
			$parts[] = $texte;

			if ($total >= self::MAX_EXTRACTED) {
				return implode(' ', $parts);
			}
		}

		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = $zip->getNameIndex($i);
			if ($name === false || preg_match(self::SHEET_ENTRY, $name) !== 1) {
				continue;
			}

			$xml = $zip->getFromIndex($i);
			if ($xml === false) {
				continue;
			}

			// Only the strings written directly in the cell.
			if (preg_match_all('#<is>(.*?)</is>#s', $xml, $inline)) {
				foreach ($inline[1] as $fragment) {
					$texte = $this->toText($fragment);
					$total += strlen($texte);
					$parts[] = $texte;

					if ($total >= self::MAX_EXTRACTED) {
						return implode(' ', $parts);
					}
				}
			}
		}

		return implode(' ', $parts);
	}

	/**
	 * A space before every tag: without it, cells and fragments weld into a single word.
	 */
	private function toText(string $xml): string {
		return html_entity_decode(
			strip_tags(str_replace('<', ' <', $xml)),
			ENT_QUOTES | ENT_XML1,
			'UTF-8'
		);
	}

	private function isBodyEntry(string $name): bool {
		foreach (self::ENTRIES as $pattern) {
			if (preg_match($pattern, $name) === 1) {
				return true;
			}
		}

		return false;
	}
}
