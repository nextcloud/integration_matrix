/*
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { loadState } from '@nextcloud/initial-state'

const state = loadState('integration_matrix', 'popup-data')
const userName = state.user_name
const userDisplayName = state.user_displayname
const userAvatarSet = state.user_avatar_set

if (window.opener) {
	window.opener.postMessage({ userName, userDisplayName, userAvatarSet })
	window.close()
}
