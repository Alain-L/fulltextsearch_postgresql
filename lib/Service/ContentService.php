<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alain Lesage
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FullTextSearch_PostgreSQL\Service;

use OCA\FullTextSearch_PostgreSQL\Db\FtsRequest;
use OCA\FullTextSearch_PostgreSQL\Service\Extractor\ITextExtractor;
use OCA\FullTextSearch_PostgreSQL\Service\Extractor\OfficeExtractor;
use OCA\FullTextSearch_PostgreSQL\Service\Extractor\PdfExtractor;
use OCP\FullTextSearch\Model\IIndexDocument;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns whatever the content provider handed us into something a tsvector can hold.
 *
 * files_fulltextsearch base64-encodes *everything* it sends — plain text as well as raw
 * PDF/Office/zip bytes — and lets the platform sort it out. ENCODED_BASE64 therefore says
 * nothing about whether the payload is text; we have to look.
 *
 * Images arrive as OCR'd text when files_fulltextsearch_tesseract is enabled. PDFs do not:
 * that extension writes their OCR into a *part*, and its PDF path is broken upstream anyway.
 * So PDF and Office payloads reach us as raw bytes and are decoded here, in PHP, without any
 * external extraction service.
 */
class ContentService {
	/** @var ITextExtractor[] */
	private array $extractors;

	public function __construct(
		private PdfExtractor $pdf,
		OfficeExtractor $office,
		private LoggerInterface $logger,
	) {
		$this->extractors = [$pdf, $office];
	}

	/**
	 * Which engine reads PDFs here — useful to the administrator, since extraction quality
	 * is not the same depending on whether poppler is installed.
	 */
	public function pdfEngine(): string {
		return $this->pdf->engine();
	}

	/**
	 * Decoded, validated, UTF-8, truncated content — or an empty string when the payload
	 * turns out to be binary.
	 */
	public function extract(IIndexDocument $document): string {
		$content = $document->getContent();
		if ($content === '') {
			return '';
		}

		if ($document->isContentEncoded() === IIndexDocument::ENCODED_BASE64) {
			$decoded = base64_decode($content, true);
			if ($decoded === false) {
				return '';
			}
			$content = $decoded;
		}

		// The signature first: it is deterministic, where "is this binary?" is a heuristic. A
		// Windows-1252 CSV is not valid UTF-8 and used to get rejected as binary, even though
		// it is text.
		$extractor = $this->extractorFor($content);
		if ($extractor !== null) {
			return $this->normalise($this->runExtractor($extractor, $content, $document));
		}

		if ($this->looksBinary($content)) {
			return '';
		}

		return $this->normalise($content);
	}

	private function extractorFor(string $content): ?ITextExtractor {
		foreach ($this->extractors as $extractor) {
			if ($extractor->supports($content)) {
				return $extractor;
			}
		}

		return null;
	}

	/**
	 * Failures are logged, never swallowed: upstream's OCR has been broken for releases
	 * precisely because a `catch (Throwable) { return; }` hid it.
	 */
	private function runExtractor(
		ITextExtractor $extractor,
		string $content,
		IIndexDocument $document,
	): string {
		try {
			$text = $extractor->extract($content);
		} catch (Throwable $t) {
			$this->logger->warning(
				'fulltextsearch_postgresql: ' . $extractor->getName() . ' extraction failed',
				['exception' => $t, 'document' => $document->getId(), 'title' => $document->getTitle()]
			);

			return '';
		}

		if (trim($text) === '') {
			// A scanned PDF has no text layer — that is the OCR's job, not ours.
			$this->logger->debug(
				'fulltextsearch_postgresql: ' . $extractor->getName() . ' found no text',
				['document' => $document->getId(), 'title' => $document->getTitle()]
			);
		}

		return $text;
	}

	/**
	 * The searchable text of every part (file comments, and whatever extensions add),
	 * flattened for the tsvector. The parts themselves are stored as-is, for retrieval.
	 */
	public function extractParts(IIndexDocument $document): string {
		$parts = [];
		foreach ($document->getParts() as $part) {
			if (is_string($part) && $part !== '' && !$this->looksBinary($part)) {
				$parts[] = $part;
			}
		}

		return $this->normalise(implode("\n", $parts));
	}

	/**
	 * Only for payloads no extractor claimed. A NUL byte is the reliable tell; beyond that,
	 * a scattering of control characters. Invalid UTF-8 is deliberately NOT a criterion —
	 * legacy single-byte text is still text, and normalise() recovers it. Only the head is
	 * inspected: these payloads can be megabytes.
	 */
	private function looksBinary(string $content): bool {
		$head = substr($content, 0, 4096);

		if (str_contains($head, "\0")) {
			return true;
		}

		$controls = preg_match_all('/[\x01-\x08\x0B\x0C\x0E-\x1F]/', $head);

		return $controls > max(8, strlen($head) / 100);
	}

	/**
	 * Drops invalid sequences and control characters, then truncates on a character
	 * boundary: an oversized document should be partially indexed, never rejected by the
	 * generated tsvector column.
	 */
	private function normalise(string $content): string {
		if (!mb_check_encoding($content, 'UTF-8')) {
			// Windows-1252 accepts any byte: this is the fallback that recovers old CSVs and
			// latin-1 text files instead of losing them.
			$content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
		}

		$content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
		$content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', ' ', $content) ?? $content;

		$entier = strlen($content);
		if ($entier > FtsRequest::MAX_CONTENT_SIZE) {
			$content = mb_strcut($content, 0, FtsRequest::MAX_CONTENT_SIZE, 'UTF-8');
			// Said out loud, because the document still turns up in results: it is searchable on
			// its first few megabytes and silent on the rest, which looks exactly like a search
			// that works until someone looks for a word near the end.
			$this->logger->warning(
				'fulltextsearch_postgresql: kept the first '
				. round(FtsRequest::MAX_CONTENT_SIZE / 1048576) . ' MiB of a document out of '
				. round($entier / 1048576, 1) . ' MiB; the rest is not searchable.'
			);
		}

		return trim($content);
	}
}
