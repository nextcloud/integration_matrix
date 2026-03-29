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

return [
	'routes' => [
		['name' => 'config#isUserConnected', 'url' => '/is-connected', 'verb' => 'GET'],
		['name' => 'config#setConfig', 'url' => '/config', 'verb' => 'PUT'],
		['name' => 'config#setAdminConfig', 'url' => '/admin-config', 'verb' => 'PUT'],

		['name' => 'matrixAPI#sendMessage', 'url' => '/sendMessage', 'verb' => 'POST'],
		['name' => 'matrixAPI#sendPublicLinks', 'url' => '/sendPublicLinks', 'verb' => 'POST'],
		['name' => 'matrixAPI#sendFile', 'url' => '/sendFile', 'verb' => 'POST'],
		['name' => 'matrixAPI#getRooms', 'url' => '/rooms', 'verb' => 'GET'],
		['name' => 'matrixAPI#getMatrixUrl', 'url' => '/url', 'verb' => 'GET'],
		['name' => 'matrixAPI#getUserAvatar', 'url' => '/users/{userId}/image', 'verb' => 'GET'],

		['name' => 'files#getFileImage', 'url' => '/preview', 'verb' => 'GET'],
	]
];
