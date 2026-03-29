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
		$url = $this->config->getUserValue($this->userId, Application::APP_ID, 'url');

		$userConfig = [
			'token' => $token !== '' ? 'dummyTokenContent' : '',
			'url' => $url,
			'user_id' => $matrixUserId,
			'user_name' => $matrixUserName,
			'user_displayname' => $matrixUserDisplayName,
			'navigation_enabled' => $navigationEnabled,
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
