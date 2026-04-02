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

use Exception;
use OC\User\NoUserException;
use OCA\Matrix\Service\MatrixAPIService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\NotPermittedException;
use OCP\IRequest;
use OCP\Lock\LockedException;

class MatrixAPIController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private MatrixAPIService $matrixAPIService,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 *
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	public function getMatrixUrl(): DataResponse {
		return new DataResponse($this->matrixAPIService->getMatrixUrl($this->userId));
	}

	/**
	 * @return DataResponse
	 * @throws Exception
	 */
	#[NoAdminRequired]
	public function getRooms() {
		$result = $this->matrixAPIService->getMyRooms($this->userId);
		if (isset($result['error'])) {
			return new DataResponse($result, Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse($result);
	}

	/**
	 * @return DataResponse
	 * @throws Exception
	 */
	#[NoAdminRequired]
	public function sendMessage(string $message, string $roomId): DataResponse {
		$result = $this->matrixAPIService->sendMessage($this->userId, $roomId, $message);
		if (isset($result['error'])) {
			return new DataResponse($result['error'], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse($result);
	}

	/**
	 * @return DataResponse
	 * @throws NotPermittedException
	 * @throws LockedException
	 * @throws NoUserException
	 */
	#[NoAdminRequired]
	public function sendFile(): DataResponse {
		$fileId = $this->request->getParam('fileId');
		$roomId = $this->request->getParam('roomId');
		if (!is_numeric($fileId) || !is_string($roomId) || $roomId === '') {
			return new DataResponse(['error' => 'Missing file ID or room ID'], Http::STATUS_BAD_REQUEST);
		}
		$result = $this->matrixAPIService->sendFile($this->userId, (int)$fileId, $roomId);
		if (isset($result['error'])) {
			return new DataResponse($result['error'], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse($result);
	}

	/**
	 * @return DataResponse
	 * @throws NoUserException
	 * @throws NotPermittedException
	 */
	#[NoAdminRequired]
	public function sendPublicLinks(): DataResponse {
		$fileIds = $this->request->getParam('fileIds', []);
		$roomId = $this->request->getParam('roomId');
		$roomName = $this->request->getParam('roomName');
		$comment = $this->request->getParam('comment', '');
		$permission = $this->request->getParam('permission');
		$expirationDate = $this->request->getParam('expirationDate');
		$password = $this->request->getParam('password');
		if (!is_array($fileIds) || !is_string($roomId) || !is_string($roomName) || !is_string($comment) || !is_string($permission)) {
			return new DataResponse(['error' => 'Invalid request payload'], Http::STATUS_BAD_REQUEST);
		}
		$fileIds = array_map('intval', $fileIds);
		$result = $this->matrixAPIService->sendPublicLinks(
			$this->userId, $fileIds, $roomId, $roomName,
			$comment, $permission,
			is_string($expirationDate) ? $expirationDate : null,
			is_string($password) ? $password : null
		);
		if (isset($result['error'])) {
			return new DataResponse($result['error'], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse($result);
	}
}
