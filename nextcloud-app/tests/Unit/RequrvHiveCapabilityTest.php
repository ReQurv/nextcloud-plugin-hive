<?php

namespace OCA\RequrvHive\Tests\Unit;

use OCA\RequrvHive\Capabilities\RequrvHiveCapability;
use OCA\RequrvHive\Service\CredentialService;
use OCA\RequrvHive\Service\HiveModels;
use OCP\App\IAppManager;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class RequrvHiveCapabilityTest extends TestCase {
    private IConfig $config;
    private CredentialService $credentialService;
    private IAppManager $appManager;
    private RequrvHiveCapability $capability;

    protected function setUp(): void {
        $this->config = $this->createMock(IConfig::class);
        $this->credentialService = $this->createMock(CredentialService::class);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->capability = new RequrvHiveCapability(
            $this->config,
            $this->credentialService,
            $this->appManager,
        );
    }

    public function testReturnsCorrectStructure(): void {
        $this->appManager->method('getAppVersion')->willReturn('1.0.0');
        $this->config->method('getAppValue')->willReturn('');
        $this->credentialService->method('getApiKey')->willReturn('');

        $result = $this->capability->getCapabilities();

        $this->assertArrayHasKey('requrvhive', $result);
        $requrvhive = $result['requrvhive'];
        $this->assertArrayHasKey('version', $requrvhive);
        $this->assertArrayHasKey('model', $requrvhive);
        $this->assertArrayHasKey('providers', $requrvhive);
        $this->assertArrayHasKey('api_configured', $requrvhive);
        $this->assertArrayHasKey('search_enabled', $requrvhive);
    }

    public function testVersionFromAppManager(): void {
        $this->appManager->method('getAppVersion')
            ->with('requrvhive')
            ->willReturn('2.5.0');
        $this->config->method('getAppValue')->willReturn('');
        $this->credentialService->method('getApiKey')->willReturn('');

        $result = $this->capability->getCapabilities();

        $this->assertEquals('2.5.0', $result['requrvhive']['version']);
    }

    public function testModelReflectsConfig(): void {
        $this->appManager->method('getAppVersion')->willReturn('1.0.0');
        $this->config->method('getAppValue')
            ->willReturnMap([
                ['requrvhive', 'model_hive', HiveModels::DEFAULT_MODEL, 'requrv-large-3.8'],
                ['requrvhive', 'search_enabled', '1', '1'],
            ]);
        $this->credentialService->method('getApiKey')->willReturn('');

        $result = $this->capability->getCapabilities();

        $this->assertEquals('requrv-large-3.8', $result['requrvhive']['model']);
    }

    public function testApiConfiguredTrueWhenKeyExists(): void {
        $this->appManager->method('getAppVersion')->willReturn('1.0.0');
        $this->config->method('getAppValue')->willReturn('');
        $this->credentialService->method('getApiKey')
            ->with(null)
            ->willReturn('sk-ant-test-key');

        $result = $this->capability->getCapabilities();

        $this->assertTrue($result['requrvhive']['api_configured']);
    }

    public function testApiConfiguredFalseWhenKeyEmpty(): void {
        $this->appManager->method('getAppVersion')->willReturn('1.0.0');
        $this->config->method('getAppValue')->willReturn('');
        $this->credentialService->method('getApiKey')
            ->with(null)
            ->willReturn('');

        $result = $this->capability->getCapabilities();

        $this->assertFalse($result['requrvhive']['api_configured']);
    }

    public function testSearchEnabledReflectsConfig(): void {
        $this->appManager->method('getAppVersion')->willReturn('1.0.0');
        $this->config->method('getAppValue')
            ->willReturnMap([
                ['requrvhive', 'model_hive', HiveModels::DEFAULT_MODEL, HiveModels::DEFAULT_MODEL],
                ['requrvhive', 'search_enabled', '1', '0'],
            ]);
        $this->credentialService->method('getApiKey')->willReturn('');

        $result = $this->capability->getCapabilities();

        $this->assertFalse($result['requrvhive']['search_enabled']);
    }

    public function testSearchEnabledDefaultsToTrue(): void {
        $this->appManager->method('getAppVersion')->willReturn('1.0.0');
        $this->config->method('getAppValue')
            ->willReturnMap([
                ['requrvhive', 'model_hive', HiveModels::DEFAULT_MODEL, HiveModels::DEFAULT_MODEL],
                ['requrvhive', 'search_enabled', '1', '1'],
            ]);
        $this->credentialService->method('getApiKey')->willReturn('');

        $result = $this->capability->getCapabilities();

        $this->assertTrue($result['requrvhive']['search_enabled']);
    }

    public function testProvidersIncludesTextGeneration(): void {
        $this->appManager->method('getAppVersion')->willReturn('1.0.0');
        $this->config->method('getAppValue')->willReturn('');
        $this->credentialService->method('getApiKey')->willReturn('');

        $result = $this->capability->getCapabilities();

        $this->assertContains('text-generation', $result['requrvhive']['providers']);
    }
}
