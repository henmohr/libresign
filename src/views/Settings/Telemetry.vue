<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection :name="t('libresign', 'Telemetry')">
		<div class="telemetry">
			<p>{{ t('libresign', 'Optional weekly reports contain software versions, aggregate user and feature counts, completed flows still stored, cancellations recorded while enabled, basic server types, and a random installation identifier.') }}</p>
			<p>{{ t('libresign', 'Reports exclude document contents, file names, email addresses, user IDs, the instance URL, secrets, and tokens. The receiving server can see the connection IP address.') }}</p>
			<NcLoadingIcon v-if="loading" />
			<template v-else-if="loaded">
				<form @submit.prevent="save">
					<NcTextField v-model="url"
						type="url"
						:label="t('libresign', 'Report receiver URL')"
						:disabled="busy"
						:required="enabled" />
					<NcCheckboxRadioSwitch v-model="enabled" type="switch" :disabled="busy">
						{{ t('libresign', 'Enable telemetry') }}
					</NcCheckboxRadioSwitch>
					<div class="telemetry__actions">
						<NcButton type="submit" variant="primary" :disabled="busy || !dirty">
							<template #icon><NcIconSvgWrapper :path="mdiContentSave" /></template>
							{{ t('libresign', 'Save') }}
						</NcButton>
						<NcButton :disabled="busy || dirty || !saved.enabled" @click="sendReport">
							<template #icon><NcIconSvgWrapper :path="mdiSend" /></template>
							{{ t('libresign', 'Send report now') }}
						</NcButton>
					</div>
				</form>
				<p>{{ saved.lastSent ? t('libresign', 'Last report sent: {date}', { date: new Date(saved.lastSent * 1000).toLocaleString() }) : t('libresign', 'No report sent yet') }}</p>
			</template>
			<NcButton v-else :disabled="busy" @click="load">
				<template #icon><NcIconSvgWrapper :path="mdiRefresh" /></template>
				{{ t('libresign', 'Retry') }}
			</NcButton>
			<p v-if="message" role="status">{{ message }}</p>
		</div>
	</NcSettingsSection>
</template>

<script setup lang="ts">
import { mdiContentSave, mdiRefresh, mdiSend } from '@mdi/js'
import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import { generateOcsUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, onMounted, ref } from 'vue'
import type { operations } from '../../types/openapi/openapi-administration'

type SettingsResponse = operations['telemetry-get-settings']['responses'][200]['content']['application/json']
type Settings = SettingsResponse['ocs']['data']
type ReportResponse = operations['telemetry-send-report']['responses'][200]['content']['application/json']

const endpoint = generateOcsUrl('/apps/libresign/api/v1/admin/telemetry')
const saved = ref<Settings>({ enabled: false, url: '', lastSent: 0 })
const enabled = ref(false)
const url = ref('')
const loading = ref(true)
const loaded = ref(false)
const busy = ref(false)
const message = ref('')
const dirty = computed(() => enabled.value !== saved.value.enabled || url.value !== saved.value.url)

function applySettings(settings: Settings) {
	saved.value = settings
	enabled.value = settings.enabled
	url.value = settings.url
}

async function load() {
	loading.value = true
	message.value = ''
	try {
		const response = await axios.get<SettingsResponse>(endpoint)
		applySettings(response.data.ocs.data)
		loaded.value = true
	} catch {
		message.value = t('libresign', 'Could not load telemetry settings.')
	} finally {
		loading.value = false
	}
}

async function save() {
	busy.value = true
	message.value = ''
	try {
		const response = await axios.put<SettingsResponse>(endpoint, { enabled: enabled.value, url: url.value })
		applySettings(response.data.ocs.data)
		message.value = t('libresign', 'Telemetry settings saved.')
	} catch {
		message.value = t('libresign', 'Could not save telemetry settings. Use an HTTPS URL without credentials, query parameters, or a fragment.')
	} finally {
		busy.value = false
	}
}

async function sendReport() {
	busy.value = true
	message.value = ''
	try {
		const response = await axios.post<ReportResponse>(`${endpoint}/report`)
		message.value = response.data.ocs.data.status === 'sent'
			? t('libresign', 'Report sent.')
			: t('libresign', 'Report was not sent.')
		const settings = await axios.get<SettingsResponse>(endpoint)
		applySettings(settings.data.ocs.data)
	} catch {
		message.value = t('libresign', 'Report was not sent.')
	} finally {
		busy.value = false
	}
}

onMounted(load)
</script>

<style scoped lang="scss">
.telemetry {
	max-inline-size: 720px;
	overflow-wrap: anywhere;

	form,
	& {
		display: flex;
		flex-direction: column;
		gap: var(--default-grid-baseline);
	}

	&__actions {
		display: flex;
		flex-wrap: wrap;
		gap: calc(var(--default-grid-baseline) * 2);
	}
}
</style>
