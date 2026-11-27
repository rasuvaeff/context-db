<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Db\Tests\Integration;

use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextExpiredException;
use Rasuvaeff\Context\Db\StatementTimeout;
use Rasuvaeff\Context\Testing\FakeTimeSource;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * Proves on live servers that the budget becomes a limit the server enforces
 * itself. Runs when `CONTEXT_DB_TEST` names one of mysql, mariadb, pgsql;
 * CI supplies all three. Note that an interrupted MySQL `SLEEP()` returns 1
 * instead of failing, so the slow queries here do real work.
 */
#[Test]
#[CoversNothing]
final class ServerTimeoutIntegrationTest
{
    private const int BUDGET_MS = 300;

    /** Upper bound for a query the server must stop at BUDGET_MS. */
    private const int STOPPED_WITHIN_NS = 3_000_000_000;

    public function theDialectIsDetected(): void
    {
        $server = $this->server();
        if ($server === null) {
            Assert::true(actual: true);

            return;
        }

        Assert::same(StatementTimeout::for($this->connect($server))->dialect()->value, $server);
    }

    public function aPerStatementLimitStopsTheQuery(): void
    {
        $server = $this->server();
        if ($server === null || $server === 'pgsql') {
            Assert::true(actual: true);

            return;
        }

        $pdo = $this->connect($server);
        $timeout = StatementTimeout::for($pdo);

        $this->assertStopped($timeout, static function (Context $context) use ($pdo, $timeout): void {
            $pdo->query($timeout->apply('SELECT COUNT(*) FROM ' . self::crossJoin(), $context));
        });
        $this->assertStopped($timeout, static function (Context $context) use ($pdo, $timeout): void {
            $pdo->query($timeout->apply('WITH n AS (SELECT 1 AS one) SELECT COUNT(*) FROM n, ' . self::crossJoin(), $context));
        });
    }

    public function aSessionLimitStopsTheQueryAndIsRestored(): void
    {
        $server = $this->server();
        if ($server === null) {
            Assert::true(actual: true);

            return;
        }

        $pdo = $this->connect($server);
        $timeout = StatementTimeout::for($pdo);
        $before = $this->sessionLimit($pdo, $server);

        $this->assertStopped($timeout, static function (Context $context) use ($pdo, $timeout, $server): void {
            $timeout->within($context, static function () use ($pdo, $server): void {
                $pdo->query($server === 'pgsql' ? 'SELECT pg_sleep(10)' : 'SELECT COUNT(*) FROM ' . self::crossJoin());
            });
        });

        Assert::same($this->sessionLimit($pdo, $server), $before);
    }

    public function postgresqlUsesSetLocalInsideATransaction(): void
    {
        if ($this->server() !== 'pgsql') {
            Assert::true(actual: true);

            return;
        }

        $pdo = $this->connect('pgsql');
        $timeout = StatementTimeout::for($pdo);
        $pdo->beginTransaction();

        try {
            $this->assertStopped($timeout, static function (Context $context) use ($pdo, $timeout): void {
                $timeout->within($context, static function () use ($pdo): void {
                    $pdo->query('SELECT pg_sleep(10)');
                });
            });
        } finally {
            // the timeout aborted the transaction: the caller rolls it back
            $pdo->rollBack();
        }

        Assert::same($this->sessionLimit($pdo, 'pgsql'), '10000');
    }

    /**
     * The safe forms on PostgreSQL: the callback opens and closes its own
     * transaction, so the restore runs outside it and nothing undoes it.
     */
    #[DataProvider('ownTransactionEndingProvider')]
    public function postgresqlRestoresAfterTheCallbackEndsItsOwnTransaction(bool $commit): void
    {
        if ($this->server() !== 'pgsql') {
            Assert::true(actual: true);

            return;
        }

        $pdo = $this->connect('pgsql');
        [$context] = Context::background()->withTimeout(5.0);

        StatementTimeout::for($pdo)->within($context, function () use ($pdo, $commit): void {
            $pdo->beginTransaction();
            Assert::true($this->isBudgetLimit($this->sessionLimit($pdo, 'pgsql')));
            $commit ? $pdo->commit() : $pdo->rollBack();
        });

        Assert::same($this->sessionLimit($pdo, 'pgsql'), '10000');
    }

    public static function ownTransactionEndingProvider(): iterable
    {
        yield 'commit' => [true];
        yield 'rollback' => [false];
    }

    /**
     * #6: PostgreSQL has no non-transactional SET, so a restore that runs
     * inside a transaction the callback left open would be undone by the
     * caller's later rollback, and the session would keep the budget.
     * within() refuses that pattern instead of leaking silently.
     */
    public function postgresqlRefusesATransactionLeftOpenAcrossWithin(): void
    {
        if ($this->server() !== 'pgsql') {
            Assert::true(actual: true);

            return;
        }

        $pdo = $this->connect('pgsql');
        [$context] = Context::background()->withTimeout(5.0);

        try {
            StatementTimeout::for($pdo)->within($context, static function () use ($pdo): void {
                $pdo->beginTransaction();
            });
        } catch (\LogicException $error) {
            Assert::string($error->getMessage())->contains('transaction');

            return;
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        throw new \LogicException('within() was expected to refuse a transaction left open');
    }

    /**
     * #6: a callback that throws with a transaction it opened still open is
     * refused, its error kept as `previous`. The leak it reports is real:
     * after the caller's rollback the session still carries the budget — the
     * caller has to reset the setting or discard the connection.
     */
    public function postgresqlRefusesACallbackThatThrowsWithATransactionLeftOpen(): void
    {
        if ($this->server() !== 'pgsql') {
            Assert::true(actual: true);

            return;
        }

        $pdo = $this->connect('pgsql');
        [$context] = Context::background()->withTimeout(5.0);
        $error = new \RuntimeException('callback failed');

        try {
            StatementTimeout::for($pdo)->within($context, static function () use ($pdo, $error): never {
                $pdo->beginTransaction();

                throw $error;
            });
        } catch (\LogicException $refusal) {
            Assert::same($refusal->getPrevious(), $error);
            Assert::true($pdo->inTransaction());

            $pdo->rollBack();
            $show = $pdo->query('SHOW statement_timeout');
            Assert::string($show === false ? '' : (string) $show->fetchColumn())->matchesRegex('/\A\d+ms\z/');
            Assert::true($this->isBudgetLimit($this->sessionLimit($pdo, 'pgsql')));
            $pdo->exec('SET statement_timeout = 10000');

            return;
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        throw new \LogicException('within() was expected to refuse a transaction left open by a throwing callback');
    }

    /**
     * #5: a MariaDB limit a fraction of a millisecond under the budget is
     * stricter and stays in force inside the scope; rounded up to whole
     * milliseconds it looked equal and was loosened to the budget.
     */
    public function mariaDbKeepsAFractionalLimitStricterThanTheBudget(): void
    {
        if ($this->server() !== 'mariadb') {
            Assert::true(actual: true);

            return;
        }

        $pdo = $this->connect('mariadb');
        $pdo->exec('SET SESSION max_statement_time = 1.4995');
        [$context] = Context::background(new FakeTimeSource())->withTimeout(1.5);

        $inside = StatementTimeout::for($pdo)->within($context, fn(): string => $this->sessionLimit($pdo, 'mariadb'));

        Assert::same($inside, '1.4995');
        Assert::same($this->sessionLimit($pdo, 'mariadb'), '1.4995');
    }

    /**
     * transaction() owns the transaction: whether it commits or a server
     * timeout makes it roll back, the session keeps the limit it had before.
     */
    public function transactionLeavesNoLimitBehind(): void
    {
        $server = $this->server();
        if ($server === null) {
            Assert::true(actual: true);

            return;
        }

        $pdo = $this->connect($server);
        $timeout = StatementTimeout::for($pdo);
        $before = $this->sessionLimit($pdo, $server);
        [$context] = Context::background()->withTimeout(5.0);

        $result = $timeout->transaction($context, static function () use ($pdo): int {
            Assert::true($pdo->inTransaction());

            return (int) $pdo->query('SELECT 1')->fetchColumn();
        });

        Assert::same($result, 1);
        Assert::false($pdo->inTransaction());
        Assert::same($this->sessionLimit($pdo, $server), $before);

        $this->assertStopped($timeout, static function (Context $context) use ($pdo, $timeout, $server): void {
            $timeout->transaction($context, static function () use ($pdo, $server): void {
                $pdo->query($server === 'pgsql' ? 'SELECT pg_sleep(10)' : 'SELECT COUNT(*) FROM ' . self::crossJoin());
            });
        });

        Assert::false($pdo->inTransaction());
        Assert::same($this->sessionLimit($pdo, $server), $before);
    }

    /**
     * MySQL and MariaDB session variables are not transactional: a
     * transaction the callback leaves open and the caller rolls back later
     * does not undo the restore (the #6 leak is PostgreSQL-only).
     */
    public function mysqlAndMariaDbKeepTheRestoreThroughALaterRollback(): void
    {
        $server = $this->server();
        if ($server === null || $server === 'pgsql') {
            Assert::true(actual: true);

            return;
        }

        $pdo = $this->connect($server);
        $before = $this->sessionLimit($pdo, $server);
        [$context] = Context::background()->withTimeout(5.0);

        StatementTimeout::for($pdo)->within($context, static function () use ($pdo): void {
            $pdo->beginTransaction();
        });
        $pdo->rollBack();

        Assert::same($this->sessionLimit($pdo, $server), $before);
    }

    /** The 5 s budget as PostgreSQL stores it: whole ms measured at entry, under the 10 s cap. */
    private function isBudgetLimit(string $setting): bool
    {
        return (int) $setting > 4_000 && (int) $setting <= 5_000;
    }

    public function mariaDbLimitsDataChangingStatementsToo(): void
    {
        if ($this->server() !== 'mariadb') {
            Assert::true(actual: true);

            return;
        }

        $pdo = $this->connect('mariadb');
        $pdo->exec('DROP TABLE IF EXISTS context_db_counts');
        $pdo->exec('CREATE TABLE context_db_counts (n BIGINT)');
        $timeout = StatementTimeout::for($pdo);

        $this->assertStopped($timeout, static function (Context $context) use ($pdo, $timeout): void {
            $pdo->exec($timeout->apply('INSERT INTO context_db_counts SELECT COUNT(*) FROM ' . self::crossJoin(), $context));
        });

        $pdo->exec('DROP TABLE context_db_counts');
    }

    /**
     * @param \Closure(Context): void $run
     */
    private function assertStopped(StatementTimeout $timeout, \Closure $run): void
    {
        [$context] = Context::background()->withTimeout(self::BUDGET_MS / 1000.0);
        $startedNs = hrtime(as_number: true);

        try {
            $run($context);
        } catch (\PDOException $error) {
            $elapsedNs = hrtime(as_number: true) - $startedNs;
            $translated = $timeout->translate($error, $context);

            Assert::true($elapsedNs < self::STOPPED_WITHIN_NS);
            Assert::instanceOf($translated, ContextExpiredException::class);

            return;
        }

        throw new \LogicException('the server was expected to stop the query');
    }

    private static function crossJoin(): string
    {
        return 'information_schema.columns a, information_schema.columns b, information_schema.columns c';
    }

    private function sessionLimit(\PDO $pdo, string $server): string
    {
        $sql = match ($server) {
            'mysql' => 'SELECT @@SESSION.max_execution_time',
            'mariadb' => 'SELECT @@SESSION.max_statement_time',
            default => "SELECT setting FROM pg_settings WHERE name = 'statement_timeout'",
        };
        $statement = $pdo->query($sql);

        return $statement === false ? '' : (string) $statement->fetchColumn();
    }

    /** @return 'mysql'|'mariadb'|'pgsql'|null */
    private function server(): ?string
    {
        $server = getenv('CONTEXT_DB_TEST');

        return match ($server) {
            'mysql', 'mariadb', 'pgsql' => $server,
            default => null,
        };
    }

    /**
     * A session cap above the budget keeps a broken limit from hanging the
     * suite: the queries here would otherwise run for minutes.
     */
    private function connect(string $server): \PDO
    {
        $host = getenv('CONTEXT_DB_HOST') ?: '127.0.0.1';
        $port = getenv('CONTEXT_DB_PORT') ?: match ($server) {
            'mysql' => '3306',
            'mariadb' => '3307',
            default => '5432',
        };
        $name = getenv('CONTEXT_DB_NAME') ?: 'context';
        $password = getenv('CONTEXT_DB_PASSWORD') ?: 'context';

        if ($server === 'pgsql') {
            $pdo = new \PDO("pgsql:host={$host};port={$port};dbname={$name}", 'postgres', $password);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->exec('SET statement_timeout = 10000');

            return $pdo;
        }

        $pdo = new \PDO("mysql:host={$host};port={$port};dbname={$name}", 'root', $password);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec($server === 'mysql' ? 'SET SESSION max_execution_time = 10000' : 'SET SESSION max_statement_time = 10');

        return $pdo;
    }
}
