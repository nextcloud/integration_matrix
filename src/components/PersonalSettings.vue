<template>
	<div id="matrix_prefs" class="section">
		<h2>
			<MatrixIcon class="icon" />
			{{ t('integration_matrix', 'Matrix integration') }}
		</h2>
		<div id="matrix-content">
			<NcNoteCard type="info">
				{{ t('integration_matrix', 'Enter your Matrix server address and an access token to enable file sending.') }}
			</NcNoteCard>
			<NcTextField
				v-model="state.url"
				:label="t('integration_matrix', 'Matrix server address')"
				:placeholder="t('integration_matrix', 'https://matrix.example.com')"
				:disabled="connected === true"
				:show-trailing-button="!!state.url"
				@trailing-button-click="state.url = ''; onInput()"
				@update:model-value="onInput">
				<template #icon>
					<EarthIcon :size="20" />
				</template>
			</NcTextField>
			<NcTextField
				v-model="accessToken"
				:label="t('integration_matrix', 'Access token')"
				:placeholder="t('integration_matrix', 'Enter your Matrix access token')"
				:type="showToken ? 'text' : 'password'"
				:disabled="connected === true"
				@update:model-value="onInput">
				<template #icon>
					<KeyIcon :size="20" />
				</template>
				<template #trailing-icon>
					<NcButton type="tertiary" @click="showToken = !showToken">
						<EyeIcon v-if="!showToken" :size="20" />
						<EyeOffIcon v-else :size="20" />
					</NcButton>
				</template>
			</NcTextField>
			<div v-if="connected" class="line">
				<CheckIcon :size="20" class="icon success-icon" />
				<label class="matrix-connected">
					{{ t('integration_matrix', 'Connected as {user}', { user: connectedDisplayName }) }}
				</label>
				<NcButton @click="onLogoutClick">
					{{ t('integration_matrix', 'Disconnect') }}
				</NcButton>
			</div>
			<NcButton v-else
				type="primary"
				:disabled="!state.url || !accessToken || loading"
				:loading="loading"
				@click="connect">
				{{ t('integration_matrix', 'Connect to Matrix') }}
			</NcButton>
			<div v-if="errorMessage" class="error-message">
				<AlertCircleIcon :size="20" class="icon error-icon" />
				<span>{{ errorMessage }}</span>
			</div>

			<NcNoteCard v-if="!connected" type="info">
				{{ t('integration_matrix', 'How to get an access token?') }}<br>
				{{ t('integration_matrix', 'Log into your Matrix server account and go to Settings > Help & About > Advanced > Access Token, or use the following command in Element Web: /devtools > Authentication > Credentials.') }}
			</NcNoteCard>

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
import AlertCircleIcon from 'vue-material-design-icons/AlertCircle.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import EarthIcon from 'vue-material-design-icons/Earth.vue'
import EyeIcon from 'vue-material-design-icons/Eye.vue'
import EyeOffIcon from 'vue-material-design-icons/EyeOff.vue'
import KeyIcon from 'vue-material-design-icons/Key.vue'

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

export default {
	name: 'PersonalSettings',

	components: {
		AlertCircleIcon,
		CheckIcon,
		EarthIcon,
		EyeIcon,
		EyeOffIcon,
		KeyIcon,
		MatrixIcon,
		NcButton,
		NcFormBox,
		NcFormBoxSwitch,
		NcNoteCard,
		NcTextField,
	},

	props: [],

	data() {
		return {
			state: loadState('integration_matrix', 'user-config'),
			accessToken: '',
			loading: false,
			errorMessage: '',
			showToken: false,
		}
	},

	computed: {
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
	},

	methods: {
		onInput() {
			this.errorMessage = ''
		},
		onLogoutClick() {
			this.state.token = ''
			this.state.user_name = ''
			this.state.user_displayname = ''
			this.state.user_id = ''
			this.accessToken = ''
			this.saveOptions({ token: '' })
		},
		onCheckboxChanged(newValue, key) {
			this.saveOptions({ [key]: newValue ? '1' : '0' }, false)
		},
		onNavigationChange(newValue) {
			this.saveOptions({ navigation_enabled: newValue ? '1' : '0' }, false)
		},
		async connect() {
			if (!this.state.url || !this.accessToken) {
				return
			}
			this.loading = true
			this.errorMessage = ''
			this.saveOptions({
				url: this.state.url,
				token: this.accessToken,
			})
		},
		saveOptions(values) {
			const req = { values }
			const url = generateUrl('/apps/integration_matrix/config')
			axios.put(url, req)
				.then((response) => {
					if (response.data.user_name !== undefined) {
						if (response.data.user_name === '') {
							this.errorMessage = t('integration_matrix', 'Invalid access token')
							this.state.token = ''
						} else {
							showSuccess(t('integration_matrix', 'Successfully connected to Matrix!'))
							this.state.token = 'dummy'
							this.state.user_id = response.data.user_id
							this.state.user_name = response.data.user_name
							this.state.user_displayname = response.data.user_displayname
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

		.line {
			display: flex;
			align-items: center;
			gap: 8px;

			> label {
				flex: 1;
			}
		}
	}

	.success-icon {
		color: var(--color-success);
	}

	.error-icon {
		color: var(--color-error);
	}

	.error-message {
		display: flex;
		align-items: center;
		gap: 8px;
		color: var(--color-error);
	}
}
</style>
