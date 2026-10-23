<?php

declare(strict_types=1);

namespace OCA\RequrvHive\Tests\Unit\Talk;

use OCA\RequrvHive\Talk\TalkBotRegistration;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

class TalkBotRegistrationTest extends TestCase {
    private const SECRET_KEY = 'talk_bot_secret';
    private const VERSION_KEY = 'talk_bot_registered_talk_version';

    private IAppConfig $appConfig;
    private IAppManager $appManager;
    private IEventDispatcher $dispatcher;
    private ISecureRandom $secureRandom;
    /** @var array<string, string> */
    private array $appValues;

    protected function setUp(): void {
        $this->appValues = [];

        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->appConfig->method('getValueString')->willReturnCallback(
            fn (string $app, string $key, string $default = '') => $this->appValues[$key] ?? $default
        );
        $this->appConfig->method('setValueString')->willReturnCallback(
            function (string $app, string $key, string $value): bool {
                $this->appValues[$key] = $value;
                return true;
            }
        );

        $this->appManager = $this->createMock(IAppManager::class);
        $this->dispatcher = $this->createMock(IEventDispatcher::class);
        $this->secureRandom = $this->createMock(ISecureRandom::class);
        $this->secureRandom->method('generate')->willReturn('generated-secret');
    }

    private function makeRegistration(): TalkBotRegistration {
        return new TalkBotRegistration(
            $this->appConfig,
            $this->appManager,
            $this->dispatcher,
            $this->secureRandom,
        );
    }

    public function testBotActorIdIsDeterministic(): void {
        $this->assertSame('bot-' . sha1(TalkBotRegistration::BOT_URL), TalkBotRegistration::botActorId());
    }

    public function testBotActorRefPrefixesTheActorType(): void {
        $this->assertSame('bots/' . TalkBotRegistration::botActorId(), TalkBotRegistration::botActorRef());
    }

    public function testGetSecretGeneratesAndPersists(): void {
        $this->assertSame('generated-secret', $this->makeRegistration()->getSecret());
        $this->assertSame('generated-secret', $this->appValues[self::SECRET_KEY]);
    }

    public function testGetSecretReturnsExistingWithoutRegenerating(): void {
        $this->appValues[self::SECRET_KEY] = 'stable-secret';

        $this->secureRandom->expects($this->never())->method('generate');
        $this->assertSame('stable-secret', $this->makeRegistration()->getSecret());
    }

    public function testRegisterIsNoOpWhenTalkIsAbsent(): void {
        // OCA\Talk\Events\BotInstallEvent is not loaded in unit tests, so the
        // guard must short-circuit before dispatching anything.
        $this->dispatcher->expects($this->never())->method('dispatchTyped');
        $this->assertFalse($this->makeRegistration()->register());
    }

    public function testEnsureRegisteredSkipsWhenTalkIsDisabled(): void {
        // getAppVersion() reports "disabled" for an uninstalled app.
        $this->appManager->method('getAppVersion')->willReturn('disabled');

        $spy = $this->makeSpy(registerReturns: true);
        $spy->ensureRegistered();

        $this->assertSame(0, $spy->registerCalls);
        $this->assertArrayNotHasKey(self::VERSION_KEY, $this->appValues);
    }

    public function testEnsureRegisteredSkipsWhenVersionAlreadyStored(): void {
        $this->appValues[self::VERSION_KEY] = '23.0.0';
        $this->appManager->method('getAppVersion')->willReturn('23.0.0');

        $spy = $this->makeSpy(registerReturns: true);
        $spy->ensureRegistered();

        $this->assertSame(0, $spy->registerCalls, 'must not re-register at an already-stored Talk version');
    }

    public function testEnsureRegisteredStoresVersionOnSuccess(): void {
        $this->appManager->method('getAppVersion')->willReturn('23.0.0');

        $spy = $this->makeSpy(registerReturns: true);
        $spy->ensureRegistered();

        $this->assertSame(1, $spy->registerCalls);
        $this->assertSame('23.0.0', $this->appValues[self::VERSION_KEY]);
    }

    public function testEnsureRegisteredDoesNotStoreVersionWhenRegistrationFails(): void {
        $this->appManager->method('getAppVersion')->willReturn('23.0.0');

        $spy = $this->makeSpy(registerReturns: false);
        $spy->ensureRegistered();

        $this->assertSame(1, $spy->registerCalls);
        $this->assertArrayNotHasKey(self::VERSION_KEY, $this->appValues);
    }

    /**
     * @return TalkBotRegistrationSpy
     */
    private function makeSpy(bool $registerReturns): TalkBotRegistration {
        return new TalkBotRegistrationSpy(
            $this->appConfig,
            $this->appManager,
            $this->dispatcher,
            $this->secureRandom,
            $registerReturns,
        );
    }
}

/**
 * Overrides only register() so the ensureRegistered() decision logic can be
 * exercised without the real Talk event class being present.
 */
class TalkBotRegistrationSpy extends TalkBotRegistration {
    public int $registerCalls = 0;

    public function __construct(
        $appConfig,
        $appManager,
        $dispatcher,
        $secureRandom,
        private bool $registerReturns,
    ) {
        parent::__construct($appConfig, $appManager, $dispatcher, $secureRandom);
    }

    public function register(): bool {
        $this->registerCalls++;
        return $this->registerReturns;
    }
}
