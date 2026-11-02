<?php

declare(strict_types=1);

namespace OCA\RequrvHive\Tests\Unit\Db;

use OCA\RequrvHive\Db\TalkBinding;
use OCA\RequrvHive\Db\TalkBindingMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\ICompositeExpression;

class TalkBindingMapperTest extends MapperTestCase {

    private function makeMapper(): TalkBindingMapper {
        return $this->getMockBuilder(TalkBindingMapper::class)
            ->setConstructorArgs([$this->db])
            ->onlyMethods(['findEntities', 'findEntity'])
            ->getMock();
    }

    public function testTableName(): void {
        $this->qb->expects($this->once())
            ->method('from')
            ->with('requrvhive_talk_bindings')
            ->willReturnSelf();

        $mapper = $this->makeMapper();
        $mapper->method('findEntity')->willThrowException(new DoesNotExistException('nope'));
        $mapper->findByTalkToken('token');
    }

    public function testFindByTalkTokenReturnsBinding(): void {
        $binding = new TalkBinding();
        $binding->setTalkToken('token');

        $mapper = $this->makeMapper();
        $mapper->expects($this->once())
            ->method('findEntity')
            ->willReturn($binding);

        $this->assertSame($binding, $mapper->findByTalkToken('token'));
    }

    public function testFindByTalkTokenReturnsNullWhenMissing(): void {
        $mapper = $this->makeMapper();
        $mapper->expects($this->once())
            ->method('findEntity')
            ->willThrowException(new DoesNotExistException('nope'));

        $this->assertNull($mapper->findByTalkToken('token'));
    }

    public function testFindByHiveConversationIdReturnsBinding(): void {
        $binding = new TalkBinding();
        $binding->setHiveConversationId(9);

        $mapper = $this->makeMapper();
        $mapper->expects($this->once())
            ->method('findEntity')
            ->willReturn($binding);

        $this->assertSame($binding, $mapper->findByHiveConversationId(9));
    }

    public function testFindByHiveConversationIdReturnsNullWhenMissing(): void {
        $mapper = $this->makeMapper();
        $mapper->expects($this->once())
            ->method('findEntity')
            ->willThrowException(new DoesNotExistException('nope'));

        $this->assertNull($mapper->findByHiveConversationId(9));
    }

    // ── ambient mode ────────────────────────────────────────────────────────

    private function stubUpdate(): void {
        $this->qb->method('update')->willReturnSelf();
        $this->qb->method('set')->willReturnSelf();
    }

    public function testSetAutoReplyUpdatesRow(): void {
        $this->stubUpdate();

        $mapper = $this->makeMapper();
        $this->qb->expects($this->once())->method('executeStatement');
        $mapper->setAutoReply('token', true);
    }

    public function testClaimAmbientTurnWinsWhenStatementAffectsRow(): void {
        $this->stubUpdate();
        $this->expr->method('orX')->willReturn($this->createMock(ICompositeExpression::class));
        $this->qb->method('executeStatement')->willReturn(1);

        $mapper = $this->makeMapper();
        $this->assertTrue($mapper->claimAmbientTurn('token', 1000, 100));
    }

    public function testClaimAmbientTurnLosesWhenStatementAffectsNothing(): void {
        $this->stubUpdate();
        $this->expr->method('orX')->willReturn($this->createMock(ICompositeExpression::class));
        $this->qb->method('executeStatement')->willReturn(0);

        $mapper = $this->makeMapper();
        $this->assertFalse($mapper->claimAmbientTurn('token', 1000, 100));
    }

    public function testReleaseAmbientTurnUpdatesRow(): void {
        $this->stubUpdate();

        $mapper = $this->makeMapper();
        $this->qb->expects($this->once())->method('executeStatement');
        $mapper->releaseAmbientTurn('token');
    }

    public function testSetAutoPendingUpdatesRow(): void {
        $this->stubUpdate();

        $mapper = $this->makeMapper();
        $this->qb->expects($this->once())->method('executeStatement');
        $mapper->setAutoPending('token', '{"prompt":"hello"}');
    }

    public function testConsumeAutoPendingReturnsNullWhenUnbound(): void {
        $mapper = $this->makeMapper();
        $mapper->method('findEntity')
            ->willThrowException(new DoesNotExistException('nope'));
        $this->qb->expects($this->never())->method('executeStatement');

        $this->assertNull($mapper->consumeAutoPending('token'));
    }

    public function testConsumeAutoPendingReturnsNullWithoutPending(): void {
        $binding = new TalkBinding();
        $binding->setTalkToken('token');

        $mapper = $this->makeMapper();
        $mapper->method('findEntity')->willReturn($binding);
        $this->qb->expects($this->never())->method('executeStatement');

        $this->assertNull($mapper->consumeAutoPending('token'));
    }

    public function testConsumeAutoPendingReturnsAndClears(): void {
        $binding = new TalkBinding();
        $binding->setTalkToken('token');
        $binding->setAutoPending('{"prompt":"hello"}');

        $this->stubUpdate();

        $mapper = $this->makeMapper();
        $mapper->method('findEntity')->willReturn($binding);
        $this->qb->expects($this->once())->method('executeStatement');

        $this->assertSame(['prompt' => 'hello'], $mapper->consumeAutoPending('token'));
    }

    public function testConsumeAutoPendingClearsUnparseablePayload(): void {
        $binding = new TalkBinding();
        $binding->setTalkToken('token');
        $binding->setAutoPending('not json');

        $this->stubUpdate();

        $mapper = $this->makeMapper();
        $mapper->method('findEntity')->willReturn($binding);
        $this->qb->expects($this->once())->method('executeStatement');

        $this->assertNull($mapper->consumeAutoPending('token'));
    }

    public function testAppendRecentMessageTrimsWindowAndCapsText(): void {
        $binding = new TalkBinding();
        $binding->setTalkToken('token');
        $binding->setRecentMessages(json_encode([
            ['name' => 'Alice', 'text' => 'a1'],
            ['name' => 'Bob', 'text' => 'b1'],
        ]));

        $this->qb->method('update')->willReturnSelf();
        $written = null;
        $this->qb->method('set')->willReturnCallback(
            function (string $column, mixed $value) use (&$written) {
                if ($column === 'recent_messages') {
                    $written = $value;
                }
                return $this->qb;
            },
        );

        $mapper = $this->makeMapper();
        $mapper->method('findEntity')->willReturn($binding);
        $mapper->appendRecentMessage('token', 'Carol', str_repeat('c', 50), 5, 10);

        $window = json_decode((string)$written, true);
        $this->assertCount(3, $window);
        $this->assertSame('Carol', $window[2]['name']);
        $this->assertSame(10, mb_strlen($window[2]['text']));
    }

    public function testAppendRecentMessageIsNoopWhenUnbound(): void {
        $mapper = $this->makeMapper();
        $mapper->method('findEntity')
            ->willThrowException(new DoesNotExistException('nope'));
        $this->qb->expects($this->never())->method('executeStatement');

        $mapper->appendRecentMessage('token', 'Carol', 'hi', 5, 10);
    }
}
