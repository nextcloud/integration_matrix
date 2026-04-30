<?php

/*
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Matrix\AppInfo;

use Closure;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Config\IUserConfig;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUserSession;
use OCP\Util;

class Application extends App implements IBootstrap {
	public const APP_ID = 'integration_matrix';

	public const INTEGRATION_USER_AGENT = 'Nextcloud Matrix integration';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
	}

	public function boot(IBootContext $context): void {
		$context->injectFn(Closure::fromCallable([$this, 'loadFilesPlugin']));
	}

	public function loadFilesPlugin(
		IUserSession $userSession,
		IEventDispatcher $eventDispatcher,
		IUserConfig $userConfig,
	): void {
		$user = $userSession->getUser();
		if ($user !== null) {
			$userId = $user->getUID();
			if ($userConfig->getValueString($userId, self::APP_ID, 'file_action_enabled', '1') === '1') {
				$eventDispatcher->addListener(LoadAdditionalScriptsEvent::class, function () {
					Util::addInitScript(self::APP_ID, self::APP_ID . '-filesplugin');
				});
			}
		}
	}
}
