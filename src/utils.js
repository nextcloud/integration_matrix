import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { DialogBuilder, showError } from '@nextcloud/dialogs'
import FileOutlineIcon from 'vue-material-design-icons/FileOutline.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import LinkVariantIcon from 'vue-material-design-icons/LinkVariant.vue'

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

export function oauthConnect(matrixUrl, clientId, oauthOrigin, usePopup = false) {
	const redirectUri = window.location.protocol + '//' + window.location.host + generateUrl('/apps/integration_matrix/oauth-redirect')

	const oauthState = Math.random().toString(36).substring(3)
	const requestUrl = matrixUrl + '/_matrix/client/v0/oauth2/authorize'
		+ '?client_id=' + encodeURIComponent(clientId)
		+ '&redirect_uri=' + encodeURIComponent(redirectUri)
		+ '&response_type=code'
		+ '&state=' + encodeURIComponent(oauthState)

	const req = {
		values: {
			oauth_state: oauthState,
			redirect_uri: redirectUri,
			oauth_origin: usePopup ? undefined : oauthOrigin,
		},
	}
	const url = generateUrl('/apps/integration_matrix/config')
	return new Promise((resolve, reject) => {
		axios.put(url, req).then((response) => {
			if (usePopup) {
				const ssoWindow = window.open(
					requestUrl,
					t('integration_matrix', 'Sign in with Matrix'),
					'toolbar=no, menubar=no, width=600, height=700')
				ssoWindow.focus()
				window.addEventListener('message', (event) => {
					console.debug('Child window message received', event)
					resolve(event.data)
				})
			} else {
				window.location.replace(requestUrl)
			}
		}).catch((error) => {
			showError(
				t('integration_matrix', 'Failed to save Matrix OAuth state')
				+ ': ' + (error.response?.request?.responseText ?? ''),
			)
			console.error(error)
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
				+ t('integration_matrix', 'You can choose another Matrix server in the "Matrix" section of your personal settings.')
				+ ' --- '
				+ t('integration_matrix', 'Do you want to connect to {matrixUrl}?', { matrixUrl }),
			)
			.setButtons([
				{
					label: t('integration_matrix', 'Cancel'),
					variant: 'secondary',
					callback: () => {
						reject(new Error('OAuth connection canceled'))
					},
				},
				{
					label: t('integration_matrix', 'Connect'),
					variant: 'primary',
					callback: () => {
						resolve()
					},
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
				callback: () => {
				},
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
