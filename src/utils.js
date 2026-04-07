/*
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { DialogBuilder, showError } from '@nextcloud/dialogs'
import FileOutlineIcon from 'vue-material-design-icons/FileOutline.vue'
import LinkVariantIcon from 'vue-material-design-icons/LinkVariant.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import { generateUrl } from '@nextcloud/router'

let mytimer = 0

export function delay(callback, ms) {
	return function() {
		const context = this
		const args = arguments
		clearTimeout(mytimer)
		mytimer = setTimeout(function() {
			callback.apply(context, args)
		}, ms || 0)
	}
}

export function oauthConnect(oauthOrigin = 'settings', usePopup = false) {
	const url = generateUrl('/apps/integration_matrix/oauth-start')
	return new Promise((resolve, reject) => {
		axios.post(url, { oauthOrigin }).then((response) => {
			const authorizationUrl = response.data.authorization_url
			if (!authorizationUrl) {
				reject(new Error('Missing Matrix OAuth authorization URL'))
				return
			}

			if (usePopup) {
				const ssoWindow = window.open(
					authorizationUrl,
					t('integration_matrix', 'Sign in with Matrix'),
					'toolbar=no, menubar=no, width=600, height=700',
				)
				if (!ssoWindow) {
					reject(new Error('Failed to open Matrix OAuth popup'))
					return
				}
				ssoWindow.focus()
				const listener = (event) => {
					window.removeEventListener('message', listener)
					resolve(event.data)
				}
				window.addEventListener('message', listener)
			} else {
				window.location.replace(authorizationUrl)
				resolve(null)
			}
		}).catch((error) => {
			showError(
				t('integration_matrix', 'Failed to start Matrix OAuth flow')
				+ ': ' + (error.response?.data?.error ?? error.message),
			)
			reject(error)
		})
	})
}

export function oauthConnectConfirmDialog(matrixUrl) {
	return new Promise((resolve, reject) => {
		new DialogBuilder()
			.setName(t('integration_matrix', 'Connect to Matrix'))
			.setText(
				t('integration_matrix', 'You need to connect to a Matrix server before using the Matrix integration.')
				+ ' --- '
				+ t('integration_matrix', 'Do you want to connect to {matrixUrl} with OAuth now?', { matrixUrl }),
			)
			.setButtons([
				{
					label: t('integration_matrix', 'Cancel'),
					variant: 'secondary',
					callback: () => reject(new Error('OAuth connection canceled')),
				},
				{
					label: t('integration_matrix', 'Connect with OAuth'),
					variant: 'primary',
					callback: () => resolve(),
				},
			])
			.build()
			.show()
	})
}

export function gotoSettingsConfirmDialog() {
	const settingsLink = generateUrl('/settings/user/connected-accounts')
	new DialogBuilder()
		.setName(t('integration_matrix', 'Connect to Matrix'))
		.setText(
			t('integration_matrix', 'You need to connect to a Matrix server before using the Matrix integration.')
			+ ' --- '
			+ t('integration_matrix', 'Do you want to go to your "Connected accounts" personal settings?'),
		)
		.setButtons([
			{
				label: t('integration_matrix', 'Cancel'),
				variant: 'secondary',
				callback: () => {},
			},
			{
				label: t('integration_matrix', 'Go to settings'),
				variant: 'primary',
				callback: () => {
					window.location.replace(settingsLink)
				},
			},
		])
		.build()
		.show()
}

export function humanFileSize(bytes, approx = false, si = false, dp = 1) {
	const thresh = si ? 1000 : 1024

	if (Math.abs(bytes) < thresh) {
		return bytes + ' B'
	}

	const units = si
		? ['kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB']
		: ['KiB', 'MiB', 'GiB', 'TiB', 'PiB', 'EiB', 'ZiB', 'YiB']
	let u = -1
	const r = 10 ** dp

	do {
		bytes /= thresh
		++u
	} while (Math.round(Math.abs(bytes) * r) / r >= thresh && u < units.length - 1)

	if (approx) {
		return Math.floor(bytes) + ' ' + units[u]
	}
	return bytes.toFixed(dp) + ' ' + units[u]
}

export const SEND_TYPE = {
	file: {
		id: 'file',
		label: t('integration_matrix', 'Upload files'),
		icon: FileOutlineIcon,
	},
	public_link: {
		id: 'public_link',
		label: t('integration_matrix', 'Public links'),
		icon: LinkVariantIcon,
	},
	internal_link: {
		id: 'internal_link',
		label: t('integration_matrix', 'Internal links (Only works for users with access to the files)'),
		icon: OpenInNewIcon,
	},
}
