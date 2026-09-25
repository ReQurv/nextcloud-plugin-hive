<?php

namespace OCA\RequrvHive\Tests\Unit;

use OCA\RequrvHive\Controller\SettingsController;
use OCA\RequrvHive\Service\CredentialService;
use OCA\RequrvHive\Service\HiveModels;
use OCA\RequrvHive\Service\Provider\LLMProviderFactory;
use OCA\RequrvHive\Service\Provider\LLMProviderInterface;
use OCA\RequrvHive\Service\Provider\ProviderSettingsService;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class SettingsControllerTest extends TestCase {
    private $config;
    private $request;
    private $factory;
    private $provider;
    private $credentials;
    private $providerSettings;
    private SettingsController $ctrl;

    protected function setUp(): void {
        $this->config      = $this->createMock(IConfig::class);
        $this->request     = $this->createMock(IRequest::class);
        $this->factory     = $this->createMock(LLMProviderFactory::class);
        $this->provider    = $this->createMock(LLMProviderInterface::class);
        $this->credentials = $this->createMock(CredentialService::class);

        // The controller no longer calls the provider directly; it goes through
        // the caching service. Stand in for it with the same live-or-fallback
        // rule the real one applies, so the provider stubs below still drive
        // what these tests assert on.
        $this->providerSettings = $this->createMock(ProviderSettingsService::class);
        $this->providerSettings->method('listModels')->willReturnCallback(
            static function (LLMProviderInterface $provider, ?string $userId): array {
                $live = $provider->listModels($userId);
                return $live !== null && $live !== [] ? $live : HiveModels::getAllModels();
            }
        );

        // Single-provider (hive) world for these tests.
        $this->provider->method('getId')->willReturn('hive');
        $this->provider->method('getLabel')->willReturn('ReQurv AI Hive');
        $this->provider->method('isConfigured')->willReturn(false);
        $this->factory->method('getActiveProviderId')->willReturn('hive');
        $this->factory->method('getProviderIds')->willReturn(['hive']);
        $this->factory->method('getProviderIdsForUser')->willReturn(['hive']);
        $this->factory->method('isKnownProviderId')->willReturn(true);
        $this->factory->method('isAllowedForUser')->willReturn(true);
        $this->factory->method('getProviderById')->willReturn($this->provider);
        $this->factory->method('getProvider')->willReturn($this->provider);

        $this->ctrl = new SettingsController(
            'requrvhive',
            $this->request,
            $this->config,
            'testuser',
            $this->factory,
            $this->credentials,
            $this->providerSettings,
            $this->createMock(\Psr\Log\LoggerInterface::class)
        );
    }

    public function testGetReturnsHasUserKeyFalse(): void {
        $this->credentials->method('hasApiKey')->willReturn(false);
        $this->config->method('getUserValue')->willReturn('');
        $this->provider->method('listModels')->willReturn(null);

        $response = $this->ctrl->get();
        $data = $response->getData();

        $this->assertFalse($data['hasUserKey']);
    }

    public function testGetReturnsHasUserKeyTrue(): void {
        $this->credentials->method('hasApiKey')->willReturn(true);
        $this->config->method('getUserValue')->willReturn('');
        $this->provider->method('listModels')->willReturn(null);

        $response = $this->ctrl->get();
        $data = $response->getData();

        $this->assertTrue($data['hasUserKey']);
    }

    public function testGetReturnsUserModelAndAvailableModels(): void {
        $this->credentials->method('hasApiKey')->willReturn(false);
        $this->config->method('getUserValue')
            ->willReturnMap([
                ['testuser', 'requrvhive', 'user_provider', '', ''],
                ['testuser', 'requrvhive', 'user_model_hive', '', HiveModels::DEFAULT_MODEL],
                ['testuser', 'requrvhive', 'default_system_prompt', '', ''],
                ['testuser', 'requrvhive', 'default_verbose', '0', '0'],
                ['testuser', 'requrvhive', 'task_success_notifications', '0', '0'],
                ['testuser', 'requrvhive', 'task_failure_notifications', '1', '1'],
            ]);
        $this->provider->method('listModels')->willReturn(null);

        $response = $this->ctrl->get();
        $data = $response->getData();

        $this->assertEquals(HiveModels::DEFAULT_MODEL, $data['userModel']);
        $this->assertEquals(HiveModels::getAllModels(), $data['availableModels']);
    }

    public function testGetUsesLiveModelsWhenAvailable(): void {
        $this->credentials->method('hasApiKey')->willReturn(false);
        $this->config->method('getUserValue')->willReturn('');
        $this->provider->method('listModels')->willReturn(['hive-test-model']);

        $response = $this->ctrl->get();
        $data = $response->getData();

        $this->assertSame(['hive-test-model'], $data['availableModels']);
    }

    public function testGetFallsBackToStaticModelsWhenListFails(): void {
        $this->credentials->method('hasApiKey')->willReturn(false);
        $this->config->method('getUserValue')->willReturn('');
        $this->provider->method('listModels')->willReturn(null);

        $response = $this->ctrl->get();
        $data = $response->getData();

        $this->assertEquals(HiveModels::getAllModels(), $data['availableModels']);
    }

    public function testGetExposesProvidersList(): void {
        $this->credentials->method('hasApiKey')->willReturn(false);
        $this->config->method('getUserValue')->willReturn('');
        $this->provider->method('listModels')->willReturn(null);

        $data = $this->ctrl->get()->getData();

        $this->assertSame('hive', $data['provider']);
        $this->assertCount(1, $data['providers']);
        $this->assertSame('hive', $data['providers'][0]['id']);
        $this->assertSame('ReQurv AI Hive', $data['providers'][0]['label']);
    }

    public function testSaveStoresApiKeyAndModel(): void {
        $this->credentials->expects($this->once())
            ->method('setApiKey')
            ->with('testuser', 'my-api-key', 'hive');

        $this->config->expects($this->once())
            ->method('setUserValue')
            ->with('testuser', 'requrvhive', 'user_model_hive', HiveModels::DEFAULT_MODEL);

        $response = $this->ctrl->save('my-api-key', HiveModels::DEFAULT_MODEL);
        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals('ok', $response->getData()['status']);
    }

    public function testSaveWithEmptyModelDeletesUserValue(): void {
        $this->credentials->expects($this->once())
            ->method('setApiKey')
            ->with('testuser', 'some-key', 'hive');

        $this->config->expects($this->once())
            ->method('deleteUserValue')
            ->with('testuser', 'requrvhive', 'user_model_hive');

        $response = $this->ctrl->save('some-key', '');
        $this->assertEquals('ok', $response->getData()['status']);
    }

    public function testSaveWithEmptyApiKeyDeletesKey(): void {
        $this->credentials->expects($this->once())
            ->method('deleteApiKey')
            ->with('testuser', 'hive');

        $this->ctrl->save('', HiveModels::DEFAULT_MODEL);
    }

    public function testSaveStoresProviderOverrideAndScopesKey(): void {
        $this->config->expects($this->once())
            ->method('setUserValue')
            ->with('testuser', 'requrvhive', 'user_provider', 'hive');

        $this->credentials->expects($this->once())
            ->method('setApiKey')
            ->with('testuser', 'hive-key', 'hive');

        $response = $this->ctrl->save('hive-key', null, 'hive');
        $this->assertEquals('ok', $response->getData()['status']);
    }
}
