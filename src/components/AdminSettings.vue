<template>
	<div id="matrix_prefs" class="section">
		<h2>
			<MatrixIcon class="icon" />
			{{ t('integration_matrix', 'Matrix integration') }}
		</h2>
		<div id="matrix-content">
			<NcNoteCard type="info">
				{{ t('integration_matrix', 'Configure the Matrix integration. Users can connect to Matrix in their personal settings by providing an access token.') }}
			</NcNoteCard>
			<NcFormBox>
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
import MatrixIcon from './icons/MatrixIcon.vue'

import NcFormBox from '@nextcloud/vue/components/NcFormBox'
import NcFormBoxSwitch from '@nextcloud/vue/components/NcFormBoxSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'

export default {
	name: 'AdminSettings',

	components: {
		MatrixIcon,
		NcFormBox,
		NcFormBoxSwitch,
		NcNoteCard,
	},

	props: [],

	data() {
		return {
			state: loadState('integration_matrix', 'admin-config'),
		}
	},

	watch: {
	},

	mounted() {
	},

	methods: {
		onNavlinkDefaultChanged(newValue) {
			this.saveOptions({ navlink_default: newValue ? '1' : '0' }, false)
		},
		saveOptions(values) {
			const req = { values }
			const url = generateUrl('/apps/integration_matrix/admin-config')
			axios.put(url, req).then((response) => {
				showSuccess(t('integration_matrix', 'Matrix admin options saved'))
			}).catch((error) => {
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
