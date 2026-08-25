<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alain Lesage
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FullTextSearch_PostgreSQL\Settings;

use OCA\FullTextSearch_PostgreSQL\ConfigLexicon;
use OCA\FullTextSearch_PostgreSQL\Db\FtsRequest;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * The admin panel, in the "Full text search" section, below the framework's own.
 *
 * It exposes a single setting — there is no server to reach and no index to name — but it does
 * it properly: the languages on offer are the ones actually installed on this PostgreSQL
 * server, read from `pg_ts_config`.
 */
class Admin implements ISettings {
	public function __construct(
		private FtsRequest $request,
		private IAppConfig $appConfig,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getForm(): TemplateResponse {
		$disponibles = [];
		$actuelles = [];

		try {
			$disponibles = $this->request->availableLanguages();
			$actuelles = $this->request->languages();
		} catch (\Throwable) {
			// Database unreachable or not PostgreSQL: the panel will say so rather than go blank.
		}

		return new TemplateResponse('fulltextsearch_postgresql', 'settings-admin', [
			'available' => $disponibles,
			'selected' => $actuelles,
			'raw' => $this->appConfig->getValueString(
				'fulltextsearch_postgresql',
				ConfigLexicon::SEARCH_LANGUAGE,
				ConfigLexicon::AUTO,
				lazy: true
			),
			'max' => ConfigLexicon::RECOMMENDED_MAX_LANGUAGES,
			// The URL is computed here rather than guessed by the script: nothing guarantees
			// that a global such as OC.linkToOCS is available by the time it runs.
			'saveUrl' => $this->urlGenerator->linkToOCSRouteAbsolute(
				'fulltextsearch_postgresql.Settings.setLanguages'
			),
		]);
	}

	public function getSection(): string {
		return 'fulltextsearch';
	}

	/** Right after the framework's own panel (30) and those of the other platforms (31). */
	public function getPriority(): int {
		return 32;
	}
}
