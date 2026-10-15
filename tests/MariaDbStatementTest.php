<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Db\Tests;

use Rasuvaeff\Context\Db\Internal\MariaDbStatement;
use Rasuvaeff\Context\Db\Tests\Support\SqlGenerators;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(MariaDbStatement::class)]
final class MariaDbStatementTest
{
    #[DataProvider('prefixProvider')]
    public function prefixesTheStatement(string $sql, string $expected): void
    {
        Assert::same(MariaDbStatement::apply($sql, 1250), $expected);
    }

    public static function prefixProvider(): iterable
    {
        yield 'select' => ['SELECT 1', 'SET STATEMENT max_statement_time=1.25 FOR SELECT 1'];
        yield 'insert' => ['INSERT INTO t VALUES (1)', 'SET STATEMENT max_statement_time=1.25 FOR INSERT INTO t VALUES (1)'];
        yield 'other variables' => [
            'SET STATEMENT sort_buffer_size=1 FOR SELECT 1',
            'SET STATEMENT max_statement_time=1.25, sort_buffer_size=1 FOR SELECT 1',
        ];
        yield 'looser existing limit' => [
            '  set statement max_statement_time = 30 for select 1',
            '  set statement max_statement_time=1.25 for select 1',
        ];
        yield 'stricter existing limit is kept' => [
            'SET STATEMENT max_statement_time=.5 FOR SELECT 1',
            'SET STATEMENT max_statement_time=0.5 FOR SELECT 1',
        ];
        yield 'unlimited existing limit' => [
            'SET STATEMENT max_statement_time=0 FOR SELECT 1',
            'SET STATEMENT max_statement_time=1.25 FOR SELECT 1',
        ];
        yield 'every duplicate gets the strictest value' => [
            'SET STATEMENT max_statement_time=9, max_statement_time=0.002 FOR SELECT 1',
            'SET STATEMENT max_statement_time=0.002, max_statement_time=0.002 FOR SELECT 1',
        ];
        yield 'empty' => ['', ''];
        yield 'whitespace' => ['  ', '  '];
    }

    #[DataProvider('secondsProvider')]
    public function formatsSecondsWithoutTrailingZeros(int $milliseconds, string $expected): void
    {
        /** @var positive-int $milliseconds */
        Assert::same(MariaDbStatement::seconds($milliseconds), $expected);
    }

    public static function secondsProvider(): iterable
    {
        yield 'one millisecond' => [1, '0.001'];
        yield 'tenth' => [300, '0.3'];
        yield 'second' => [1_000, '1'];
        yield 'fraction' => [1_250, '1.25'];
        yield 'minute' => [60_000, '60'];
        yield 'odd' => [12_345, '12.345'];
    }

    /**
     * @param positive-int $first
     * @param positive-int $second
     */
    #[Property(runs: 300)]
    public function applyingTwiceKeepsTheStricterLimit(string $sql, int $first, int $second): void
    {
        $once = MariaDbStatement::apply($sql, min($first, $second));

        Assert::same(MariaDbStatement::apply(MariaDbStatement::apply($sql, $first), $second), $once);
        Assert::true(preg_match('/\A\s*SET\s+STATEMENT\s/i', $once) === 1);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function applyingTwiceKeepsTheStricterLimitGenerators(): array
    {
        return [
            'sql' => SqlGenerators::mariaDbStatement(),
            'first' => Gen::intBetween(1, 100_000),
            'second' => Gen::intBetween(1, 100_000),
        ];
    }
}
