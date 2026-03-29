<?php

/**
 * Nextcloud - Matrix
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier
 * @copyright Julien Veyssier 2022
 */

namespace OCA\Matrix\Service;

use DateTime;
use Exception;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use OC\User\NoUserException;
use OCA\Matrix\AppInfo\Application;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Lock\LockedException;
use OCP\PreConditionNotMetException;
use OCP\Security\ICrypto;
use OCP\Share\IManager as ShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

class MatrixAPIService {

	private IClient $client;

	public function __construct(
		private LoggerInterface $logger,
		private IL10N $l10n,
		private IAppConfig $appConfig,
		private IConfig $config,
		private IRootFolder $root,
		private ShareManager $shareManager,
		private IURLGenerator $urlGenerator,
		private ICrypto $crypto,
		private NetworkService $networkService,
		IClientService $clientService,
	) {
		$this->client = $clientService->newClient();
	}

	/**
	 * @param string $userId
	 * @return string
	 */
	public function getMatrixUrl(string $userId): string {
		$adminOauthUrl = $this->appConfig->getAppValueString('oauth_instance_url', lazy: true);
		return $this->config->getUserValue($userId, Application::APP_ID, 'url', $adminOauthUrl) ?: $adminOauthUrl;
	}

	/**
	 * @param string $userId
	 * @param string $roomId
	 * @return array|string[]
	 */
	public function getRoomInfo(string $userId, string $roomId): array {
		return $this->request($userId, 'rooms/' . urlencode($roomId));
	}

	/**
	 * @param string $userId
	 * @return array|string[]
	 * @throws PreConditionNotMetException
	 */
	public function getMyRooms(string $userId): array {
		$result = $this->request($userId, 'joined_rooms');
		if (isset($result['chunk'])) {
			return $result['chunk'];
		}
		return $result ?? [];
	}

	/**
	 * @param string $userId
	 * @param string $roomId
	 * @param string $message
	 * @param string|null $txnId
	 * @return array|string[]
	 * @throws PreConditionNotMetException
	 */
	public function sendMessage(string $userId, string $roomId, string $message, ?string $txnId = null): array {
		$txnId = $txnId ?? $this->generateTxnId();
		$content = [
			'msgtype' => 'm.text',
			'body' => $message,
		];
		return $this->request($userId, 'rooms/' . urlencode($roomId) . '/send/m.room.message/' . $txnId, $content, 'PUT');
	}

	/**
	 * @param string $userId
	 * @param array $fileIds
	 * @param string $roomId
	 * @param string $roomName
	 * @param string $comment
	 * @param string $permission
	 * @param string|null $expirationDate
	 * @param string|null $password
	 * @return array|string[]
	 * @throws NoUserException
	 * @throws NotPermittedException
	 * @throws PreConditionNotMetException
	 */
	public function sendPublicLinks(
		string $userId,
		array $fileIds,
		string $roomId,
		string $roomName,
		string $comment,
		string $permission,
		?string $expirationDate = null,
		?string $password = null,
	): array {
		$links = [];
		$userFolder = $this->root->getUserFolder($userId);

		foreach ($fileIds as $fileId) {
			$nodes = $userFolder->getById($fileId);
			if (count($nodes) > 0 && ($nodes[0] instanceof File || $nodes[0] instanceof Folder)) {
				$node = $nodes[0];

				$share = $this->shareManager->newShare();
				$share->setNode($node);
				if ($permission === 'edit') {
					$share->setPermissions(Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE);
				} else {
					$share->setPermissions(Constants::PERMISSION_READ);
				}
				$share->setShareType(IShare::TYPE_LINK);
				$share->setSharedBy($userId);
				$share->setLabel('Matrix (' . $roomName . ')');
				if ($expirationDate !== null) {
					$share->setExpirationDate(new DateTime($expirationDate));
				}
				if ($password !== null) {
					try {
						$share->setPassword($password);
					} catch (Exception $e) {
						return ['error' => $e->getMessage()];
					}
				}
				try {
					$share = $this->shareManager->createShare($share);
					if ($expirationDate === null) {
						$share->setExpirationDate(null);
						$this->shareManager->updateShare($share);
					}
				} catch (Exception $e) {
					return ['error' => $e->getMessage()];
				}
				$token = $share->getToken();
				$linkUrl = $this->urlGenerator->getAbsoluteURL(
					$this->urlGenerator->linkToRoute('files_sharing.Share.showShare', [
						'token' => $token,
					])
				);
				$links[] = [
					'name' => $node->getName(),
					'url' => $linkUrl,
				];
			}
		}

		if (count($links) > 0) {
			$message = $comment . "\n";
			foreach ($links as $link) {
				$message .= '```' . $link['name'] . '```: ' . $link['url'] . "\n";
			}
			return $this->sendMessage($userId, $roomId, $message);
		} else {
			return ['error' => 'Files not found'];
		}
	}

	/**
	 * @param string $userId
	 * @param int $fileId
	 * @param string $roomId
	 * @return array|string[]
	 * @throws NoUserException
	 * @throws NotPermittedException
	 * @throws LockedException
	 */
	public function sendFile(string $userId, int $fileId, string $roomId): array {
		$userFolder = $this->root->getUserFolder($userId);
		$files = $userFolder->getById($fileId);
		if (count($files) > 0 && $files[0] instanceof File) {
			$file = $files[0];
			$uploadResult = $this->uploadFile($userId, $file);
			if (isset($uploadResult['content_uri'])) {
				$txnId = $this->generateTxnId();
				$content = [
					'msgtype' => 'm.file',
					'body' => $file->getName(),
					'filename' => $file->getName(),
					'url' => $uploadResult['content_uri'],
					'info' => [
						'mimetype' => $file->getMimeType(),
						'size' => $file->getSize(),
					],
				];
				$result = $this->request($userId, 'rooms/' . urlencode($roomId) . '/send/m.room.message/' . $txnId, $content, 'PUT');
				if (isset($result['event_id'])) {
					return [
						'remote_file_id' => $result['event_id'],
					];
				}
				return $result;
			}
			return $uploadResult;
		}
		return ['error' => 'File not found'];
	}

	/**
	 * @param string $userId
	 * @param File $file
	 * @return array
	 */
	private function uploadFile(string $userId, File $file): array {
		$this->checkTokenExpiration($userId);
		$matrixUrl = $this->getMatrixUrl($userId);
		$accessToken = $this->config->getUserValue($userId, Application::APP_ID, 'token');
		$accessToken = $accessToken === '' ? '' : $this->crypto->decrypt($accessToken);

		try {
			$url = $matrixUrl . '/_matrix/client/v0/media/upload';
			$options = [
				'headers' => [
					'Authorization' => 'Bearer ' . $accessToken,
					'User-Agent' => Application::INTEGRATION_USER_AGENT,
					'Content-Type' => $file->getMimeType(),
				],
				'body' => $file->fopen('r'),
			];

			$response = $this->client->post($url, $options);
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('Bad credentials')];
			}
			return json_decode($body, true) ?? [];
		} catch (ServerException|ClientException $e) {
			$this->logger->error('Matrix API upload file error: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * @param string $userId
	 * @param string $endPoint
	 * @param array $params
	 * @param string $method
	 * @param bool $jsonResponse
	 * @return array|mixed|resource|string|string[]
	 * @throws PreConditionNotMetException
	 */
	public function request(
		string $userId,
		string $endPoint,
		array $params = [],
		string $method = 'GET',
		bool $jsonResponse = true,
	) {
		$matrixUrl = $this->getMatrixUrl($userId);
		$this->checkTokenExpiration($userId);
		return $this->networkService->request($userId, $matrixUrl, $endPoint, $params, $method, $jsonResponse);
	}

	/**
	 * @param string $userId
	 * @return void
	 * @throws \OCP\PreConditionNotMetException
	 */
	private function checkTokenExpiration(string $userId): void {
		$refreshToken = $this->config->getUserValue($userId, Application::APP_ID, 'refresh_token');
		$expireAt = $this->config->getUserValue($userId, Application::APP_ID, 'token_expires_at');
		if ($refreshToken !== '' && $expireAt !== '') {
			$nowTs = (new DateTime())->getTimestamp();
			$expireAt = (int)$expireAt;
			if ($nowTs > $expireAt - 60) {
				$this->refreshToken($userId);
			}
		}
	}

	/**
	 * @param string $userId
	 * @return bool
	 * @throws \OCP\PreConditionNotMetException
	 */
	private function refreshToken(string $userId): bool {
		$matrixUrl = $this->getMatrixUrl($userId);
		$clientID = $this->appConfig->getAppValueString('client_id', lazy: true);
		$clientSecret = $this->appConfig->getAppValueString('client_secret', lazy: true);
		$redirect_uri = $this->config->getUserValue($userId, Application::APP_ID, 'redirect_uri');
		$refreshToken = $this->config->getUserValue($userId, Application::APP_ID, 'refresh_token');
		$refreshToken = $refreshToken === '' ? '' : $this->crypto->decrypt($refreshToken);
		if (!$refreshToken) {
			$this->logger->error('No Matrix refresh token found', ['app' => Application::APP_ID]);
			return false;
		}
		$result = $this->requestOAuthAccessToken($matrixUrl, [
			'client_id' => $clientID,
			'client_secret' => $clientSecret,
			'grant_type' => 'refresh_token',
			'redirect_uri' => $redirect_uri,
			'refresh_token' => $refreshToken,
		], 'POST');
		if (isset($result['access_token'])) {
			$this->logger->info('Matrix access token successfully refreshed', ['app' => Application::APP_ID]);
			$accessToken = $result['access_token'];
			$encryptedToken = $accessToken === '' ? '' : $this->crypto->encrypt($accessToken);
			$refreshToken = $result['refresh_token'] ?? '';
			$encryptedRefreshToken = $refreshToken === '' ? '' : $this->crypto->encrypt($refreshToken);
			$this->config->setUserValue($userId, Application::APP_ID, 'token', $encryptedToken);
			$this->config->setUserValue($userId, Application::APP_ID, 'refresh_token', $encryptedRefreshToken);
			if (isset($result['expires_in'])) {
				$nowTs = (new DateTime())->getTimestamp();
				$expiresAt = $nowTs + (int)$result['expires_in'];
				$this->config->setUserValue($userId, Application::APP_ID, 'token_expires_at', strval($expiresAt));
			}
			return true;
		}
		$this->logger->error(
			'Token is not valid anymore. Impossible to refresh it. '
				. ($result['error'] ?? 'unknown error') . ' '
				. ($result['error_description'] ?? '[no error description]'),
			['app' => Application::APP_ID]
		);
		return false;
	}

	/**
	 * Matrix OAuth2 token endpoint
	 *
	 * @param string $url
	 * @param array $params
	 * @param string $method
	 * @return array
	 */
	public function requestOAuthAccessToken(string $url, array $params = [], string $method = 'GET'): array {
		try {
			$url = $url . '/_matrix/client/v0/oauth2/token';
			$options = [
				'headers' => [
					'User-Agent' => Application::INTEGRATION_USER_AGENT,
					'Content-Type' => 'application/x-www-form-urlencoded',
				]
			];

			if (count($params) > 0) {
				if ($method === 'GET') {
					$paramsContent = http_build_query($params);
					$url .= '?' . $paramsContent;
				} else {
					$options['body'] = http_build_query($params);
				}
			}

			if ($method === 'GET') {
				$response = $this->client->get($url, $options);
			} elseif ($method === 'POST') {
				$response = $this->client->post($url, $options);
			} elseif ($method === 'PUT') {
				$response = $this->client->put($url, $options);
			} elseif ($method === 'DELETE') {
				$response = $this->client->delete($url, $options);
			} else {
				return ['error' => $this->l10n->t('Bad HTTP method')];
			}
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('OAuth access token refused')];
			}
			return json_decode($body, true) ?? [];
		} catch (Exception $e) {
			$this->logger->error('Matrix OAuth error: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * Generate a unique transaction ID for Matrix messages
	 *
	 * @return string
	 */
	private function generateTxnId(): string {
		return sprintf('%d-%s', (new DateTime())->getTimestamp(), bin2hex(random_bytes(8)));
	}
}
