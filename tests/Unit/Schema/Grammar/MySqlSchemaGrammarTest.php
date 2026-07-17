<?php

declare(strict_types=1);

namespace PHPdot\Database\Tests\Unit\Schema\Grammar;

use PHPdot\Database\Schema\Blueprint;
use PHPdot\Database\Schema\Grammar\MySqlSchemaGrammar;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MySqlSchemaGrammarTest extends TestCase
{
    private MySqlSchemaGrammar $grammar;

    protected function setUp(): void
    {
        $this->grammar = new MySqlSchemaGrammar();
    }

    #[Test]
    public function stringDefaultEscapesBackslashAndQuote(): void
    {
        $blueprint = new Blueprint('paths');
        $blueprint->string('location')->default('C:\\Temp\\it\'s');

        $sql = $this->grammar->compileCreate($blueprint);

        self::assertStringContainsString("DEFAULT 'C:\\\\Temp\\\\it''s'", $sql);
    }

    #[Test]
    public function enumValuesEscapeBackslash(): void
    {
        $blueprint = new Blueprint('files');
        $blueprint->enum('kind', ['a\\b']);

        $sql = $this->grammar->compileCreate($blueprint);

        self::assertStringContainsString("ENUM('a\\\\b')", $sql);
    }
}
