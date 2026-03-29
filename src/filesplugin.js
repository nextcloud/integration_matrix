/*
 * Copyright (c) 2022 Julien Veyssier <julien-nc@posteo.net>
 *
 * This file is licensed under the Affero General Public License version 3
 * or later.
 *
 * See the COPYING-README file.
 *
 */
import SendFilesModal from './components/SendFilesModal.vue'

import axios from '@nextcloud/axios'
import moment from '@nextcloud/moment'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { SEND_TYPE } from './utils.js'
import { registerFileAction, Permission } from '@nextcloud/files'
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
		return nodes.length > 1
			? t('integration_matrix', 'Send files to Matrix')
			: t('integration_matrix', 'Send file to Matrix')
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
			id: node.fileid,
			name: node.basename,
			type: node.type,
			size: node.size,
		}
	})
	if (OCA.Matrix.matrixConnected) {
		openRoomSelector(formattedNodes)
	} else {
		showError(t('integration_matrix', 'You need to connect to Matrix first in your personal settings'))
	}
}

// ///////////////// Network

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
	axios.post(url, req).then((response) => {
		const number = OCA.Matrix.filesToSend.length
		showSuccess(
			n(
				'integration_matrix',
				'A link to {fileName} was sent to {roomName}',
				'{number} links were sent to {roomName}',
				number,
				{
					fileName: OCA.Matrix.filesToSend[0].name,
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
	sendMessage(roomId, comment).then((response) => {
		OCA.Matrix.filesToSend.forEach(f => {
			const link = window.location.protocol + '//' + window.location.host + generateUrl('/f/' + f.id)
			const message = f.name + ': ' + link
			sendMessage(roomId, message)
		})
		const number = OCA.Matrix.filesToSend.length
		showSuccess(
			n(
				'integration_matrix',
				'A link to {fileName} was sent to {roomName}',
				'{number} links were sent to {roomName}',
				number,
				{
					fileName: OCA.Matrix.filesToSend[0].name,
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
	sendMessage(roomId, comment, OCA.Matrix.remoteFileIds).then((response) => {
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

// ////////////// Main

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

// get Matrix state
const urlCheckConnection = generateUrl('/apps/integration_matrix/is-connected')
axios.get(urlCheckConnection).then((response) => {
	OCA.Matrix.matrixConnected = response.data.connected
	OCA.Matrix.matrixUrl = response.data.url
	if (DEBUG) console.debug('[Matrix] OCA.Matrix', OCA.Matrix)
}).catch((error) => {
	console.error(error)
})
