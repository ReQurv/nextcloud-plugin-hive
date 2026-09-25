<?php

namespace OCA\RequrvHive\Tests\Unit;

use OCA\RequrvHive\Service\CredentialService;
use OCA\RequrvHive\Service\Provider\AbstractOpenAiCompatibleProvider;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A tool result can carry an image (the chat's read_image tool does). The
 * OpenAI wire format has no image inside a `tool` message, so a vision-capable
 * backend must receive the image as a follow-up `user` message of image_url
 * parts, while a text-only backend must drop the image rather than fail.
 */
class ToolResultImageTest extends TestCase {
    private function provider(bool $vision): AbstractOpenAiCompatibleProvider {
        $deps = [
            $this->createMock(IClientService::class),
            $this->createMock(IConfig::class),
            $this->createMock(CredentialService::class),
            $this->createMock(LoggerInterface::class),
        ];
        return new class($vision, $deps) extends AbstractOpenAiCompatibleProvider {
            public function __construct(
                private bool $vision,
                array $deps,
            ) {
                parent::__construct(...$deps);
            }

            public function getId(): string {
                return 'test';
            }

            public function getLabel(): string {
                return 'Test';
            }

            protected function apiBase(): string {
                return 'http://test/v1';
            }

            protected function defaultModel(): string {
                return 'test-model';
            }

            protected function defaultMaxTokens(): int {
                return 100;
            }

            public function getModel(?string $userId = null): string {
                return 'test-model';
            }

            public function getMaxTokens(?string $userId = null): int {
                return 100;
            }

            protected function supportsVisionInput(?string $userId = null): bool {
                return $this->vision;
            }
        };
    }

    private function toOpenAiMessages(AbstractOpenAiCompatibleProvider $provider, bool $vision): array {
        $method = new \ReflectionMethod(AbstractOpenAiCompatibleProvider::class, 'toOpenAiMessages');
        $messages = [
            ['role' => 'user', 'content' => 'show me my cat'],
            [
                'role' => 'assistant',
                'content' => [
                    ['type' => 'tool_use', 'id' => 't1', 'name' => 'read_image', 'input' => ['path' => 'cat.jpg']],
                ],
            ],
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'tool_result',
                        'tool_use_id' => 't1',
                        'content' => "Image 'cat.jpg' (image/jpeg, 123 bytes) loaded for visual analysis.",
                        'images' => [
                            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => 'QUJD']],
                        ],
                    ],
                ],
            ],
        ];
        $out = $method->invoke($provider, $messages, null, 'user');
        $this->assertSame($vision ? 4 : 3, count($out), 'vision keeps the image message, text-only drops it');
        return $out;
    }

    public function testVisionBackendReceivesTheImageAsFollowUpUserMessage(): void {
        $out = $this->toOpenAiMessages($this->provider(true), true);

        $this->assertSame('tool', $out[2]['role']);
        $this->assertStringContainsString('cat.jpg', (string)$out[2]['content']);

        $this->assertSame('user', $out[3]['role']);
        $this->assertIsArray($out[3]['content']);
        $this->assertSame('image_url', $out[3]['content'][0]['type']);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $out[3]['content'][0]['image_url']['url']);
    }

    public function testTextOnlyBackendDropsTheImage(): void {
        $out = $this->toOpenAiMessages($this->provider(false), false);

        $this->assertSame('tool', $out[2]['role']);
        foreach ($out as $msg) {
            $this->assertIsString($msg['content'], 'a text-only backend must only ever see strings');
        }
    }

    public function testExecuteToolCallsSplitsTextAndImages(): void {
        $provider = $this->provider(true);
        $method = new \ReflectionMethod(AbstractOpenAiCompatibleProvider::class, 'executeToolCalls');
        $executor = static function (string $name, array $input): array {
            return [
                'content' => [
                    ['type' => 'text', 'text' => 'loaded'],
                    ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => 'Q']],
                ],
                'isError' => false,
            ];
        };

        $blocks = $method->invoke($provider, [
            ['id' => 't9', 'function' => ['name' => 'read_image', 'arguments' => '{}']],
        ], $executor);

        $this->assertCount(1, $blocks);
        $this->assertSame('loaded', $blocks[0]['content']);
        $this->assertSame('image', $blocks[0]['images'][0]['type']);
        $this->assertArrayNotHasKey('is_error', $blocks[0]);
    }
}
