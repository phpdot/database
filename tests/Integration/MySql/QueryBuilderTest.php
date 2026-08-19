<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Integration\MySql;

use PHPdot\Database\Exception\RecordNotFoundException;
use PHPdot\Database\Result\ResultSet;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

#[Group('mysql')]
#[Group('integration')]
final class QueryBuilderTest extends MySqlTestCase
{
    // ---------------------------------------------------------------
    //  SELECT — get / first / firstOrFail / sole / find / value / pluck
    // ---------------------------------------------------------------

    #[Test]
    public function getReturnsResultSetWithCorrectData(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $result = $this->db->table('users')->get();

        self::assertInstanceOf(ResultSet::class, $result);
        self::assertSame(5, $result->count());
        self::assertSame('Alice', $result->first()['name'] ?? null);
    }

    #[Test]
    public function firstReturnsSingleRowAsArray(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $row = $this->db->table('users')->where('name', 'Bob')->first();

        self::assertIsArray($row);
        self::assertSame('Bob', $row['name']);
        self::assertSame('bob@example.com', $row['email']);
    }

    #[Test]
    public function firstReturnsNullWhenNoMatch(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $row = $this->db->table('users')->where('name', 'Nonexistent')->first();

        self::assertNull($row);
    }

    #[Test]
    public function firstOrFailThrowsRecordNotFoundException(): void
    {
        $this->createUsersTable();

        $this->expectException(RecordNotFoundException::class);
        $this->db->table('users')->where('name', 'Nobody')->firstOrFail();
    }

    #[Test]
    public function soleReturnsExactlyOneRow(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $row = $this->db->table('users')->where('name', 'Alice')->sole();

        self::assertSame('Alice', $row['name']);
    }

    #[Test]
    public function soleThrowsWhenMultipleRowsMatch(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $this->expectException(RuntimeException::class);
        $this->db->table('users')->where('active', 1)->sole();
    }

    #[Test]
    public function findById(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $row = $this->db->table('users')->find(1);

        self::assertIsArray($row);
        self::assertSame('Alice', $row['name']);
    }

    #[Test]
    public function valueReturnsSingleColumnValue(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $email = $this->db->table('users')->where('name', 'Alice')->value('email');

        self::assertSame('alice@example.com', $email);
    }

    #[Test]
    public function pluckReturnsArrayOfValues(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $names = $this->db->table('users')->orderBy('id')->pluck('name');

        self::assertSame(['Alice', 'Bob', 'Charlie', 'Diana', 'Eve'], $names);
    }

    #[Test]
    public function pluckWithKeyColumn(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $result = $this->db->table('users')->orderBy('id')->pluck('name', 'email');

        self::assertSame('Alice', $result['alice@example.com']);
        self::assertSame('Bob', $result['bob@example.com']);
    }

    // ---------------------------------------------------------------
    //  EXISTS
    // ---------------------------------------------------------------

    #[Test]
    public function existsReturnsTrueWhenMatchesFound(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        self::assertTrue($this->db->table('users')->where('name', 'Alice')->exists());
    }

    #[Test]
    public function existsReturnsFalseWhenNoMatches(): void
    {
        $this->createUsersTable();

        self::assertFalse($this->db->table('users')->where('name', 'Nobody')->exists());
    }

    #[Test]
    public function doesntExist(): void
    {
        $this->createUsersTable();

        self::assertTrue($this->db->table('users')->doesntExist());
    }

    // ---------------------------------------------------------------
    //  SELECT specific columns / distinct
    // ---------------------------------------------------------------

    #[Test]
    public function selectSpecificColumns(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $row = $this->db->table('users')->select(['name', 'email'])->first();

        self::assertIsArray($row);
        self::assertArrayHasKey('name', $row);
        self::assertArrayHasKey('email', $row);
        self::assertArrayNotHasKey('age', $row);
    }

    #[Test]
    public function distinct(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $count = $this->db->table('users')->distinct()->select(['active'])->get()->count();

        self::assertSame(2, $count);
    }

    // ---------------------------------------------------------------
    //  WHERE clauses
    // ---------------------------------------------------------------

    #[Test]
    public function whereEquals(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $result = $this->db->table('users')->where('name', 'Alice')->get();

        self::assertSame(1, $result->count());
    }

    #[Test]
    public function whereGreaterThan(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $result = $this->db->table('users')->where('age', '>', 30)->get();

        self::assertSame(1, $result->count());
        self::assertSame('Charlie', $result->first()['name'] ?? null);
    }

    #[Test]
    public function whereLessThan(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $result = $this->db->table('users')->where('age', '<', 25)->get();

        self::assertSame(1, $result->count());
        self::assertSame('Eve', $result->first()['name'] ?? null);
    }

    #[Test]
    public function whereGreaterThanOrEqual(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $result = $this->db->table('users')->where('age', '>=', 30)->get();

        self::assertSame(2, $result->count());
    }

    #[Test]
    public function whereLessThanOrEqual(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $result = $this->db->table('users')->where('age', '<=', 25)->get();

        self::assertSame(2, $result->count());
    }

    #[Test]
    public function whereNotEqual(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $result = $this->db->table('users')->where('name', '<>', 'Alice')->get();

        self::assertSame(4, $result->count());
    }

    #[Test]
    public function whereNotEqualBang(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $result = $this->db->table('users')->where('name', '!=', 'Alice')->get();

        self::assertSame(4, $result->count());
    }

    #[Test]
    public function whereLike(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $result = $this->db->table('users')->where('name', 'LIKE', 'A%')->get();

        self::assertSame(1, $result->count());
        self::assertSame('Alice', $result->first()['name'] ?? null);
    }

    #[Test]
    public function orWhere(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $result = $this->db->table('users')
            ->where('name', 'Alice')
            ->orWhere('name', 'Bob')
            ->get();

        self::assertSame(2, $result->count());
    }

    #[Test]
    public function whereIn(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $result = $this->db->table('users')->whereIn('name', ['Alice', 'Bob', 'Eve'])->get();

        self::assertSame(3, $result->count());
    }

    #[Test]
    public function whereNotIn(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $result = $this->db->table('users')->whereNotIn('name', ['Alice', 'Bob'])->get();

        self::assertSame(3, $result->count());
    }

    #[Test]
    public function whereBetween(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $result = $this->db->table('users')->whereBetween('age', 25, 30)->get();

        self::assertSame(3, $result->count());
    }

    #[Test]
    public function whereNotBetween(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $result = $this->db->table('users')->whereBetween('age', 25, 30, true)->get();

        self::assertSame(2, $result->count());
    }

    #[Test]
    public function whereNull(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $result = $this->db->table('users')->whereNull('deleted_at')->get();

        self::assertSame(5, $result->count());
    }

    #[Test]
    public function whereNotNull(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $result = $this->db->table('users')->whereNotNull('age')->get();

        self::assertSame(5, $result->count());
    }

    #[Test]
    public function whereColumn(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $result = $this->db->table('users')->whereColumn('created_at', '=', 'updated_at')->get();

        self::assertGreaterThanOrEqual(0, $result->count());
    }

    #[Test]
    public function whereDate(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $today = date('Y-m-d');
        $result = $this->db->table('users')->whereDate('created_at', '=', $today)->get();

        self::assertSame(5, $result->count());
    }

    #[Test]
    public function whereYear(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $year = date('Y');
        $result = $this->db->table('users')->whereYear('created_at', '=', $year)->get();

        self::assertSame(5, $result->count());
    }

    #[Test]
    public function whereMonth(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $month = date('m');
        $result = $this->db->table('users')->whereMonth('created_at', '=', $month)->get();

        self::assertSame(5, $result->count());
    }

    #[Test]
    public function nestedWhereWithClosure(): void
    {
        $this->createUsersTable();
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

    #[Test]
    public function multipleChainedWheres(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $result = $this->db->table('users')
            ->where('active', 1)
            ->where('age', '>=', 25)
            ->get();

        self::assertSame(3, $result->count());
    }

    // ---------------------------------------------------------------
    //  ORDER BY / LIMIT / OFFSET
    // ---------------------------------------------------------------

    #[Test]
    public function orderBy(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $result = $this->db->table('users')->orderBy('age', 'asc')->get();

        self::assertSame('Eve', $result->first()['name'] ?? null);
    }

    #[Test]
    public function orderByDesc(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $result = $this->db->table('users')->orderByDesc('age')->get();

        self::assertSame('Charlie', $result->first()['name'] ?? null);
    }

    #[Test]
    public function limitAndOffset(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $result = $this->db->table('users')->orderBy('id')->limit(2)->offset(1)->get();

        self::assertSame(2, $result->count());
        self::assertSame('Bob', $result->first()['name'] ?? null);
    }

    // ---------------------------------------------------------------
    //  GROUP BY / HAVING
    // ---------------------------------------------------------------

    #[Test]
    public function groupByWithHaving(): void
    {
        $this->createUsersTable();
        $this->createPostsTable();
        $this->seedUsers();
        $this->seedPosts();

        $result = $this->db->table('posts')
            ->select([new \PHPdot\Database\Query\Expression('`user_id`, COUNT(*) AS `post_count`')])
            ->groupBy('user_id')
            ->having('post_count', '>', 1)
            ->get();

        self::assertSame(1, $result->count());
    }

    // ---------------------------------------------------------------
    //  AGGREGATES
    // ---------------------------------------------------------------

    #[Test]
    public function countsMatchingRows(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        self::assertSame(5, $this->db->table('users')->count());
    }

    #[Test]
    public function sum(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        self::assertSame(850.5, $this->db->table('users')->sum('balance'));
    }

    #[Test]
    public function avg(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $avg = $this->db->table('users')->avg('age');

        self::assertEqualsWithDelta(28.0, $avg, 0.01);
    }

    #[Test]
    public function min(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $min = $this->db->table('users')->min('age');

        self::assertEquals(22, $min);
    }

    #[Test]
    public function max(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $max = $this->db->table('users')->max('age');

        self::assertEquals(35, $max);
    }

    // ---------------------------------------------------------------
    //  JOIN
    // ---------------------------------------------------------------

    #[Test]
    public function innerJoinWithCorrectResults(): void
    {
        $this->createUsersTable();
        $this->createPostsTable();
        $this->seedUsers();
        $this->seedPosts();

        $result = $this->db->table('users')
            ->join('posts', 'users.id', '=', 'posts.user_id')
            ->select(['users.name', 'posts.title'])
            ->get();

        self::assertSame(4, $result->count());
    }

    #[Test]
    public function leftJoinIncludesNullMatches(): void
    {
        $this->createUsersTable();
        $this->createPostsTable();
        $this->seedUsers();
        $this->seedPosts();

        $result = $this->db->table('users')
            ->leftJoin('posts', 'users.id', '=', 'posts.user_id')
            ->select(['users.name', 'posts.title'])
            ->get();

        // 5 users, Alice has 2 posts, Bob has 1, Charlie has 1, Diana has 0, Eve has 0
        // Left join: 2 + 1 + 1 + 1 + 1 = 6
        self::assertSame(6, $result->count());
    }

    #[Test]
    public function selectFromJoinedTables(): void
    {
        $this->createUsersTable();
        $this->createPostsTable();
        $this->seedUsers();
        $this->seedPosts();

        $row = $this->db->table('users')
            ->join('posts', 'users.id', '=', 'posts.user_id')
            ->select(['users.name', 'posts.title'])
            ->where('posts.title', 'First Post')
            ->first();

        self::assertIsArray($row);
        self::assertSame('Alice', $row['name']);
        self::assertSame('First Post', $row['title']);
    }

    // ---------------------------------------------------------------
    //  INSERT
    // ---------------------------------------------------------------

    #[Test]
    public function insertSingleRowAndVerifyWithSelect(): void
    {
        $this->createUsersTable();

        $this->db->table('users')->insert([
            'name' => 'Test',
            'email' => 'test@example.com',
            'age' => 40,
        ]);

        $row = $this->db->table('users')->where('email', 'test@example.com')->first();

        self::assertIsArray($row);
        self::assertSame('Test', $row['name']);
    }

    #[Test]
    public function insertGetIdReturnsAutoIncrementId(): void
    {
        $this->createUsersTable();

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

    #[Test]
    public function insertBatchMultipleRows(): void
    {
        $this->createUsersTable();

        $this->db->table('users')->insertBatch([
            ['name' => 'A', 'email' => 'a@test.com'],
            ['name' => 'B', 'email' => 'b@test.com'],
            ['name' => 'C', 'email' => 'c@test.com'],
        ]);

        self::assertSame(3, $this->db->table('users')->count());
    }

    #[Test]
    public function insertOrIgnoreSkipsDuplicates(): void
    {
        $this->createUsersTable();

        $this->db->table('users')->insert([
            'name' => 'Test',
            'email' => 'dup@example.com',
        ]);

        // Should not throw on duplicate email (unique constraint)
        $this->db->table('users')->insertOrIgnore([
            'name' => 'Test2',
            'email' => 'dup@example.com',
        ]);

        self::assertSame(1, $this->db->table('users')->count());
    }

    // ---------------------------------------------------------------
    //  UPDATE
    // ---------------------------------------------------------------

    #[Test]
    public function updateChangesData(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $affected = $this->db->table('users')->where('name', 'Alice')->update(['age' => 31]);

        self::assertSame(1, $affected);

        $row = $this->db->table('users')->where('name', 'Alice')->first();
        self::assertEquals(31, $row['age'] ?? null);
    }

    #[Test]
    public function increment(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $this->db->table('users')->where('name', 'Alice')->increment('age', 5);

        $row = $this->db->table('users')->where('name', 'Alice')->first();
        self::assertEquals(35, $row['age'] ?? null);
    }

    #[Test]
    public function decrement(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $this->db->table('users')->where('name', 'Alice')->decrement('age', 3);

        $row = $this->db->table('users')->where('name', 'Alice')->first();
        self::assertEquals(27, $row['age'] ?? null);
    }

    #[Test]
    public function updateWithWhereCondition(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $affected = $this->db->table('users')->where('active', 0)->update(['active' => 1]);

        self::assertSame(1, $affected);
        self::assertSame(5, $this->db->table('users')->where('active', 1)->count());
    }

    // ---------------------------------------------------------------
    //  UPSERT
    // ---------------------------------------------------------------

    #[Test]
    public function upsertInsertsNewRow(): void
    {
        $this->createUsersTable();

        $this->db->table('users')->upsert(
            ['name' => 'New', 'email' => 'new@example.com', 'age' => 20],
            ['email'],
            ['name', 'age'],
        );

        self::assertSame(1, $this->db->table('users')->count());
        self::assertSame('New', $this->db->table('users')->where('email', 'new@example.com')->value('name'));
    }

    #[Test]
    public function upsertUpdatesExistingRow(): void
    {
        $this->createUsersTable();
        $this->db->table('users')->insert(['name' => 'Old', 'email' => 'upsert@example.com', 'age' => 20]);

        $this->db->table('users')->upsert(
            ['name' => 'Updated', 'email' => 'upsert@example.com', 'age' => 99],
            ['email'],
            ['name', 'age'],
        );

        self::assertSame(1, $this->db->table('users')->count());
        self::assertSame('Updated', $this->db->table('users')->where('email', 'upsert@example.com')->value('name'));
    }

    // ---------------------------------------------------------------
    //  DELETE
    // ---------------------------------------------------------------

    #[Test]
    public function deleteWithWhereRemovesSpecificRows(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $deleted = $this->db->table('users')->where('name', 'Alice')->delete();

        self::assertSame(1, $deleted);
        self::assertSame(4, $this->db->table('users')->count());
    }

    #[Test]
    public function deleteWithoutWhereRemovesAll(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $deleted = $this->db->table('users')->delete();

        self::assertSame(5, $deleted);
        self::assertSame(0, $this->db->table('users')->count());
    }

    #[Test]
    public function truncateEmptiesTable(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $this->db->table('users')->truncate();

        self::assertSame(0, $this->db->table('users')->count());
    }

    // ---------------------------------------------------------------
    //  CHUNKING
    // ---------------------------------------------------------------

    #[Test]
    public function chunkProcessesAllRowsInBatches(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $allNames = [];
        $this->db->table('users')->orderBy('id')->chunk(2, function (ResultSet $results) use (&$allNames): void {
            foreach ($results->all() as $row) {
                $allNames[] = $row['name'];
            }
        });

        self::assertCount(5, $allNames);
    }

    #[Test]
    public function chunkByIdProcessesCorrectly(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $allIds = [];
        $this->db->table('users')->chunkById(2, function (ResultSet $results) use (&$allIds): void {
            foreach ($results->all() as $row) {
                $allIds[] = $row['id'];
            }
        });

        self::assertCount(5, $allIds);
    }

    #[Test]
    public function lazyYieldsAllRows(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $count = 0;
        foreach ($this->db->table('users')->orderBy('id')->lazy(2) as $row) {
            $count++;
        }

        self::assertSame(5, $count);
    }

    #[Test]
    public function chunkCallbackCanReturnFalseToStop(): void
    {
        $this->createUsersTable();
        $this->seedUsers();

        $processed = 0;
        $stopped = $this->db->table('users')->orderBy('id')->chunk(2, function () use (&$processed): false {
            $processed++;
            return false;
        });

        self::assertFalse($stopped);
        self::assertSame(1, $processed);
    }
}
