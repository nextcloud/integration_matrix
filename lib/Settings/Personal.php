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
		private IUserConfig $config,
		private IInitialState $initialStateService,
		private MatrixAPIService $matrixAPIService,
		private ?string $userId,
	) {
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm(): TemplateResponse {
		$token = $this->config->getValueString($this->userId, Application::APP_ID, 'token');
		$navlinkDefault = $this->appConfig->getAppValueString('navlink_default', '0', lazy: true);
		$navigationEnabled = $this->config->getValueString($this->userId, Application::APP_ID, 'navigation_enabled', $navlinkDefault) === '1';
		$fileActionEnabled = $this->config->getValueString($this->userId, Application::APP_ID, 'file_action_enabled', '1') === '1';
		$matrixUserId = $this->config->getValueString($this->userId, Application::APP_ID, 'user_id');
		$matrixUserName = $this->config->getValueString($this->userId, Application::APP_ID, 'user_name');
		$matrixUserDisplayName = $this->config->getValueString($this->userId, Application::APP_ID, 'user_displayname');
		$userAvatarSet = $this->config->getValueString($this->userId, Application::APP_ID, 'user_avatar_url') !== '';
		$oauthUrl = $this->appConfig->getAppValueString('oauth_instance_url', lazy: true);
		$oauthApiUrl = $oauthUrl !== '' ? $this->matrixAPIService->resolveMatrixUrl($oauthUrl) : '';
		$clientId = $this->appConfig->getAppValueString('client_id', lazy: true);
		$registeredClientUrl = $this->appConfig->getAppValueString('registered_client_url', lazy: true);
		$usePopup = $this->appConfig->getAppValueString('use_popup', '0', lazy: true) === '1';
		$url = $this->config->getValueString($this->userId, Application::APP_ID, 'url');
		$oauthConfigured = $oauthUrl !== '' && $clientId !== '' && $this->isAdminOauthClientCompatible($oauthUrl, $registeredClientUrl);
		$oauthBlockedByUserUrl = $oauthConfigured && $url !== '' && !$this->matrixAPIService->sameMatrixServer($url, $oauthUrl);

		$userConfig = [
			'token' => $token !== '' ? 'dummyTokenContent' : '',
			'url' => $url,
			'oauth_instance_url' => $oauthUrl,
			'oauth_instance_api_url' => $oauthApiUrl,
			'oauth_configured' => $oauthConfigured,
			'oauth_possible' => $oauthConfigured && !$oauthBlockedByUserUrl,
			'oauth_blocked_by_user_url' => $oauthBlockedByUserUrl,
			'use_popup' => $usePopup,
			'user_id' => $matrixUserId,
			'user_name' => $matrixUserName,
			'user_displayname' => $matrixUserDisplayName,
			'user_avatar_set' => $userAvatarSet,
			'navigation_enabled' => $navigationEnabled,
			'file_action_enabled' => $fileActionEnabled,
		];
		$this->initialStateService->provideInitialState('user-config', $userConfig);
		return new TemplateResponse(Application::APP_ID, 'personalSettings');
	}

	private function isAdminOauthClientCompatible(string $adminOauthUrl, string $registeredClientUrl): bool {
		if ($registeredClientUrl === '') {
			return true;
		}

		return $this->matrixAPIService->sameMatrixServer($registeredClientUrl, $adminOauthUrl);
	}

	public function getSection(): string {
		return 'connected-accounts';
	}

	public function getPriority(): int {
		return 10;
	}
}
