<?php

declare(strict_types=1);

namespace OCA\RequrvHive\Tests\Unit\Service;

use OCP\DB\IResult;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Shared DB mock for the domain tool services (calendar, mail).
 *
 * The services run several queries per tool call — a mailbox lookup, then the
 * messages, then their recipients — so a single stubbed builder is not
 * enough. `makeDb` hands each `getQueryBuilder()` call the next builder in a
 * queue, and each builder returns the next result in the same queue: the
 * test describes the queries it expects in the exact order the service runs
 * them, and a service that issues one query too many (or in a different
 * order) gets a builder without a result and fails loudly.
 */
abstract class DbToolsTestCase extends TestCase {
    /** @var IDBConnection|null */
    protected $db;

    /**
     * @param list<IResult> $results one entry per query the service will run, in order
     */
    protected function makeDb(array $results): IDBConnection {
        $db = $this->createMock(IDBConnection::class);
        // Captured by reference on purpose: PHP does not implicitly capture a
        // variable a closure only touches through pass-by-reference calls like
        // array_shift(), and the queue must survive across getQueryBuilder()
        // calls.
        $db->method('getQueryBuilder')->willReturnCallback(function () use (&$results): IQueryBuilder {
            return $this->makeQueryBuilder(array_shift($results));
        });

        return $db;
    }

    protected function makeQueryBuilder(?IResult $result): IQueryBuilder {
        $qb = $this->createMock(IQueryBuilder::class);

        $expr = $this->createMock(IExpressionBuilder::class);
        foreach (['eq', 'neq', 'lt', 'lte', 'gt', 'gte', 'isNull', 'in', 'notIn', 'like'] as $method) {
            $expr->method($method)->willReturnCallback(static fn(): string => 'expr:' . $method);
        }
        // A bare mock is enough: the stubbed where-clauses ignore their
        // arguments, and ICompositeExpression itself declares no __toString.
        $composite = $this->createMock(ICompositeExpression::class);
        $expr->method('orX')->willReturn($composite);
        $expr->method('andX')->willReturn($composite);

        foreach (['select', 'from', 'innerJoin', 'where', 'andWhere', 'orderBy', 'addOrderBy', 'insert', 'update', 'values', 'set', 'setParameter', 'setMaxResults'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('expr')->willReturn($expr);
        // The stubbed builders never build real SQL: stand in for a
        // placeholder with the value itself so a test can assert on inputs.
        $qb->method('createNamedParameter')->willReturnCallback(
            static fn(mixed $value): string => is_array($value) ? implode(',', $value) : (string)$value
        );
        $qb->method('getColumnName')->willReturnCallback(static fn($column, $tableAlias = ''): string => (string)$column);
        $qb->method('getTableName')->willReturnCallback(static fn($table): string => (string)$table);
        $qb->method('createFunction')->willReturnCallback(static fn($call): string => (string)$call);
        if ($result !== null) {
            $qb->method('executeQuery')->willReturn($result);
        }
        $qb->method('executeStatement')->willReturn(1);

        return $qb;
    }

    /** @param list<array<string, mixed>> $rows */
    protected function makeResult(array $rows): IResult {
        $result = $this->createMock(IResult::class);
        $result->method('fetchAll')->willReturn($rows);
        $result->method('fetch')->willReturn($rows[0] ?? false);
        $result->method('fetchOne')->willReturn($rows === [] ? false : reset($rows[0]));

        return $result;
    }
}
