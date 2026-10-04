<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * The built-in chat tools for the user's mail.
 *
 * The mail app persists account, mailbox and message metadata in its own
 * tables, but the message body stays on the IMAP server. The listing tools
 * therefore read the metadata straight from the database — fast and safe
 * even when no IMAP connection is possible — while get_mail_message reuses
 * the mail app's own service stack (MailManager + IMAP client) to fetch the
 * full text of one message on demand, falling back to the stored preview
 * when the server cannot be reached.
 */
class MailToolsService {
    public const TOOL_LIST_MAILBOXES = 'list_mailboxes';
    public const TOOL_LIST_MAIL_MESSAGES = 'list_mail_messages';
    public const TOOL_GET_MAIL_MESSAGE = 'get_mail_message';

    private const DEFAULT_MESSAGE_LIMIT = 20;
    private const MAX_MESSAGE_LIMIT = 50;
    private const MAX_BODY_CHARS = 64 * 1024;

    public function __construct(
        private IDBConnection $db,
        private LoggerInterface $logger,
    ) {
    }

    public static function isTool(string $name): bool {
        return match ($name) {
            self::TOOL_LIST_MAILBOXES,
            self::TOOL_LIST_MAIL_MESSAGES,
            self::TOOL_GET_MAIL_MESSAGE => true,
            default => false,
        };
    }

    /**
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public function getTools(): array {
        $mailboxProp = [
            'type' => 'string',
            'description' => "Name of the mailbox as shown by list_mailboxes, e.g. 'INBOX'.",
        ];

        return [
            [
                'name' => self::TOOL_LIST_MAILBOXES,
                'description' => "List the user's mail accounts and their mailboxes (INBOX, Sent, ...), with total and unread counts. Use it before listing messages to know the exact mailbox names.",
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [],
                    'required' => [],
                ],
            ],
            [
                'name' => self::TOOL_LIST_MAIL_MESSAGES,
                'description' => "List recent messages of one of the user's mailboxes (newest first), with sender, subject, date, flags and a short preview. Use it when the user asks about their mail without naming a specific message.",
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'mailbox' => $mailboxProp,
                        'unread_only' => [
                            'type' => 'boolean',
                            'description' => 'Only list messages that have not been read yet. Defaults to false.',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'maximum' => self::MAX_MESSAGE_LIMIT,
                            'description' => 'Maximum number of messages to return (default 20).',
                        ],
                    ],
                    'required' => ['mailbox'],
                ],
            ],
            [
                'name' => self::TOOL_GET_MAIL_MESSAGE,
                'description' => "Read one specific mail message: sender, recipients, date, full subject and the message text. Use it after list_mail_messages when the user wants the content of a particular mail. The mail's 'uid' comes from the list.",
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'mailbox' => $mailboxProp,
                        'uid' => [
                            'type' => 'integer',
                            'description' => 'IMAP UID of the message, as reported by list_mail_messages.',
                        ],
                    ],
                    'required' => ['mailbox', 'uid'],
                ],
            ],
        ];
    }

    /**
     * Execute one of the mail tools.
     *
     * Never throws: a failure is returned as an isError result so the model
     * can react to it inside the same turn.
     *
     * @param array<string, mixed> $input
     * @return array{content: list<array<string, mixed>>, isError: bool}
     */
    public function execute(string $name, array $input, string $userId): array {
        try {
            $result = match ($name) {
                self::TOOL_LIST_MAILBOXES => $this->listMailboxes($userId),
                self::TOOL_LIST_MAIL_MESSAGES => $this->listMessages(
                    (string)($input['mailbox'] ?? ''),
                    (bool)($input['unread_only'] ?? false),
                    isset($input['limit']) ? (int)$input['limit'] : null,
                    $userId,
                ),
                self::TOOL_GET_MAIL_MESSAGE => $this->getMessage(
                    (string)($input['mailbox'] ?? ''),
                    isset($input['uid']) ? (int)$input['uid'] : null,
                    $userId,
                ),
                default => throw new \InvalidArgumentException("Unknown tool: $name"),
            };
        } catch (\Throwable $e) {
            $this->logger->warning('RequrvHive mail tool failed', [
                'tool' => $name,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResult('The mail tool call failed. Check the parameters and try again.');
        }

        return $result;
    }

    /**
     * @return array{content: list<array<string, mixed>>, isError: bool}
     */
    private function listMailboxes(string $userId): array {
        // select()/where()/orderBy() quote their identifiers themselves, so
        // they take plain dotted names; getColumnName() is only for raw-SQL
        // slots like the join condition.
        $qb = $this->db->getQueryBuilder();
        $qb->select(
            'a.email AS account_email',
            'a.name AS account_name',
            'm.name AS mailbox_name',
            'm.messages AS messages',
            'm.unseen AS unseen',
            'm.special_use AS special_use',
        )
            ->from('mail_mailboxes', 'm')
            ->innerJoin('m', 'mail_accounts', 'a', $qb->getColumnName('id', 'a') . ' = ' . $qb->getColumnName('account_id', 'm'))
            ->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
            ->orderBy('a.order', 'ASC')
            ->addOrderBy('m.name', 'ASC');

        $accounts = [];
        foreach ($qb->executeQuery()->fetchAll() as $row) {
            $account = (string)$row['account_email'] . (($row['account_name'] !== '' && $row['account_name'] !== $row['account_email']) ? ' (' . $row['account_name'] . ')' : '');
            $label = (string)$row['mailbox_name'];
            if ((string)$row['special_use'] !== '') {
                $label .= '  [' . $row['special_use'] . ']';
            }
            $accounts[$account][] = $label . '  (' . (int)$row['messages'] . ' messages, ' . (int)$row['unseen'] . ' unread)';
        }

        if ($accounts === []) {
            return $this->errorResult('No mail account is configured for this user. Add one in the Mail app before using the mail tools.');
        }

        $lines = [];
        foreach ($accounts as $email => $mailboxes) {
            $lines[] = $email . ':';
            foreach ($mailboxes as $mailbox) {
                $lines[] = '  ' . $mailbox;
            }
        }
        $lines[] = '';
        $lines[] = "Use the mailbox name (e.g. 'INBOX') in list_mail_messages and get_mail_message.";

        return $this->textResult(implode("\n", $lines));
    }

    /**
     * @return array{content: list<array<string, mixed>>, isError: bool}
     */
    private function listMessages(string $mailbox, bool $unreadOnly, ?int $limit, string $userId): array {
        if (trim($mailbox) === '') {
            return $this->errorResult("A mailbox name is required. Call list_mailboxes first to see the available ones.");
        }
        $limit = $limit === null ? self::DEFAULT_MESSAGE_LIMIT : min(max($limit, 1), self::MAX_MESSAGE_LIMIT);

        $found = $this->findMailbox($mailbox, $userId);
        if ($found === null) {
            return $this->errorResult("Mailbox '{$mailbox}' not found. Call list_mailboxes to see the available names.");
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'uid', 'subject', 'sent_at', 'flag_seen', 'flag_flagged', 'flag_important', 'flag_draft', 'flag_answered', 'flag_attachments', 'preview_text')
            ->from('mail_messages')
            ->where($qb->expr()->eq('mailbox_id', $qb->createNamedParameter((int)$found['id'], IQueryBuilder::PARAM_INT)))
            ->orderBy('sent_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults($limit);
        if ($unreadOnly) {
            $qb->andWhere($qb->expr()->eq('flag_seen', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
        }

        $messages = $qb->executeQuery()->fetchAll();
        if ($messages === []) {
            $what = $unreadOnly ? 'No unread' : 'No';
            return $this->textResult("$what messages in mailbox '{$mailbox}'.");
        }

        $recipients = $this->recipients(array_map(static fn(array $m): int => (int)$m['id'], $messages));

        $lines = ['Messages in mailbox ' . $mailbox . ' (newest first):'];
        foreach ($messages as $i => $message) {
            $lines[] = ($i + 1) . '. [' . date('Y-m-d H:i', (int)$message['sent_at']) . '] ' . ($message['subject'] !== '' ? $message['subject'] : '(no subject)')
                . ' — from: ' . $this->address($recipients, (int)$message['id'], 0)
                . $this->flagLine((int)$message['flag_flagged'], (int)$message['flag_important'], (int)$message['flag_draft'], (int)$message['flag_attachments']);
            $lines[] = '    uid: ' . (int)$message['uid'] . '  (use it with get_mail_message to read the full text)';
            $preview = trim((string)$message['preview_text']);
            if ($preview !== '') {
                $lines[] = '    preview: ' . (mb_strlen($preview) > 200 ? mb_substr($preview, 0, 200) . '…' : $preview);
            }
        }

        return $this->textResult(implode("\n", $lines));
    }

    /**
     * @return array{content: list<array<string, mixed>>, isError: bool}
     */
    private function getMessage(string $mailbox, ?int $uid, string $userId): array {
        if (trim($mailbox) === '') {
            return $this->errorResult("A mailbox name is required. Call list_mailboxes first to see the available ones.");
        }
        if ($uid === null || $uid <= 0) {
            return $this->errorResult('A valid message uid is required (a positive integer, as reported by list_mail_messages).');
        }

        $found = $this->findMailbox($mailbox, $userId);
        if ($found === null) {
            return $this->errorResult("Mailbox '{$mailbox}' not found. Call list_mailboxes to see the available names.");
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'uid', 'subject', 'sent_at', 'preview_text')
            ->from('mail_messages')
            ->where($qb->expr()->eq('mailbox_id', $qb->createNamedParameter((int)$found['id'], IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid, IQueryBuilder::PARAM_INT)));
        $row = $qb->executeQuery()->fetch();
        if ($row === false) {
            return $this->errorResult("Message uid {$uid} not found in mailbox '{$mailbox}'. It may have been deleted or moved; run list_mail_messages again.");
        }

        $header = "Message " . $uid . " in mailbox " . $mailbox . ' (db id ' . (int)$row['id'] . "):\n"
            . 'subject: ' . ($row['subject'] !== '' ? $row['subject'] : '(no subject)') . "\n"
            . 'date: ' . date('Y-m-d H:i', (int)$row['sent_at']);

        $body = $this->fetchBody((int)$row['id'], (int)$found['account_id'], (string)$found['name'], $uid, $userId);
        if ($body === null) {
            $preview = trim((string)$row['preview_text']);
            $header .= "\n\n[The full text could not be fetched right now (IMAP server unreachable?)."
                . ($preview !== '' ? " Stored preview follows.]" : ']') . "\n" . $preview;
            return $this->textResult($header);
        }

        $truncated = mb_strlen($body) > self::MAX_BODY_CHARS;
        if ($truncated) {
            $body = mb_substr($body, 0, self::MAX_BODY_CHARS) . "\n\n[body truncated at " . self::MAX_BODY_CHARS . ' characters]';
        }

        return $this->textResult($header . "\n\n--- body ---\n" . $body);
    }

    /**
     * Fetch the full message text through the mail app's own service stack.
     *
     * Returns null when the mail app or its IMAP layer is not available or
     * the server cannot be reached; the caller falls back to the preview.
     */
    private function fetchBody(int $messageId, int $accountId, string $mailbox, int $uid, string $userId): ?string {
        try {
            if (!class_exists('OCA\\Mail\\Service\\MailManager') || !class_exists('OCA\\Mail\\IMAP\\IMAPClientFactory')) {
                return null;
            }

            $mailManager = \OCP\Server::get(\OCA\Mail\Service\MailManager::class);
            $message = $mailManager->getMessage($userId, $messageId);
            $mailboxEntity = $mailManager->getMailbox($userId, $message->getMailboxId());
            $account = \OCP\Server::get(\OCA\Mail\Service\AccountService::class)->find($userId, $accountId);
            $client = \OCP\Server::get(\OCA\Mail\IMAP\IMAPClientFactory::class)->getClient($account);
            try {
                $imapMessage = $mailManager->getImapMessage($client, $account, $mailboxEntity, $uid, true);
                $full = $imapMessage->getFullMessage($messageId);
            } finally {
                $client->logout();
            }

            return (string)($full['body'] ?? '');
        } catch (\Throwable $e) {
            $this->logger->debug('RequrvHive: mail body fetch failed, using preview', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * @return array{id: int, name: string, account_id: int}|null
     */
    private function findMailbox(string $name, string $userId): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select(
            'm.id AS id',
            'm.name AS name',
            'm.account_id AS account_id',
        )
            ->from('mail_mailboxes', 'm')
            ->innerJoin('m', 'mail_accounts', 'a', $qb->getColumnName('id', 'a') . ' = ' . $qb->getColumnName('account_id', 'm'))
            ->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('m.name', $qb->createNamedParameter($name)));
        $row = $qb->executeQuery()->fetch();

        if ($row === false) {
            return null;
        }

        return ['id' => (int)$row['id'], 'name' => (string)$row['name'], 'account_id' => (int)$row['account_id']];
    }

    /**
     * Sender/recipients per message, keyed by the local message id.
     *
     * @param list<int> $messageIds
     * @return array<int, array<int, list<string>>>
     */
    private function recipients(array $messageIds): array {
        if ($messageIds === []) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('local_message_id', 'type', 'label', 'email')
            ->from('mail_recipients')
            ->where($qb->expr()->in('local_message_id', $qb->createNamedParameter($messageIds, IQueryBuilder::PARAM_INT_ARRAY)));
        $rows = $qb->executeQuery()->fetchAll();

        $byMessage = [];
        foreach ($rows as $row) {
            $address = (string)$row['label'] . ' <' . $row['email'] . '>';
            $byMessage[(int)$row['local_message_id']][(int)$row['type']][] = $address;
        }

        return $byMessage;
    }

    /**
     * @param array<int, array<int, list<string>>> $byMessage
     */
    private function address(array $byMessage, int $messageId, int $type): string {
        $addresses = $byMessage[$messageId][$type] ?? [];
        return $addresses === [] ? '(unknown)' : implode(', ', $addresses);
    }

    private function flagLine(int $flagged, int $important, int $draft, int $attachments): string {
        $flags = [];
        if ($draft) {
            $flags[] = 'draft';
        }
        if ($flagged) {
            $flags[] = 'starred';
        }
        if ($important) {
            $flags[] = 'important';
        }
        if ($attachments) {
            $flags[] = 'has attachments';
        }
        return $flags === [] ? '' : '  {' . implode(', ', $flags) . '}';
    }

    /** @return array{content: list<array<string, mixed>>, isError: bool} */
    private function textResult(string $text): array {
        return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => false];
    }

    /** @return array{content: list<array<string, mixed>>, isError: bool} */
    private function errorResult(string $text): array {
        return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => true];
    }
}
