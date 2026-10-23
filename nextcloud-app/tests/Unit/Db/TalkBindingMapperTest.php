<?php

declare(strict_types=1);

namespace OCA\RequrvHive\Tests\Unit\Db;

use OCA\RequrvHive\Db\TalkBinding;
use OCA\RequrvHive\Db\TalkBindingMapper;
use OCP\AppFramework\Db\DoesNotExistException;

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
}
