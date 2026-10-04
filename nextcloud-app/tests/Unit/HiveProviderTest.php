<?php

namespace OCA\RequrvHive\Tests\Unit;

use OCA\RequrvHive\Service\CredentialService;
use OCA\RequrvHive\Service\Provider\HiveProvider;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * `reasoning_effort` reaches the upstream model server through ReQurv's
 * `extra_body` pass-through (documentation point 6.3). It is added to the
 * chat-completions body only for the models that accept it; every other model
 * goes out unchanged.
 */
class HiveProviderTest extends TestCase {
    private function provider(): HiveProvider {
        $config = $this->createMock(IConfig::class);
        // Nothing configured: every read falls back to its default.
        $config->method('getAppValue')->willReturnArgument(2);
        $config->method('getUserValue')->willReturn('');
        return new HiveProvider(
            $this->createMock(IClientService::class),
            $config,
            $this->createMock(CredentialService::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    /** @return array<string, mixed> */
    private function buildBody(HiveProvider $provider, array $options, bool $stream = false): array {
        $method = new \ReflectionMethod(HiveProvider::class, 'buildBody');
        return $method->invoke($provider, [['role' => 'user', 'content' => 'hi']], null, 'alice', $options, $stream);
    }

    public function testReasoningModelCarriesReasoningEffortInExtraBody(): void {
        $body = $this->buildBody($this->provider(), ['model' => 'requrv-small-3.8']);

        $this->assertSame(['reasoning_effort' => 'low'], $body['extra_body']);
    }

    public function testDefaultModelCarriesReasoningEffort(): void {
        $body = $this->buildBody($this->provider(), []);

        $this->assertSame('requrv-small-3.8', $body['model']);
        $this->assertSame(['reasoning_effort' => 'low'], $body['extra_body']);
    }

    public function testOtherModelsGetNoExtraBody(): void {
        $body = $this->buildBody($this->provider(), ['model' => 'some-other-model']);

        $this->assertArrayNotHasKey('extra_body', $body);
    }

    public function testStreamingBodyCarriesReasoningEffort(): void {
        $body = $this->buildBody($this->provider(), ['model' => 'requrv-small-3.8'], true);

        $this->assertTrue($body['stream']);
        $this->assertSame(['reasoning_effort' => 'low'], $body['extra_body']);
    }
}
