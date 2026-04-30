<?php

/*
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Matrix\Settings;

use OCA\Matrix\AppInfo\Application;
use OCA\Matrix\Service\MatrixAPIService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;

class Admin implements ISettings {

	public function __construct(
		private IAppConfig $appConfig,
		private IInitialState $initialStateService,
		private MatrixAPIService $matrixAPIService,
	) {
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm(): TemplateResponse {
		$clientId = $this->appConfig->getAppValueString('client_id', lazy: true);
		$clientSecret = $this->appConfig->getAppValueString('client_secret', lazy: true);
		$oauthUrl = $this->appConfig->getAppValueString('oauth_instance_url', lazy: true);
		$oauthApiUrl = $this->appConfig->getAppValueString('oauth_instance_api_url', lazy: true);
		$registeredClientApiUrl = $this->appConfig->getAppValueString('registered_client_api_url', lazy: true);
		$usePopup = $this->appConfig->getAppValueString('use_popup', '0', lazy: true);

		$adminConfig = [
			'client_id' => $clientId,
			'client_secret' => $clientSecret !== '' ? 'dummySecret' : '',
			'oauth_instance_url' => $oauthUrl,
			'oauth_instance_api_url' => $oauthApiUrl,
			'registered_client_api_url' => $registeredClientApiUrl,
			'use_popup' => $usePopup === '1',
		];
		$this->initialStateService->provideInitialState('admin-config', $adminConfig);
		return new TemplateResponse(Application::APP_ID, 'adminSettings');
	}

	public function getSection(): string {
		return 'connected-accounts';
	}

	public function getPriority(): int {
		return 10;
	}
}
