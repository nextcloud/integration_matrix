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
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Services\IInitialState;
use OCP\Config\IUserConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\PreConditionNotMetException;
use OCP\Security\ICrypto;

class ConfigController extends Controller {

	private const MATRIX_OAUTH_API_SCOPE = 'urn:matrix:client:api:*';
	private const MATRIX_OAUTH_DEVICE_SCOPE_PREFIX = 'urn:matrix:client:device:';
	private const MATRIX_OAUTH_GRANT_TYPES = ['authorization_code', 'refresh_token'];
	private const MATRIX_OAUTH_RESPONSE_TYPES = ['code'];

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserConfig $config,
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
		$userMatrixUrl = $this->config->getValueString($this->userId, Application::APP_ID, 'url');
		$matrixUrl = $userMatrixUrl !== '' ? $userMatrixUrl : $adminOauthUrl;
		$token = $this->config->getValueString($this->userId, Application::APP_ID, 'token');
		$clientId = $this->appConfig->getAppValueString('client_id', lazy: true);
		$usePopup = $this->appConfig->getAppValueString('use_popup', '0', lazy: true) === '1';
		$registeredClientUrl = $this->appConfig->getAppValueString('registered_client_url', lazy: true);
		$oauthPossible = $this->isOAuthPossibleForUserUrl($userMatrixUrl, $adminOauthUrl, $clientId, $registeredClientUrl);

		return new DataResponse([
			'connected' => $matrixUrl !== '' && $token !== '',
			'oauth_possible' => $oauthPossible,
			'use_popup' => $usePopup,
			'url' => $matrixUrl,
			'oauth_instance_url' => $adminOauthUrl,
		]);
	}

	/**
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	public function getFilesToSend(): DataResponse {
		$adminOauthUrl = $this->appConfig->getAppValueString('oauth_instance_url', lazy: true);
		$matrixUrl = $this->config->getValueString($this->userId, Application::APP_ID, 'url', $adminOauthUrl) ?: $adminOauthUrl;
		$token = $this->config->getValueString($this->userId, Application::APP_ID, 'token');

		if ($matrixUrl !== '' && $token !== '') {
			$fileIdsToSendAfterOAuth = $this->config->getValueString($this->userId, Application::APP_ID, 'file_ids_to_send_after_oauth');
			$currentDirAfterOAuth = $this->config->getValueString($this->userId, Application::APP_ID, 'current_dir_after_oauth');
			$this->config->deleteUserConfig($this->userId, Application::APP_ID, 'file_ids_to_send_after_oauth');
			$this->config->deleteUserConfig($this->userId, Application::APP_ID, 'current_dir_after_oauth');

			return new DataResponse([
				'file_ids_to_send_after_oauth' => $fileIdsToSendAfterOAuth,
				'current_dir_after_oauth' => $currentDirAfterOAuth,
			]);
		}

		return new DataResponse(['message' => 'Not connected']);
	}

	/**
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	public function startOauth(string $oauthOrigin = 'settings'): DataResponse {
		$matrixUrl = $this->appConfig->getAppValueString('oauth_instance_url', lazy: true);
		$clientId = $this->appConfig->getAppValueString('client_id', lazy: true);
		$registeredClientUrl = $this->appConfig->getAppValueString('registered_client_url', lazy: true);
		$userMatrixUrl = $this->config->getValueString($this->userId, Application::APP_ID, 'url');

		if ($matrixUrl === '' || $clientId === '' || !$this->isAdminOauthClientCompatible($matrixUrl, $registeredClientUrl)) {
			return new DataResponse(['error' => $this->l->t('OAuth is not configured')], Http::STATUS_BAD_REQUEST);
		}
		if (!$this->isUserAllowedToUseAdminOauth($userMatrixUrl, $matrixUrl)) {
			return new DataResponse([
				'error' => $this->l->t('OAuth is only available when your Matrix server address matches the administrator-provided server or is left empty'),
			], Http::STATUS_BAD_REQUEST);
		}

		$authMetadata = $this->matrixAPIService->getAuthMetadata($matrixUrl);
		if (isset($authMetadata['error'])) {
			return new DataResponse($authMetadata, Http::STATUS_BAD_REQUEST);
		}

		$authorizationEndpoint = $authMetadata['authorization_endpoint'] ?? '';
		if ($authorizationEndpoint === '') {
			return new DataResponse(['error' => $this->l->t('The Matrix server did not provide an OAuth authorization endpoint')], Http::STATUS_BAD_REQUEST);
		}

		$redirectUri = $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->linkToRoute('integration_matrix.config.oauthRedirect')
		);
		$deviceId = $this->config->getValueString($this->userId, Application::APP_ID, 'oauth_device_id');
		if ($deviceId === '') {
			$deviceId = $this->generateOauthDeviceId();
			$this->config->setValueString($this->userId, Application::APP_ID, 'oauth_device_id', $deviceId);
		}
		$oauthState = bin2hex(random_bytes(16));
		$codeVerifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
		$codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

		$this->config->setValueString($this->userId, Application::APP_ID, 'oauth_state', $oauthState);
		$this->config->setValueString($this->userId, Application::APP_ID, 'oauth_code_verifier', $codeVerifier);
		$this->config->setValueString($this->userId, Application::APP_ID, 'redirect_uri', $redirectUri);
		$this->config->setValueString($this->userId, Application::APP_ID, 'oauth_origin', $oauthOrigin);

		$authorizationUrl = $authorizationEndpoint . '?' . http_build_query([
			'client_id' => $clientId,
			'redirect_uri' => $redirectUri,
			'response_type' => 'code',
			'scope' => self::MATRIX_OAUTH_API_SCOPE . ' ' . self::MATRIX_OAUTH_DEVICE_SCOPE_PREFIX . $deviceId,
			'state' => $oauthState,
			'code_challenge' => $codeChallenge,
			'code_challenge_method' => 'S256',
		]);

		return new DataResponse([
			'authorization_url' => $authorizationUrl,
		]);
	}

	/**
	 * Set config values
	 *
	 * @return DataResponse
	 * @throws PreConditionNotMetException
	 */
	#[NoAdminRequired]
	public function setConfig(array $values): DataResponse {
		foreach ($values as $key => $value) {
			if (!is_string($key) || !is_string($value)) {
				continue;
			}
			if ($key === 'token' && $value !== '') {
				$this->config->setValueString($this->userId, Application::APP_ID, $key, $this->crypto->encrypt($value));
			} elseif ($key === 'token' && $value === '') {
				foreach (['token', 'user_id', 'user_name', 'user_displayname', 'user_avatar_url', 'refresh_token', 'token_expires_at'] as $configKey) {
					$this->config->deleteUserConfig($this->userId, Application::APP_ID, $configKey);
				}
			} else {
				if ($key === 'url') {
					$value = $this->matrixAPIService->normalizeMatrixUrl($value);
				}
				$this->config->setValueString($this->userId, Application::APP_ID, $key, $value);
			}
		}

		$result = [];
		if (isset($values['token']) && is_string($values['token']) && $values['token'] !== '') {
			$this->config->deleteUserConfig($this->userId, Application::APP_ID, 'refresh_token');
			$this->config->deleteUserConfig($this->userId, Application::APP_ID, 'token_expires_at');
			$result = $this->storeUserInfo();
			if (($result['user_name'] ?? '') === '') {
				$this->config->deleteUserConfig($this->userId, Application::APP_ID, 'token');
			}
		}
		return new DataResponse($result);
	}

	/**
	 * Set admin config values
	 *
	 * @return DataResponse
	 */
	public function setAdminConfig(array $values): DataResponse {
		$clientIdWasUpdated = isset($values['client_id']) && is_string($values['client_id']);

		foreach ($values as $key => $value) {
			if (!is_string($key) || !is_string($value) || $key === 'client_secret') {
				continue;
			}
			if ($key === 'oauth_instance_url') {
				$value = $this->matrixAPIService->normalizeMatrixUrl($value);
			}
			$this->appConfig->setAppValueString($key, $value, lazy: true);
		}

		if ($clientIdWasUpdated) {
			$this->appConfig->deleteAppValue('registered_client_url');
		}

		return new DataResponse([]);
	}

	/**
	 * Set sensitive admin config values
	 *
	 * @return DataResponse
	 */
	#[PasswordConfirmationRequired]
	public function setSensitiveAdminConfig(array $values): DataResponse {
		$clientSecretWasUpdated = isset($values['client_secret']) && is_string($values['client_secret']);

		foreach ($values as $key => $value) {
			if (!is_string($key) || !is_string($value)) {
				continue;
			}
			if ($key === 'client_secret') {
				$this->appConfig->setAppValueString($key, $value, lazy: true, sensitive: $value !== '');
			}
		}

		if ($clientSecretWasUpdated) {
			$this->appConfig->deleteAppValue('registered_client_url');
		}

		return new DataResponse([]);
	}

	/**
	 * @return DataResponse
	 */
	#[PasswordConfirmationRequired]
	public function registerAdminOauthClient(string $oauth_instance_url): DataResponse {
		$matrixUrl = $this->matrixAPIService->normalizeMatrixUrl($oauth_instance_url);
		if ($matrixUrl === '') {
			return new DataResponse(['error' => $this->l->t('Please provide a Matrix OAuth server URL first')], Http::STATUS_BAD_REQUEST);
		}

		$authMetadata = $this->matrixAPIService->getAuthMetadata($matrixUrl);
		if (isset($authMetadata['error'])) {
			return new DataResponse($authMetadata, Http::STATUS_BAD_REQUEST);
		}

		$registrationEndpoint = $authMetadata['registration_endpoint'] ?? '';
		if ($registrationEndpoint === '') {
			return new DataResponse(['error' => $this->l->t('The Matrix server did not provide an OAuth client registration endpoint')], Http::STATUS_BAD_REQUEST);
		}

		$redirectUri = $this->urlGenerator->linkToRouteAbsolute('integration_matrix.config.oauthRedirect');
		$clientUri = $this->urlGenerator->getAbsoluteURL('/');
		$clientName = $this->l->t('Nextcloud Matrix integration');

		$registrationResponse = $this->matrixAPIService->registerOAuthClient($authMetadata, [
			'client_name' => $clientName,
			'client_uri' => $clientUri,
			'application_type' => 'web',
			'grant_types' => self::MATRIX_OAUTH_GRANT_TYPES,
			'response_types' => self::MATRIX_OAUTH_RESPONSE_TYPES,
			'redirect_uris' => [$redirectUri],
			'token_endpoint_auth_method' => 'none',
		]);

		$clientId = $registrationResponse['client_id'] ?? '';
		if ($clientId === '') {
			$message = $registrationResponse['error_description'] ?? $registrationResponse['error'] ?? $this->l->t('OAuth client registration failed');
			return new DataResponse(['error' => $message], Http::STATUS_BAD_REQUEST);
		}

		$this->appConfig->setAppValueString('oauth_instance_url', $matrixUrl, lazy: true);
		$this->appConfig->setAppValueString('client_id', $clientId, lazy: true);
		$resolvedMatrixUrl = $this->matrixAPIService->resolveMatrixUrl($matrixUrl);
		$clientSecret = (string)($registrationResponse['client_secret'] ?? '');
		if ($clientSecret !== '') {
			$this->appConfig->setAppValueString('client_secret', $clientSecret, lazy: true, sensitive: true);
		} else {
			$this->appConfig->deleteAppValue('client_secret');
		}
		$this->appConfig->setAppValueString('registered_client_url', $resolvedMatrixUrl, lazy: true);

		return new DataResponse([
			'client_id' => $clientId,
			'client_secret' => $clientSecret !== '' ? 'dummySecret' : '',
			'oauth_instance_url' => $matrixUrl,
			'oauth_instance_api_url' => $resolvedMatrixUrl,
			'registered_client_url' => $resolvedMatrixUrl,
		]);
	}

	/**
	 * @param string $user_name
	 * @param string $user_displayname
	 * @return TemplateResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function popupSuccessPage(string $user_name, string $user_displayname, bool $user_avatar_set = false): TemplateResponse {
		$this->initialStateService->provideInitialState('popup-data', [
			'user_name' => $user_name,
			'user_displayname' => $user_displayname,
			'user_avatar_set' => $user_avatar_set,
		]);
		return new TemplateResponse(Application::APP_ID, 'popupSuccess', [], TemplateResponse::RENDER_AS_GUEST);
	}

	/**
	 * Receive the Matrix OAuth authorization code and exchange it for an access token.
	 *
	 * @param string $code
	 * @param string $state
	 * @param string $error
	 * @param string $error_description
	 * @return RedirectResponse
	 * @throws PreConditionNotMetException
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function oauthRedirect(
		string $code = '',
		string $state = '',
		string $error = '',
		string $error_description = '',
	): RedirectResponse {
		$storedState = $this->config->getValueString($this->userId, Application::APP_ID, 'oauth_state');
		$storedVerifier = $this->config->getValueString($this->userId, Application::APP_ID, 'oauth_code_verifier');
		$oauthOrigin = $this->config->getValueString($this->userId, Application::APP_ID, 'oauth_origin');
		$redirectUri = $this->config->getValueString($this->userId, Application::APP_ID, 'redirect_uri');
		$matrixUrl = $this->appConfig->getAppValueString('oauth_instance_url', lazy: true);
		$clientId = $this->appConfig->getAppValueString('client_id', lazy: true);
		$clientSecret = $this->appConfig->getAppValueString('client_secret', lazy: true);
		$usePopup = $this->appConfig->getAppValueString('use_popup', '0', lazy: true) === '1';

		foreach (['oauth_state', 'oauth_code_verifier'] as $configKey) {
			$this->config->deleteUserConfig($this->userId, Application::APP_ID, $configKey);
		}

		if ($error !== '') {
			$message = $error_description !== '' ? $error_description : $error;
			return $this->redirectToSettingsError($message);
		}

		if ($matrixUrl === '' || $clientId === '' || $storedState === '' || $storedState !== $state || $storedVerifier === '' || $code === '') {
			return $this->redirectToSettingsError($this->l->t('Error during OAuth exchanges'));
		}

		$authMetadata = $this->matrixAPIService->getAuthMetadata($matrixUrl);
		if (isset($authMetadata['error'])) {
			return $this->redirectToSettingsError($authMetadata['error']);
		}

		$tokenResponse = $this->matrixAPIService->requestOAuthAccessToken($authMetadata, [
			'grant_type' => 'authorization_code',
			'client_id' => $clientId,
			'code' => $code,
			'redirect_uri' => $redirectUri,
			'code_verifier' => $storedVerifier,
		], $clientSecret !== '' ? $clientSecret : null);

		if (!isset($tokenResponse['access_token'])) {
			$message = $tokenResponse['error_description'] ?? $tokenResponse['error'] ?? $this->l->t('Error getting OAuth access token.');
			return $this->redirectToSettingsError($message);
		}

		$this->config->setValueString(
			$this->userId,
			Application::APP_ID,
			'token',
			$this->crypto->encrypt($tokenResponse['access_token'])
		);

		$refreshToken = $tokenResponse['refresh_token'] ?? '';
		if ($refreshToken !== '') {
			$this->config->setValueString(
				$this->userId,
				Application::APP_ID,
				'refresh_token',
				$this->crypto->encrypt($refreshToken)
			);
		} else {
			$this->config->deleteUserConfig($this->userId, Application::APP_ID, 'refresh_token');
		}

		if (isset($tokenResponse['expires_in'])) {
			$this->config->setValueString(
				$this->userId,
				Application::APP_ID,
				'token_expires_at',
				strval(time() + (int)$tokenResponse['expires_in'])
			);
		} else {
			$this->config->deleteUserConfig($this->userId, Application::APP_ID, 'token_expires_at');
		}

		$userInfo = $this->storeUserInfo();
		if (($userInfo['user_name'] ?? '') === '') {
			foreach (['token', 'refresh_token', 'token_expires_at'] as $configKey) {
				$this->config->deleteUserConfig($this->userId, Application::APP_ID, $configKey);
			}
			return $this->redirectToSettingsError($this->l->t('The OAuth access token could not be used with the Matrix client-server API'));
		}
		$this->config->deleteUserConfig($this->userId, Application::APP_ID, 'oauth_origin');

		if ($usePopup) {
			return new RedirectResponse($this->urlGenerator->linkToRoute('integration_matrix.config.popupSuccessPage', [
				'user_name' => $userInfo['user_name'] ?? '',
				'user_displayname' => $userInfo['user_displayname'] ?? '',
				'user_avatar_set' => $userInfo['user_avatar_set'] ?? false,
			]));
		}

		if ($oauthOrigin === 'settings' || $oauthOrigin === '') {
			return new RedirectResponse(
				$this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'connected-accounts']) . '?matrixToken=success'
			);
		}

		if (preg_match('/^files--.*/', $oauthOrigin) === 1) {
			$parts = explode('--', $oauthOrigin);
			if (count($parts) > 1) {
				$path = $parts[1];
				if (count($parts) > 2) {
					$this->config->setValueString($this->userId, Application::APP_ID, 'file_ids_to_send_after_oauth', $parts[2]);
					$this->config->setValueString($this->userId, Application::APP_ID, 'current_dir_after_oauth', $path);
				}
				return new RedirectResponse($this->urlGenerator->linkToRoute('files.view.index', ['dir' => $path]));
			}
		}

		return new RedirectResponse(
			$this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'connected-accounts']) . '?matrixToken=success'
		);
	}

	/**
	 * @param string $message
	 * @return RedirectResponse
	 */
	private function redirectToSettingsError(string $message): RedirectResponse {
		return new RedirectResponse(
			$this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'connected-accounts'])
			. '?matrixToken=error&message=' . urlencode($message)
		);
	}

	private function isOAuthPossibleForUserUrl(string $userMatrixUrl, string $adminOauthUrl, string $clientId, string $registeredClientUrl): bool {
		if ($adminOauthUrl === '' || $clientId === '' || !$this->isAdminOauthClientCompatible($adminOauthUrl, $registeredClientUrl)) {
			return false;
		}

		return $this->isUserAllowedToUseAdminOauth($userMatrixUrl, $adminOauthUrl);
	}

	private function isAdminOauthClientCompatible(string $adminOauthUrl, string $registeredClientUrl): bool {
		if ($registeredClientUrl === '') {
			return true;
		}

		return $this->matrixAPIService->sameMatrixServer($registeredClientUrl, $adminOauthUrl);
	}

	private function isUserAllowedToUseAdminOauth(string $userMatrixUrl, string $adminOauthUrl): bool {
		return $userMatrixUrl === '' || $this->matrixAPIService->sameMatrixServer($userMatrixUrl, $adminOauthUrl);
	}

	private function generateOauthDeviceId(): string {
		$alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-._~';
		$alphabetLength = strlen($alphabet);
		$deviceId = '';
		for ($i = 0; $i < 10; $i++) {
			$deviceId .= $alphabet[random_int(0, $alphabetLength - 1)];
		}

		return $deviceId;
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
			$profileInfo = $this->matrixAPIService->request($this->userId, 'profile/' . urlencode($userId));
			$userDisplayName = $profileInfo['displayname'] ?? strtok($userName, ':');
			$this->config->setValueString($this->userId, Application::APP_ID, 'user_id', $userId ?? '');
			$this->config->setValueString($this->userId, Application::APP_ID, 'user_name', $userName ?? '');
			$this->config->setValueString($this->userId, Application::APP_ID, 'user_displayname', $userDisplayName);
			if ($profileInfo['avatar_url'] ?? '') {
				$this->config->setValueString($this->userId, Application::APP_ID, 'user_avatar_url', $profileInfo['avatar_url']);
			} else {
				$this->config->deleteUserConfig($this->userId, Application::APP_ID, 'user_avatar_url');
			}

			return [
				'user_id' => $userId ?? '',
				'user_name' => $userName ?? '',
				'user_displayname' => $userDisplayName,
				'user_avatar_set' => ($profileInfo['avatar_url'] ?? '') !== '',
			];
		}

		$this->config->setValueString($this->userId, Application::APP_ID, 'user_id', '');
		$this->config->setValueString($this->userId, Application::APP_ID, 'user_name', '');
		$this->config->setValueString($this->userId, Application::APP_ID, 'user_displayname', '');
		$this->config->deleteUserConfig($this->userId, Application::APP_ID, 'user_avatar_url');
		return [
			'user_id' => '',
			'user_name' => '',
			'user_displayname' => '',
			'user_avatar_set' => false,
		];
	}
}
