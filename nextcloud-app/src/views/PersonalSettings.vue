<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="requrvhive-personal">
		<h2>RequrvHive</h2>
		<p class="requrvhive-personal__intro">
			{{ t('requrvhive', 'Choose which model RequrvHive uses for you, and set the defaults new conversations start with. Individual conversations can pin a different model from the chat header.') }}
		</p>

		<NcLoadingIcon v-if="loading" :size="32" />

		<SettingsTabs v-else v-model="tab" :tabs="tabs">
			<template #providers>
				<!--
					An admin can block every provider for a user. Say so plainly
					rather than showing an empty page with a note about defaults
					that no longer apply to anything.
				-->
				<NcNoteCard v-if="providers.length === 0" type="warning">
					{{ t('requrvhive', 'No AI provider is available for your account. Ask your administrator for access.') }}
				</NcNoteCard>

				<NcNoteCard v-else type="info">
					{{ inheritNote }}
				</NcNoteCard>

				<div class="requrvhive-personal__cards">
					<ProviderCard v-for="provider in providers"
						:key="provider.id"
						:provider="provider"
						:default-provider="selectedProvider"
						:refreshing="refreshing"
						scope="user"
						@make-default="makeDefault"
						@refresh-models="refreshModels"
						@saved="load(false)" />
				</div>

				<NcButton v-if="userProvider && providers.length > 0" type="tertiary" @click="followInstanceDefault">
					{{ t('requrvhive', 'Follow the instance default again') }}
				</NcButton>
			</template>

			<template #defaults>
				<NcSettingsSection :name="t('requrvhive', 'New conversations')"
					:description="t('requrvhive', 'Applied when you start a conversation. Existing conversations keep whatever they were created with.')">
					<div class="requrvhive-personal__field">
						<label class="requrvhive-personal__label" for="requrvhive-system-prompt">
							{{ t('requrvhive', 'Default system prompt') }}
						</label>
						<textarea id="requrvhive-system-prompt"
							v-model="defaultSystemPrompt"
							class="requrvhive-personal__textarea"
							rows="4"
							:placeholder="t('requrvhive', 'Custom instructions for the model…')"
							@input="dirty = true" />
					</div>

					<NcCheckboxRadioSwitch :model-value="defaultVerbose"
						@update:model-value="v => { defaultVerbose = v; dirty = true }">
						{{ t('requrvhive', 'Show verbose mode by default') }}
					</NcCheckboxRadioSwitch>
				</NcSettingsSection>

				<NcSettingsSection :name="t('requrvhive', 'Notifications')"
					:description="t('requrvhive', 'RequrvHive answers AI tasks that other apps start, and those apps normally show you the result themselves. Successful tasks stay quiet unless you ask for them; failures are reported because they usually point at your RequrvHive configuration.')">
					<NcCheckboxRadioSwitch :model-value="taskSuccessNotifications"
						@update:model-value="v => { taskSuccessNotifications = v; dirty = true }">
						{{ t('requrvhive', 'Notify me when an AI task completes') }}
					</NcCheckboxRadioSwitch>

					<NcCheckboxRadioSwitch :model-value="taskFailureNotifications"
						@update:model-value="v => { taskFailureNotifications = v; dirty = true }">
						{{ t('requrvhive', 'Notify me when an AI task fails') }}
					</NcCheckboxRadioSwitch>

					<div class="requrvhive-personal__actions">
						<NcButton type="primary" :disabled="saving || !dirty" @click="saveDefaults">
							{{ saving ? t('requrvhive', 'Saving…') : t('requrvhive', 'Save') }}
						</NcButton>
					</div>

					<NcNoteCard v-if="message" :type="messageType">
						{{ message }}
					</NcNoteCard>
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

import ProviderCard from '../components/settings/ProviderCard.vue'
import SettingsTabs from '../components/settings/SettingsTabs.vue'
import {
	getSettings,
	getUserProviders,
	saveSettings,
	saveUserProvider,
} from '../settings-api.js'

export default {
	name: 'PersonalSettings',
	components: {
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
			saving: false,
			dirty: false,
			message: '',
			messageType: 'success',
			providers: [],
			/** The effective provider — a personal override, or the instance default. */
			selectedProvider: '',
			/** The personal override alone; '' means "follow the instance default". */
			userProvider: '',
			adminProvider: '',
			defaultSystemPrompt: '',
			defaultVerbose: false,
			taskSuccessNotifications: false,
			taskFailureNotifications: true,
		}
	},
	computed: {
		tabs() {
			return [
				{ id: 'providers', label: t('requrvhive', 'Providers') },
				{ id: 'defaults', label: t('requrvhive', 'Defaults') },
			]
		},
		inheritNote() {
			const label = this.providers.find(p => p.id === this.adminProvider)?.label || this.adminProvider
			return this.userProvider
				? t('requrvhive', 'You have picked your own provider. Leave a field blank on a card to fall back to the instance setting.')
				: t('requrvhive', 'You are following the instance default ({provider}). Picking a provider here overrides it for your conversations.', { provider: label })
		},
	},
	async mounted() {
		await this.load()
	},
	methods: {
		t,
		async load(showSpinner = true) {
			if (showSpinner) {
				this.loading = true
			}
			try {
				const [{ data: providerData }, { data: settings }] = await Promise.all([
					getUserProviders(false),
					getSettings(),
				])
				this.providers = providerData.providers
				this.selectedProvider = providerData.defaultProvider
				this.userProvider = providerData.userProvider
				this.adminProvider = providerData.adminProvider

				this.defaultSystemPrompt = settings.defaultSystemPrompt || ''
				this.defaultVerbose = !!settings.defaultVerbose
				this.taskSuccessNotifications = !!settings.taskSuccessNotifications
				this.taskFailureNotifications = !!settings.taskFailureNotifications
				this.dirty = false
			} finally {
				this.loading = false
			}
		},
		async refreshModels() {
			this.refreshing = true
			try {
				const { data } = await getUserProviders(true)
				this.providers = data.providers
			} finally {
				this.refreshing = false
			}
		},
		async makeDefault(providerId) {
			this.selectedProvider = providerId
			await saveUserProvider(providerId, {}, true)
			await this.load(false)
		},
		/** Clears the personal override so the instance default applies again. */
		async followInstanceDefault() {
			await saveUserProvider(this.selectedProvider, {}, false)
			await this.load(false)
		},
		async saveDefaults() {
			this.saving = true
			this.message = ''
			try {
				await saveSettings({
					default_system_prompt: this.defaultSystemPrompt,
					default_verbose: this.defaultVerbose ? '1' : '0',
					task_success_notifications: this.taskSuccessNotifications ? '1' : '0',
					task_failure_notifications: this.taskFailureNotifications ? '1' : '0',
				})
				this.dirty = false
				this.messageType = 'success'
				this.message = t('requrvhive', 'Saved.')
			} catch (err) {
				this.messageType = 'error'
				this.message = err.response?.data?.error || err.message
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.requrvhive-personal {
	padding: 8px 0;
}

.requrvhive-personal__intro {
	max-width: 900px;
	margin-bottom: 24px;
	color: var(--color-text-maxcontrast);
}

.requrvhive-personal__cards {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
	gap: 16px;
	margin: 16px 0;
	align-items: start;
}

.requrvhive-personal__field {
	max-width: 640px;
	margin-bottom: 16px;
}

.requrvhive-personal__label {
	display: block;
	margin-bottom: 4px;
	font-weight: 600;
}

.requrvhive-personal__textarea {
	width: 100%;
	resize: vertical;
}

.requrvhive-personal__actions {
	margin-top: 16px;
}
</style>
