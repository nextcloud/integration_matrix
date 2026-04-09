<?php

/*
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

return [
	'routes' => [
		['name' => 'config#isUserConnected', 'url' => '/is-connected', 'verb' => 'GET'],
		['name' => 'config#getFilesToSend', 'url' => '/files-to-send', 'verb' => 'GET'],
		['name' => 'config#startOauth', 'url' => '/oauth-start', 'verb' => 'POST'],
		['name' => 'config#oauthRedirect', 'url' => '/oauth-redirect', 'verb' => 'GET'],
		['name' => 'config#setConfig', 'url' => '/config', 'verb' => 'PUT'],
		['name' => 'config#setAdminConfig', 'url' => '/admin-config', 'verb' => 'PUT'],
		['name' => 'config#setSensitiveAdminConfig', 'url' => '/sensitive-admin-config', 'verb' => 'PUT'],
		['name' => 'config#registerAdminOauthClient', 'url' => '/register-oauth-client', 'verb' => 'POST'],
		['name' => 'config#popupSuccessPage', 'url' => '/popup-success', 'verb' => 'GET'],

		['name' => 'matrixAPI#sendMessage', 'url' => '/sendMessage', 'verb' => 'POST'],
		['name' => 'matrixAPI#sendPublicLinks', 'url' => '/sendPublicLinks', 'verb' => 'POST'],
		['name' => 'matrixAPI#sendFile', 'url' => '/sendFile', 'verb' => 'POST'],
		['name' => 'matrixAPI#getRooms', 'url' => '/rooms', 'verb' => 'GET'],
		['name' => 'matrixAPI#getMatrixUrl', 'url' => '/url', 'verb' => 'GET'],
		['name' => 'matrixAPI#getAvatar', 'url' => '/avatar', 'verb' => 'GET'],
		['name' => 'matrixAPI#getUserAvatar', 'url' => '/user-avatar', 'verb' => 'GET'],

		['name' => 'files#getFileImage', 'url' => '/preview', 'verb' => 'GET'],
	]
];
