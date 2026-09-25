<?php

declare(strict_types=1);

namespace OCA\RequrvHive\Tests\Unit\ContextChat;

use OCA\RequrvHive\ContextChat\ConversationContentProvider;
use OCA\RequrvHive\Service\ContextChatService;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class ConversationContentProviderTest extends TestCase {
    private $service;
    private $urlGenerator;
    private ConversationContentProvider $provider;

    protected function setUp(): void {
        $this->service = $this->createMock(ContextChatService::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->provider = new ConversationContentProvider($this->service, $this->urlGenerator);
    }

    public function testIdentity(): void {
        $this->assertSame('conversation', $this->provider->getId());
        $this->assertSame('requrvhive', $this->provider->getAppId());
    }

    public function testItemUrlDeepLinksToConversation(): void {
        $this->urlGenerator->method('linkToRoute')->with('requrvhive.page.index')->willReturn('/apps/requrvhive/');
        $this->urlGenerator->method('getAbsoluteURL')
            ->willReturnCallback(static fn (string $url): string => 'https://cloud.example' . $url);

        $this->assertSame(
            'https://cloud.example/apps/requrvhive/#/conversations/42',
            $this->provider->getItemUrl('42'),
        );
    }

    public function testInitialImportReindexesAll(): void {
        $this->service->expects($this->once())->method('reindexAll');
        $this->provider->triggerInitialImport();
    }
}
