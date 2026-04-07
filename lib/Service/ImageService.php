<?php

/*
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Matrix\Service;

use OCP\Files\File;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IPreview;
use Psr\Log\LoggerInterface;

class ImageService {

	public function __construct(
		private IRootFolder $root,
		private LoggerInterface $logger,
		private IPreview $previewManager,
		private IMimeTypeDetector $mimeTypeDetector,
	) {
	}

	/**
	 * @param int $fileId
	 * @param string $userId
	 * @param int $x
	 * @param int $y
	 * @return array|null
	 * @throws \OCP\Files\NotPermittedException
	 * @throws \OC\User\NoUserException
	 */
	public function getFilePreviewFile(int $fileId, string $userId, int $x = 100, int $y = 100): ?array {
		$userFolder = $this->root->getUserFolder($userId);
		$files = $userFolder->getById($fileId);
		if (count($files) > 0 && $files[0] instanceof File) {
			$file = $files[0];
			if ($this->previewManager->isMimeSupported($file->getMimeType())) {
				try {
					return [
						'type' => 'file',
						'file' => $this->previewManager->getPreview($file, $x, $y),
					];
				} catch (NotFoundException $e) {
					$this->logger->error('Mimetype is supported but no preview available', ['exception' => $e]);
				}
			}
			return [
				'type' => 'icon',
				'icon' => $this->mimeTypeDetector->mimeTypeIcon($file->getMimeType()),
			];
		}
		return null;
	}
}
