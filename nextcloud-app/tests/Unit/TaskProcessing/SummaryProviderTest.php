<?php

namespace OCA\RequrvHive\Tests\Unit\TaskProcessing;

use OCA\RequrvHive\Service\Provider\LLMProviderFactory;
use OCA\RequrvHive\Service\Provider\LLMProviderInterface;
use OCA\RequrvHive\TaskProcessing\ProviderResolver;
use OCA\RequrvHive\TaskProcessing\SummaryProvider;
use PHPUnit\Framework\TestCase;

class SummaryProviderTest extends TestCase {
    private $factory;
    private SummaryProvider $provider;

    protected function setUp(): void {
        $this->factory = $this->createMock(LLMProviderFactory::class);
        $this->provider = new SummaryProvider(new ProviderResolver($this->factory));
    }

    public function testIdAndTaskTypeAreUnchangedByTheRename(): void {
        $this->assertSame('requrvhive:text2text:summary', $this->provider->getId());
        $this->assertSame('core:text2text:summary', $this->provider->getTaskTypeId());
        $this->assertSame('RequrvHive', $this->provider->getName());
    }

    public function testUsesTheGenericAskPath(): void {
        $local = $this->createMock(LLMProviderInterface::class);
        $local->expects($this->once())
            ->method('ask')
            ->with($this->stringContains('Summarize the following content concisely'), '', 'alice')
            ->willReturn(['response' => 'A summary']);
        $this->factory->method('getProviderForUser')->willReturn($local);

        $progress = [];
        $result = $this->provider->process('alice', ['input' => 'Some long text'], static function (float $p) use (&$progress): void {
            $progress[] = $p;
        });

        $this->assertSame(['output' => 'A summary'], $result);
        $this->assertNotEmpty($progress);
    }

    public function testProviderErrorBecomesAnException(): void {
        $local = $this->createMock(LLMProviderInterface::class);
        $local->method('ask')->willReturn(['error' => 'upstream is down']);
        $this->factory->method('getProviderForUser')->willReturn($local);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('upstream is down');
        $this->provider->process('alice', ['input' => 'Some long text'], static fn (float $p) => null);
    }

    public function testEmptyInputIsRejectedBeforeAnyProviderIsResolved(): void {
        $this->factory->expects($this->never())->method('getProviderForUser');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No input text provided');
        $this->provider->process('alice', ['input' => ''], static fn (float $p) => null);
    }
}
