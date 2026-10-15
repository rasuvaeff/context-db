<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Db\Tests;

use Rasuvaeff\Context\Db\Internal\MySqlHint;
use Rasuvaeff\Context\Db\Tests\Support\SqlGenerators;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(MySqlHint::class)]
final class MySqlHintTest
{
    #[DataProvider('rewrittenProvider')]
    public function addsTheHintToTheMainSelect(string $sql, string $expected): void
    {
        Assert::same(MySqlHint::apply($sql, 1500), $expected);
    }

    public static function rewrittenProvider(): iterable
    {
        $hint = '/*+ MAX_EXECUTION_TIME(1500) */';

        yield 'plain select' => ['SELECT * FROM t', "SELECT {$hint} * FROM t"];
        yield 'lowercase' => ['select 1', "select {$hint} 1"];
        yield 'leading whitespace' => ["  \n\tSELECT 1", "  \n\tSELECT {$hint} 1"];
        yield 'leading block comment' => ['/* report */ SELECT 1', "/* report */ SELECT {$hint} 1"];
        yield 'leading dash comment' => ["-- report\nSELECT 1", "-- report\nSELECT {$hint} 1"];
        yield 'leading hash comment' => ["# report\nSELECT 1", "# report\nSELECT {$hint} 1"];
        yield 'parenthesised union' => ['(SELECT 1) UNION (SELECT 2)', "(SELECT {$hint} 1) UNION (SELECT 2)"];
        yield 'select followed by a newline' => ["SELECT\n1", "SELECT {$hint}\n1"];
        yield 'select at the very end' => ['SELECT', "SELECT {$hint}"];
        yield 'with' => [
            'WITH x AS (SELECT 1 AS o) SELECT o FROM x',
            "WITH x AS (SELECT 1 AS o) SELECT {$hint} o FROM x",
        ];
        yield 'with recursive and column list' => [
            'WITH RECURSIVE n (v) AS (SELECT 1 UNION ALL SELECT v + 1 FROM n WHERE v < 5) SELECT v FROM n',
            "WITH RECURSIVE n (v) AS (SELECT 1 UNION ALL SELECT v + 1 FROM n WHERE v < 5) SELECT {$hint} v FROM n",
        ];
        yield 'several ctes' => [
            'with a as (select 1), `b c` as (select 2) select * from a, `b c`',
            "with a as (select 1), `b c` as (select 2) select {$hint} * from a, `b c`",
        ];
        yield 'quotes and comments inside a cte' => [
            "WITH x AS (SELECT ')' AS a, 'it''s (' AS b, \"q\\\")\" AS c /* ) */ -- )\n) SELECT * FROM x",
            "WITH x AS (SELECT ')' AS a, 'it''s (' AS b, \"q\\\")\" AS c /* ) */ -- )\n) SELECT {$hint} * FROM x",
        ];
        yield 'existing hint without a limit' => [
            'SELECT /*+ BKA(t) */ * FROM t',
            'SELECT /*+ BKA(t) MAX_EXECUTION_TIME(1500) */ * FROM t',
        ];
        yield 'looser existing limit' => [
            'SELECT /*+ MAX_EXECUTION_TIME(9000) BKA(t) */ 1',
            'SELECT /*+ MAX_EXECUTION_TIME(1500) BKA(t) */ 1',
        ];
        yield 'stricter existing limit is kept' => [
            'SELECT /*+ max_execution_time( 200 ) */ 1',
            'SELECT /*+ MAX_EXECUTION_TIME(200) */ 1',
        ];
        yield 'unlimited existing limit' => [
            'SELECT /*+ MAX_EXECUTION_TIME(0) */ 1',
            'SELECT /*+ MAX_EXECUTION_TIME(1500) */ 1',
        ];
    }

    #[DataProvider('untouchedProvider')]
    public function leavesEverythingElseAlone(string $sql): void
    {
        Assert::same(MySqlHint::apply($sql, 1500), $sql);
    }

    public static function untouchedProvider(): iterable
    {
        yield 'insert' => ['INSERT INTO t VALUES (1)'];
        yield 'insert select' => ['INSERT INTO t SELECT * FROM s'];
        yield 'update' => ['UPDATE t SET a = 1'];
        yield 'delete' => ['DELETE FROM t'];
        yield 'with update' => ['WITH x AS (SELECT 1) UPDATE t SET a = 1'];
        yield 'set' => ['SET @a = 1'];
        yield 'call' => ['CALL p()'];
        yield 'show' => ['SHOW TABLES'];
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
        yield 'not a keyword boundary' => ['SELECTED'];
        yield 'with not a keyword' => ['WITHIN x'];
        yield 'versioned comment' => ['/*!50000 SELECT 1 */'];
        yield 'leading hint comment' => ['/*+ BKA(t) */ SELECT 1'];
        yield 'unterminated comment' => ['/* SELECT 1'];
        yield 'unbalanced cte' => ['WITH x AS (SELECT 1 SELECT 2'];
        yield 'unterminated quote in cte' => ["WITH x AS (SELECT 'a) SELECT 1"];
        yield 'cte without as' => ['WITH x (SELECT 1) SELECT 2'];
        yield 'cte without body' => ['WITH x AS SELECT 2'];
        yield 'cte without a name' => ['WITH AS (SELECT 1) SELECT 2'];
        yield 'unbalanced column list' => ['WITH x (a AS (SELECT 1) SELECT 2'];
        yield 'unterminated quoted cte name' => ['WITH `x AS (SELECT 1) SELECT 2'];
        yield 'unterminated comment in cte' => ['WITH x AS (SELECT 1 /* ) SELECT 2'];
        yield 'nothing after the ctes' => ['WITH x AS (SELECT 1)'];
        yield 'dashes without a space are not a comment' => ['--x SELECT 1'];
    }

    public function aDashCommentAtTheVeryEndIsAComment(): void
    {
        Assert::same(MySqlHint::apply('SELECT 1 --', 1500), 'SELECT /*+ MAX_EXECUTION_TIME(1500) */ 1 --');
        Assert::same(MySqlHint::apply("--\nSELECT 1", 1500), "--\nSELECT /*+ MAX_EXECUTION_TIME(1500) */ 1");
    }

    #[Property(runs: 300)]
    public function theHintFollowsTheMainSelectKeyword(string $prefix, string $keyword, string $body): void
    {
        $sql = $prefix . $keyword . $body;

        Assert::same(MySqlHint::apply($sql, 700), $prefix . $keyword . ' /*+ MAX_EXECUTION_TIME(700) */' . $body);

        Classify::cover(str_contains($prefix, 'WITH') || str_contains($prefix, 'with'), 'behind a cte list', 20);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function theHintFollowsTheMainSelectKeywordGenerators(): array
    {
        return [
            'prefix' => SqlGenerators::mySqlSelectPrefix(),
            'keyword' => Gen::elements(['SELECT', 'select', 'Select', 'sElEcT']),
            'body' => Gen::regex('( [a-z0-9*,=]{1,12}){0,4}'),
        ];
    }

    /**
     * Applying twice with budgets a and b equals applying once with the
     * smaller: the stricter limit always wins and nothing is duplicated.
     *
     * @param positive-int $first
     * @param positive-int $second
     */
    #[Property(runs: 300)]
    public function applyingTwiceKeepsTheStricterLimit(string $sql, int $first, int $second): void
    {
        Assert::same(MySqlHint::apply(MySqlHint::apply($sql, $first), $second), MySqlHint::apply($sql, min($first, $second)));
        Assert::true(substr_count(MySqlHint::apply($sql, $first), 'MAX_EXECUTION_TIME') <= 1);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function applyingTwiceKeepsTheStricterLimitGenerators(): array
    {
        return [
            'sql' => SqlGenerators::mySqlStatement(),
            'first' => Gen::intBetween(1, 100_000),
            'second' => Gen::intBetween(1, 100_000),
        ];
    }

    #[Property(runs: 200)]
    public function dataChangingStatementsAreNeverRewritten(string $verb, string $body): void
    {
        $sql = $verb . $body;

        Assert::same(MySqlHint::apply($sql, 500), $sql);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function dataChangingStatementsAreNeverRewrittenGenerators(): array
    {
        return [
            'verb' => Gen::elements(['INSERT', 'update', 'DELETE', 'REPLACE', 'SET', 'CALL', 'LOAD DATA', 'TRUNCATE', 'WITH x AS (SELECT 1) UPDATE']),
            'body' => Gen::regex(' [a-z0-9 (),=*]{0,30}'),
        ];
    }
}
