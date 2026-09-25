<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\Listener;

use OCA\RequrvHive\AppInfo\Application;
use OCA\RequrvHive\ContextChat\ConversationContentProvider;
use OCA\RequrvHive\Service\ContextChatService;
use OCP\ContextChat\Events\ContentProviderRegisterEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Registers the RequrvHive conversation provider with Context Chat.
 *
 * Context Chat dispatches ContentProviderRegisterEvent only when it is
 * installed, so this listener is inert on servers without it.
 *
 * @template-implements IEventListener<ContentProviderRegisterEvent>
 */
class ContextChatProviderListener implements IEventListener {
    public function handle(Event $event): void {
        if (!$event instanceof ContentProviderRegisterEvent) {
            return;
        }

        $event->registerContentProvider(
            Application::APP_ID,
            ContextChatService::PROVIDER_ID,
            ConversationContentProvider::class,
        );
    }
}
