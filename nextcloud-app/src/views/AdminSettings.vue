<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="requrvhive-admin">
		<h2>RequrvHive</h2>
		<p class="requrvhive-admin__intro">
			{{ t('requrvhive', 'RequrvHive connects Nextcloud to ReQurv AI Hive so your users can chat, summarise, translate and more, directly inside Nextcloud. Add the instance API key and use Test connection to confirm it works.') }}
		</p>

		<NcLoadingIcon v-if="loading" :size="32" />

		<SettingsTabs v-else v-model="tab" :tabs="tabs">
			<template #providers>
				<NcNoteCard type="info">
					{{ t('requrvhive', 'The provider marked as default serves every user who has not picked their own. Users can override the provider and the model in their personal settings, and pin either one per conversation.') }}
				</NcNoteCard>

				<div class="requrvhive-admin__cards">
					<ProviderCard v-for="provider in providers"
						:key="provider.id"
						:provider="provider"
						:default-provider="defaultProvider"
						:refreshing="refreshing"
						scope="admin"
						@make-default="makeDefault"
						@refresh-models="refreshModels"
						@saved="load(false)" />
				</div>
			</template>

			<template #defaults>
				<NcSettingsSection :name="t('requrvhive', 'Search integration')"
					:description="t('requrvhive', 'Expose RequrvHive conversations to Nextcloud\'s unified search.')">
					<NcCheckboxRadioSwitch :model-value="searchEnabled" @update:model-value="saveSearch">
						{{ t('requrvhive', 'Include RequrvHive in unified search') }}
					</NcCheckboxRadioSwitch>
				</NcSettingsSection>

				<NcSettingsSection :name="t('requrvhive', 'Model defaults')"
					:description="t('requrvhive', 'Model and token limits are configured on the provider card on the Providers tab.')">
					<NcButton type="secondary" @click="tab = 'providers'">
						{{ t('requrvhive', 'Go to providers') }}
					</NcButton>
				</NcSettingsSection>
			</template>

			<template #mcp>
				<NcSettingsSection :name="t('requrvhive', 'MCP servers')"
					:description="t('requrvhive', 'Model Context Protocol servers give the model tools. Tool calls are dispatched by this Nextcloud instance unless the native connector below is enabled.')">
					<McpServerList />
				</NcSettingsSection>
			</template>

			<template #advanced>
				<NcSettingsSection :name="t('requrvhive', 'Resources')">
					<ul class="requrvhive-admin__links">
						<li><a href="https://hive.requrv.ai" target="_blank" rel="noopener noreferrer">ReQurv AI Hive</a></li>
					</ul>
				</NcSettingsSection>
			</template>
		</SettingsTabs>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'

import McpServerList from '../components/settings/McpServerList.vue'
import ProviderCard from '../components/settings/ProviderCard.vue'
import SettingsTabs from '../components/settings/SettingsTabs.vue'
import {
	getAdminProviders,
	saveAdminProvider,
	saveAdminSettings,
} from '../settings-api.js'

export default {
	name: 'AdminSettings',
	components: {
		McpServerList,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcSettingsSection,
		ProviderCard,
		SettingsTabs,
	},
	data() {
		return {
			tab: 'providers',
			loading: true,
			refreshing: false,
			providers: [],
			defaultProvider: '',
			searchEnabled: false,
		}
	},
	computed: {
		tabs() {
			return [
				{ id: 'providers', label: t('requrvhive', 'Providers') },
				{ id: 'defaults', label: t('requrvhive', 'Defaults') },
				{ id: 'mcp', label: t('requrvhive', 'MCP servers') },
				{ id: 'advanced', label: t('requrvhive', 'Advanced') },
			]
		},
	},
	async mounted() {
		const dataset = document.getElementById('requrvhive-admin-settings')?.dataset
		this.searchEnabled = dataset?.searchEnabled === '1'
		await this.load()
	},
	methods: {
		t,
		async load(showSpinner = true) {
			if (showSpinner) {
				this.loading = true
			}
			try {
				const { data } = await getAdminProviders(false)
				this.providers = data.providers
				this.defaultProvider = data.defaultProvider
			} finally {
				this.loading = false
			}
		},
		/** Bypasses the model-list cache; one outbound call per provider. */
		async refreshModels() {
			this.refreshing = true
			try {
				const { data } = await getAdminProviders(true)
				this.providers = data.providers
			} finally {
				this.refreshing = false
			}
		},
		async makeDefault(providerId) {
			// Optimistic: the radio has already moved visually.
			this.defaultProvider = providerId
			await saveAdminProvider(providerId, {}, true)
			await this.load(false)
		},
		async saveSearch(enabled) {
			this.searchEnabled = enabled
			await saveAdminSettings({ search_enabled: enabled ? '1' : '0' })
		},
	},
}
</script>

<style scoped>
.requrvhive-admin {
	padding: 8px 0;
}

.requrvhive-admin__intro {
	max-width: 900px;
	margin-bottom: 24px;
	color: var(--color-text-maxcontrast);
}

.requrvhive-admin__cards {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
	gap: 16px;
	margin-top: 16px;
	align-items: start;
}

.requrvhive-admin__links {
	margin-top: 12px;
	padding-left: 0;
	list-style: none;
}

.requrvhive-admin__links a {
	text-decoration: underline;
}
</style>
