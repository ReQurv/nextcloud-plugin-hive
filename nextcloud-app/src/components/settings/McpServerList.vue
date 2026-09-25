<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="mcp-servers">
		<NcEmptyContent v-if="!loading && servers.length === 0"
			:name="t('requrvhive', 'No MCP servers configured')"
			:description="t('requrvhive', 'Add an MCP server to give the model tools that reach beyond this Nextcloud instance.')" />

		<div v-for="server in servers" :key="server.id" class="mcp-server">
			<div class="mcp-server__head">
				<strong>{{ server.display_name }}</strong>
				<span class="mcp-server__status" :class="`mcp-server__status--${server.last_status || 'unknown'}`">
					{{ server.last_status || t('requrvhive', 'unknown') }}
				</span>
			</div>

			<div class="mcp-server__meta">
				<code>{{ server.url }}</code>
				<span>{{ metaLine(server) }}</span>
			</div>

			<NcNoteCard v-if="server.last_error" type="error">
				{{ server.last_error }}
			</NcNoteCard>
			<NcNoteCard v-if="results[server.id]" :type="results[server.id].type">
				{{ results[server.id].message }}
			</NcNoteCard>

			<div class="mcp-server__actions">
				<NcButton type="secondary" :disabled="testing === server.id" @click="test(server)">
					{{ testing === server.id ? t('requrvhive', 'Testing…') : t('requrvhive', 'Test connection') }}
				</NcButton>
				<NcButton type="tertiary" @click="edit(server)">
					{{ t('requrvhive', 'Edit') }}
				</NcButton>
				<NcButton type="tertiary" @click="toggle(server)">
					{{ server.is_enabled ? t('requrvhive', 'Disable') : t('requrvhive', 'Enable') }}
				</NcButton>
				<NcButton type="tertiary" @click="remove(server)">
					{{ t('requrvhive', 'Delete') }}
				</NcButton>
			</div>
		</div>

		<NcButton type="primary" @click="add">
			{{ t('requrvhive', 'Add MCP server') }}
		</NcButton>

		<NcDialog v-if="form"
			:name="form.id ? t('requrvhive', 'Edit MCP server') : t('requrvhive', 'Add MCP server')"
			:open="true"
			size="normal"
			@update:open="form = null">
			<div class="mcp-form">
				<NcTextField v-model="form.displayName"
					:label="t('requrvhive', 'Display name')"
					placeholder="My MCP Server" />

				<NcTextField v-model="form.url"
					:label="t('requrvhive', 'Server URL')"
					placeholder="http://localhost:3339/mcp" />

				<div>
					<label class="mcp-form__label" for="mcp-auth-type">{{ t('requrvhive', 'Authentication') }}</label>
					<NcSelect v-model="form.authType"
						input-id="mcp-auth-type"
						:options="authTypes"
						:reduce="o => o.id"
						label="label"
						:clearable="false" />
				</div>

				<NcPasswordField v-if="form.authType === 'bearer'"
					v-model="form.authToken"
					:label="t('requrvhive', 'Bearer token')" />

				<div v-if="form.authType === 'oauth2'">
					<NcPasswordField v-model="form.registrationToken"
						:label="t('requrvhive', 'Registration token (optional)')"
						@input="form.registrationTokenDirty = true" />
					<p class="mcp-form__hint">
						{{ form.hasRegistrationToken
							? t('requrvhive', 'A registration token is stored. Leave empty to keep it, or type a new one to replace it.')
							: t('requrvhive', 'Only needed if the MCP server gates dynamic client registration (MCP_REGISTRATION_TOKEN). Leave empty for open registration.') }}
					</p>
					<NcButton type="secondary" @click="authorize">
						{{ t('requrvhive', 'Authenticate') }}
					</NcButton>
					<p v-if="oauthStatus" class="mcp-form__hint">{{ oauthStatus }}</p>
				</div>

				<NcNoteCard v-if="formError" type="error">
					{{ formError }}
				</NcNoteCard>
			</div>

			<template #actions>
				<NcButton type="tertiary" @click="form = null">
					{{ t('requrvhive', 'Cancel') }}
				</NcButton>
				<NcButton type="primary" :disabled="saving" @click="save">
					{{ saving ? t('requrvhive', 'Saving…') : t('requrvhive', 'Save') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import {
	authorizeMcpServer,
	createMcpServer,
	deleteMcpServer,
	listMcpServers,
	testMcpServer,
	updateMcpServer,
} from '../../settings-api.js'

export default {
	name: 'McpServerList',
	components: {
		NcButton,
		NcDialog,
		NcEmptyContent,
		NcNoteCard,
		NcPasswordField,
		NcSelect,
		NcTextField,
	},
	data() {
		return {
			servers: [],
			loading: true,
			saving: false,
			testing: null,
			form: null,
			formError: '',
			oauthStatus: '',
			results: {},
		}
	},
	computed: {
		authTypes() {
			return [
				{ id: 'none', label: t('requrvhive', 'None') },
				{ id: 'bearer', label: t('requrvhive', 'Bearer token') },
				{ id: 'oauth2', label: t('requrvhive', 'OAuth 2.0') },
			]
		},
	},
	mounted() {
		this.load()
		// The authorize flow finishes in a popup, which posts back here.
		window.addEventListener('message', this.onOauthMessage)
	},
	beforeUnmount() {
		window.removeEventListener('message', this.onOauthMessage)
	},
	methods: {
		t,
		async load() {
			this.loading = true
			try {
				const { data } = await listMcpServers()
				this.servers = Array.isArray(data) ? data : (data.servers || [])
			} finally {
				this.loading = false
			}
		},
		metaLine(server) {
			const parts = [
				server.auth_type,
				server.is_enabled ? t('requrvhive', 'Enabled') : t('requrvhive', 'Disabled'),
			]
			if (server.tool_count !== null && server.tool_count !== undefined) {
				parts.push(t('requrvhive', '{count} tools', { count: server.tool_count }))
			}
			if (server.auth_type === 'oauth2' && server.oauth_status) {
				parts.push(this.oauthLabel(server.oauth_status))
			}
			return parts.join(' · ')
		},
		oauthLabel(status) {
			if (status === 'authenticated') {
				return t('requrvhive', 'OAuth: OK')
			}
			return status === 'expired'
				? t('requrvhive', 'OAuth: expired')
				: t('requrvhive', 'OAuth: not authenticated')
		},
		add() {
			this.form = {
				id: null,
				displayName: '',
				url: '',
				authType: 'none',
				authToken: '',
				registrationToken: '',
				registrationTokenDirty: false,
				hasRegistrationToken: false,
			}
			this.formError = ''
			this.oauthStatus = ''
		},
		edit(server) {
			this.form = {
				id: server.id,
				displayName: server.display_name,
				url: server.url,
				authType: server.auth_type,
				authToken: '',
				registrationToken: '',
				registrationTokenDirty: false,
				hasRegistrationToken: !!server.registration_token_masked,
			}
			this.formError = ''
			this.oauthStatus = server.auth_type === 'oauth2' && server.oauth_status
				? this.oauthLabel(server.oauth_status)
				: ''
		},
		async save() {
			this.saving = true
			this.formError = ''
			const payload = {
				displayName: this.form.displayName,
				url: this.form.url,
				authType: this.form.authType,
				authToken: this.form.authToken,
			}
			// Only submit the registration token when it was actually edited —
			// the API reads a submitted empty string as "clear the stored one".
			if (this.form.authType === 'oauth2' && this.form.registrationTokenDirty) {
				payload.registrationToken = this.form.registrationToken
			}
			try {
				if (this.form.id) {
					await updateMcpServer(this.form.id, payload)
				} else {
					await createMcpServer(payload)
				}
				this.form = null
				await this.load()
			} catch (err) {
				this.formError = err.response?.data?.error || err.message
			} finally {
				this.saving = false
			}
		},
		async test(server) {
			this.testing = server.id
			try {
				const { data } = await testMcpServer(server.id)
				this.results = {
					...this.results,
					[server.id]: {
						type: data.success ? 'success' : 'error',
						message: data.message || (data.success ? t('requrvhive', 'Reachable') : t('requrvhive', 'Unreachable')),
					},
				}
				await this.load()
			} catch (err) {
				this.results = {
					...this.results,
					[server.id]: { type: 'error', message: err.response?.data?.error || err.message },
				}
			} finally {
				this.testing = null
			}
		},
		async toggle(server) {
			await updateMcpServer(server.id, { isEnabled: !server.is_enabled })
			await this.load()
		},
		async remove(server) {
			await deleteMcpServer(server.id)
			await this.load()
		},
		async authorize() {
			if (!this.form.id) {
				// The flow needs a server row to hang the token on.
				this.oauthStatus = t('requrvhive', 'Save the server first, then authenticate.')
				return
			}
			this.oauthStatus = t('requrvhive', 'Starting OAuth flow…')
			try {
				const { data } = await authorizeMcpServer(this.form.id)
				if (data.error) {
					this.oauthStatus = data.error
					return
				}
				const popup = window.open(data.authorize_url, 'requrvhive-oauth', 'width=600,height=700,popup=yes')
				this.oauthStatus = popup
					? t('requrvhive', 'Waiting for authentication…')
					: t('requrvhive', 'Popup blocked. Allow popups for this site and try again.')
			} catch (err) {
				this.oauthStatus = err.response?.data?.error || err.message
			}
		},
		onOauthMessage(event) {
			// Same-origin only: the callback page is served by this instance.
			if (event.origin !== window.location.origin) {
				return
			}
			if (event.data?.type === 'requrvhive-oauth-complete') {
				this.form = null
				this.load()
			}
		},
	},
}
</script>

<style scoped>
.mcp-server {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	padding: 12px 16px;
	margin-bottom: 12px;
}

.mcp-server__head {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	gap: 8px;
}

.mcp-server__status--ok {
	color: var(--color-success);
}

.mcp-server__status--error {
	color: var(--color-error);
}

.mcp-server__status--unknown {
	color: var(--color-text-maxcontrast);
}

.mcp-server__meta {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	margin: 4px 0 8px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.mcp-server__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.mcp-form {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 8px 0;
}

.mcp-form__label {
	display: block;
	margin-bottom: 4px;
	font-weight: 600;
}

.mcp-form__hint {
	margin-top: 4px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>
