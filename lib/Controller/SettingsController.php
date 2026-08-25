<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alain Lesage
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FullTextSearch_PostgreSQL\Controller;

use OCA\FullTextSearch_PostgreSQL\ConfigLexicon;
use OCA\FullTextSearch_PostgreSQL\Db\FtsRequest;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IAppConfig;
use OCP\IRequest;
use OCA\FullTextSearch_PostgreSQL\Settings\Admin;

class SettingsController extends OCSController {
	public function __construct(
		IRequest $request,
		private IAppConfig $appConfig,
		private FtsRequest $ftsRequest,
	) {
		parent::__construct('fulltextsearch_postgresql', $request);
	}

	/**
	 * Stores the indexing language, or languages.
	 *
	 * The value is taken as it comes: FtsRequest is the one that validates it, drops what it
	 * does not know and truncates past the cap. The response returns what will actually be
	 * used, so that the panel shows the truth rather than the input.
	 */
	#[AuthorizedAdminSetting(settings: Admin::class)]
	public function setLanguages(string $languages): DataResponse {
		$avant = $this->ftsRequest->languages();

		$this->appConfig->setValueString(
			'fulltextsearch_postgresql',
			ConfigLexicon::SEARCH_LANGUAGE,
			trim($languages) === '' ? ConfigLexicon::AUTO : trim($languages),
			lazy: true
		);

		$apres = $this->ftsRequest->languages();

		// Without this, search would fail until the next reindexing: the configuration
		// derived from the new language would not exist yet.
		$this->ftsRequest->ensureTextSearchConfigurations();

		return new DataResponse([
			'effective' => $apres,
			// Changing the language forces an index rebuild: the panel only says so in
			// that case, rather than displaying it permanently.
			'rebuilt' => $avant !== $apres,
		]);
	}
}
