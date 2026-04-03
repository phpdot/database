<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Unit\Query\Grammar;

use PHPdot\Database\Query\Grammar\MySqlGrammar;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MySqlGrammarTest extends TestCase
{
    private MySqlGrammar $grammar;

    protected function setUp(): void
    {
        $this->grammar = new MySqlGrammar();
    }

    #[Test]
    public function backtickQuoting(): void
    {
        self::assertSame('`users`', $this->grammar->wrap('users'));
        self::assertSame('`users`.`name`', $this->grammar->wrap('users.name'));
        self::assertSame('*', $this->grammar->wrap('*'));
    }

    #[Test]
    public function wrapTableWithPrefix(): void
    {
        $this->grammar->setTablePrefix('app_');

        self::assertSame('`app_users`', $this->grammar->wrapTable('users'));
    }

    #[Test]
    public function wrapAlias(): void
    {
        self::assertSame('`name` AS `display_name`', $this->grammar->wrap('name as display_name'));
    }

    #[Test]
    public function wrapTableDotStar(): void
    {
        self::assertSame('`users`.*', $this->grammar->wrap('users.*'));
    }

    #[Test]
    public function insertIgnoreSyntax(): void
    {
        $sql = $this->grammar->compileInsertOrIgnore('users', ['name' => '?']);

        self::assertSame('INSERT IGNORE INTO `users` (`name`) VALUES (?)', $sql);
    }

    #[Test]
    public function onDuplicateKeyUpdateSyntax(): void
    {
        $sql = $this->grammar->compileUpsert('users', ['email' => '?', 'name' => '?'], ['email'], ['name']);

        self::assertSame(
            'INSERT INTO `users` (`email`, `name`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `name` = VALUES(`name`)',
            $sql,
        );
    }

    #[Test]
    public function lockForUpdate(): void
    {
        $sql = $this->grammar->compileSelect([
            'columns' => ['*'],
            'from' => 'users',
            'lock' => true,
        ]);

        self::assertSame('SELECT * FROM `users` FOR UPDATE', $sql);
    }

    #[Test]
    public function lockInShareMode(): void
    {
        $sql = $this->grammar->compileSelect([
            'columns' => ['*'],
            'from' => 'users',
            'lock' => 'LOCK IN SHARE MODE',
        ]);

        self::assertSame('SELECT * FROM `users` LOCK IN SHARE MODE', $sql);
    }

    #[Test]
    public function dateFunction(): void
    {
        $sql = $this->grammar->compileSelect([
            'columns' => ['*'],
            'from' => 'users',
            'wheres' => [
                ['type' => 'date', 'dateType' => 'date', 'column' => 'created_at', 'operator' => '=', 'value' => '?', 'boolean' => 'and'],
            ],
        ]);

        self::assertSame('SELECT * FROM `users` WHERE DATE(`created_at`) = ?', $sql);
    }

    #[Test]
    public function yearFunction(): void
    {
        $sql = $this->grammar->compileSelect([
            'columns' => ['*'],
            'from' => 'users',
            'wheres' => [
                ['type' => 'date', 'dateType' => 'year', 'column' => 'created_at', 'operator' => '=', 'value' => '?', 'boolean' => 'and'],
            ],
        ]);

        self::assertSame('SELECT * FROM `users` WHERE YEAR(`created_at`) = ?', $sql);
    }

    #[Test]
    public function jsonContainsSyntax(): void
    {
        $sql = $this->grammar->compileSelect([
            'columns' => ['*'],
            'from' => 'users',
            'wheres' => [
                ['type' => 'jsonContains', 'column' => 'tags->role', 'value' => '?', 'not' => false, 'boolean' => 'and'],
            ],
        ]);

        self::assertSame("SELECT * FROM `users` WHERE JSON_CONTAINS(`tags`->'\$.role', ?)", $sql);
    }

    #[Test]
    public function jsonContainsNotSyntax(): void
    {
        $sql = $this->grammar->compileSelect([
            'columns' => ['*'],
            'from' => 'users',
            'wheres' => [
                ['type' => 'jsonContains', 'column' => 'tags', 'value' => '?', 'not' => true, 'boolean' => 'and'],
            ],
        ]);

        self::assertSame('SELECT * FROM `users` WHERE NOT JSON_CONTAINS(`tags`, ?)', $sql);
    }

    #[Test]
    public function jsonLengthSyntax(): void
    {
        $sql = $this->grammar->compileSelect([
            'columns' => ['*'],
            'from' => 'users',
            'wheres' => [
                ['type' => 'jsonLength', 'column' => 'tags->items', 'value' => '?', 'operator' => '>', 'boolean' => 'and'],
            ],
        ]);

        self::assertSame("SELECT * FROM `users` WHERE JSON_LENGTH(`tags`->'\$.items') > ?", $sql);
    }

    #[Test]
    public function matchAgainstSyntax(): void
    {
        $sql = $this->grammar->compileSelect([
            'columns' => ['*'],
            'from' => 'posts',
            'wheres' => [
                ['type' => 'fullText', 'columns' => ['title', 'body'], 'value' => '?', 'boolean' => 'and'],
            ],
        ]);

        self::assertSame('SELECT * FROM `posts` WHERE MATCH(`title`, `body`) AGAINST(?)', $sql);
    }

    #[Test]
    public function matchAgainstBooleanMode(): void
    {
        $sql = $this->grammar->compileSelect([
            'columns' => ['*'],
            'from' => 'posts',
            'wheres' => [
                ['type' => 'fullText', 'columns' => ['title'], 'value' => '?', 'options' => ['mode' => 'boolean'], 'boolean' => 'and'],
            ],
        ]);

        self::assertSame('SELECT * FROM `posts` WHERE MATCH(`title`) AGAINST(? IN BOOLEAN MODE)', $sql);
    }

    #[Test]
    public function likeBinaryCaseSensitive(): void
    {
        $sql = $this->grammar->compileSelect([
            'columns' => ['*'],
            'from' => 'users',
            'wheres' => [
                ['type' => 'like', 'column' => 'name', 'value' => '?', 'not' => false, 'caseSensitive' => true, 'boolean' => 'and'],
            ],
        ]);

        self::assertSame('SELECT * FROM `users` WHERE `name` LIKE BINARY ?', $sql);
    }

    #[Test]
    public function likeNotBinary(): void
    {
        $sql = $this->grammar->compileSelect([
            'columns' => ['*'],
            'from' => 'users',
            'wheres' => [
                ['type' => 'like', 'column' => 'name', 'value' => '?', 'not' => true, 'caseSensitive' => true, 'boolean' => 'and'],
            ],
        ]);

        self::assertSame('SELECT * FROM `users` WHERE `name` NOT LIKE BINARY ?', $sql);
    }

    #[Test]
    public function randomOrder(): void
    {
        self::assertSame('RAND()', $this->grammar->compileRandomOrder());
    }

    #[Test]
    public function truncate(): void
    {
        self::assertSame('TRUNCATE TABLE `users`', $this->grammar->compileTruncate('users'));
    }

    #[Test]
    public function compileSelectFull(): void
    {
        $sql = $this->grammar->compileSelect([
            'columns' => ['*'],
            'from' => 'users',
            'wheres' => [
                ['type' => 'basic', 'column' => 'active', 'operator' => '=', 'value' => '?', 'boolean' => 'and'],
            ],
            'orders' => [
                ['column' => 'name', 'direction' => 'ASC'],
            ],
            'limit' => 10,
            'offset' => 20,
        ]);

        self::assertSame('SELECT * FROM `users` WHERE `active` = ? ORDER BY `name` ASC LIMIT 10 OFFSET 20', $sql);
    }

    #[Test]
    public function columnize(): void
    {
        self::assertSame('`name`, `email`, `age`', $this->grammar->columnize(['name', 'email', 'age']));
    }

    #[Test]
    public function parameterize(): void
    {
        self::assertSame('?, ?, ?', $this->grammar->parameterize(['a', 'b', 'c']));
    }
}
