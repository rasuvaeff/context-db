<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Db\Benchmarks;

use Rasuvaeff\Context\Db\Internal\MariaDbStatement;
use Rasuvaeff\Context\Db\Internal\MySqlHint;
use Testo\Bench;

/**
 * apply() runs on every statement: the MySQL hint scans the SQL to find the
 * main SELECT, the MariaDB prefix does not.
 */
final class StatementTimeoutBench
{
    private const string SIMPLE = 'SELECT id, name FROM users WHERE id = ?';

    private const string CTE = <<<'SQL'
        -- report
        WITH recent AS (SELECT user_id, MAX(created_at) AS at FROM orders WHERE note <> 'SELECT (' GROUP BY user_id)
        SELECT u.id, r.at FROM users u JOIN recent r ON r.user_id = u.id WHERE u.name LIKE 'a%' ORDER BY r.at DESC
        SQL;

    #[Bench(
        callables: [
            'mysql hint, CTE with comments and literals' => [self::class, 'mysqlCte'],
            'mariadb prefix' => [self::class, 'mariadb'],
        ],
        calls: 20_000,
        iterations: 10,
    )]
    public static function mysqlSimple(): string
    {
        return MySqlHint::apply(self::SIMPLE, 300);
    }

    public static function mysqlCte(): string
    {
        return MySqlHint::apply(self::CTE, 300);
    }

    public static function mariadb(): string
    {
        return MariaDbStatement::apply(self::SIMPLE, 300);
    }
}
