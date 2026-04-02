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
	public function sendFile(int $fileId, string $roomId): DataResponse {
		$result = $this->matrixAPIService->sendFile($this->userId, $fileId, $roomId);
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
	public function sendPublicLinks(
		array $fileIds,
		string $roomId,
		string $roomName,
		string $comment,
		string $permission,
		?string $expirationDate = null,
		?string $password = null,
	): DataResponse {
		$fileIds = array_map('intval', $fileIds);
		$result = $this->matrixAPIService->sendPublicLinks(
			$this->userId, $fileIds, $roomId, $roomName,
			$comment, $permission, $expirationDate, $password,
		);
		if (isset($result['error'])) {
			return new DataResponse($result['error'], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse($result);
	}
}
