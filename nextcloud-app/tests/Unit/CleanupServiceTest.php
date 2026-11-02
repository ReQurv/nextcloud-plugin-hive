<?php

declare(strict_types=1);

namespace OCA\RequrvHive\Tests\Unit;

use OCA\RequrvHive\Service\CleanupService;
use OCA\RequrvHive\Service\Provider\LLMProviderFactory;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CleanupServiceTest extends TestCase {
    private IDBConnection $db;
    private IQueryBuilder $qb;
    private IExpressionBuilder $expr;
    private IParameter $parameter;
    private ICache $cache;
    private ICacheFactory $cacheFactory;
    private CleanupService $cleanup;

    /** @var list<string> tables the dropTable mock was asked to drop */
    private array $dropped;

    protected function setUp(): void {
        $this->dropped = [];
        $this->parameter = $this->createMock(IParameter::class);
        $this->expr = $this->createMock(IExpressionBuilder::class);
        $this->expr->method('like')->willReturn('identifier LIKE ?');
        $this->expr->method('eq')->willReturn('column = ?');

        $this->qb = $this->createMock(IQueryBuilder::class);
        $this->qb->method('delete')->willReturn($this->qb);
        $this->qb->method('where')->willReturn($this->qb);
        $this->qb->method('expr')->willReturn($this->expr);
        $this->qb->method('createNamedParameter')->willReturn($this->parameter);

        $this->db = $this->createMock(IDBConnection::class);
        $this->db->method('getQueryBuilder')->willReturn($this->qb);
        $this->db->method('dropTable')->willReturnCallback(
            function (string $table): void {
                $this->dropped[] = $table;
            }
        );

        $this->cache = $this->createMock(ICache::class);
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->cacheFactory->method('createDistributed')->willReturn($this->cache);

        $providerFactory = $this->createMock(LLMProviderFactory::class);
        $providerFactory->method('getProviderIds')->willReturn(['hive']);

        $this->cleanup = new CleanupService(
            $this->db,
            $this->cacheFactory,
            $providerFactory,
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testResetConfigurationWipesCredentialsConfigPreferencesJobsAndCache(): void {
        // One statement each: credentials, appconfig, preferences, then three
        // job classes.
        $this->qb->method('executeStatement')
            ->willReturnOnConsecutiveCalls(3, 5, 2, 1, 1, 0);
        $this->cache->expects($this->once())->method('clear');

        $counts = $this->cleanup->resetConfiguration();

        $this->assertSame(
            ['credentials' => 3, 'appconfig' => 5, 'preferences' => 2, 'jobs' => 2],
            $counts,
        );
    }

    public function testResetConfigurationTargetsOnlyAppOwnedRows(): void {
        $tables = [];
        $this->qb->method('delete')->willReturnCallback(
            function (string $table) use (&$tables) {
                $tables[] = $table;
                return $this->qb;
            }
        );

        $this->cleanup->resetConfiguration();

        $this->assertSame(
            ['storages_credentials', 'appconfig', 'preferences', 'jobs', 'jobs', 'jobs'],
            $tables,
        );
    }

    public function testPurgeDataDropsOnlyExistingTables(): void {
        $this->db->method('tableExists')->willReturnCallback(
            static fn (string $table): bool => in_array($table, ['requrvhive_conversations', 'requrvhive_projects'], true)
        );

        $dropped = $this->cleanup->purgeData();

        $this->assertSame(['requrvhive_conversations', 'requrvhive_projects'], $dropped);
        $this->assertSame(['requrvhive_conversations', 'requrvhive_projects'], $this->dropped);
    }

    public function testPurgeDataIsANoOpWhenNothingExists(): void {
        $this->db->method('tableExists')->willReturn(false);
        $this->db->expects($this->never())->method('dropTable');

        $this->assertSame([], $this->cleanup->purgeData());
    }
}
