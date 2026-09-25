<?php

namespace OCA\RequrvHive\Tests\Unit;

use OCA\RequrvHive\Service\Provider\HiveProvider;
use OCA\RequrvHive\Service\Provider\LLMProviderFactory;
use OCA\RequrvHive\Service\Provider\NoPermittedProviderException;
use OCA\RequrvHive\Service\Provider\ProviderAccessService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class LLMProviderFactoryTest extends TestCase {
    private $config;
    private $hive;
    private $access;
    private LLMProviderFactory $factory;

    protected function setUp(): void {
        $this->config = $this->createMock(IConfig::class);
        $this->hive = $this->createMock(HiveProvider::class);
        $this->hive->method('getId')->willReturn('hive');

        // Access control off by default: filterAllowed() returns everything, so
        // these tests keep asserting the plain precedence rules.
        $this->access = $this->createMock(ProviderAccessService::class);
        $this->access->method('filterAllowed')->willReturnCallback(
            static fn (array $ids, ?string $userId): array => $ids,
        );
        $this->access->method('isAllowed')->willReturn(true);

        $this->factory = new LLMProviderFactory($this->config, $this->hive, $this->access);
    }

    /** Rebuild the factory with only $permitted allowed for every user. */
    private function permitOnly(array $permitted): void {
        $access = $this->createMock(ProviderAccessService::class);
        $access->method('filterAllowed')->willReturnCallback(
            static fn (array $ids, ?string $userId): array => array_values(array_intersect($ids, $permitted)),
        );
        $access->method('isAllowed')->willReturnCallback(
            static fn (string $id, ?string $userId): bool => in_array($id, $permitted, true),
        );
        $this->factory = new LLMProviderFactory($this->config, $this->hive, $access);
    }

    public function testDefaultsToHive(): void {
        $this->config->method('getUserValue')->willReturn('');
        $this->config->method('getAppValue')->willReturnArgument(2);

        $this->assertSame('hive', $this->factory->getActiveProviderId('u'));
        $this->assertSame($this->hive, $this->factory->getProvider('u'));
        $this->assertSame(['hive'], $this->factory->getProviderIds());
    }

    public function testUserOverrideIgnoredForUnknownProviders(): void {
        $this->config->method('getUserValue')->willReturn('bogus');
        $this->config->method('getAppValue')->willReturn('also-bogus');

        $this->assertSame('hive', $this->factory->getActiveProviderId('u'));
        $this->assertSame($this->hive, $this->factory->getProviderById('nope'));
    }

    public function testDescribeProviders(): void {
        $this->config->method('getUserValue')->willReturn('');
        $this->config->method('getAppValue')->willReturnArgument(2);
        $this->hive->method('getLabel')->willReturn('ReQurv AI Hive');
        $this->hive->method('isConfigured')->willReturn(true);

        $described = $this->factory->describeProviders('u');
        $this->assertCount(1, $described);
        $this->assertSame('hive', $described[0]['id']);
        $this->assertSame('ReQurv AI Hive', $described[0]['label']);
        $this->assertTrue($described[0]['configured']);
    }

    public function testNoPermittedProviderFailsClosed(): void {
        $this->config->method('getUserValue')->willReturn('');
        $this->config->method('getAppValue')->willReturnArgument(2);
        $this->permitOnly([]);

        $this->expectException(NoPermittedProviderException::class);
        $this->factory->getActiveProviderId('u');
    }

    public function testGetProviderForUserDegradesADeniedPin(): void {
        $this->config->method('getUserValue')->willReturn('');
        $this->config->method('getAppValue')->willReturn('hive');

        // The conversation is pinned to a provider that no longer exists; the
        // factory must still fall back to the permitted provider.
        $this->assertSame($this->hive, $this->factory->getProviderForUser('u', 'bogus'));
    }

    public function testGetProviderForUserHonoursAPermittedPin(): void {
        $this->config->method('getUserValue')->willReturn('');
        $this->config->method('getAppValue')->willReturn('hive');

        $this->assertSame($this->hive, $this->factory->getProviderForUser('u', 'hive'));
    }

    public function testSystemContextIsUnrestricted(): void {
        $this->config->method('getAppValue')->willReturn('hive');
        $this->assertSame('hive', $this->factory->getActiveProviderId(null));
    }
}
