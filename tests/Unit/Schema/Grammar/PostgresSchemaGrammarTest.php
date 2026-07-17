<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Unit\Schema\Grammar;

use PHPdot\Database\Schema\Blueprint;
use PHPdot\Database\Schema\Grammar\PostgresSchemaGrammar;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PostgresSchemaGrammarTest extends TestCase
{
    private PostgresSchemaGrammar $grammar;

    protected function setUp(): void
    {
        $this->grammar = new PostgresSchemaGrammar();
    }

    #[Test]
    public function createCompilesBasicTable(): void
    {
        $blueprint = new Blueprint('users');
        $blueprint->id();
        $blueprint->string('name', 100);
        $blueprint->integer('age')->nullable();

        $sql = $this->grammar->compileCreate($blueprint);

        self::assertStringContainsString('CREATE TABLE "users"', $sql);
        self::assertStringContainsString('"id" BIGSERIAL', $sql);
        self::assertStringContainsString('"name" VARCHAR(100) NOT NULL', $sql);
        self::assertStringContainsString('"age" INTEGER NULL', $sql);
        self::assertStringContainsString('PRIMARY KEY ("id")', $sql);
    }

    #[Test]
    public function storedGeneratedColumnCompilesToGeneratedAlwaysStored(): void
    {
        $blueprint = new Blueprint('boxes');
        $blueprint->id();
        $blueprint->integer('w');
        $blueprint->integer('area')->storedAs('w * 2');

        $sql = $this->grammar->compileCreate($blueprint);

        self::assertStringContainsString('"area" INTEGER GENERATED ALWAYS AS (w * 2) STORED', $sql);
    }

    #[Test]
    public function virtualGeneratedColumnFallsBackToStored(): void
    {
        $blueprint = new Blueprint('boxes');
        $blueprint->integer('w');
        $blueprint->integer('area')->virtualAs('w * 3');

        $sql = $this->grammar->compileCreate($blueprint);

        self::assertStringContainsString('"area" INTEGER GENERATED ALWAYS AS (w * 3) STORED', $sql);
    }

    #[Test]
    public function createIndexesEmitsStandaloneUniqueAndPlainIndexes(): void
    {
        $blueprint = new Blueprint('t');
        $blueprint->string('email');
        $blueprint->unique('email');
        $blueprint->index('name');

        $statements = $this->grammar->compileCreateIndexes($blueprint);

        self::assertContains('CREATE UNIQUE INDEX "t_email_unique" ON "t" ("email")', $statements);
        self::assertContains('CREATE INDEX "t_name_index" ON "t" ("name")', $statements);
    }

    #[Test]
    public function fullTextIndexUsesGinOverTsvector(): void
    {
        $blueprint = new Blueprint('t');
        $blueprint->text('body');
        $blueprint->fullText('body');

        $statements = $this->grammar->compileCreateIndexes($blueprint);

        self::assertContains(
            'CREATE INDEX "t_body_fulltext" ON "t" USING GIN (to_tsvector(\'english\', coalesce("body", \'\')))',
            $statements,
        );
    }

    #[Test]
    public function fullTextIndexHonoursLanguageAndConcatenatesColumns(): void
    {
        $blueprint = new Blueprint('t');
        $blueprint->fullText(['title', 'body'])->language('spanish');

        $statements = $this->grammar->compileCreateIndexes($blueprint);

        self::assertCount(1, $statements);
        self::assertStringContainsString("to_tsvector('spanish'", $statements[0]);
        self::assertStringContainsString('coalesce("title", \'\') || \' \' || coalesce("body", \'\')', $statements[0]);
    }

    #[Test]
    public function spatialIndexUsesGist(): void
    {
        $blueprint = new Blueprint('t');
        $blueprint->spatialIndex('geom');

        $statements = $this->grammar->compileCreateIndexes($blueprint);

        self::assertContains('CREATE INDEX "t_geom_spatial" ON "t" USING GIST ("geom")', $statements);
    }

    #[Test]
    public function indexAlgorithmBecomesAUsingClause(): void
    {
        $blueprint = new Blueprint('t');
        $blueprint->index('email')->algorithm('hash');

        $statements = $this->grammar->compileCreateIndexes($blueprint);

        self::assertContains('CREATE INDEX "t_email_index" ON "t" USING hash ("email")', $statements);
    }

    #[Test]
    public function changeEmitsTypeNullabilityAndDefault(): void
    {
        $blueprint = new Blueprint('t');
        $blueprint->bigInteger('cnt')->nullable(false)->default(7)->change();

        $statements = $this->grammar->compileAlter($blueprint);

        self::assertContains('ALTER TABLE "t" ALTER COLUMN "cnt" TYPE BIGINT', $statements);
        self::assertContains('ALTER TABLE "t" ALTER COLUMN "cnt" SET NOT NULL', $statements);
        self::assertContains('ALTER TABLE "t" ALTER COLUMN "cnt" SET DEFAULT 7', $statements);
    }

    #[Test]
    public function nullableChangeWithoutDefaultDropsBoth(): void
    {
        $blueprint = new Blueprint('t');
        $blueprint->integer('cnt')->nullable()->change();

        $statements = $this->grammar->compileAlter($blueprint);

        self::assertContains('ALTER TABLE "t" ALTER COLUMN "cnt" TYPE INTEGER', $statements);
        self::assertContains('ALTER TABLE "t" ALTER COLUMN "cnt" DROP NOT NULL', $statements);
        self::assertContains('ALTER TABLE "t" ALTER COLUMN "cnt" DROP DEFAULT', $statements);
    }

    #[Test]
    public function alterHonoursColumnLevelUnique(): void
    {
        $blueprint = new Blueprint('t');
        $blueprint->string('email', 100)->unique();

        $statements = $this->grammar->compileAlter($blueprint);

        self::assertContains('ALTER TABLE "t" ADD COLUMN "email" VARCHAR(100) NOT NULL', $statements);
        self::assertContains('CREATE UNIQUE INDEX "t_email_unique" ON "t" ("email")', $statements);
    }

    #[Test]
    public function alterAddsCompositePrimaryKey(): void
    {
        $blueprint = new Blueprint('t');
        $blueprint->primary(['a', 'b']);

        $statements = $this->grammar->compileAlter($blueprint);

        self::assertContains('ALTER TABLE "t" ADD PRIMARY KEY ("a", "b")', $statements);
    }
}
