<template>
	<div id="matrix_prefs" class="section">
		<h2>
			<MatrixIcon class="icon" />
			{{ t('integration_matrix', 'Matrix integration') }}
		</h2>
		<div id="matrix-content">
			<div id="matrix-connect-block">
				<NcNoteCard type="info">
					{{ t('integration_matrix', 'Connect to your Matrix server using OAuth2 to enable file sending.') }}
				</NcNoteCard>
				<NcTextField
					v-model="state.url"
					:label="t('integration_matrix', 'Matrix server address')"
					:placeholder="t('integration_matrix', 'Matrix server address')"
					:disabled="connected === true"
					:show-trailing-button="!!state.url"
					@trailing-button-click="state.url = ''; onSensitiveInput()"
					@update:model-value="onSensitiveInput">
					<template #icon>
						<EarthIcon :size="20" />
					</template>
				</NcTextField>
				<br>
				<NcButton v-if="!connected"
					id="matrix-connect"
					:disabled="loading === true || !showOAuth"
					@click="onConnectClick">
					<template #icon>
						<NcLoadingIcon v-if="loading" />
						<OpenInNewIcon v-else :size="20" />
					</template>
					{{ t('integration_matrix', 'Connect to Matrix') }}
				</NcButton>
				<div v-else class="line">
					<label class="matrix-connected">
						<CheckIcon :size="20" class="icon" />
						{{ t('integration_matrix', 'Connected as {user}', { user: connectedDisplayName }) }}
					</label>
					<NcButton id="matrix-rm-cred" @click="onLogoutClick">
						<template #icon>
							<CloseIcon :size="20" />
						</template>
						{{ t('integration_matrix', 'Disconnect from Matrix') }}
					</NcButton>
				</div>
			</div>
			<br>
			<NcFormBox>
				<NcFormBoxSwitch
					v-model="state.file_action_enabled"
					@update:model-value="onCheckboxChanged($event, 'file_action_enabled')">
					{{ t('integration_matrix', 'Add file action to send files to Matrix rooms') }}
				</NcFormBoxSwitch>
				<NcFormBoxSwitch
					v-model="state.navigation_enabled"
					@update:model-value="onNavigationChange">
					{{ t('integration_matrix', 'Enable navigation link (link to Matrix with a top menu item)') }}
				</NcFormBoxSwitch>
			</NcFormBox>
		</div>
	</div>
</template>

<script>
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import EarthIcon from 'vue-material-design-icons/Earth.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'

import MatrixIcon from './icons/MatrixIcon.vue'

import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcFormBox from '@nextcloud/vue/components/NcFormBox'
import NcFormBoxSwitch from '@nextcloud/vue/components/NcFormBoxSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { showSuccess, showError } from '@nextcloud/dialogs'

import { delay, oauthConnect } from '../utils.js'

export default {
	name: 'PersonalSettings',

	components: {
		MatrixIcon,
		NcNoteCard,
		NcFormBox,
		NcFormBoxSwitch,
		NcButton,
		NcLoadingIcon,
		OpenInNewIcon,
		CloseIcon,
		EarthIcon,
		CheckIcon,
	},

	props: [],

	data() {
		return {
			state: loadState('integration_matrix', 'user-config'),
			loading: false,
			redirect_uri: window.location.protocol + '//' + window.location.host + generateUrl('/apps/integration_matrix/oauth-redirect'),
		}
	},

	computed: {
		showOAuth() {
			return (this.state.url === this.state.oauth_instance_url) && this.state.client_id && this.state.client_secret
		},
		connected() {
			return !!this.state.token
				&& !!this.state.url
				&& !!this.state.user_name
		},
		connectedDisplayName() {
			return this.state.user_displayname + ' (' + this.state.user_name + ')'
		},
	},

	watch: {
	},

	mounted() {
		const paramString = window.location.search.substr(1)
		const urlParams = new URLSearchParams(paramString)
		const matrixToken = urlParams.get('matrixToken')
		if (matrixToken === 'success') {
			showSuccess(t('integration_matrix', 'Successfully connected to Matrix!'))
		} else if (matrixToken === 'error') {
			showError(t('integration_matrix', 'Error connecting to Matrix:') + ' ' + urlParams.get('message'))
		}
	},

	methods: {
		onLogoutClick() {
			this.state.token = ''
			this.saveOptions({ token: '' })
		},
		onCheckboxChanged(newValue, key) {
			this.saveOptions({ [key]: newValue ? '1' : '0' }, false)
		},
		onNavigationChange(newValue) {
			this.saveOptions({ navigation_enabled: newValue ? '1' : '0' }, false)
		},
		onSensitiveInput() {
			this.loading = true
			delay(() => {
				this.saveOptions({
					url: this.state.url,
				})
			}, 2000)()
		},
		async saveOptions(values, sensitive = true) {
			const req = {
				values,
			}
			const url = sensitive
				? generateUrl('/apps/integration_matrix/sensitive-config')
				: generateUrl('/apps/integration_matrix/config')
			axios.put(url, req)
				.then((response) => {
					if (response.data.user_name !== undefined) {
						this.state.user_name = response.data.user_name
						if (this.state.token && response.data.user_name === '') {
							showError(t('integration_matrix', 'Invalid access token'))
							this.state.token = ''
						} else if (response.data.user_name) {
							showSuccess(t('integration_matrix', 'Successfully connected to Matrix!'))
							this.state.user_id = response.data.user_id
							this.state.user_name = response.data.user_name
							this.state.user_displayname = response.data.user_displayname
							this.state.token = 'dumdum'
						}
					} else {
						showSuccess(t('integration_matrix', 'Matrix options saved'))
					}
				})
				.catch((error) => {
					showError(t('integration_matrix', 'Failed to save Matrix options'))
					console.error(error)
				})
				.then(() => {
					this.loading = false
				})
		},
		onConnectClick() {
			if (this.showOAuth) {
				this.connectWithOauth()
			}
		},
		connectWithOauth() {
			if (this.state.use_popup) {
				oauthConnect(this.state.url, this.state.client_id, null, true)
					.then((data) => {
						this.state.token = 'dummyToken'
						this.state.user_name = data.userName
						this.state.user_displayname = data.userDisplayName
					})
			} else {
				oauthConnect(this.state.url, this.state.client_id, 'settings')
			}
		},
	},
}
</script>

<style scoped lang="scss">
#matrix_prefs {
	h2 {
		display: flex;
		justify-content: start;
		gap: 8px;
	}
	#matrix-content {
		margin-left: 40px;
		display: flex;
		flex-direction: column;
		gap: 8px;
		max-width: 800px;

		#matrix-connect {
			margin-top: 8px;
		}

		.line {
			display: flex;
			align-items: center;

			> label {
				width: 300px;
				display: flex;
				align-items: center;
			}
		}
	}
}
</style>
