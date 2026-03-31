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
		return $this->config->getUserValue($userId, Application::APP_ID, 'url') ?: '';
	}

	/**
	 * @param string $userId
	 * @param string $roomId
	 * @return array
	 */
	public function getRoomInfo(string $userId, string $roomId, string $matrixUserId): array {
		$state = $this->request($userId, 'rooms/' . urlencode($roomId) . '/state');
		if (isset($state['error'])) {
			return [];
		}
		return $this->parseRoomInfoFromState($state, $roomId, $matrixUserId);
	}

	/**
	 * @param array $stateEvents
	 * @param string $roomId
	 * @param string $matrixUserId
	 * @return array
	 */
	private function parseRoomInfoFromState(array $stateEvents, string $roomId, string $matrixUserId): array {
		$roomName = '';
		$otherJoinedMemberId = null;
		$otherJoinedMemberDisplayname = null;

		foreach ($stateEvents as $event) {
			if (($event['type'] ?? '') === 'm.room.name') {
				$roomName = $event['content']['name'] ?? '';
				if ($roomName !== '') {
					return [
						'type' => 'room',
						'name' => $roomName . ' (' . $roomId . ')',
						'id' => $roomId,
					];
				}
				break;
			}
		}

		if ($roomName === '') {
			foreach ($stateEvents as $event) {
				if (($event['type'] ?? '') === 'm.room.member') {
					$stateKey = $event['state_key'] ?? '';
					$membership = $event['content']['membership'] ?? '';
					if ($stateKey !== '' && $stateKey !== $matrixUserId && $membership === 'join') {
						$otherJoinedMemberId = $stateKey;
						$otherJoinedMemberDisplayname = $event['content']['displayname'] ?? null;
						break;
					}
				}
			}
		}

		if ($roomName === '' && $otherJoinedMemberId !== null) {
			if ($otherJoinedMemberDisplayname !== null && $otherJoinedMemberDisplayname !== '') {
				return [
					'type' => 'dm',
					'name' => $otherJoinedMemberDisplayname . ' (' . $otherJoinedMemberId . ')',
					'id' => $otherJoinedMemberId,
				];
			} else {
				return [
					'type' => 'dm',
					'name' => $otherJoinedMemberId,
					'id' => $otherJoinedMemberId,
				];
			}
		}

		return [
			'type' => 'room',
			'name' => $roomId,
			'id' => $roomId,
		];
	}

	/**
	 * @param string $userId
	 * @return array
	 * @throws PreConditionNotMetException
	 */
	public function getMyRooms(string $userId): array {
		$matrixUserId = $this->config->getUserValue($userId, Application::APP_ID, 'user_id');
		$filter = json_encode([
			'room' => [
				'state' => [
					'lazy_load_members' => true,
				],
			],
		]);
		$result = $this->request($userId, 'sync?filter=' . urlencode($filter));
		if (isset($result['error'])) {
			return [];
		}

		$joinedRooms = $result['rooms']['join'] ?? [];
		$rooms = [];
		foreach ($joinedRooms as $roomId => $roomData) {
			$timelineEvents = $roomData['timeline']['events'] ?? [];
			$stateEvents = $roomData['state']['events'] ?? [];
			$allEvents = array_merge($stateEvents, $timelineEvents);
			$rooms[] = [
				'id' => $roomId,
				'info' => $this->parseRoomInfoFromState($allEvents, $roomId, $matrixUserId),
			];
		}
		return $rooms;
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
		$matrixUrl = $this->getMatrixUrl($userId);
		$accessToken = $this->config->getUserValue($userId, Application::APP_ID, 'token');
		$accessToken = $accessToken === '' ? '' : $this->crypto->decrypt($accessToken);

		try {
			$url = $matrixUrl . '/_matrix/client/v3/media/upload';
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
		return $this->networkService->request($userId, $matrixUrl, $endPoint, $params, $method, $jsonResponse);
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
