<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Integration\Sqlite;

use PHPdot\Database\Query\Expression;
use PHPdot\Database\Result\CursorPaginator;
use PHPdot\Database\Result\Paginator;
use PHPdot\Database\Result\ResultSet;

final class QueryBuilderTest extends SqliteTestCase
{
    public function testGetReturnsCorrectData(): void
    {
        $this->seedUsers();

        $result = $this->db->table('users')->get();

        self::assertInstanceOf(ResultSet::class, $result);
        self::assertSame(5, $result->count());
        self::assertSame('Alice', $result->first()['name'] ?? null);
    }

    public function testFirstReturnsSingleRow(): void
    {
        $this->seedUsers();

        $row = $this->db->table('users')->where('name', 'Bob')->first();

        self::assertIsArray($row);
        self::assertSame('Bob', $row['name']);
        self::assertSame('bob@example.com', $row['email']);
    }

    public function testFirstReturnsNullWhenEmpty(): void
    {
        $row = $this->db->table('users')->where('name', 'Nobody')->first();

        self::assertNull($row);
    }

    public function testFindById(): void
    {
        $this->seedUsers();

        $row = $this->db->table('users')->find(1);

        self::assertIsArray($row);
        self::assertSame('Alice', $row['name']);
    }

    public function testValueReturnsSingleValue(): void
    {
        $this->seedUsers();

        $email = $this->db->table('users')->where('name', 'Alice')->value('email');

        self::assertSame('alice@example.com', $email);
    }

    public function testPluckWithoutKey(): void
    {
        $this->seedUsers();

        $names = $this->db->table('users')->orderBy('id')->pluck('name');

        self::assertSame(['Alice', 'Bob', 'Charlie', 'Diana', 'Eve'], $names);
    }

    public function testPluckWithKey(): void
    {
        $this->seedUsers();

        $result = $this->db->table('users')->orderBy('id')->pluck('name', 'email');

        self::assertSame('Alice', $result['alice@example.com']);
        self::assertSame('Bob', $result['bob@example.com']);
    }

    public function testExistsReturnsTrue(): void
    {
        $this->seedUsers();

        self::assertTrue($this->db->table('users')->where('name', 'Alice')->exists());
    }

    public function testDoesntExistReturnsTrue(): void
    {
        self::assertTrue($this->db->table('users')->doesntExist());
    }

    public function testWhereEquals(): void
    {
        $this->seedUsers();

        $result = $this->db->table('users')->where('name', 'Alice')->get();

        self::assertSame(1, $result->count());
    }

    public function testWhereGreaterThan(): void
    {
        $this->seedUsers();

        $result = $this->db->table('users')->where('age', '>', 30)->get();

        self::assertSame(1, $result->count());
        self::assertSame('Charlie', $result->first()['name'] ?? null);
    }

    public function testWhereLessThan(): void
    {
        $this->seedUsers();

        $result = $this->db->table('users')->where('age', '<', 25)->get();

        self::assertSame(1, $result->count());
        self::assertSame('Eve', $result->first()['name'] ?? null);
    }

    public function testWhereLike(): void
    {
        $this->seedUsers();

        $result = $this->db->table('users')->where('name', 'LIKE', 'A%')->get();

        self::assertSame(1, $result->count());
        self::assertSame('Alice', $result->first()['name'] ?? null);
    }

    public function testOrWhere(): void
    {
        $this->seedUsers();

        $result = $this->db->table('users')
            ->where('name', 'Alice')
            ->orWhere('name', 'Bob')
            ->get();

        self::assertSame(2, $result->count());
    }

    public function testWhereIn(): void
    {
        $this->seedUsers();

        $result = $this->db->table('users')->whereIn('name', ['Alice', 'Bob', 'Eve'])->get();

        self::assertSame(3, $result->count());
    }

    public function testWhereNotIn(): void
    {
        $this->seedUsers();

        $result = $this->db->table('users')->whereNotIn('name', ['Alice', 'Bob'])->get();

        self::assertSame(3, $result->count());
    }

    public function testWhereBetween(): void
    {
        $this->seedUsers();

        $result = $this->db->table('users')->whereBetween('age', 25, 30)->get();

        self::assertSame(3, $result->count());
    }

    public function testWhereNull(): void
    {
        $this->seedUsers();

        $result = $this->db->table('users')->whereNull('created_at')->get();

        self::assertSame(5, $result->count());
    }

    public function testWhereNotNull(): void
    {
        $this->seedUsers();

        $result = $this->db->table('users')->whereNotNull('age')->get();

        self::assertSame(5, $result->count());
    }

    public function testWhereColumn(): void
    {
        $this->db->unprepared('CREATE TABLE pairs (id INTEGER PRIMARY KEY, a INTEGER, b INTEGER)');
        $this->db->table('pairs')->insertBatch([
            ['a' => 1, 'b' => 1],
            ['a' => 2, 'b' => 3],
            ['a' => 5, 'b' => 5],
        ]);

        $result = $this->db->table('pairs')->whereColumn('a', '=', 'b')->get();

        self::assertSame(2, $result->count());
    }

    public function testNestedWhereWithClosure(): void
    {
        $this->seedUsers();

        $result = $this->db->table('users')
            ->where('active', 1)
            ->where(function ($query): void {
                $query->where('age', '>', 28)
                    ->orWhere('name', 'Eve');
            })
            ->get();

        self::assertSame(2, $result->count());
    }

    public function testOrderBy(): void
    {
        $this->seedUsers();

        $result = $this->db->table('users')->orderBy('age', 'asc')->get();

        self::assertSame('Eve', $result->first()['name'] ?? null);
    }

    public function testOrderByDesc(): void
    {
        $this->seedUsers();

        $result = $this->db->table('users')->orderByDesc('age')->get();

        self::assertSame('Charlie', $result->first()['name'] ?? null);
    }

    public function testLimitAndOffset(): void
    {
        $this->seedUsers();

        $result = $this->db->table('users')->orderBy('id')->limit(2)->offset(1)->get();

        self::assertSame(2, $result->count());
        self::assertSame('Bob', $result->first()['name'] ?? null);
    }

    public function testGroupByWithCount(): void
    {
        $this->seedUsers();
        $this->seedPosts();

        $result = $this->db->table('posts')
            ->select([new Expression('"user_id", COUNT(*) AS "post_count"')])
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        self::assertSame(1, $result->count());
    }

    public function testCount(): void
    {
        $this->seedUsers();

        self::assertSame(5, $this->db->table('users')->count());
    }

    public function testSum(): void
    {
        $this->seedUsers();

        self::assertSame(850.5, $this->db->table('users')->sum('balance'));
    }

    public function testAvg(): void
    {
        $this->seedUsers();

        $avg = $this->db->table('users')->avg('age');

        self::assertEqualsWithDelta(28.0, $avg, 0.01);
    }

    public function testMin(): void
    {
        $this->seedUsers();

        self::assertEquals(22, $this->db->table('users')->min('age'));
    }

    public function testMax(): void
    {
        $this->seedUsers();

        self::assertEquals(35, $this->db->table('users')->max('age'));
    }

    public function testInnerJoinWithResults(): void
    {
        $this->seedUsers();
        $this->seedPosts();

        $result = $this->db->table('users')
            ->join('posts', 'users.id', '=', 'posts.user_id')
            ->select(['users.name', 'posts.title'])
            ->get();

        self::assertSame(3, $result->count());
    }

    public function testLeftJoinIncludesNulls(): void
    {
        $this->seedUsers();
        $this->seedPosts();

        $result = $this->db->table('users')
            ->leftJoin('posts', 'users.id', '=', 'posts.user_id')
            ->select(['users.name', 'posts.title'])
            ->get();

        self::assertSame(6, $result->count());
    }

    public function testInsertAndVerify(): void
    {
        $this->db->table('users')->insert([
            'name' => 'Test',
            'email' => 'test@example.com',
            'age' => 40,
        ]);

        $row = $this->db->table('users')->where('email', 'test@example.com')->first();

        self::assertIsArray($row);
        self::assertSame('Test', $row['name']);
    }

    public function testInsertGetIdReturnsId(): void
    {
        $id = $this->db->table('users')->insertGetId([
            'name' => 'Test',
            'email' => 'test@example.com',
        ]);

        self::assertSame(1, $id);

        $id2 = $this->db->table('users')->insertGetId([
            'name' => 'Test2',
            'email' => 'test2@example.com',
        ]);

        self::assertSame(2, $id2);
    }

    public function testInsertBatch(): void
    {
        $this->db->table('users')->insertBatch([
            ['name' => 'A', 'email' => 'a@test.com'],
            ['name' => 'B', 'email' => 'b@test.com'],
            ['name' => 'C', 'email' => 'c@test.com'],
        ]);

        self::assertSame(3, $this->db->table('users')->count());
    }

    public function testInsertOrIgnoreSkipsDuplicates(): void
    {
        $this->db->table('users')->insert([
            'name' => 'Test',
            'email' => 'dup@example.com',
        ]);

        $this->db->table('users')->insertOrIgnore([
            'name' => 'Test2',
            'email' => 'dup@example.com',
        ]);

        self::assertSame(1, $this->db->table('users')->count());
    }

    public function testUpdateAndVerify(): void
    {
        $this->seedUsers();

        $affected = $this->db->table('users')->where('name', 'Alice')->update(['age' => 31]);

        self::assertSame(1, $affected);

        $row = $this->db->table('users')->where('name', 'Alice')->first();
        self::assertEquals(31, $row['age'] ?? null);
    }

    public function testIncrement(): void
    {
        $this->seedUsers();

        $this->db->table('users')->where('name', 'Alice')->increment('age', 5);

        $row = $this->db->table('users')->where('name', 'Alice')->first();
        self::assertEquals(35, $row['age'] ?? null);
    }

    public function testDecrement(): void
    {
        $this->seedUsers();

        $this->db->table('users')->where('name', 'Alice')->decrement('age', 3);

        $row = $this->db->table('users')->where('name', 'Alice')->first();
        self::assertEquals(27, $row['age'] ?? null);
    }

    public function testUpsertInsertNew(): void
    {
        $this->db->table('users')->upsert(
            ['name' => 'New', 'email' => 'new@example.com', 'age' => 20],
            ['email'],
            ['name', 'age'],
        );

        self::assertSame(1, $this->db->table('users')->count());
        self::assertSame('New', $this->db->table('users')->where('email', 'new@example.com')->value('name'));
    }

    public function testUpsertUpdateExisting(): void
    {
        $this->db->table('users')->insert(['name' => 'Old', 'email' => 'upsert@example.com', 'age' => 20]);

        $this->db->table('users')->upsert(
            ['name' => 'Updated', 'email' => 'upsert@example.com', 'age' => 99],
            ['email'],
            ['name', 'age'],
        );

        self::assertSame(1, $this->db->table('users')->count());
        self::assertSame('Updated', $this->db->table('users')->where('email', 'upsert@example.com')->value('name'));
    }

    public function testDeleteWithWhere(): void
    {
        $this->seedUsers();

        $deleted = $this->db->table('users')->where('name', 'Alice')->delete();

        self::assertSame(1, $deleted);
        self::assertSame(4, $this->db->table('users')->count());
    }

    public function testTruncate(): void
    {
        $this->seedUsers();

        $this->db->table('users')->truncate();

        self::assertSame(0, $this->db->table('users')->count());
    }

    public function testChunkProcessesAll(): void
    {
        $this->seedUsers();

        $allNames = [];
        $this->db->table('users')->orderBy('id')->chunk(2, function (ResultSet $results) use (&$allNames): void {
            foreach ($results->all() as $row) {
                $allNames[] = $row['name'];
            }
        });

        self::assertCount(5, $allNames);
    }

    public function testLazyYieldsAll(): void
    {
        $this->seedUsers();

        $count = 0;
        foreach ($this->db->table('users')->orderBy('id')->lazy(2) as $row) {
            $count++;
        }

        self::assertSame(5, $count);
    }

    public function testPaginateReturnsCorrectTotals(): void
    {
        $this->seedUsers();

        $page = $this->db->table('users')->orderBy('id')->paginate(1, 2);

        self::assertInstanceOf(Paginator::class, $page);
        self::assertSame(5, $page->total());
        self::assertSame(2, $page->count());
        self::assertSame(1, $page->currentPage());
        self::assertTrue($page->hasMorePages());
    }

    public function testSimplePaginate(): void
    {
        $this->seedUsers();

        $page = $this->db->table('users')->orderBy('id')->simplePaginate(1, 2);

        self::assertInstanceOf(Paginator::class, $page);
        self::assertSame(2, $page->count());
    }

    public function testCursorPaginate(): void
    {
        $this->seedUsers();

        $page = $this->db->table('users')->cursorPaginate(2, null, 'id');

        self::assertInstanceOf(CursorPaginator::class, $page);
        self::assertSame(2, $page->count());
        self::assertTrue($page->hasMorePages());
        self::assertNotNull($page->nextCursor());
    }

    public function testCloneProducesIndependentQueries(): void
    {
        $this->seedUsers();

        $base = $this->db->table('users')->where('active', 1);
        $clone = clone $base;
        $clone->where('age', '>', 28);

        self::assertSame(4, $base->count());
        self::assertSame(1, $clone->count());
    }
}
