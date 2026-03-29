import FileOutlineIcon from 'vue-material-design-icons/FileOutline.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import LinkVariantIcon from 'vue-material-design-icons/LinkVariant.vue'

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
