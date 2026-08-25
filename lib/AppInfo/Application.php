<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alain Lesage
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FullTextSearch_PostgreSQL\AppInfo;

// Nextcloud only autoloads an app's own lib/ (OC_App::registerAutoloading). Vendored
// dependencies are the app's business — every app in the ecosystem does exactly this.
require_once __DIR__ . '/../../vendor/autoload.php';

use OCA\FullTextSearch_PostgreSQL\ConfigLexicon;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * The platform itself is registered declaratively, through the <fulltextsearch><platform>
 * tag of appinfo/info.xml — there is nothing to wire up here.
 */
class Application extends App implements IBootstrap {
	public const APP_ID = 'fulltextsearch_postgresql';

	public function __construct(array $params = []) {
		parent::__construct(self::APP_ID, $params);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerConfigLexicon(ConfigLexicon::class);
	}

	public function boot(IBootContext $context): void {
	}
}
