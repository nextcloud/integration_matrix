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
use OCP\Config\IUserConfig;
use OCP\Settings\ISettings;

class Personal implements ISettings {

	public function __construct(
		private IAppConfig $appConfig,
		private IUserConfig $userConfig,
		private IInitialState $initialStateService,
		private MatrixAPIService $matrixAPIService,
		private ?string $userId,
	) {
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm(): TemplateResponse {
		$token = $this->userConfig->getValueString($this->userId, Application::APP_ID, 'token');
		$fileActionEnabled = $this->userConfig->getValueString($this->userId, Application::APP_ID, 'file_action_enabled', '1') === '1';
		$matrixUserId = $this->userConfig->getValueString($this->userId, Application::APP_ID, 'user_id');
		$matrixUserName = $this->userConfig->getValueString($this->userId, Application::APP_ID, 'user_name');
		$matrixUserDisplayName = $this->userConfig->getValueString($this->userId, Application::APP_ID, 'user_displayname');
		$userAvatarSet = $this->userConfig->getValueString($this->userId, Application::APP_ID, 'user_avatar_url') !== '';
		$oauthUrl = $this->appConfig->getAppValueString('oauth_instance_url', lazy: true);
		$clientId = $this->appConfig->getAppValueString('client_id', lazy: true);
		$oauthApiUrl = $this->appConfig->getAppValueString('oauth_instance_api_url', lazy: true);
		$registeredClientApiUrl = $this->appConfig->getAppValueString('registered_client_api_url', lazy: true);
		$usePopup = $this->appConfig->getAppValueString('use_popup', '0', lazy: true) === '1';
		$url = $this->userConfig->getValueString($this->userId, Application::APP_ID, 'url');
		$oauthPossible = $oauthUrl !== ''
			&& $clientId !== ''
			&& $registeredClientApiUrl !== ''
			&& $registeredClientApiUrl === $oauthApiUrl;

		$userConfig = [
			'token' => $token !== '' ? 'dummyTokenContent' : '',
			'url' => $url,
			'oauth_instance_url' => $oauthUrl,
			'oauth_instance_api_url' => $oauthApiUrl,
			'oauth_possible' => $oauthPossible,
			'use_popup' => $usePopup,
			'user_id' => $matrixUserId,
			'user_name' => $matrixUserName,
			'user_displayname' => $matrixUserDisplayName,
			'user_avatar_set' => $userAvatarSet,
			'file_action_enabled' => $fileActionEnabled,
		];
		$this->initialStateService->provideInitialState('user-config', $userConfig);
		return new TemplateResponse(Application::APP_ID, 'personalSettings');
	}

	public function getSection(): string {
		return 'connected-accounts';
	}

	public function getPriority(): int {
		return 10;
	}
}
