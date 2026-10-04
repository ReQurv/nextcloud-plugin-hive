<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Stubs for the Mail app's public classes, reached directly by
 * `MailToolsService::fetchBody()` to pull a full message body.
 *
 * The Mail app is a separate Composer package that this app does not depend
 * on — it is installed side by side in the Nextcloud instance — so its
 * classes cannot be resolved from vendor/. Every call site is guarded with
 * `class_exists()` and wrapped in a catch-all, so the stubs only need the
 * signatures that are used. Like the other stubs, each is guarded so that a
 * real class, if ever loadable, wins.
 */

namespace OCA\Mail\Db;

if (!class_exists(Account::class)) {
    class Account {
    }
}

if (!class_exists(Mailbox::class)) {
    class Mailbox {
    }
}

if (!class_exists(Message::class)) {
    class Message {
        public function getMailboxId(): int {
            return 0;
        }
    }
}

namespace OCA\Mail\IMAP;

use OCA\Mail\Db\Account;

if (!class_exists(IMAPClient::class)) {
    class IMAPClient {
        public function logout(): void {
        }
    }
}

if (!class_exists(Message::class)) {
    class Message {
        /** @return array<string, mixed> */
        public function getFullMessage(int $messageId): array {
            return [];
        }
    }
}

if (!class_exists(IMAPClientFactory::class)) {
    class IMAPClientFactory {
        public function getClient(Account $account): IMAPClient {
            return new IMAPClient();
        }
    }
}

namespace OCA\Mail\Service;

use OCA\Mail\Db\Account;
use OCA\Mail\Db\Mailbox;
use OCA\Mail\Db\Message;
use OCA\Mail\IMAP\IMAPClient;
use OCA\Mail\IMAP\Message as ImapMessage;

if (!class_exists(MailManager::class)) {
    class MailManager {
        public function getMessage(string $userId, int $messageId): Message {
            return new Message();
        }

        public function getMailbox(string $userId, int $mailboxId): Mailbox {
            return new Mailbox();
        }

        public function getImapMessage(IMAPClient $client, Account $account, Mailbox $mailbox, int $uid, bool $loadBody): ImapMessage {
            return new ImapMessage();
        }
    }
}

if (!class_exists(AccountService::class)) {
    class AccountService {
        public function find(string $userId, int $accountId): Account {
            return new Account();
        }
    }
}
