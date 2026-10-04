<?php

declare(strict_types=1);

namespace OCA\RequrvHive\Tests\Unit\Service;

use OCA\RequrvHive\Service\MailToolsService;
use Psr\Log\LoggerInterface;

/**
 * The mail tools read the mail app's metadata tables. The body never lives
 * in the database, so the tests pin the behaviour the model actually feels:
 * listings work with zero IMAP access, and get_mail_message degrades to the
 * stored preview (never an exception) when the IMAP layer is unavailable —
 * which is also the case in this test environment, where the mail app's
 * classes are not loaded.
 */
class MailToolsServiceTest extends DbToolsTestCase {
    private MailToolsService $service;

    /** @param list<\OCP\DB\IResult|null> $results */
    private function makeService(array $results): MailToolsService {
        $db = $this->makeDb($results);
        $this->service = new MailToolsService($db, $this->createMock(LoggerInterface::class));
        $this->db = $db;

        return $this->service;
    }

    private function messageRow(array $overrides = []): array {
        return array_merge([
            'id' => 101,
            'uid' => 55,
            'subject' => 'Invoice #204',
            'sent_at' => 1760000000,
            'flag_seen' => 0,
            'flag_flagged' => 0,
            'flag_important' => 0,
            'flag_draft' => 0,
            'flag_answered' => 0,
            'flag_attachments' => 0,
            'preview_text' => 'Please find attached the invoice for October.',
        ], $overrides);
    }

    public function testIsToolRecognisesOnlyTheMailTools(): void {
        $this->assertTrue(MailToolsService::isTool(MailToolsService::TOOL_LIST_MAILBOXES));
        $this->assertTrue(MailToolsService::isTool(MailToolsService::TOOL_LIST_MAIL_MESSAGES));
        $this->assertTrue(MailToolsService::isTool(MailToolsService::TOOL_GET_MAIL_MESSAGE));
        $this->assertFalse(MailToolsService::isTool('list_calendars'));
        $this->assertFalse(MailToolsService::isTool('send_mail'));
    }

    public function testGetToolsOffersThreeToolsInCanonicalShape(): void {
        $this->makeService([]);

        $tools = $this->service->getTools();
        $names = array_map(static fn(array $t): string => (string)$t['name'], $tools);

        $this->assertSame(
            ['list_mailboxes', 'list_mail_messages', 'get_mail_message'],
            $names
        );
        foreach ($tools as $tool) {
            $this->assertArrayHasKey('description', $tool);
            $this->assertSame('object', $tool['input_schema']['type'] ?? null);
        }
    }

    public function testListMailboxesGroupsMailboxesPerAccount(): void {
        $this->makeService([$this->makeResult([
            ['account_email' => 'requrv@hive.local', 'account_name' => 'Personal', 'mailbox_name' => 'INBOX', 'messages' => 12, 'unseen' => 3, 'special_use' => '\Inbox'],
            ['account_email' => 'requrv@hive.local', 'account_name' => 'Personal', 'mailbox_name' => 'Sent', 'messages' => 40, 'unseen' => 0, 'special_use' => '\Sent'],
        ])]);

        $result = $this->service->execute(MailToolsService::TOOL_LIST_MAILBOXES, [], 'requrv');
        $this->assertFalse($result['isError']);
        $text = (string)$result['content'][0]['text'];

        $this->assertStringContainsString('requrv@hive.local (Personal):', $text);
        $this->assertStringContainsString('INBOX  [\Inbox]  (12 messages, 3 unread)', $text);
        $this->assertStringContainsString('Sent  [\Sent]  (40 messages, 0 unread)', $text);
    }

    public function testListMailboxesWithoutAnAccountIsAnErrorResult(): void {
        $this->makeService([$this->makeResult([])]);

        $result = $this->service->execute(MailToolsService::TOOL_LIST_MAILBOXES, [], 'requrv');
        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('No mail account is configured', (string)$result['content'][0]['text']);
    }

    public function testListMessagesShowsSenderFlagsAndPreview(): void {
        $this->makeService([
            $this->makeResult([['id' => 7, 'name' => 'INBOX', 'account_id' => 3]]), // findMailbox
            $this->makeResult([ // messages
                $this->messageRow(['flag_flagged' => 1, 'flag_attachments' => 1]),
                $this->messageRow(['id' => 102, 'uid' => 56, 'subject' => '', 'flag_seen' => 1, 'preview_text' => '']),
            ]),
            $this->makeResult([ // recipients
                ['local_message_id' => 101, 'type' => 0, 'label' => 'Acme Corp', 'email' => 'billing@acme.example'],
            ]),
        ]);

        $result = $this->service->execute(MailToolsService::TOOL_LIST_MAIL_MESSAGES, [
            'mailbox' => 'INBOX',
        ], 'requrv');
        $this->assertFalse($result['isError']);
        $text = (string)$result['content'][0]['text'];

        $this->assertStringContainsString('Messages in mailbox INBOX (newest first):', $text);
        $this->assertStringContainsString('Invoice #204', $text);
        $this->assertStringContainsString('from: Acme Corp <billing@acme.example>', $text);
        $this->assertStringContainsString('{starred, has attachments}', $text);
        $this->assertStringContainsString('uid: 55', $text);
        $this->assertStringContainsString('preview: Please find attached the invoice for October.', $text);
        // The second message has no sender row and no subject.
        $this->assertStringContainsString('(no subject)', $text);
        $this->assertStringContainsString('from: (unknown)', $text);
    }

    public function testListMessagesRejectsAnUnknownMailbox(): void {
        $this->makeService([
            $this->makeResult([]), // findMailbox finds nothing
        ]);

        $result = $this->service->execute(MailToolsService::TOOL_LIST_MAIL_MESSAGES, [
            'mailbox' => 'Trash',
        ], 'requrv');
        $this->assertTrue($result['isError']);
        $this->assertStringContainsString("Mailbox 'Trash' not found", (string)$result['content'][0]['text']);
    }

    public function testGetMessageFallsBackToThePreviewWhenImapIsUnavailable(): void {
        $this->makeService([
            $this->makeResult([['id' => 7, 'name' => 'INBOX', 'account_id' => 3]]), // findMailbox
            $this->makeResult([
                $this->messageRow(['id' => 101, 'uid' => 55]),
            ]),
        ]);

        $result = $this->service->execute(MailToolsService::TOOL_GET_MAIL_MESSAGE, [
            'mailbox' => 'INBOX',
            'uid' => 55,
        ], 'requrv');
        // Not an error: the preview is a valid answer, with a notice attached.
        $this->assertFalse($result['isError']);
        $text = (string)$result['content'][0]['text'];

        $this->assertStringContainsString('Message 55 in mailbox INBOX (db id 101):', $text);
        $this->assertStringContainsString('subject: Invoice #204', $text);
        $this->assertStringContainsString('could not be fetched', $text);
        $this->assertStringContainsString('Please find attached the invoice for October.', $text);
    }

    public function testGetMessageReportsADeletedMessageAsNotFound(): void {
        $this->makeService([
            $this->makeResult([['id' => 7, 'name' => 'INBOX', 'account_id' => 3]]),
            $this->makeResult([]), // no message row
        ]);

        $result = $this->service->execute(MailToolsService::TOOL_GET_MAIL_MESSAGE, [
            'mailbox' => 'INBOX',
            'uid' => 999,
        ], 'requrv');
        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('Message uid 999 not found', (string)$result['content'][0]['text']);
    }

    public function testGetMessageValidatesTheUid(): void {
        $this->makeService([]);

        $result = $this->service->execute(MailToolsService::TOOL_GET_MAIL_MESSAGE, [
            'mailbox' => 'INBOX',
            'uid' => 0,
        ], 'requrv');
        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('uid', (string)$result['content'][0]['text']);
    }

    public function testUnknownToolIsAnErrorResult(): void {
        $this->makeService([]);

        $result = $this->service->execute('send_mail', [], 'requrv');
        $this->assertTrue($result['isError']);
    }
}
