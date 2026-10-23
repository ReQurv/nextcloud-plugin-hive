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

    // ── Retries on transient provider failures ───────────────────────────────

    private function fastProvider(): HiveProvider {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnArgument(2);
        // retryDelaySeconds() = 0 keeps the retry tests instant.
        return new class(
            $this->createMock(IClientService::class),
            $config,
            $this->createMock(CredentialService::class),
            $this->createMock(LoggerInterface::class),
        ) extends HiveProvider {
            protected function retryDelaySeconds(): int {
                return 0;
            }
        };
    }

    private function invokeWithRetry(HiveProvider $provider, callable $attempt): mixed {
        $method = new \ReflectionMethod(HiveProvider::class, 'withRetry');
        return $method->invoke($provider, $attempt);
    }

    public function testWithRetryRecoversFromAConnectionReset(): void {
        $calls = 0;
        $result = $this->invokeWithRetry($this->fastProvider(), function () use (&$calls) {
            $calls++;
            if ($calls < 3) {
                throw new \RuntimeException('cURL error 56: Connection reset by peer');
            }
            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertSame(3, $calls);
    }

    public function testWithRetryGivesUpAfterTheBudgetIsSpent(): void {
        $calls = 0;
        try {
            $this->invokeWithRetry($this->fastProvider(), function () use (&$calls) {
                $calls++;
                throw new \RuntimeException('cURL error 56: Connection reset by peer');
            });
            $this->fail('Expected the last transient failure to be rethrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame('cURL error 56: Connection reset by peer', $e->getMessage());
        }

        // One initial attempt plus maxRetries() (5) retries.
        $this->assertSame(6, $calls);
    }

    public function testWithRetryDoesNotRetryANonTransientError(): void {
        $calls = 0;
        try {
            $this->invokeWithRetry($this->fastProvider(), function () use (&$calls) {
                $calls++;
                throw new \RuntimeException('Client error: 401 Unauthorized');
            });
            $this->fail('Expected the non-retryable failure to be rethrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Client error: 401 Unauthorized', $e->getMessage());
        }

        $this->assertSame(1, $calls);
    }

    // ── Tool-call arguments must always be a JSON object ─────────────────────
    //
    // The Qwen3.x chat template renders `function.arguments` with
    // `arguments | items`, which requires a JSON object. A no-arg tool call
    // ("{}") round-trips through decodeArguments() as an empty PHP array; if
    // that is re-encoded with plain json_encode() it becomes "[]" (a JSON
    // array) and the model server rejects the whole turn with
    // "Can only get item pairs from a mapping".

    private function encodeToolArguments(mixed $input): string {
        $method = new \ReflectionMethod(HiveProvider::class, 'encodeToolArguments');
        return $method->invoke($this->provider(), $input);
    }

    public function testEncodeToolArgumentsEmptyArrayBecomesObject(): void {
        // json_encode([]) is "[]" — the exact value that crashed the model.
        $this->assertSame('{}', $this->encodeToolArguments([]));
    }

    public function testEncodeToolArgumentsAssociativeObjectIsPreserved(): void {
        $this->assertSame('{"uri":"personal"}', $this->encodeToolArguments(['uri' => 'personal']));
    }

    public function testEncodeToolArgumentsScalarBecomesObject(): void {
        $this->assertSame('{}', $this->encodeToolArguments('no-args'));
        $this->assertSame('{}', $this->encodeToolArguments(null));
    }

    public function testEncodeToolArgumentsListBecomesObject(): void {
        $this->assertSame('{}', $this->encodeToolArguments([1, 2, 3]));
    }

    public function testNoArgAssistantToolCallRoundTripsToObject(): void {
        // An assistant turn that called a tool with no arguments must leave the
        // history with arguments = "{}", never "[]".
        $method = new \ReflectionMethod(HiveProvider::class, 'toOpenAiMessages');
        $openAi = $method->invoke($this->provider(), [
            ['role' => 'assistant', 'content' => [
                ['type' => 'tool_use', 'id' => 'a', 'name' => 'list_calendars', 'input' => []],
            ]],
            ['role' => 'user', 'content' => [
                ['type' => 'tool_result', 'tool_use_id' => 'a', 'content' => '- Personal (uri: personal)'],
            ]],
        ], null, 'alice');

        $this->assertSame('{}', $openAi[0]['tool_calls'][0]['function']['arguments']);
    }
}
