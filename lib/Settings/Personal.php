<?php

namespace OCA\Matrix\Settings;

use OCA\Matrix\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\Security\ICrypto;
use OCP\Settings\ISettings;

class Personal implements ISettings {

	public function __construct(
		private IAppConfig $appConfig,
		private IConfig $config,
		private IInitialState $initialStateService,
		private ICrypto $crypto,
		private ?string $userId,
	) {
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm(): TemplateResponse {
		$token = $this->config->getUserValue($this->userId, Application::APP_ID, 'token');
		$token = $token === '' ? '' : $this->crypto->decrypt($token);
		$navlinkDefault = $this->appConfig->getAppValueString('navlink_default', '0', lazy: true);
		$navigationEnabled = $this->config->getUserValue($this->userId, Application::APP_ID, 'navigation_enabled', $navlinkDefault) === '1';
		$fileActionEnabled = $this->config->getUserValue($this->userId, Application::APP_ID, 'file_action_enabled', '1') === '1';
		$matrixUserId = $this->config->getUserValue($this->userId, Application::APP_ID, 'user_id');
		$matrixUserName = $this->config->getUserValue($this->userId, Application::APP_ID, 'user_name');
		$matrixUserDisplayName = $this->config->getUserValue($this->userId, Application::APP_ID, 'user_displayname');
		$oauthUrl = $this->appConfig->getAppValueString('oauth_instance_url', lazy: true);
		$clientId = $this->appConfig->getAppValueString('client_id', lazy: true);
		$registeredClientUrl = $this->appConfig->getAppValueString('registered_client_url', lazy: true);
		$usePopup = $this->appConfig->getAppValueString('use_popup', '0', lazy: true) === '1';
		$url = $this->config->getUserValue($this->userId, Application::APP_ID, 'url');
		$oauthConfigured = $oauthUrl !== '' && $clientId !== '' && $this->isAdminOauthClientCompatible($oauthUrl, $registeredClientUrl);
		$oauthBlockedByUserUrl = $oauthConfigured && $url !== '' && !$this->sameHomeserverUrl($url, $oauthUrl);

		$userConfig = [
			'token' => $token !== '' ? 'dummyTokenContent' : '',
			'url' => $url,
			'oauth_instance_url' => $oauthUrl,
			'oauth_configured' => $oauthConfigured,
			'oauth_possible' => $oauthConfigured && !$oauthBlockedByUserUrl,
			'oauth_blocked_by_user_url' => $oauthBlockedByUserUrl,
			'use_popup' => $usePopup,
			'user_id' => $matrixUserId,
			'user_name' => $matrixUserName,
			'user_displayname' => $matrixUserDisplayName,
			'navigation_enabled' => $navigationEnabled,
			'file_action_enabled' => $fileActionEnabled,
		];
		$this->initialStateService->provideInitialState('user-config', $userConfig);
		return new TemplateResponse(Application::APP_ID, 'personalSettings');
	}

	private function normalizeHomeserverUrl(string $url): string {
		$url = trim($url);
		return $url === '' ? '' : rtrim($url, '/');
	}

	private function sameHomeserverUrl(string $left, string $right): bool {
		return $this->normalizeHomeserverUrl($left) === $this->normalizeHomeserverUrl($right);
	}

	private function isAdminOauthClientCompatible(string $adminOauthUrl, string $registeredClientUrl): bool {
		$registeredClientUrl = $this->normalizeHomeserverUrl($registeredClientUrl);
		if ($registeredClientUrl === '') {
			return true;
		}

		return $registeredClientUrl === $this->normalizeHomeserverUrl($adminOauthUrl);
	}

	public function getSection(): string {
		return 'connected-accounts';
	}

	public function getPriority(): int {
		return 10;
	}
}
