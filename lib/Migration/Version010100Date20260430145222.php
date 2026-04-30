<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Matrix\Migration;

use Closure;
use OCA\Matrix\AppInfo\Application;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version010100Date20260430145222 extends SimpleMigrationStep {

	public function __construct(
		private IUserConfig $userConfig,
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options) {
		$this->userConfig->deleteApp(Application::APP_ID);
		foreach ([
			'oauth_instance_url',
			'oauth_instance_api_url',
			'registered_client_url',
			'registered_client_api_url',
			'client_id',
			'client_secret',
		] as $key) {
			$this->appConfig->deleteKey(Application::APP_ID, $key);
		}
	}
}
