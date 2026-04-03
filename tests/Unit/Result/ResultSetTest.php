<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Unit\Result;

use ArrayIterator;
use PHPdot\Database\Result\ResultSet;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResultSetTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $sampleRows;

    protected function setUp(): void
    {
        $this->sampleRows = [
            ['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com'],
            ['id' => 2, 'name' => 'Bob', 'email' => 'bob@example.com'],
            ['id' => 3, 'name' => 'Charlie', 'email' => 'charlie@example.com'],
        ];
    }

    #[Test]
    public function allReturnsAllRows(): void
    {
        $result = new ResultSet($this->sampleRows);

        self::assertSame($this->sampleRows, $result->all());
    }

    #[Test]
    public function firstReturnsFirstRow(): void
    {
        $result = new ResultSet($this->sampleRows);

        self::assertSame($this->sampleRows[0], $result->first());
    }

    #[Test]
    public function firstReturnsNullWhenEmpty(): void
    {
        $result = new ResultSet([]);

        self::assertNull($result->first());
    }

    #[Test]
    public function lastReturnsLastRow(): void
    {
        $result = new ResultSet($this->sampleRows);

        self::assertSame($this->sampleRows[2], $result->last());
    }

    #[Test]
    public function lastReturnsNullWhenEmpty(): void
    {
        $result = new ResultSet([]);

        self::assertNull($result->last());
    }

    #[Test]
    public function countReturnsCorrectCount(): void
    {
        $result = new ResultSet($this->sampleRows);

        self::assertSame(3, $result->count());
    }

    #[Test]
    public function isEmptyReturnsTrueWhenEmpty(): void
    {
        $result = new ResultSet([]);

        self::assertTrue($result->isEmpty());
    }

    #[Test]
    public function isEmptyReturnsFalseWhenNotEmpty(): void
    {
        $result = new ResultSet($this->sampleRows);

        self::assertFalse($result->isEmpty());
    }

    #[Test]
    public function isNotEmptyReturnsTrueWhenNotEmpty(): void
    {
        $result = new ResultSet($this->sampleRows);

        self::assertTrue($result->isNotEmpty());
    }

    #[Test]
    public function isNotEmptyReturnsFalseWhenEmpty(): void
    {
        $result = new ResultSet([]);

        self::assertFalse($result->isNotEmpty());
    }

    #[Test]
    public function pluckWithSingleColumn(): void
    {
        $result = new ResultSet($this->sampleRows);

        self::assertSame(['Alice', 'Bob', 'Charlie'], $result->pluck('name'));
    }

    #[Test]
    public function pluckWithKeyColumn(): void
    {
        $result = new ResultSet($this->sampleRows);

        self::assertSame(
            [1 => 'alice@example.com', 2 => 'bob@example.com', 3 => 'charlie@example.com'],
            $result->pluck('email', 'id'),
        );
    }

    #[Test]
    public function valueReturnsSingleValue(): void
    {
        $result = new ResultSet($this->sampleRows);

        self::assertSame('Alice', $result->value('name'));
    }

    #[Test]
    public function valueReturnsNullWhenEmpty(): void
    {
        $result = new ResultSet([]);

        self::assertNull($result->value('name'));
    }

    #[Test]
    public function columnReturnsArrayOfValues(): void
    {
        $result = new ResultSet($this->sampleRows);

        self::assertSame(
            ['alice@example.com', 'bob@example.com', 'charlie@example.com'],
            $result->column('email'),
        );
    }

    #[Test]
    public function keyByIndexesByColumn(): void
    {
        $result = new ResultSet($this->sampleRows);
        $keyed = $result->keyBy('id');

        self::assertArrayHasKey(1, $keyed);
        self::assertArrayHasKey(2, $keyed);
        self::assertArrayHasKey(3, $keyed);
        self::assertSame('Alice', $keyed[1]['name']);
        self::assertSame('Bob', $keyed[2]['name']);
        self::assertSame('Charlie', $keyed[3]['name']);
    }

    #[Test]
    public function mapTransformsRowsAndReturnsNewResultSet(): void
    {
        $result = new ResultSet($this->sampleRows);
        $mapped = $result->map(fn(array $row): array => ['name' => strtoupper((string) $row['name'])]);

        self::assertNotSame($result, $mapped);
        self::assertInstanceOf(ResultSet::class, $mapped);
        self::assertSame('ALICE', $mapped->first()['name']);
        self::assertSame(3, $mapped->count());
    }

    #[Test]
    public function filterFiltersRowsAndReturnsNewResultSet(): void
    {
        $result = new ResultSet($this->sampleRows);
        $filtered = $result->filter(fn(array $row): bool => $row['id'] > 1);

        self::assertNotSame($result, $filtered);
        self::assertInstanceOf(ResultSet::class, $filtered);
        self::assertSame(2, $filtered->count());
        self::assertSame('Bob', $filtered->first()['name']);
    }

    #[Test]
    public function eachIteratesAllRowsAndReturnsSelf(): void
    {
        $result = new ResultSet($this->sampleRows);
        $names = [];
        $returned = $result->each(function (array $row) use (&$names): void {
            $names[] = $row['name'];
        });

        self::assertSame($result, $returned);
        self::assertSame(['Alice', 'Bob', 'Charlie'], $names);
    }

    #[Test]
    public function uniqueRemovesDuplicatesByColumn(): void
    {
        $rows = [
            ['id' => 1, 'role' => 'admin'],
            ['id' => 2, 'role' => 'user'],
            ['id' => 3, 'role' => 'admin'],
            ['id' => 4, 'role' => 'user'],
        ];
        $result = new ResultSet($rows);
        $unique = $result->unique('role');

        self::assertSame(2, $unique->count());
        self::assertSame(1, $unique->first()['id']);
        self::assertSame(2, $unique->last()['id']);
    }

    #[Test]
    public function toArrayReturnsRawArray(): void
    {
        $result = new ResultSet($this->sampleRows);

        self::assertSame($this->sampleRows, $result->toArray());
    }

    #[Test]
    public function toJsonReturnsJsonString(): void
    {
        $result = new ResultSet($this->sampleRows);
        $json = $result->toJson();

        self::assertSame(json_encode($this->sampleRows), $json);
    }

    #[Test]
    public function getIteratorReturnsArrayIterator(): void
    {
        $result = new ResultSet($this->sampleRows);

        self::assertInstanceOf(ArrayIterator::class, $result->getIterator());
    }

    #[Test]
    public function countableInterfaceWorksWithCount(): void
    {
        $result = new ResultSet($this->sampleRows);

        self::assertCount(3, $result);
    }
}
