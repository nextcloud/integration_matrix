<?php

/**
 * Nextcloud - Matrix
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <julien-nc@posteo.net>
 * @copyright Julien Veyssier 2022
 */

namespace OCA\Matrix\Controller;

use OCA\Matrix\AppInfo\Application;
use OCA\Matrix\Service\MatrixAPIService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\PreConditionNotMetException;
use OCP\Security\ICrypto;

class ConfigController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private IConfig $config,
		private IAppConfig $appConfig,
		private IL10N $l,
		private IInitialState $initialStateService,
		private ICrypto $crypto,
		private MatrixAPIService $matrixAPIService,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	public function isUserConnected(): DataResponse {
		$matrixUrl = $this->config->getUserValue($this->userId, Application::APP_ID, 'url');
		$token = $this->config->getUserValue($this->userId, Application::APP_ID, 'token');

		return new DataResponse([
			'connected' => $matrixUrl && $token,
			'url' => $matrixUrl,
		]);
	}

	/**
	 * Set config values
	 *
	 * @param array $values
	 * @return DataResponse
	 * @throws PreConditionNotMetException
	 */
	#[NoAdminRequired]
	public function setConfig(array $values): DataResponse {
		foreach ($values as $key => $value) {
			if ($key === 'token' && $value !== '') {
				$encryptedValue = $this->crypto->encrypt($value);
				$this->config->setUserValue($this->userId, Application::APP_ID, $key, $encryptedValue);
			} elseif ($key === 'token' && $value === '') {
				$this->config->deleteUserValue($this->userId, Application::APP_ID, 'token');
				$this->config->deleteUserValue($this->userId, Application::APP_ID, 'user_id');
				$this->config->deleteUserValue($this->userId, Application::APP_ID, 'user_name');
				$this->config->deleteUserValue($this->userId, Application::APP_ID, 'user_displayname');
			} else {
				$this->config->setUserValue($this->userId, Application::APP_ID, $key, $value);
			}
		}

		$result = [];
		if (isset($values['token']) && $values['token'] !== '') {
			$result = $this->storeUserInfo();
		}
		return new DataResponse($result);
	}

	/**
	 * Set admin config values
	 *
	 * @param array $values
	 * @return DataResponse
	 */
	public function setAdminConfig(array $values): DataResponse {
		foreach ($values as $key => $value) {
			$this->appConfig->setAppValueString($key, $value, lazy: true);
		}
		return new DataResponse([]);
	}

	/**
	 * @return array
	 * @throws PreConditionNotMetException
	 */
	private function storeUserInfo(): array {
		$info = $this->matrixAPIService->request($this->userId, 'account/whoami');
		if (isset($info['user_id'])) {
			$userId = $info['user_id'];
			$userName = substr($userId, 1);
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_id', $userId ?? '');
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_name', $userName ?? '');
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_displayname', $info['displayname'] ?? $userName);

			return [
				'user_id' => $userId ?? '',
				'user_name' => $userName ?? '',
				'user_displayname' => $info['displayname'] ?? $userName,
			];
		} else {
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_id', '');
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_name', '');
			return [
				'user_id' => '',
				'user_name' => '',
				'user_displayname' => '',
			];
		}
	}
}
