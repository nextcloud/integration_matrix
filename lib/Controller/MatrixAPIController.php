<?php

/*
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Matrix\Controller;

use Exception;
use OC\User\NoUserException;
use OCA\Matrix\Service\MatrixAPIService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\NotPermittedException;
use OCP\Http\Client\IResponse;
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
		return new DataResponse($this->matrixAPIService->getUserMatrixApiUrl($this->userId));
	}

	/**
	 * @return Response
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getUserAvatar(): Response {
		$response = $this->matrixAPIService->getMyAvatar($this->userId);
		return $this->buildAvatarResponse($response);
	}

	/**
	 * @param string $avatarUrl
	 * @return Response
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getAvatar(string $avatarUrl): Response {
		$response = $this->matrixAPIService->getAvatar($this->userId, $avatarUrl);
		return $this->buildAvatarResponse($response);
	}

	/**
	 * @param IResponse|null $response
	 * @return Response
	 */
	private function buildAvatarResponse(?IResponse $response): Response {
		if ($response === null) {
			return new DataResponse('', Http::STATUS_NOT_FOUND);
		}

		$contentType = $response->getHeader('Content-Type');
		$displayResponse = new DataDisplayResponse((string)$response->getBody(), Http::STATUS_OK, [
			'Content-Type' => $contentType !== '' ? $contentType : 'application/octet-stream',
		]);
		$displayResponse->addHeader('Cache-Control', 'private, max-age=300');
		return $displayResponse;
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
