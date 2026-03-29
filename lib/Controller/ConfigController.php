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

use DateTime;
use OCA\Matrix\AppInfo\Application;
use OCA\Matrix\Service\MatrixAPIService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\PreConditionNotMetException;
use OCP\Security\ICrypto;

class ConfigController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private IConfig $config,
		private IAppConfig $appConfig,
		private IURLGenerator $urlGenerator,
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
		$adminOauthUrl = $this->appConfig->getAppValueString('oauth_instance_url', lazy: true);
		$matrixUrl = $this->config->getUserValue($this->userId, Application::APP_ID, 'url', $adminOauthUrl) ?: $adminOauthUrl;
		$token = $this->config->getUserValue($this->userId, Application::APP_ID, 'token');

		$clientID = $this->appConfig->getAppValueString('client_id', lazy: true);
		$clientSecret = $this->appConfig->getAppValueString('client_secret', lazy: true);
		$oauthPossible = $clientID !== '' && $clientSecret !== '' && $matrixUrl === $adminOauthUrl;
		$usePopup = $this->appConfig->getAppValueString('use_popup', '0', lazy: true);

		return new DataResponse([
			'connected' => $matrixUrl && $token,
			'oauth_possible' => $oauthPossible,
			'use_popup' => ($usePopup === '1'),
			'url' => $matrixUrl,
			'client_id' => $clientID,
		]);
	}

	/**
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	public function getFilesToSend(): DataResponse {
		$adminOauthUrl = $this->appConfig->getAppValueString('oauth_instance_url', lazy: true);
		$matrixUrl = $this->config->getUserValue($this->userId, Application::APP_ID, 'url', $adminOauthUrl) ?: $adminOauthUrl;
		$token = $this->config->getUserValue($this->userId, Application::APP_ID, 'token');
		$isConnected = $matrixUrl && $token;

		if ($isConnected) {
			$fileIdsToSendAfterOAuth = $this->config->getUserValue($this->userId, Application::APP_ID, 'file_ids_to_send_after_oauth');
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'file_ids_to_send_after_oauth');
			$currentDirAfterOAuth = $this->config->getUserValue($this->userId, Application::APP_ID, 'current_dir_after_oauth');
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'current_dir_after_oauth');

			return new DataResponse([
				'file_ids_to_send_after_oauth' => $fileIdsToSendAfterOAuth,
				'current_dir_after_oauth' => $currentDirAfterOAuth,
			]);
		}
		return new DataResponse(['message' => 'Not connected']);
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
			if (in_array($key, ['url', 'token'], true)) {
				return new DataResponse([], Http::STATUS_BAD_REQUEST);
			}
			$this->config->setUserValue($this->userId, Application::APP_ID, $key, $value);
		}
		return new DataResponse([]);
	}

	/**
	 * Set sensitive config values
	 *
	 * @param array $values
	 * @return DataResponse
	 * @throws PreConditionNotMetException
	 */
	#[NoAdminRequired]
	#[PasswordConfirmationRequired]
	public function setSensitiveConfig(array $values): DataResponse {
		foreach ($values as $key => $value) {
			if ($key === 'token' && $value !== '') {
				$encryptedValue = $this->crypto->encrypt($value);
				$this->config->setUserValue($this->userId, Application::APP_ID, $key, $encryptedValue);
			} else {
				$this->config->setUserValue($this->userId, Application::APP_ID, $key, $value);
			}
		}
		$result = [];

		if (isset($values['token'])) {
			if ($values['token'] && $values['token'] !== '') {
				$result = $this->storeUserInfo();
			} else {
				$this->config->deleteUserValue($this->userId, Application::APP_ID, 'user_id');
				$this->config->deleteUserValue($this->userId, Application::APP_ID, 'user_name');
				$this->config->deleteUserValue($this->userId, Application::APP_ID, 'user_displayname');
				$this->config->deleteUserValue($this->userId, Application::APP_ID, 'token');
				$result['user_id'] = '';
				$result['user_name'] = '';
				$result['user_displayname'] = '';
			}
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'refresh_token');
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'token_expires_at');
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
			if (in_array($key, ['client_id', 'client_secret', 'oauth_instance_url'], true)) {
				return new DataResponse([], Http::STATUS_BAD_REQUEST);
			}
			$this->appConfig->setAppValueString($key, $value, lazy: true);
		}
		return new DataResponse([]);
	}

	/**
	 * Set sensitive admin config values
	 *
	 * @param array $values
	 * @return DataResponse
	 */
	#[PasswordConfirmationRequired]
	public function setSensitiveAdminConfig(array $values): DataResponse {
		foreach ($values as $key => $value) {
			if (in_array($key, ['client_id', 'client_secret'], true) && $value !== '') {
				$this->appConfig->setAppValueString($key, $value, sensitive: true, lazy: true);
			} else {
				$this->appConfig->setAppValueString($key, $value, lazy: true);
			}
		}
		return new DataResponse([]);
	}

	/**
	 * @param string $user_name
	 * @param string $user_displayname
	 * @return TemplateResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function popupSuccessPage(string $user_name, string $user_displayname): TemplateResponse {
		$this->initialStateService->provideInitialState('popup-data', ['user_name' => $user_name, 'user_displayname' => $user_displayname]);
		return new TemplateResponse(Application::APP_ID, 'popupSuccess', [], TemplateResponse::RENDER_AS_GUEST);
	}

	/**
	 * receive oauth code and get oauth access token
	 * Matrix OAuth2 authorization code flow
	 *
	 * @param string $code
	 * @param string $state
	 * @return RedirectResponse
	 * @throws PreConditionNotMetException
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function oauthRedirect(string $code = '', string $state = ''): RedirectResponse {
		$configState = $this->config->getUserValue($this->userId, Application::APP_ID, 'oauth_state');
		$clientID = $this->appConfig->getAppValueString('client_id', lazy: true);
		$clientSecret = $this->appConfig->getAppValueString('client_secret', lazy: true);

		$this->config->deleteUserValue($this->userId, Application::APP_ID, 'oauth_state');

		$adminOauthUrl = $this->appConfig->getAppValueString('oauth_instance_url', lazy: true);
		$matrixUrl = $this->config->getUserValue($this->userId, Application::APP_ID, 'url', $adminOauthUrl) ?: $adminOauthUrl;

		if ($matrixUrl !== $adminOauthUrl) {
			$result = $this->l->t('The instance URL does not match the one currently configured for OAuth authentication');
		} elseif ($clientID && $clientSecret && $configState !== '' && $configState === $state) {
			$redirect_uri = $this->config->getUserValue($this->userId, Application::APP_ID, 'redirect_uri');
			$result = $this->matrixAPIService->requestOAuthAccessToken($matrixUrl, [
				'client_id' => $clientID,
				'client_secret' => $clientSecret,
				'code' => $code,
				'redirect_uri' => $redirect_uri,
				'grant_type' => 'authorization_code',
			], 'POST');
			if (isset($result['access_token'])) {
				$accessToken = $result['access_token'];
				$refreshToken = $result['refresh_token'] ?? '';
				if (isset($result['expires_in'])) {
					$nowTs = (new DateTime())->getTimestamp();
					$expiresAt = $nowTs + (int)$result['expires_in'];
					$this->config->setUserValue($this->userId, Application::APP_ID, 'token_expires_at', strval($expiresAt));
				}
				$encryptedToken = $accessToken === '' ? '' : $this->crypto->encrypt($accessToken);
				$this->config->setUserValue($this->userId, Application::APP_ID, 'token', $encryptedToken);
				$encryptedRefreshToken = $refreshToken === '' ? '' : $this->crypto->encrypt($refreshToken);
				$this->config->setUserValue($this->userId, Application::APP_ID, 'refresh_token', $encryptedRefreshToken);
				$userInfo = $this->storeUserInfo();
				$usePopup = $this->appConfig->getAppValueString('use_popup', '0', lazy: true) === '1';
				if ($usePopup) {
					return new RedirectResponse(
						$this->urlGenerator->linkToRoute('integration_matrix.config.popupSuccessPage', [
							'user_name' => $userInfo['user_name'] ?? '',
							'user_displayname' => $userInfo['user_displayname'] ?? '',
						])
					);
				} else {
					$oauthOrigin = $this->config->getUserValue($this->userId, Application::APP_ID, 'oauth_origin');
					$this->config->deleteUserValue($this->userId, Application::APP_ID, 'oauth_origin');
					if ($oauthOrigin === 'settings') {
						return new RedirectResponse(
							$this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'connected-accounts'])
							. '?matrixToken=success'
						);
					} elseif (preg_match('/^files--.*/', $oauthOrigin)) {
						$parts = explode('--', $oauthOrigin);
						if (count($parts) > 1) {
							$path = $parts[1];
							if (count($parts) > 2) {
								$this->config->setUserValue($this->userId, Application::APP_ID, 'file_ids_to_send_after_oauth', $parts[2]);
								$this->config->setUserValue($this->userId, Application::APP_ID, 'current_dir_after_oauth', $path);
							}
							return new RedirectResponse(
								$this->urlGenerator->linkToRoute('files.view.index', ['dir' => $path])
							);
						}
					}
				}
			}
			$result = $this->l->t('Error getting OAuth access token. ' . ($result['error'] ?? 'Unknown error'));
		} else {
			$result = $this->l->t('Error during OAuth exchanges');
		}
		return new RedirectResponse(
			$this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'connected-accounts'])
			. '?matrixToken=error&message=' . urlencode($result)
		);
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
