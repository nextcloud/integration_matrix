<template>
	<div id="matrix_prefs" class="section">
		<h2>
			<MatrixIcon class="icon" />
			{{ t('integration_matrix', 'Matrix integration') }}
		</h2>
		<div id="matrix-content">
			<NcNoteCard type="info">
				{{ t('integration_matrix', 'Choose a Matrix homeserver and register an OAuth client automatically if you want users to connect with OAuth.') }}
				{{ t('integration_matrix', 'Users can still connect manually with an access token even if OAuth is configured.') }}
				<br>
				{{ t('integration_matrix', 'If automatic registration is not available on your homeserver, you can still enter the client ID and secret manually.') }}
				<br>
				{{ t('integration_matrix', 'This redirect URI is used for the registered OAuth client:') }}
				<br>
				<strong>{{ redirectUri }}</strong>
			</NcNoteCard>

			<NcTextField
				v-model="state.oauth_instance_url"
				:label="t('integration_matrix', 'Matrix OAuth homeserver URL')"
				:placeholder="t('integration_matrix', 'https://matrix.example.com')"
				:show-trailing-button="!!state.oauth_instance_url"
				@trailing-button-click="state.oauth_instance_url = ''; onInput()"
				@update:model-value="onInput">
				<template #icon>
					<EarthIcon :size="20" />
				</template>
			</NcTextField>

			<NcButton
				type="primary"
				:disabled="!state.oauth_instance_url || registering"
				:loading="registering"
				@click="registerOauthClient">
				<template #icon>
					<KeyOutlineIcon :size="20" />
				</template>
				{{ t('integration_matrix', 'Register OAuth client') }}
			</NcButton>

			<NcNoteCard v-if="hasRegisteredClientMismatch" type="warning">
				{{ t('integration_matrix', 'The stored OAuth client was registered for {matrixUrl}. Register a new client for the currently selected homeserver to enable OAuth again.', { matrixUrl: state.registered_client_url }) }}
			</NcNoteCard>

			<NcTextField
				v-model="state.client_id"
				:label="t('integration_matrix', 'OAuth client ID')"
				:placeholder="t('integration_matrix', 'Client ID of your Matrix OAuth application')"
				:show-trailing-button="!!state.client_id"
				@trailing-button-click="state.client_id = ''; onClientIdInput()"
				@update:model-value="onClientIdInput">
				<template #icon>
					<KeyOutlineIcon :size="20" />
				</template>
			</NcTextField>

			<NcTextField
				v-model="state.client_secret"
				type="password"
				:label="t('integration_matrix', 'OAuth client secret')"
				:placeholder="t('integration_matrix', 'Optional for public PKCE clients')"
				:readonly="readonly"
				:show-trailing-button="!!state.client_secret"
				@trailing-button-click="state.client_secret = ''; onSecretInput()"
				@focus="onSecretFocus"
				@update:model-value="onSecretInput">
				<template #icon>
					<KeyOutlineIcon :size="20" />
				</template>
			</NcTextField>

			<NcFormBox>
				<NcFormBoxSwitch
					v-model="state.use_popup"
					@update:model-value="onUsePopupChanged">
					{{ t('integration_matrix', 'Use a popup for OAuth login') }}
				</NcFormBoxSwitch>
				<NcFormBoxSwitch
					v-model="state.navlink_default"
					@update:model-value="onNavlinkDefaultChanged">
					{{ t('integration_matrix', 'Enable navigation link as default for all users') }}
				</NcFormBoxSwitch>
			</NcFormBox>
		</div>
	</div>
</template>

<script>
import EarthIcon from 'vue-material-design-icons/Earth.vue'
import KeyOutlineIcon from 'vue-material-design-icons/KeyOutline.vue'

import MatrixIcon from './icons/MatrixIcon.vue'

import NcButton from '@nextcloud/vue/components/NcButton'
import NcFormBox from '@nextcloud/vue/components/NcFormBox'
import NcFormBoxSwitch from '@nextcloud/vue/components/NcFormBoxSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { confirmPassword } from '@nextcloud/password-confirmation'

import { delay } from '../utils.js'

export default {
	name: 'AdminSettings',

	components: {
		EarthIcon,
		KeyOutlineIcon,
		MatrixIcon,
		NcButton,
		NcFormBox,
		NcFormBoxSwitch,
		NcNoteCard,
		NcTextField,
	},

	data() {
		return {
			state: loadState('integration_matrix', 'admin-config'),
			registering: false,
			readonly: true,
			redirectUri: window.location.protocol + '//' + window.location.host + generateUrl('/apps/integration_matrix/oauth-redirect'),
		}
	},

	computed: {
		hasRegisteredClientMismatch() {
			return !!this.state.registered_client_url && this.normalizeUrl(this.state.registered_client_url) !== this.normalizeUrl(this.state.oauth_instance_url)
		},
	},

	methods: {
		normalizeUrl(url) {
			return url.trim().replace(/\/+$/, '')
		},
		onUsePopupChanged(newValue) {
			this.saveOptions({ use_popup: newValue ? '1' : '0' }, false)
		},
		onNavlinkDefaultChanged(newValue) {
			this.saveOptions({ navlink_default: newValue ? '1' : '0' }, false)
		},
		onInput() {
			delay(() => {
				this.saveOptions({
					oauth_instance_url: this.normalizeUrl(this.state.oauth_instance_url),
				}, false)
			}, 500)()
		},
		onClientIdInput() {
			this.state.registered_client_url = ''
			delay(() => {
				this.saveOptions({
					oauth_instance_url: this.normalizeUrl(this.state.oauth_instance_url),
					client_id: this.state.client_id,
				}, false)
			}, 500)()
		},
		onSecretInput() {
			this.state.registered_client_url = ''
			delay(() => {
				const values = {
					client_secret: this.state.client_secret,
				}
				this.saveOptions(values, true)
			}, 500)()
		},
		onSecretFocus() {
			this.readonly = false
			if (this.state.client_secret === 'dummySecret') {
				this.state.client_secret = ''
			}
		},
		async registerOauthClient() {
			try {
				await confirmPassword()
			} catch (error) {
				return
			}

			this.registering = true
			axios.post(generateUrl('/apps/integration_matrix/register-oauth-client'), {
				oauth_instance_url: this.normalizeUrl(this.state.oauth_instance_url),
			}).then((response) => {
				this.state.oauth_instance_url = response.data.oauth_instance_url
				this.state.client_id = response.data.client_id
				this.state.client_secret = response.data.client_secret
				this.state.registered_client_url = response.data.registered_client_url
				this.readonly = true
				showSuccess(t('integration_matrix', 'Matrix OAuth client registered'))
			}).catch((error) => {
				showError(t('integration_matrix', 'Failed to register Matrix OAuth client') + ': ' + (error.response?.data?.error ?? error.message))
				console.error(error)
			}).finally(() => {
				this.registering = false
			})
		},
		async saveOptions(values, sensitive = false) {
			if (sensitive) {
				try {
					await confirmPassword()
				} catch (error) {
					return
				}
			}
			const req = { values }
			const url = sensitive
				? generateUrl('/apps/integration_matrix/sensitive-admin-config')
				: generateUrl('/apps/integration_matrix/admin-config')
			axios.put(url, req).then(() => {
				showSuccess(t('integration_matrix', 'Matrix admin options saved'))
				if (sensitive && this.state.client_secret === '') {
					this.readonly = true
				}
			}).catch((error) => {
				showError(t('integration_matrix', 'Failed to save Matrix admin options'))
				console.error(error)
			})
		},
	},
}
</script>

<style scoped lang="scss">
#matrix_prefs {
	#matrix-content {
		margin-left: 40px;
		display: flex;
		flex-direction: column;
		gap: 8px;
		max-width: 800px;
	}

	h2 {
		display: flex;
		justify-content: start;
		gap: 8px;
	}
}
</style>
