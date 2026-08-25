<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alain Lesage
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Minimal stand-ins for the Nextcloud and PSR interfaces, so the extractors can be exercised
 * without installing Nextcloud. Only loaded when the real ones are missing.
 */

if (!interface_exists('OCP\IBinaryFinder')) {
	eval('namespace OCP; interface IBinaryFinder { public function findBinaryPath(string $program); }');
}

if (!interface_exists('Psr\Log\LoggerInterface')) {
	eval('namespace Psr\Log; interface LoggerInterface {
		public function emergency($m, array $c = []): void; public function alert($m, array $c = []): void;
		public function critical($m, array $c = []): void;  public function error($m, array $c = []): void;
		public function warning($m, array $c = []): void;   public function notice($m, array $c = []): void;
		public function info($m, array $c = []): void;      public function debug($m, array $c = []): void;
		public function log($l, $m, array $c = []): void; }');
}

/** Silent logger. */
class LoggerMuet implements Psr\Log\LoggerInterface {
	public function emergency($m, array $c = []): void {}
	public function alert($m, array $c = []): void {}
	public function critical($m, array $c = []): void {}
	public function error($m, array $c = []): void {}
	public function warning($m, array $c = []): void {}
	public function notice($m, array $c = []): void {}
	public function info($m, array $c = []): void {}
	public function debug($m, array $c = []): void {}
	public function log($l, $m, array $c = []): void {}
}

/** Simulates the presence — or the absence — of a system binary. */
class BinaryFinderStub implements OCP\IBinaryFinder {
	public function __construct(private bool $disponible) {
	}

	public function findBinaryPath(string $program) {
		if (!$this->disponible) {
			return false;
		}
		$path = trim((string)shell_exec('command -v ' . escapeshellarg($program) . ' 2>/dev/null'));

		return $path !== '' ? $path : false;
	}
}
