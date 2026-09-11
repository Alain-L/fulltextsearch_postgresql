<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alain Lesage
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FullTextSearch_PostgreSQL\Service\Extractor;

use OCP\IBinaryFinder;
use Psr\Log\LoggerInterface;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Reads the text layer of a PDF, by the best means available.
 *
 * A PDF is not a text container: streams are compressed, glyphs go through font encodings
 * and ToUnicode tables. Two engines, in this order:
 *
 *  1. **pdftotext** (poppler), when installed: 3.8 times faster at extraction, and one more
 *     PDF read out of the 28 in the test corpus. Quality is on par — 0.9% more lexemes
 *     indexed, which nobody notices in practice.
 *  2. **smalot/pdfparser** (LGPL-3.0, bundled) otherwise — because poppler is absent from
 *     the official Nextcloud image, from the snap and from AIO. Without that net, the app
 *     would read no PDF at all on most installations.
 *
 * A homegrown extractor was tried and dropped: 2 usable PDFs out of 53, and binary
 * gibberish for the rest.
 */
class PdfExtractor implements ITextExtractor {
	private const SIGNATURE = '%PDF-';

	/** Past this we give up: a several-hundred-page PDF has no business stalling indexing. */
	private const MAX_PAGES = 200;
	private const TIMEOUT_SECONDS = 30;

	/**
	 * What the bundled parser is allowed to be handed, and how much room it is assumed to need.
	 *
	 * pdftotext is a separate process: it can fail, it cannot exhaust PHP's memory. The bundled
	 * parser runs in-process and decompresses streams as it goes, and a PHP fatal error is not
	 * catchable — no try/catch, nothing runs afterwards, the whole indexing run is over. Seen on
	 * a real corpus: 512M exhausted on one PDF, 796 documents in, and a stale “running” lock
	 * left behind for half an hour.
	 *
	 * So the defence has to be taken before the call. Neither number can be exact — a PDF's
	 * compressed streams say little about what they expand to — which is why both are used:
	 * refuse what is plainly too big, and refuse anything for which the remaining headroom
	 * looks thin. Skipping a document costs its content; getting this wrong costs the run.
	 */
	private const MAX_PARSER_INPUT = 8388608;
	private const PARSER_HEADROOM_FACTOR = 12;

	private ?string $poppler = null;
	private bool $popplerLookedUp = false;

	public function __construct(
		private IBinaryFinder $binaryFinder,
		private LoggerInterface $logger,
	) {
	}

	public function getName(): string {
		return 'pdf';
	}

	public function supports(string $content): bool {
		// The spec tolerates a bit of noise before the signature.
		return str_contains(substr($content, 0, 1024), self::SIGNATURE);
	}

	/**
	 * The engine actually in use, so that `occ fulltextsearch:check` shows it.
	 */
	public function engine(): string {
		return $this->popplerBinary() !== null ? 'pdftotext (poppler)' : 'smalot/pdfparser';
	}

	public function extract(string $content): string {
		$binary = $this->popplerBinary();
		if ($binary !== null) {
			$text = $this->viaPoppler($binary, $content);
			if ($text !== null) {
				return $text;
			}
			// Poppler failed on this document: the library may have better luck.
			$this->logger->debug('fulltextsearch_postgresql: pdftotext failed, falling back to pdfparser');
		}

		$refus = $this->whyParserCannotRun($content);
		if ($refus !== null) {
			$this->logger->warning(
				'fulltextsearch_postgresql: skipped the text of a PDF rather than risk the '
				. 'indexing run — ' . $refus . '. Install pdftotext (poppler-utils): it runs as '
				. 'a separate process and has no such limit. The document is still indexed on '
				. 'its title.'
			);

			return '';
		}

		return (new Parser())->parseContent($content)->getText();
	}

	/**
	 * Why the bundled parser must not be handed this document, or null when it may be.
	 */
	private function whyParserCannotRun(string $content): ?string {
		$taille = strlen($content);
		if ($taille > self::MAX_PARSER_INPUT) {
			return 'it is ' . round($taille / 1048576, 1) . ' MiB, over the '
				. round(self::MAX_PARSER_INPUT / 1048576) . ' MiB the bundled parser is trusted with';
		}

		$limite = $this->memoryLimit();
		if ($limite === null) {
			return null;
		}

		$marge = $limite - memory_get_usage(true);
		$besoin = $taille * self::PARSER_HEADROOM_FACTOR;
		if ($marge < $besoin) {
			return 'only ' . round($marge / 1048576) . ' MiB of PHP memory is left, and the '
				. 'bundled parser may want around ' . round($besoin / 1048576) . ' MiB for it';
		}

		return null;
	}

	/**
	 * The PHP memory ceiling in bytes, or null when there is none.
	 */
	private function memoryLimit(): ?int {
		$brut = trim((string)ini_get('memory_limit'));
		if ($brut === '' || $brut === '-1') {
			return null;
		}

		$unite = strtolower(substr($brut, -1));
		$valeur = (int)$brut;

		return match ($unite) {
			'g' => $valeur * 1073741824,
			'm' => $valeur * 1048576,
			'k' => $valeur * 1024,
			default => $valeur,
		};
	}

	/**
	 * Looked up once per instance: findBinaryPath() hits the filesystem.
	 */
	private function popplerBinary(): ?string {
		if ($this->popplerLookedUp) {
			return $this->poppler;
		}

		$this->popplerLookedUp = true;

		if (!function_exists('proc_open')) {
			// Common on hardened shared hosting.
			return $this->poppler = null;
		}

		$path = $this->binaryFinder->findBinaryPath('pdftotext');

		return $this->poppler = is_string($path) ? $path : null;
	}

	/**
	 * Calls pdftotext on a temporary file, without going through a shell.
	 *
	 * Standard input would be more elegant, but it deadlocks: writing a PDF of several
	 * megabytes fills the input pipe while pdftotext, for its part, waits for its output pipe
	 * to be drained. The two processes block each other. A temporary file costs one disk write
	 * and makes the problem go away.
	 *
	 * @return string|null null when the call failed, to give the second engine its chance
	 */
	private function viaPoppler(string $binary, string $content): ?string {
		$handle = tmpfile();
		if ($handle === false) {
			return null;
		}

		$path = stream_get_meta_data($handle)['uri'];
		fwrite($handle, $content);

		$command = [$binary, '-q', '-enc', 'UTF-8', '-l', (string)self::MAX_PAGES, $path, '-'];
		$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

		$process = @proc_open($command, $descriptors, $pipes);
		if (!is_resource($process)) {
			fclose($handle);

			return null;
		}

		$debut = time();
		$text = '';
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		try {
			while (true) {
				$text .= (string)stream_get_contents($pipes[1]);
				stream_get_contents($pipes[2]);   // drained, or pdftotext blocks on it

				$etat = proc_get_status($process);
				if (!$etat['running']) {
					$text .= (string)stream_get_contents($pipes[1]);
					break;
				}

				if (time() - $debut > self::TIMEOUT_SECONDS) {
					// proc_close() would wait forever on a process that is still alive.
					proc_terminate($process, 9);
					$this->logger->warning('fulltextsearch_postgresql: pdftotext timed out, aborted');

					return null;
				}

				usleep(10000);
			}

			$code = $etat['exitcode'];
		} finally {
			foreach ($pipes as $pipe) {
				if (is_resource($pipe)) {
					fclose($pipe);
				}
			}
			proc_close($process);
			fclose($handle);
		}

		// Poppler returns 0 on success; 1 signals an unreadable or encrypted file.
		return ($code === 0 && $text !== '') ? $text : null;
	}
}
