/*
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import SendFilesModal from './components/SendFilesModal.vue'

import axios from '@nextcloud/axios'
import moment from '@nextcloud/moment'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import {
	gotoSettingsConfirmDialog,
	oauthConnect,
	oauthConnectConfirmDialog,
	SEND_TYPE,
} from './utils.js'
import { registerFileAction, Permission } from '@nextcloud/files'
import {
	defaultRootPath,
	getClient,
	getDefaultPropfind,
	resultToNode,
} from '@nextcloud/files/dav'
import { subscribe } from '@nextcloud/event-bus'
import MatrixIcon from '../img/app.svg'

import { createApp } from 'vue'

const DEBUG = false

if (!OCA.Matrix) {
	OCA.Matrix = {
		actionIgnoreLists: [
			'trashbin',
			'files.public',
		],
		filesToSend: [],
		currentFileList: null,
	}
}

subscribe('files:list:updated', onFilesListUpdated)
function onFilesListUpdated({ view, folder, contents }) {
	OCA.Matrix.currentFileList = { view, folder, contents }
}

function openRoomSelector(files) {
	OCA.Matrix.filesToSend = files
	const modalVue = OCA.Matrix.MatrixSendModalVue
	modalVue.updateRooms()
	modalVue.setFiles([...files])
	modalVue.showModal()
}

const sendAction = {
	id: 'matrixSend',
	displayName: ({ nodes }) => {
		return n('integration_matrix', 'Send file to Matrix', 'Send files to Matrix', nodes.length)
	},
	enabled({ nodes, view }) {
		return !OCA.Matrix.actionIgnoreLists.includes(view.id)
			&& nodes.length > 0
			&& !nodes.some(({ permissions }) => (permissions & Permission.READ) === 0)
	},
	iconSvgInline: () => MatrixIcon,
	async exec({ nodes }) {
		sendSelectedNodes([nodes[0]])
		return null
	},
	async execBatch({ nodes }) {
		sendSelectedNodes(nodes)
		return nodes.map(_ => null)
	},
}
registerFileAction(sendAction)

function sendSelectedNodes(nodes) {
	const formattedNodes = nodes.map((node) => {
		return {
			id: parseInt(node.id),
			name: node.basename,
			type: node.type,
			size: node.size,
		}
	})
	if (OCA.Matrix.matrixConnected) {
		openRoomSelector(formattedNodes)
	} else if (OCA.Matrix.oauthPossible) {
		connectToMatrix(formattedNodes)
	} else {
		gotoSettingsConfirmDialog()
	}
}

function checkIfFilesToSend() {
	const urlCheckConnection = generateUrl('/apps/integration_matrix/files-to-send')
	axios.get(urlCheckConnection)
		.then((response) => {
			const fileIdsStr = response?.data?.file_ids_to_send_after_oauth
			const currentDir = response?.data?.current_dir_after_oauth
			if (fileIdsStr && currentDir) {
				sendFileIdsAfterOAuth(fileIdsStr, currentDir)
			} else if (DEBUG) {
				console.debug('[Matrix] nothing to send')
			}
		})
		.catch((error) => {
			console.error(error)
		})
}

async function sendFileIdsAfterOAuth(fileIdsStr, currentDir) {
	if (!fileIdsStr) {
		return
	}

	const client = getClient()
	const results = await client.getDirectoryContents(`${defaultRootPath}${currentDir}`, {
		details: true,
		data: getDefaultPropfind(),
	})
	const nodes = results.data.map((r) => resultToNode(r))
	const fileIds = fileIdsStr.split(',')
	const files = fileIds.map((fid) => {
		const file = nodes.find((node) => parseInt(node.id) === parseInt(fid))
		if (!file) {
			return null
		}
		return {
			id: parseInt(file.id),
			name: file.basename,
			type: file.type,
			size: file.size,
		}
	}).filter((file) => file !== null)

	if (files.length > 0) {
		openRoomSelector(files)
	}
}

function connectToMatrix(selectedFiles = []) {
	oauthConnectConfirmDialog(OCA.Matrix.oauthInstanceUrl || OCA.Matrix.matrixUrl).then(() => {
		const selectedFilesIds = selectedFiles.map((file) => file.id)
		// it used to work like that but the default root path (/files/USER_ID) is now included in filename
		// const currentDirectory = OCA.Matrix.currentFileList?.folder?.attributes?.filename ?? '/'
		// the path is what we want, this is the path relative to the storage root
		const currentDirectory = OCA.Matrix.currentFileList?.folder?.path ?? '/'
		const oauthOrigin = 'files--' + currentDirectory + '--' + selectedFilesIds.join(',')
		oauthConnect(oauthOrigin, OCA.Matrix.usePopup).then(() => {
			if (OCA.Matrix.usePopup) {
				OCA.Matrix.matrixConnected = true
				openRoomSelector(selectedFiles)
			}
		}).catch((error) => {
			console.debug('OAuth error', error)
		})
	}).catch((error) => {
		console.debug('OAuth error', error)
	})
}

function sendPublicLinks(roomId, roomName, comment, permission, expirationDate, password) {
	const req = {
		fileIds: OCA.Matrix.filesToSend.map((f) => f.id),
		roomId,
		roomName,
		comment,
		permission,
		expirationDate: expirationDate ? moment(expirationDate).format('YYYY-MM-DD') : undefined,
		password,
	}
	const url = generateUrl('apps/integration_matrix/sendPublicLinks')
	axios.post(url, req).then(() => {
		const number = OCA.Matrix.filesToSend.length
		showSuccess(
			n(
				'integration_matrix',
				'{number} link was sent to {roomName}',
				'{number} links were sent to {roomName}',
				number,
				{
					roomName,
					number,
				},
			),
		)
		OCA.Matrix.MatrixSendModalVue.success()
	}).catch((error) => {
		console.error(error)
		OCA.Matrix.MatrixSendModalVue.failure()
		OCA.Matrix.filesToSend = []
		showError(
			t('integration_matrix', 'Failed to send links to Matrix')
			+ ' ' + error.response?.request?.responseText,
		)
	})
}

function sendInternalLinks(roomId, roomName, comment) {
	sendMessage(roomId, comment).then(() => {
		OCA.Matrix.filesToSend.forEach((file) => {
			const link = window.location.protocol + '//' + window.location.host + generateUrl('/f/' + file.id)
			const message = file.name + ': ' + link
			sendMessage(roomId, message)
		})
		const number = OCA.Matrix.filesToSend.length
		showSuccess(
			n(
				'integration_matrix',
				'{number} link was sent to {roomName}',
				'{number} links were sent to {roomName}',
				number,
				{
					roomName,
					number,
				},
			),
		)
		OCA.Matrix.MatrixSendModalVue.success()
	}).catch((error) => {
		console.error(error)
		OCA.Matrix.MatrixSendModalVue.failure()
		OCA.Matrix.filesToSend = []
		showError(
			t('integration_matrix', 'Failed to send internal links to Matrix')
			+ ': ' + error.response?.request?.responseText,
		)
	})
}

function sendFileLoop(roomId, roomName, comment) {
	const file = OCA.Matrix.filesToSend.shift()
	if (file.type === 'dir') {
		if (OCA.Matrix.filesToSend.length === 0) {
			sendMessageAfterFilesUpload(roomId, roomName, comment)
		} else {
			sendFileLoop(roomId, roomName, comment)
		}
		return
	}
	OCA.Matrix.MatrixSendModalVue.fileStarted(file.id)
	const req = {
		fileId: file.id,
		roomId,
	}
	const url = generateUrl('apps/integration_matrix/sendFile')
	axios.post(url, req).then((response) => {
		OCA.Matrix.remoteFileIds.push(response.data.remote_file_id)
		OCA.Matrix.sentFileNames.push(file.name)
		OCA.Matrix.MatrixSendModalVue.fileFinished(file.id)
		if (OCA.Matrix.filesToSend.length === 0) {
			sendMessageAfterFilesUpload(roomId, roomName, comment)
		} else {
			sendFileLoop(roomId, roomName, comment)
		}
	}).catch((error) => {
		console.error(error)
		OCA.Matrix.MatrixSendModalVue.failure()
		OCA.Matrix.filesToSend = []
		OCA.Matrix.sentFileNames = []
		showError(
			t('integration_matrix', 'Failed to send {name} to Matrix', { name: file.name })
			+ ' ' + error.response?.request?.responseText,
		)
	})
}

function sendMessageAfterFilesUpload(roomId, roomName, comment) {
	const count = OCA.Matrix.sentFileNames.length
	const lastFileName = count === 0 ? t('integration_matrix', 'Nothing') : OCA.Matrix.sentFileNames[count - 1]
	sendMessage(roomId, comment, OCA.Matrix.remoteFileIds).then(() => {
		showSuccess(
			n(
				'integration_matrix',
				'{fileName} was sent to {roomName}',
				'{count} files were sent to {roomName}',
				count,
				{
					fileName: lastFileName,
					roomName,
					count,
				},
			),
		)
		OCA.Matrix.MatrixSendModalVue.success()
	}).catch((error) => {
		console.error(error)
		OCA.Matrix.MatrixSendModalVue.failure()
		showError(
			t('integration_matrix', 'Failed to send files to Matrix')
			+ ': ' + error.response?.request?.responseText,
		)
	}).then(() => {
		OCA.Matrix.filesToSend = []
		OCA.Matrix.remoteFileIds = []
		OCA.Matrix.sentFileNames = []
	})
}

function sendMessage(roomId, message, remoteFileIds = undefined) {
	const req = {
		message,
		roomId,
		remoteFileIds,
	}
	const url = generateUrl('apps/integration_matrix/sendMessage')
	return axios.post(url, req)
}

const modalId = 'matrixSendModal'
const modalElement = document.createElement('div')
modalElement.id = modalId
document.body.append(modalElement)

const app = createApp(SendFilesModal)
app.mixin({ methods: { t, n } })
OCA.Matrix.MatrixSendModalVue = app.mount(modalElement)

modalElement.addEventListener('closed', () => {
	if (DEBUG) console.debug('[Matrix] modal closed')
})

modalElement.addEventListener('validate', (data) => {
	const { filesToSend, roomId, roomName, type, comment, permission, expirationDate, password } = data.detail

	OCA.Matrix.filesToSend = filesToSend
	if (type === SEND_TYPE.public_link.id) {
		sendPublicLinks(roomId, roomName, comment, permission, expirationDate, password)
	} else if (type === SEND_TYPE.internal_link.id) {
		sendInternalLinks(roomId, roomName, comment)
	} else {
		OCA.Matrix.remoteFileIds = []
		OCA.Matrix.sentFileNames = []
		sendFileLoop(roomId, roomName, comment)
	}
})

const urlCheckConnection = generateUrl('/apps/integration_matrix/is-connected')
axios.get(urlCheckConnection).then((response) => {
	OCA.Matrix.matrixConnected = response.data.connected
	OCA.Matrix.oauthPossible = response.data.oauth_possible
	OCA.Matrix.usePopup = response.data.use_popup
	OCA.Matrix.matrixUrl = response.data.url
	OCA.Matrix.oauthInstanceUrl = response.data.oauth_instance_url
	if (DEBUG) console.debug('[Matrix] OCA.Matrix', OCA.Matrix)
}).catch((error) => {
	console.error(error)
})

document.addEventListener('DOMContentLoaded', () => {
	checkIfFilesToSend()
})
