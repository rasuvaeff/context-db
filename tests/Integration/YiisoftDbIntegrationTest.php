<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Db\Tests\Integration;

use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextExpiredException;
use Rasuvaeff\Context\Db\Dialect;
use Rasuvaeff\Context\Db\StatementTimeout;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Driver\Pdo\PdoConnectionInterface;
use Yiisoft\Db\Exception\Exception as DbException;
use Yiisoft\Db\Mysql\Connection as MysqlConnection;
use Yiisoft\Db\Mysql\Driver as MysqlDriver;
use Yiisoft\Db\Pgsql\Connection as PgsqlConnection;
use Yiisoft\Db\Pgsql\Driver as PgsqlDriver;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

/**
 * Executes the yiisoft/db recipe from the README as written: the PDO behind
 * the connection, `apply()` on the SQL handed to a command, and the
 * driver error unwrapped from yiisoft/db's exception for `translate()`.
 */
#[Test]
#[CoversNothing]
final class YiisoftDbIntegrationTest
{
    public function theRecipeStopsTheQueryOnTheServer(): void
    {
        $server = getenv('CONTEXT_DB_TEST');
        if (!\in_array($server, ['mysql', 'mariadb', 'pgsql'], strict: true)) {
            Assert::true(actual: true);

            return;
        }

        $db = $this->connection($server);
        // a cap above the budget keeps a broken recipe from hanging the suite
        $db->getActivePdo()->exec(match ($server) {
            'mysql' => 'SET SESSION max_execution_time = 10000',
            'mariadb' => 'SET SESSION max_statement_time = 10',
            default => 'SET statement_timeout = 10000',
        });
        [$context] = Context::background()->withTimeout(0.3);
        $startedNs = hrtime(as_number: true);

        try {
            $this->recipe($db, $context, $server === 'pgsql'
                ? 'SELECT pg_sleep(10)'
                : 'SELECT COUNT(*) FROM information_schema.columns a, information_schema.columns b, information_schema.columns c');
        } catch (ContextExpiredException) {
            Assert::true(hrtime(as_number: true) - $startedNs < 3_000_000_000);

            return;
        }

        throw new \LogicException('the server was expected to stop the query');
    }

    public function theRecipeRunsOnSqliteWithoutALimit(): void
    {
        $db = new SqliteConnection(
            driver: new SqliteDriver(dsn: 'sqlite::memory:'),
            schemaCache: new SchemaCache(psrCache: new MemorySimpleCache()),
        );
        [$context] = Context::background()->withTimeout(5.0);

        Assert::same($this->recipe($db, $context, 'SELECT 41 + 1'), '42');
    }

    /** The README recipe, verbatim apart from the return. */
    private function recipe(ConnectionInterface&PdoConnectionInterface $db, Context $context, string $sql): string
    {
        $timeout = StatementTimeout::for($db->getActivePdo());

        try {
            $value = $timeout->dialect() === Dialect::PostgreSql
                ? $timeout->within($context, static fn(): mixed => $db->createCommand($sql)->queryScalar())
                : $db->createCommand($timeout->apply($sql, $context))->queryScalar();
        } catch (DbException $error) {
            $driverError = $error->getPrevious();

            throw $driverError instanceof \PDOException ? $timeout->translate($driverError, $context) : $error;
        }

        return \is_scalar($value) ? (string) $value : '';
    }

    private function connection(string $server): ConnectionInterface&PdoConnectionInterface
    {
        $cache = new SchemaCache(psrCache: new MemorySimpleCache());
        $host = getenv('CONTEXT_DB_HOST') ?: '127.0.0.1';
        $name = getenv('CONTEXT_DB_NAME') ?: 'context';
        $password = getenv('CONTEXT_DB_PASSWORD') ?: 'context';
        $port = getenv('CONTEXT_DB_PORT') ?: match ($server) {
            'mysql' => '3306',
            'mariadb' => '3307',
            default => '5432',
        };

        return $server === 'pgsql'
            ? new PgsqlConnection(
                driver: new PgsqlDriver(dsn: "pgsql:host={$host};port={$port};dbname={$name}", username: 'postgres', password: $password),
                schemaCache: $cache,
            )
            : new MysqlConnection(
                driver: new MysqlDriver(dsn: "mysql:host={$host};port={$port};dbname={$name}", username: 'root', password: $password),
                schemaCache: $cache,
            );
    }
}
