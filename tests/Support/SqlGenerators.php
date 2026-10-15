<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Db\Tests\Support;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;

/**
 * SQL fragments for the rewriting properties.
 */
final class SqlGenerators
{
    /** Whatever may stand before the main SELECT keyword. */
    public static function mySqlSelectPrefix(): ArbitraryInterface
    {
        return Gen::map(
            Gen::tuple(
                Gen::elements(['', '  ', "\n\t", '/* c */ ', "-- c\n", "# c\n", '/* a */ /* b */ ']),
                Gen::frequency([
                    [3, Gen::constant(value: '')],
                    [1, Gen::constant(value: '(')],
                    [2, Gen::elements([
                        'WITH x AS (SELECT 1) ',
                        'with recursive r (v) as (select 1 union all select v + 1 from r where v < 3) ',
                        "WITH a AS (SELECT ')' AS p), `b c` AS (SELECT \"(\" AS q) ",
                        "WITH x AS (SELECT 1 /* ) */ -- )\n) ",
                    ])],
                ]),
            ),
            static fn(array $parts): string => $parts[0] . $parts[1],
        );
    }

    /** Statements MySQL may or may not accept a hint on. */
    public static function mySqlStatement(): ArbitraryInterface
    {
        return Gen::elements([
            'SELECT 1',
            'select * from t where a = 1',
            '(SELECT 1) UNION (SELECT 2)',
            'WITH x AS (SELECT 1) SELECT * FROM x',
            'SELECT /*+ BKA(t) */ * FROM t',
            'SELECT /*+ MAX_EXECUTION_TIME(50) */ 1',
            'SELECT /*+ MAX_EXECUTION_TIME(0) */ 1',
            'SELECT /*+ MAX_EXECUTION_TIME(250000) BKA(t) */ 1',
            'INSERT INTO t VALUES (1)',
            'UPDATE t SET a = 1',
            '/*!50000 SELECT 1 */',
        ]);
    }

    /** Statements for the MariaDB prefix. */
    public static function mariaDbStatement(): ArbitraryInterface
    {
        return Gen::elements([
            'SELECT 1',
            'INSERT INTO t VALUES (1)',
            'UPDATE t SET a = 1',
            '  WITH x AS (SELECT 1) SELECT * FROM x',
            'SET STATEMENT sort_buffer_size=262144 FOR SELECT 1',
            'SET STATEMENT max_statement_time=0.05 FOR SELECT 1',
            'SET STATEMENT max_statement_time=0 FOR SELECT 1',
            'set statement max_statement_time=250, sort_buffer_size=1 for select 1',
            'SET STATEMENT max_statement_time=9, max_statement_time=.002 FOR SELECT 1',
        ]);
    }
}
