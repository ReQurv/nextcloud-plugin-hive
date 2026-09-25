<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<NcModal
		:name="t('requrvhive', 'Ask Hive about {filename}', { filename: file.basename })"
		@close="onClose">
		<div class="requrvhive-modal-content">
			<!-- File size warning -->
			<NcNoteCard v-if="isLargeFile" type="warning">
				<p>
					{{ t('requrvhive', 'This file is {size}MB. Processing large files may take longer or fail.', { size: fileSizeMB }) }}
				</p>
				<p>
					{{ t('requrvhive', 'Consider selecting a smaller portion or asking your administrator to increase limits.') }}
				</p>
			</NcNoteCard>

			<!-- Image preview -->
			<div v-if="isImage" class="requrvhive-image-preview">
				<img v-if="previewUrl"
					:src="previewUrl"
					:alt="file.basename"
					class="preview-image" />
				<NcLoadingIcon v-else :size="32" />
			</div>

			<!-- Input field for question -->
			<NcTextField v-model="prompt"
				:label="t('requrvhive', 'What would you like to know?')"
				:placeholder="isImage ? t('requrvhive', 'Ask a question about this image...') : t('requrvhive', 'Ask a question about this file...')"
				type="textarea"
				:rows="4"
				class="requrvhive-prompt-input" />

			<!-- Action buttons -->
			<div class="requrvhive-actions">
				<NcButton type="secondary"
					:disabled="loading"
					@click="summarize">
					<template #icon>
						<FileDocumentIcon v-if="!isImage" :size="20" />
						<ImageIcon v-else :size="20" />
					</template>
					{{ isImage ? t('requrvhive', 'Describe') : t('requrvhive', 'Summarize') }}
				</NcButton>

				<NcButton type="primary"
					:disabled="!prompt || loading"
					@click="ask">
					<template #icon>
						<CommentQuestionIcon :size="20" />
					</template>
					{{ t('requrvhive', 'Ask') }}
				</NcButton>
			</div>

			<!-- Loading indicator -->
			<NcLoadingIcon v-if="loading" :size="32" class="requrvhive-loading" />

			<!-- Response display -->
			<div v-if="response" class="requrvhive-response">
				<h3>{{ t('requrvhive', 'Response') }}</h3>
				<div class="requrvhive-response-text">
					{{ response }}
				</div>
			</div>

			<!-- Error display -->
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
		</div>
	</NcModal>
</template>

<script>
import { NcModal, NcTextField, NcButton, NcNoteCard, NcLoadingIcon } from '@nextcloud/vue'
import FileDocumentIcon from 'vue-material-design-icons/FileDocument.vue'
import CommentQuestionIcon from 'vue-material-design-icons/CommentQuestion.vue'
import ImageIcon from 'vue-material-design-icons/Image.vue'

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'

import { isImageMime, getFilePreview, analyzeFile } from '../api.js'

export default {
	name: 'AskHiveModal',

	components: {
		NcModal,
		NcTextField,
		NcButton,
		NcNoteCard,
		NcLoadingIcon,
		FileDocumentIcon,
		CommentQuestionIcon,
		ImageIcon,
	},

	props: {
		file: {
			type: Object,
			required: true,
		},
		onClose: {
			type: Function,
			required: true,
		},
	},

	data() {
		return {
			prompt: '',
			response: '',
			error: '',
			loading: false,
			previewUrl: null,
		}
	},

	computed: {
		fileSizeMB() {
			return (this.file.size / (1024 * 1024)).toFixed(2)
		},

		isLargeFile() {
			return this.file.size > 5 * 1024 * 1024 // 5MB
		},

		isImage() {
			return isImageMime(this.file.mime)
		},

		isPdf() {
			return this.file.mime === 'application/pdf'
		},

		filePath() {
			return this.file.path || this.file.source
		},
	},

	mounted() {
		if (this.isImage) {
			this.loadPreview()
		}
	},

	methods: {
		t,

		async loadPreview() {
			try {
				const { data } = await getFilePreview(this.filePath, 400, 400)
				this.previewUrl = 'data:' + data.mimeType + ';base64,' + data.content
			} catch {
				// Preview not available
			}
		},

		async getFileContent() {
			try {
				const response = await axios.get(this.file.source)
				return response.data
			} catch (err) {
				console.error('[RequrvHive] Error fetching file content:', err)
				throw new Error(t('requrvhive', 'Failed to read file content'))
			}
		},

		async summarize() {
			this.loading = true
			this.error = ''
			this.response = ''

			try {
				if (this.isImage || this.isPdf) {
					const defaultPrompt = this.isImage
						? 'Describe this image in detail.'
						: 'Summarize this document.'
					const result = await this.analyzeViaBackend(defaultPrompt)
					this.response = result.response
				} else {
					const content = await this.getFileContent()
					const result = await this.callHiveAPI('summarize', { content })
					if (result.error) {
						this.error = result.error
					} else {
						this.response = result.response
					}
				}
			} catch (err) {
				this.error = err.message
			} finally {
				this.loading = false
			}
		},

		async ask() {
			if (!this.prompt) return

			this.loading = true
			this.error = ''
			this.response = ''

			try {
				if (this.isImage || this.isPdf) {
					const result = await this.analyzeViaBackend(this.prompt)
					this.response = result.response
				} else {
					const content = await this.getFileContent()
					const result = await this.callHiveAPI('ask', {
						prompt: this.prompt,
						context: content,
					})
					if (result.error) {
						this.error = result.error
					} else {
						this.response = result.response
					}
				}
			} catch (err) {
				this.error = err.message
			} finally {
				this.loading = false
			}
		},

		async analyzeViaBackend(prompt) {
			try {
				const { data } = await analyzeFile(this.filePath, prompt)
				if (data.error) {
					throw new Error(data.error)
				}
				return data
			} catch (err) {
				throw new Error(
					err.response?.data?.error
					|| err.message
					|| t('requrvhive', 'Failed to analyze file'),
				)
			}
		},

		async callHiveAPI(endpoint, data) {
			try {
				const { generateUrl } = await import('@nextcloud/router')
				const response = await axios.post(
					generateUrl(`/apps/requrvhive/api/${endpoint}`),
					data,
				)
				return response.data
			} catch (err) {
				console.error('[RequrvHive] API error:', err)
				throw new Error(
					err.response?.data?.error
					|| t('requrvhive', 'Failed to communicate with the Hive API'),
				)
			}
		},
	},
}
</script>

<style scoped>
.requrvhive-modal-content {
	padding: 20px;
	min-width: 500px;
	max-width: 700px;
}

.requrvhive-image-preview {
	display: flex;
	justify-content: center;
	margin-bottom: 16px;
	padding: 8px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius-large);
	min-height: 100px;
	align-items: center;
}

.preview-image {
	max-width: 100%;
	max-height: 300px;
	border-radius: var(--border-radius);
	object-fit: contain;
}

.requrvhive-prompt-input {
	margin: 20px 0;
}

.requrvhive-actions {
	display: flex;
	gap: 10px;
	margin: 20px 0;
}

.requrvhive-loading {
	display: flex;
	justify-content: center;
	margin: 20px 0;
}

.requrvhive-response {
	margin-top: 20px;
	padding: 15px;
	background-color: var(--color-background-dark);
	border-radius: var(--border-radius-large);
}

.requrvhive-response h3 {
	margin-top: 0;
	margin-bottom: 10px;
}

.requrvhive-response-text {
	white-space: pre-wrap;
	font-family: var(--font-face);
	line-height: 1.6;
}
</style>
