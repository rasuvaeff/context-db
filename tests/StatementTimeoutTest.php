<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Db\Tests;

use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextCancelledException;
use Rasuvaeff\Context\ContextExpiredException;
use Rasuvaeff\Context\Db\Dialect;
use Rasuvaeff\Context\Db\Internal\DialectDetector;
use Rasuvaeff\Context\Db\StatementTimeout;
use Rasuvaeff\Context\Db\Tests\Support\Contexts;
use Rasuvaeff\Context\Db\Tests\Support\FakePdo;
use Rasuvaeff\Context\Testing\FakeTimeSource;
use Rasuvaeff\Context\Testing\SteppingTimeSource;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(StatementTimeout::class)]
#[Covers(DialectDetector::class)]
final class StatementTimeoutTest
{
    #[DataProvider('dialectProvider')]
    public function detectsTheDialect(string $driver, string $version, Dialect $expected): void
    {
        Assert::same(StatementTimeout::for(new FakePdo($driver, $version))->dialect(), $expected);
    }

    public static function dialectProvider(): iterable
    {
        yield 'mysql' => ['mysql', '8.0.46', Dialect::MySql];
        yield 'mariadb' => ['mysql', '11.8.9-MariaDB-ubu2404', Dialect::MariaDb];
        yield 'mariadb through an old client' => ['mysql', '5.5.5-10.6.18-MariaDB-log', Dialect::MariaDb];
        yield 'postgresql' => ['pgsql', '16.15', Dialect::PostgreSql];
        yield 'sqlite' => ['sqlite', '3.45.1', Dialect::Unsupported];
        yield 'sqlsrv' => ['sqlsrv', '16.0', Dialect::Unsupported];
    }

    public function aRealSqliteConnectionIsUnsupported(): void
    {
        $timeout = StatementTimeout::for(new \PDO('sqlite::memory:'));

        Assert::same($timeout->dialect(), Dialect::Unsupported);
        Assert::same($timeout->apply('SELECT 1', Contexts::withBudget(500)), 'SELECT 1');
    }

    public function anExplicitDialectWinsOverDetection(): void
    {
        Assert::same(StatementTimeout::for(new FakePdo('mysql', '8.0'), Dialect::MariaDb)->dialect(), Dialect::MariaDb);
    }

    #[DataProvider('applyProvider')]
    public function appliesTheBudgetPerStatement(Dialect $dialect, string $sql, string $expected): void
    {
        $timeout = StatementTimeout::for(new FakePdo('mysql'), $dialect);

        Assert::same($timeout->apply($sql, Contexts::withBudget(1_500)), $expected);
    }

    public static function applyProvider(): iterable
    {
        yield 'mysql select' => [Dialect::MySql, 'SELECT 1', 'SELECT /*+ MAX_EXECUTION_TIME(1500) */ 1'];
        yield 'mysql insert untouched' => [Dialect::MySql, 'INSERT INTO t VALUES (1)', 'INSERT INTO t VALUES (1)'];
        yield 'mariadb' => [Dialect::MariaDb, 'UPDATE t SET a = 1', 'SET STATEMENT max_statement_time=1.5 FOR UPDATE t SET a = 1'];
        yield 'unsupported' => [Dialect::Unsupported, 'SELECT 1', 'SELECT 1'];
    }

    public function withoutADeadlineNothingIsApplied(): void
    {
        $timeout = StatementTimeout::for(new FakePdo('mysql'), Dialect::MySql);

        Assert::same($timeout->apply('SELECT 1', Context::background()), 'SELECT 1');
    }

    public function aBudgetUnderAMillisecondIsStillALimit(): void
    {
        [$context] = Context::background(new FakeTimeSource())->withTimeout(0.000_4);
        $timeout = StatementTimeout::for(new FakePdo('mysql'), Dialect::MySql);

        Assert::same($timeout->apply('SELECT 1', $context), 'SELECT /*+ MAX_EXECUTION_TIME(1) */ 1');
    }

    public function aSessionBudgetUnderAMillisecondIsStillALimit(): void
    {
        [$context] = Context::background(new FakeTimeSource())->withTimeout(0.000_4);
        $pdo = new FakePdo('mysql', current: 0);

        StatementTimeout::for($pdo, Dialect::MySql)->within($context, static fn(): bool => true);

        Assert::same($pdo->statements, [
            'SELECT @@SESSION.max_execution_time',
            'SET SESSION max_execution_time = 1',
            'SET SESSION max_execution_time = 0',
        ]);
    }

    /** #5: 0.0004 s is stricter than the 1 ms a sub-millisecond budget becomes, never loosened to 0.001. */
    public function aSubMillisecondPreviousLimitIsKeptUnderASubMillisecondBudget(): void
    {
        [$context] = Context::background(new FakeTimeSource())->withTimeout(0.000_4);
        $pdo = new FakePdo('mysql', current: '0.0004');

        StatementTimeout::for($pdo, Dialect::MariaDb)->within($context, static fn(): bool => true);

        Assert::same($pdo->statements, ['SELECT @@SESSION.max_statement_time']);
    }

    public function postgresqlHasNoPerStatementSyntax(): void
    {
        Expect::exception(\LogicException::class);
        StatementTimeout::for(new FakePdo('pgsql'))->apply('SELECT 1', Context::background());
    }

    public function aTerminatedContextIsRefused(): void
    {
        [$context, $controller] = Context::background()->withCancel();
        $controller->cancel();

        Expect::exception(ContextCancelledException::class);
        StatementTimeout::for(new FakePdo('mysql'), Dialect::MySql)->apply('SELECT 1', $context);
    }

    /**
     * Every clock read moves 0.6 s: the deadline passes between reading the
     * budget and the check. A budget read after the check would be 0 — no
     * limit at all to every server.
     */
    public function aDeadlinePassingDuringTheCallNeverBecomesUnlimited(): void
    {
        $pdo = new FakePdo('mysql', current: 0);

        foreach ([Dialect::MySql, Dialect::MariaDb] as $dialect) {
            [$context] = Context::background(new SteppingTimeSource(600_000_000))->withTimeout(1.0);

            try {
                StatementTimeout::for($pdo, $dialect)->apply('SELECT 1', $context);

                throw new \LogicException('an expiry was expected');
            } catch (ContextExpiredException) {
            }
        }

        [$context] = Context::background(new SteppingTimeSource(600_000_000))->withTimeout(1.0);
        $called = false;

        try {
            StatementTimeout::for($pdo, Dialect::MySql)->within($context, static function () use (&$called): void {
                $called = true;
            });
        } catch (ContextExpiredException) {
            Assert::false($called);
            Assert::same($pdo->statements, []);

            return;
        }

        throw new \LogicException('an expiry was expected');
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('withinProvider')]
    public function withinSetsAndRestoresTheSessionLimit(FakePdo $pdo, Dialect $dialect, array $expected): void
    {
        $result = StatementTimeout::for($pdo, $dialect)->within(Contexts::withBudget(1_500), static fn(): string => 'done');

        Assert::same($result, 'done');
        Assert::same($pdo->statements, $expected);
    }

    public static function withinProvider(): iterable
    {
        yield 'mysql' => [new FakePdo('mysql', current: 0), Dialect::MySql, [
            'SELECT @@SESSION.max_execution_time',
            'SET SESSION max_execution_time = 1500',
            'SET SESSION max_execution_time = 0',
        ]];
        yield 'mysql keeps a stricter session limit without touching it' => [new FakePdo('mysql', current: 800), Dialect::MySql, [
            'SELECT @@SESSION.max_execution_time',
        ]];
        yield 'mysql replaces a looser session limit' => [new FakePdo('mysql', current: 9_000), Dialect::MySql, [
            'SELECT @@SESSION.max_execution_time',
            'SET SESSION max_execution_time = 1500',
            'SET SESSION max_execution_time = 9000',
        ]];
        yield 'mariadb' => [new FakePdo('mysql', current: '0.000000'), Dialect::MariaDb, [
            'SELECT @@SESSION.max_statement_time',
            'SET SESSION max_statement_time = 1.5',
            'SET SESSION max_statement_time = 0',
        ]];
        yield 'mariadb restores a fractional limit' => [new FakePdo('mysql', current: '30.250000'), Dialect::MariaDb, [
            'SELECT @@SESSION.max_statement_time',
            'SET SESSION max_statement_time = 1.5',
            'SET SESSION max_statement_time = 30.25',
        ]];
        yield 'mariadb keeps a sub-millisecond limit' => [new FakePdo('mysql', current: '0.000400'), Dialect::MariaDb, [
            'SELECT @@SESSION.max_statement_time',
        ]];
        yield 'mariadb keeps one second' => [new FakePdo('mysql', current: '1.000000'), Dialect::MariaDb, [
            'SELECT @@SESSION.max_statement_time',
        ]];
        yield 'mariadb keeps a limit just below the budget' => [new FakePdo('mysql', current: '1.499100'), Dialect::MariaDb, [
            'SELECT @@SESSION.max_statement_time',
        ]];
        yield 'mariadb replaces a limit just above the budget' => [new FakePdo('mysql', current: '1.500100'), Dialect::MariaDb, [
            'SELECT @@SESSION.max_statement_time',
            'SET SESSION max_statement_time = 1.5',
            'SET SESSION max_statement_time = 1.5001',
        ]];
        yield 'mysql replaces a limit one millisecond above the budget' => [new FakePdo('mysql', current: 1_501), Dialect::MySql, [
            'SELECT @@SESSION.max_execution_time',
            'SET SESSION max_execution_time = 1500',
            'SET SESSION max_execution_time = 1501',
        ]];
        yield 'mariadb replaces ten seconds' => [new FakePdo('mysql', current: '10.000000'), Dialect::MariaDb, [
            'SELECT @@SESSION.max_statement_time',
            'SET SESSION max_statement_time = 1.5',
            'SET SESSION max_statement_time = 10',
        ]];
        // pdo_mysql returns native numbers since PHP 8.1: MariaDB's setting
        // arrives as a float (found by the live MariaDB integration run).
        yield 'mariadb native float zero' => [new FakePdo('mysql', current: 0.0), Dialect::MariaDb, [
            'SELECT @@SESSION.max_statement_time',
            'SET SESSION max_statement_time = 1.5',
            'SET SESSION max_statement_time = 0',
        ]];
        yield 'mariadb native float fraction' => [new FakePdo('mysql', current: 30.25), Dialect::MariaDb, [
            'SELECT @@SESSION.max_statement_time',
            'SET SESSION max_statement_time = 1.5',
            'SET SESSION max_statement_time = 30.25',
        ]];
        yield 'mariadb native float that a string cast would print as 1.0E-5' => [new FakePdo('mysql', current: 0.00001), Dialect::MariaDb, [
            'SELECT @@SESSION.max_statement_time',
        ]];
        yield 'mariadb restores a native float exactly' => [new FakePdo('mysql', current: 10.00001), Dialect::MariaDb, [
            'SELECT @@SESSION.max_statement_time',
            'SET SESSION max_statement_time = 1.5',
            'SET SESSION max_statement_time = 10.00001',
        ]];
        yield 'mysql native int' => [new FakePdo('mysql', current: 0), Dialect::MySql, [
            'SELECT @@SESSION.max_execution_time',
            'SET SESSION max_execution_time = 1500',
            'SET SESSION max_execution_time = 0',
        ]];
        yield 'postgresql keeps a stricter limit' => [new FakePdo('pgsql', current: '1000'), Dialect::PostgreSql, [
            "SELECT setting FROM pg_settings WHERE name = 'statement_timeout'",
        ]];
        yield 'postgresql outside a transaction' => [new FakePdo('pgsql', current: '0'), Dialect::PostgreSql, [
            "SELECT setting FROM pg_settings WHERE name = 'statement_timeout'",
            'SET statement_timeout = 1500',
            'SET statement_timeout = 0',
        ]];
        yield 'postgresql inside a transaction' => [new FakePdo('pgsql', current: '60000', transaction: true), Dialect::PostgreSql, [
            "SELECT setting FROM pg_settings WHERE name = 'statement_timeout'",
            'SET LOCAL statement_timeout = 1500',
            'SET LOCAL statement_timeout = 60000',
        ]];
        yield 'unsupported' => [new FakePdo('sqlite'), Dialect::Unsupported, []];
    }

    /**
     * #5: a previous limit no looser than the budget is kept untouched —
     * compared exactly, not after rounding it up to whole milliseconds, which
     * made 0.0004 s equal to a 1 ms budget and loosened it to 0.001 for the
     * scope.
     *
     * @param positive-int $budget
     */
    #[DataProvider('noLooserProvider')]
    public function withinKeepsAPreviousLimitNoLooserThanTheBudget(FakePdo $pdo, Dialect $dialect, int $budget, string $read): void
    {
        $result = StatementTimeout::for($pdo, $dialect)->within(Contexts::withBudget($budget), static fn(): string => 'done');

        Assert::same($result, 'done');
        Assert::same($pdo->statements, [$read]);
    }

    public static function noLooserProvider(): iterable
    {
        $mysql = 'SELECT @@SESSION.max_execution_time';
        $mariadb = 'SELECT @@SESSION.max_statement_time';
        $postgresql = "SELECT setting FROM pg_settings WHERE name = 'statement_timeout'";

        yield 'mariadb sub-millisecond under a 1 ms budget' => [new FakePdo('mysql', current: '0.000400'), Dialect::MariaDb, 1, $mariadb];
        yield 'mariadb native float sub-millisecond under a 1 ms budget' => [new FakePdo('mysql', current: 0.0004), Dialect::MariaDb, 1, $mariadb];
        yield 'mariadb equal to a 1 ms budget' => [new FakePdo('mysql', current: '0.001000'), Dialect::MariaDb, 1, $mariadb];
        yield 'mariadb a fraction of a millisecond under the budget' => [new FakePdo('mysql', current: '1.499500'), Dialect::MariaDb, 1_500, $mariadb];
        yield 'mariadb equal to the budget' => [new FakePdo('mysql', current: '1.500000'), Dialect::MariaDb, 1_500, $mariadb];
        yield 'mysql equal to the budget' => [new FakePdo('mysql', current: 1_500), Dialect::MySql, 1_500, $mysql];
        yield 'postgresql equal to the budget' => [new FakePdo('pgsql', current: '1500'), Dialect::PostgreSql, 1_500, $postgresql];
    }

    #[DataProvider('leftOpenProvider')]
    public function postgresqlRefusesATransactionLeftOpenByTheCallback(string $current): void
    {
        $pdo = new FakePdo('pgsql', current: $current);

        try {
            StatementTimeout::for($pdo, Dialect::PostgreSql)
                ->within(Contexts::withBudget(1_500), static fn(): bool => $pdo->beginTransaction());
        } catch (\LogicException $error) {
            Assert::string($error->getMessage())->contains('left a transaction open');
            Assert::same($pdo->statements[\count($pdo->statements) - 1], $current === '0' ? 'SET statement_timeout = 0' : 'BEGIN');

            return;
        }

        throw new \LogicException('a refusal was expected');
    }

    public static function leftOpenProvider(): iterable
    {
        yield 'after the restore' => ['0'];
        yield 'with a stricter limit kept' => ['1000'];
    }

    public function postgresqlRefusesATransactionLeftOpenWithoutADeadlineToo(): void
    {
        $pdo = new FakePdo('pgsql', current: '0');

        try {
            StatementTimeout::for($pdo, Dialect::PostgreSql)
                ->within(Context::background(), static fn(): bool => $pdo->beginTransaction());
        } catch (\LogicException) {
            Assert::same($pdo->statements, ['BEGIN']);

            return;
        }

        throw new \LogicException('a refusal was expected');
    }

    public function withoutADeadlineOtherDialectsIgnoreATransactionLeftOpen(): void
    {
        $pdo = new FakePdo('mysql', current: 0);

        Assert::true(StatementTimeout::for($pdo, Dialect::MySql)->within(Context::background(), static fn(): bool => $pdo->beginTransaction()));
    }

    public function postgresqlAcceptsATransactionTheCallbackEnds(): void
    {
        $pdo = new FakePdo('pgsql', current: '0');

        $result = StatementTimeout::for($pdo, Dialect::PostgreSql)->within(Contexts::withBudget(1_500), static function () use ($pdo): int {
            $pdo->beginTransaction();
            $pdo->commit();

            return 7;
        });

        Assert::same($result, 7);
    }

    public function postgresqlAcceptsATransactionEndedByTheCallbackItWasCalledIn(): void
    {
        $pdo = new FakePdo('pgsql', current: '0', transaction: true);

        $result = StatementTimeout::for($pdo, Dialect::PostgreSql)->within(Contexts::withBudget(1_500), static function () use ($pdo): int {
            $pdo->commit();

            return 7;
        });

        Assert::same($result, 7);
    }

    /**
     * #6: a callback that throws with a transaction it opened still open is
     * refused like one that returns: the restore ran inside that transaction,
     * and the caller's rollback would leave the budget on the session. The
     * callback's error stays reachable as `previous` (translate() still finds
     * a server timeout in it), and the foreign transaction is not rolled back.
     */
    #[DataProvider('throwsLeftOpenProvider')]
    public function postgresqlRefusesACallbackThatThrowsWithATransactionLeftOpen(string $current, bool $deadline): void
    {
        $pdo = new FakePdo('pgsql', current: $current);
        $error = new \PDOException('canceling statement due to statement timeout');
        $error->errorInfo = ['57014', 7, 'ERROR:  canceling statement due to statement timeout'];
        $clock = new FakeTimeSource();
        $context = $deadline ? Context::background($clock)->withTimeout(1.5)[0] : Context::background($clock);
        $timeout = StatementTimeout::for($pdo, Dialect::PostgreSql);

        try {
            $timeout->within($context, static function () use ($pdo, $error): never {
                $pdo->beginTransaction();

                throw $error;
            });
        } catch (\LogicException $refusal) {
            Assert::same($refusal->getPrevious(), $error);
            Assert::string($refusal->getMessage())->contains('discard the connection');
            Assert::true($pdo->inTransaction());
            Assert::false(\in_array('ROLLBACK', $pdo->statements, strict: true));

            $clock->advanceMs(1_500);
            Assert::same($timeout->translate($refusal, $context)::class, $deadline ? ContextExpiredException::class : \LogicException::class);

            return;
        }

        throw new \LogicException('a refusal was expected');
    }

    public static function throwsLeftOpenProvider(): iterable
    {
        yield 'after the restore' => ['0', true];
        yield 'with a stricter limit kept' => ['1000', true];
        yield 'without a deadline' => ['0', false];
    }

    /**
     * @param \Closure(FakePdo): void $body
     */
    #[DataProvider('throwsKeptProvider')]
    public function aCallbackErrorIsKeptWhenNoTransactionIsLeftOpenByIt(FakePdo $pdo, Dialect $dialect, \Closure $body): void
    {
        $error = new \RuntimeException('callback failed');

        try {
            StatementTimeout::for($pdo, $dialect)->within(Contexts::withBudget(1_500), static function () use ($pdo, $body, $error): never {
                $body($pdo);

                throw $error;
            });
        } catch (\RuntimeException $caught) {
            Assert::same($caught, $error);

            return;
        }

        throw new \LogicException('the callback error was expected');
    }

    public static function throwsKeptProvider(): iterable
    {
        $nothing = static function (FakePdo $pdo): void {};
        $begin = static function (FakePdo $pdo): void {
            $pdo->beginTransaction();
        };
        $beginAndCommit = static function (FakePdo $pdo): void {
            $pdo->beginTransaction();
            $pdo->commit();
        };

        yield 'postgresql, no transaction' => [new FakePdo('pgsql', current: '0'), Dialect::PostgreSql, $nothing];
        yield 'postgresql, a transaction the callback ended' => [new FakePdo('pgsql', current: '0'), Dialect::PostgreSql, $beginAndCommit];
        yield 'postgresql, entered inside a transaction' => [new FakePdo('pgsql', current: '0', transaction: true), Dialect::PostgreSql, $nothing];
        yield 'mysql, a transaction left open' => [new FakePdo('mysql', current: 0), Dialect::MySql, $begin];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('transactionProvider')]
    public function transactionCommitsAroundTheLimit(FakePdo $pdo, Dialect $dialect, array $expected): void
    {
        $result = StatementTimeout::for($pdo, $dialect)->transaction(Contexts::withBudget(1_500), static fn(): string => 'done');

        Assert::same($result, 'done');
        Assert::same($pdo->statements, $expected);
        Assert::false($pdo->inTransaction());
    }

    public static function transactionProvider(): iterable
    {
        yield 'postgresql' => [new FakePdo('pgsql', current: '0'), Dialect::PostgreSql, [
            'BEGIN',
            "SELECT setting FROM pg_settings WHERE name = 'statement_timeout'",
            'SET LOCAL statement_timeout = 1500',
            'SET LOCAL statement_timeout = 0',
            'COMMIT',
        ]];
        yield 'mysql' => [new FakePdo('mysql', current: 0), Dialect::MySql, [
            'BEGIN',
            'SELECT @@SESSION.max_execution_time',
            'SET SESSION max_execution_time = 1500',
            'SET SESSION max_execution_time = 0',
            'COMMIT',
        ]];
        yield 'unsupported' => [new FakePdo('sqlite'), Dialect::Unsupported, ['BEGIN', 'COMMIT']];
    }

    #[DataProvider('rollBackProvider')]
    public function transactionRollsBackAndRethrows(bool $failRollBack): void
    {
        $pdo = new FakePdo('pgsql', current: '0', failRollBack: $failRollBack);
        $error = new \RuntimeException('query failed');

        try {
            StatementTimeout::for($pdo, Dialect::PostgreSql)->transaction(Contexts::withBudget(1_500), static function () use ($error): never {
                throw $error;
            });
        } catch (\RuntimeException $caught) {
            Assert::same($caught, $error);
            Assert::same($pdo->statements[\count($pdo->statements) - 1], 'ROLLBACK');
            Assert::false($pdo->inTransaction());

            return;
        }

        throw new \LogicException('the callback error was expected');
    }

    public static function rollBackProvider(): iterable
    {
        yield 'rolled back' => [false];
        yield 'rollback failed too' => [true];
    }

    public function transactionRollsBackForATerminatedContext(): void
    {
        $pdo = new FakePdo('pgsql', current: '0');
        [$context, $controller] = Context::background()->withCancel();
        $controller->cancel();

        try {
            StatementTimeout::for($pdo, Dialect::PostgreSql)->transaction($context, static fn(): bool => true);
        } catch (ContextCancelledException) {
            Assert::same($pdo->statements, ['BEGIN', 'ROLLBACK']);

            return;
        }

        throw new \LogicException('a cancellation was expected');
    }

    public function transactionRefusesAnOpenTransaction(): void
    {
        $pdo = new FakePdo('pgsql', current: '0', transaction: true);

        try {
            StatementTimeout::for($pdo, Dialect::PostgreSql)->transaction(Contexts::withBudget(1_500), static fn(): bool => true);
        } catch (\LogicException $error) {
            Assert::string($error->getMessage())->contains('already open');
            Assert::same($pdo->statements, []);

            return;
        }

        throw new \LogicException('a refusal was expected');
    }

    public function withinWithoutADeadlineOnlyRunsTheCallback(): void
    {
        $pdo = new FakePdo('mysql');

        Assert::same(StatementTimeout::for($pdo, Dialect::MySql)->within(Context::background(), static fn(): int => 7), 7);
        Assert::same($pdo->statements, []);
    }

    public function aStricterLimitStillRunsTheCallbackAndPassesItsErrorThrough(): void
    {
        $pdo = new FakePdo('mysql', current: 800);
        $timeout = StatementTimeout::for($pdo, Dialect::MySql);
        $error = new \RuntimeException('query failed');

        Assert::same($timeout->within(Contexts::withBudget(1_500), static fn(): int => 7), 7);

        try {
            $timeout->within(Contexts::withBudget(1_500), static function () use ($error): never {
                throw $error;
            });
        } catch (\RuntimeException $caught) {
            Assert::same($caught, $error);
            Assert::same($pdo->statements, ['SELECT @@SESSION.max_execution_time', 'SELECT @@SESSION.max_execution_time']);

            return;
        }

        throw new \LogicException('the callback error was expected');
    }

    /**
     * @param list<string> $statements
     */
    #[DataProvider('runProvider')]
    public function runPicksThePerStatementLimitOrTheSessionLimit(FakePdo $pdo, Dialect $dialect, string $sent, array $statements): void
    {
        $result = StatementTimeout::for($pdo, $dialect)->run(
            'SELECT * FROM t',
            Contexts::withBudget(1_500),
            static function (string $sql) use ($pdo): string {
                $pdo->statements[] = 'run: ' . $sql;

                return 'rows';
            },
        );

        Assert::same($result, 'rows');
        Assert::same($pdo->statements, [...\array_slice($statements, 0, 2), 'run: ' . $sent, ...\array_slice($statements, 2)]);
    }

    public static function runProvider(): iterable
    {
        yield 'mysql hint' => [new FakePdo('mysql'), Dialect::MySql, 'SELECT /*+ MAX_EXECUTION_TIME(1500) */ * FROM t', []];
        yield 'mariadb prefix' => [new FakePdo('mysql'), Dialect::MariaDb, 'SET STATEMENT max_statement_time=1.5 FOR SELECT * FROM t', []];
        yield 'postgresql session limit' => [new FakePdo('pgsql', current: '0'), Dialect::PostgreSql, 'SELECT * FROM t', [
            "SELECT setting FROM pg_settings WHERE name = 'statement_timeout'",
            'SET statement_timeout = 1500',
            'SET statement_timeout = 0',
        ]];
        yield 'unsupported unchanged' => [new FakePdo('sqlite'), Dialect::Unsupported, 'SELECT * FROM t', []];
    }

    public function runWithoutADeadlineSendsTheSqlAsIs(): void
    {
        $pdo = new FakePdo('pgsql', current: '0');
        $sent = null;

        StatementTimeout::for($pdo, Dialect::PostgreSql)->run('SELECT 1', Context::background(), static function (string $sql) use (&$sent): void {
            $sent = $sql;
        });

        Assert::same($sent, 'SELECT 1');
        Assert::same($pdo->statements, []);
    }

    public function runRefusesATerminatedContext(): void
    {
        [$context, $controller] = Context::background()->withCancel();
        $controller->cancel();
        $ran = false;

        try {
            StatementTimeout::for(new FakePdo('mysql'), Dialect::MySql)->run('SELECT 1', $context, static function () use (&$ran): void {
                $ran = true;
            });
        } catch (ContextCancelledException) {
            Assert::false($ran);

            return;
        }

        throw new \LogicException('a cancellation was expected');
    }

    public function withinRestoresWhenTheCallbackThrows(): void
    {
        $pdo = new FakePdo('mysql', current: 0);
        $error = new \RuntimeException('query failed');

        try {
            StatementTimeout::for($pdo, Dialect::MySql)->within(Contexts::withBudget(500), static function () use ($error): never {
                throw $error;
            });
        } catch (\RuntimeException $caught) {
            Assert::same($caught, $error);
            Assert::same($pdo->statements[2] ?? null, 'SET SESSION max_execution_time = 0');

            return;
        }

        throw new \LogicException('the callback error was expected');
    }

    public function aFailingRestoreNeverHidesTheCallbackError(): void
    {
        $pdo = new FakePdo('pgsql', current: '0', transaction: true, onExec: static function (string $sql): void {
            if (str_contains($sql, '= 0')) {
                throw new \PDOException('current transaction is aborted');
            }
        });
        $error = new \PDOException('canceling statement due to statement timeout');

        try {
            StatementTimeout::for($pdo, Dialect::PostgreSql)->within(Contexts::withBudget(500), static function () use ($error): never {
                throw $error;
            });
        } catch (\PDOException $caught) {
            Assert::same($caught, $error);

            return;
        }

        throw new \LogicException('the callback error was expected');
    }

    public function aFailingRestoreAfterSuccessIsReported(): void
    {
        $pdo = new FakePdo('mysql', current: 0, onExec: static function (string $sql): void {
            if (str_ends_with($sql, '= 0')) {
                throw new \PDOException('connection lost');
            }
        });

        Expect::exception(\PDOException::class);
        StatementTimeout::for($pdo, Dialect::MySql)->within(Contexts::withBudget(500), static fn(): bool => true);
    }

    #[DataProvider('unreadableProvider')]
    public function anUnreadableSessionLimitIsAnError(string|int|float|null $current): void
    {
        Expect::exception(\RuntimeException::class);
        StatementTimeout::for(new FakePdo('mysql', current: $current), Dialect::MySql)
            ->within(Contexts::withBudget(500), static fn(): bool => true);
    }

    public static function unreadableProvider(): iterable
    {
        yield 'query failed' => [null];
        yield 'not a number' => ['abc'];
        yield 'negative' => [-1];
    }

    #[DataProvider('translateProvider')]
    public function translatesOnlyTheBudgetRunningOut(Dialect $dialect, array $errorInfo, string $context, string $expected): void
    {
        $error = new \PDOException('server error');
        $error->errorInfo = $errorInfo;
        $clock = new FakeTimeSource();
        $subject = match ($context) {
            'no deadline' => Context::background($clock),
            'far from the deadline' => Context::background($clock)->withTimeout(10.0)[0],
            default => Context::background($clock)->withTimeout(1.0)[0],
        };
        $clock->advanceMs(match ($context) {
            'expired' => 1_500,
            'within tolerance' => 995,
            'sub-millisecond left' => 999.6,
            'just outside the tolerance' => 994,
            default => 0,
        });

        $result = StatementTimeout::for(new FakePdo('mysql'), $dialect)->translate($error, $subject);

        Assert::same($result::class, $expected);
        if ($result instanceof ContextExpiredException) {
            Assert::same($result->getPrevious(), $error);
            Assert::same($result->deadlineNs(), $subject->deadlineNs());
        } else {
            Assert::same($result, $error);
        }
    }

    public static function translateProvider(): iterable
    {
        $mysql = ['HY000', 3024, 'Query execution was interrupted, maximum statement execution time exceeded'];
        $mariadb = ['70100', 1969, 'Query execution was interrupted (max_statement_time exceeded)'];
        $postgresql = ['57014', 7, 'ERROR:  canceling statement due to statement timeout'];
        $other = ['23000', 1062, 'Duplicate entry'];
        $expired = ContextExpiredException::class;
        $pdo = \PDOException::class;

        yield 'mysql timeout, expired' => [Dialect::MySql, $mysql, 'expired', $expired];
        yield 'mysql timeout, 5 ms left (within tolerance)' => [Dialect::MySql, $mysql, 'within tolerance', $expired];
        yield 'mysql timeout, 0.4 ms left (rounding)' => [Dialect::MySql, $mysql, 'sub-millisecond left', $expired];
        yield 'mysql timeout, 6 ms left (a stricter limit set elsewhere)' => [Dialect::MySql, $mysql, 'just outside the tolerance', $pdo];
        yield 'mysql timeout, far from the deadline' => [Dialect::MySql, $mysql, 'far from the deadline', $pdo];
        yield 'mysql timeout, no deadline' => [Dialect::MySql, $mysql, 'no deadline', $pdo];
        yield 'mysql other error' => [Dialect::MySql, $other, 'expired', $pdo];
        yield 'mariadb code on mysql' => [Dialect::MySql, $mariadb, 'expired', $pdo];
        yield 'mariadb timeout' => [Dialect::MariaDb, $mariadb, 'expired', $expired];
        yield 'mysql code on mariadb' => [Dialect::MariaDb, $mysql, 'expired', $pdo];
        yield 'postgresql timeout' => [Dialect::PostgreSql, $postgresql, 'expired', $expired];
        yield 'postgresql other error' => [Dialect::PostgreSql, $other, 'expired', $pdo];
        yield 'unsupported' => [Dialect::Unsupported, $mysql, 'expired', $pdo];
    }

    public function aTimeoutWrappedByAnotherExceptionIsFoundInTheChain(): void
    {
        $pdo = new \PDOException('server error');
        $pdo->errorInfo = ['HY000', 3024, 'Query execution was interrupted'];
        $wrapper = new \RuntimeException('SQLSTATE[HY000]: ...', previous: new \LogicException('middle', previous: $pdo));
        $clock = new FakeTimeSource();
        [$context] = Context::background($clock)->withTimeout(1.0);
        $clock->advanceMs(1_500);

        $result = StatementTimeout::for(new FakePdo('mysql'), Dialect::MySql)->translate($wrapper, $context);

        Assert::instanceOf($result, ContextExpiredException::class);
        Assert::same($result->getPrevious(), $wrapper);
    }

    public function anErrorWithoutAPdoExceptionInTheChainIsReturnedAsIs(): void
    {
        $error = new \RuntimeException('not a database error', previous: new \LogicException('cause'));
        [$context] = Context::background(new FakeTimeSource())->withTimeout(0.0);

        Assert::same(StatementTimeout::for(new FakePdo('mysql'), Dialect::MySql)->translate($error, $context), $error);
    }

    public function anErrorWithoutDriverInfoIsReturnedAsIs(): void
    {
        $error = new \PDOException('no info');
        [$context] = Context::background(new FakeTimeSource())->withTimeout(0.0);

        Assert::same(StatementTimeout::for(new FakePdo('mysql'), Dialect::MySql)->translate($error, $context), $error);
    }
}
