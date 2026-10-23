<?php

declare(strict_types=1);

namespace OCA\RequrvHive\Tests\Unit\Talk;

use OCA\RequrvHive\Talk\TalkBotClient;
use OCA\RequrvHive\Talk\TalkBotRegistration;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TalkBotClientTest extends TestCase {
    private const RANDOM = 'test-random-seed-64-bytes-padded-out-0000000000';
    private const SECRET = 'test-shared-secret';
    private const MESSAGE = 'Hello there';

    private IClientService $clientService;
    private IClient $client;
    private IResponse $response;
    private TalkBotRegistration $registration;
    private LoggerInterface $logger;
    private TalkBotClient $clientUnderTest;
    /** @var array{url: string, options: array}|null */
    private ?array $captured;
    private int $responseStatus;

    protected function setUp(): void {
        $this->captured = null;
        $this->responseStatus = 201;

        $this->client = $this->createMock(IClient::class);
        $this->client->method('post')->willReturnCallback(
            function (string $url, array $options = []): IResponse {
                $this->captured = ['url' => $url, 'options' => $options];
                return $this->response;
            }
        );

        $this->clientService = $this->createMock(IClientService::class);
        $this->clientService->method('newClient')->willReturn($this->client);

        $this->response = $this->createMock(IResponse::class);
        $this->response->method('getStatusCode')->willReturnCallback(
            fn (): int => $this->responseStatus
        );

        $secureRandom = $this->createMock(ISecureRandom::class);
        $secureRandom->method('generate')->willReturn(self::RANDOM);

        $this->registration = $this->createMock(TalkBotRegistration::class);
        $this->registration->method('getSecret')->willReturn(self::SECRET);

        $this->logger = $this->createMock(LoggerInterface::class);

        $this->clientUnderTest = new TalkBotClient(
            $this->clientService,
            $secureRandom,
            $this->registration,
            $this->logger,
        );
    }

    public function testSendsSignedMessage(): void {
        $this->clientUnderTest->sendMessage('roomtoken', self::MESSAGE, 'https://nextcloud.example/nextcloud');

        $this->assertNotNull($this->captured);
        $this->assertSame(
            'https://nextcloud.example/nextcloud/ocs/v2.php/apps/spreed/api/v1/bot/roomtoken/message',
            $this->captured['url'],
        );

        $headers = $this->captured['options']['headers'];
        $this->assertSame('application/json', $headers['Content-Type']);
        $this->assertSame('true', $headers['OCS-APIRequest']);
        $this->assertSame(self::RANDOM, $headers['X-Nextcloud-Talk-Bot-Random']);
        $this->assertSame(
            hash_hmac('sha256', self::RANDOM . self::MESSAGE, self::SECRET),
            $headers['X-Nextcloud-Talk-Bot-Signature'],
        );

        $body = json_decode($this->captured['options']['body'], true);
        $this->assertSame(self::MESSAGE, $body['message']);
        $this->assertSame(0, $body['replyTo']);
        $this->assertSame('', $body['referenceId']);
        $this->assertFalse($body['silent']);
    }

    public function testPassesReplyToAndReference(): void {
        $this->clientUnderTest->sendMessage('roomtoken', self::MESSAGE, 'https://x.example', 777, 'ref-123', true);

        $body = json_decode($this->captured['options']['body'], true);
        $this->assertSame(777, $body['replyTo']);
        $this->assertSame('ref-123', $body['referenceId']);
        $this->assertTrue($body['silent']);
    }

    public function testReturnsResponseOnSuccess(): void {
        $this->assertSame($this->response, $this->clientUnderTest->sendMessage('roomtoken', self::MESSAGE, 'https://x.example'));
    }

    public function testThrowsOnUnauthorized(): void {
        $this->responseStatus = 401;
        $this->logger->expects($this->once())->method('error');

        $this->expectException(\RuntimeException::class);
        $this->clientUnderTest->sendMessage('roomtoken', self::MESSAGE, 'https://x.example');
    }
}
