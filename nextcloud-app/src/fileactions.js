// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * RequrvHive File Actions - Modern Vue Implementation
 * Registers "Ask Hive" action in Files app
 */

import { registerFileAction, FileAction, Permission } from '@nextcloud/files'
import { translate as t } from '@nextcloud/l10n'

console.log('[RequrvHive] Registering modern file action')

// Register the "Ask Hive" file action
registerFileAction(new FileAction({
	id: 'requrvhive-ask-hive',

	displayName: () => t('requrvhive', 'Ask Hive'),

	iconSvgInline: () => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
		<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 17h-2v-2h2v2zm2.07-7.75l-.9.92C13.45 12.9 13 13.5 13 15h-2v-.5c0-1.1.45-2.1 1.17-2.83l1.24-1.26c.37-.36.59-.86.59-1.41 0-1.1-.9-2-2-2s-2 .9-2 2H8c0-2.21 1.79-4 4-4s4 1.79 4 4c0 .88-.36 1.68-.93 2.25z"/>
	</svg>`,

	// Only show for readable files
	enabled: ({ nodes }) => {
		// Show for single files only (not directories)
		if (nodes.length !== 1) return false
		const node = nodes[0]

		// Must be a file, not a directory
		if (node.type !== 'file') return false

		// Must have read permission
		if ((node.permissions & Permission.READ) === 0) return false

		return true
	},

	// Execute when the action is clicked
	exec: async ({ nodes }) => {
		const node = nodes[0]
		try {
			console.log('[RequrvHive] File action triggered for:', node.basename)

			// Import and show the modal dialog
			const { createApp } = await import('vue')
			const { default: AskHiveModal } = await import('./components/AskHiveModal.vue')

			// Create a modal container
			const modalId = 'requrvhive-modal-' + Date.now()
			const container = document.createElement('div')
			container.id = modalId
			document.body.appendChild(container)

			// Create Vue app with the modal
			const app = createApp(AskHiveModal, {
				file: node,
				onClose: () => {
					app.unmount()
					container.remove()
				},
			})

			app.mount(container)

			return null
		} catch (error) {
			console.error('[RequrvHive] Error opening Ask Hive modal:', error)
			return null
		}
	},

	// Order in the menu (lower = higher priority)
	order: 10,
}))

console.log('[RequrvHive] File action registered successfully')
