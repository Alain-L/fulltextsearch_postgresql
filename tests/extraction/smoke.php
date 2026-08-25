<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alain Lesage
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Exercises the extractors against fixtures built on the fly.
 *
 * Why it exists: the upstream files_fulltextsearch_tesseract app shipped a broken PDF OCR for
 * several releases running, because its vendored dependency had changed API and the error was
 * being swallowed. A test that really calls the library catches that on the first `composer
 * update`. Depends on neither Nextcloud nor a corpus.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/stubs.php';

use OCA\FullTextSearch_PostgreSQL\Service\Extractor\ITextExtractor;
use OCA\FullTextSearch_PostgreSQL\Service\Extractor\OfficeExtractor;
use OCA\FullTextSearch_PostgreSQL\Service\Extractor\PdfExtractor;

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void {
	global $failures;
	if (!$ok) {
		$failures++;
	}
	printf("  %s %s%s\n", $ok ? '✓' : '✗', $label, $detail === '' ? '' : " — $detail");
}

/** A minimal .docx, but a genuine one: a real ZIP holding a real word/document.xml. */
function makeDocx(string $text): string {
	$path = tempnam(sys_get_temp_dir(), 'fts') ?: '';
	$zip = new ZipArchive();
	$zip->open($path, ZipArchive::OVERWRITE);
	$zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types/>');
	$zip->addFromString(
		'word/document.xml',
		'<?xml version="1.0"?><w:document xmlns:w="x"><w:body><w:p><w:r><w:t>'
		. htmlspecialchars($text, ENT_XML1) . '</w:t></w:r></w:p></w:body></w:document>'
	);
	$zip->close();
	$content = (string)file_get_contents($path);
	unlink($path);

	return $content;
}

/** A minimal PDF with an uncompressed content stream. */
function makePdf(string $text): string {
	$stream = "BT /F1 24 Tf 72 700 Td (" . $text . ") Tj ET";
	$objects = [
		"<</Type/Catalog/Pages 2 0 R>>",
		"<</Type/Pages/Kids[3 0 R]/Count 1>>",
		"<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Contents 4 0 R"
			. "/Resources<</Font<</F1 5 0 R>>>>>>",
		"<</Length " . strlen($stream) . ">>\nstream\n" . $stream . "\nendstream",
		"<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>",
	];

	$pdf = "%PDF-1.4\n";
	$offsets = [];
	foreach ($objects as $i => $object) {
		$offsets[] = strlen($pdf);
		$pdf .= ($i + 1) . " 0 obj\n" . $object . "\nendobj\n";
	}

	$xref = strlen($pdf);
	$pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
	foreach ($offsets as $offset) {
		$pdf .= sprintf("%010d 00000 n \n", $offset);
	}
	$pdf .= "trailer\n<</Size " . (count($objects) + 1) . "/Root 1 0 R>>\nstartxref\n"
		. $xref . "\n%%EOF\n";

	return $pdf;
}

/** @param ITextExtractor $extractor */
function exercise(ITextExtractor $extractor, string $payload, string $needle): void {
	$name = $extractor->getName();

	check("$name: recognizes its signature", $extractor->supports($payload));
	check("$name: ignores plain text", !$extractor->supports('bonjour, ceci est du texte'));

	try {
		$text = $extractor->extract($payload);
	} catch (Throwable $t) {
		// This is where an API break in the vendored library shows up.
		check("$name: extracts without error", false, get_class($t) . ': ' . $t->getMessage());

		return;
	}

	check("$name: extracts without error", true);
	check(
		"$name: finds '$needle'",
		str_contains(mb_strtolower($text), mb_strtolower($needle)),
		'text obtained: ' . substr(trim(preg_replace('/\s+/u', ' ', $text) ?? ''), 0, 60)
	);
}

echo "\n=== Extraction ===\n";
exercise(new OfficeExtractor(), makeDocx('réfection du chauffage collectif'), 'réfection');

// Both PDF paths get exercised: with poppler when it is there, and without — because the
// fallback is what makes the app work on the official Nextcloud image, which has no pdftotext.
foreach ([true => 'with poppler if present', false => 'no poppler (pdfparser fallback)'] as $dispo => $libelle) {
	$extractor = new PdfExtractor(new BinaryFinderStub((bool)$dispo), new LoggerMuet());
	echo "  — $libelle: engine '" . $extractor->engine() . "'\n";
	exercise($extractor, makePdf('refection du chauffage'), 'refection');
}

echo "\n";
if ($failures > 0) {
	echo "$failures check(s) failed\n";
	exit(1);
}
echo "all green\n";
