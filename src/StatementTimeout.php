<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Db;

use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextExpiredException;
use Rasuvaeff\Context\Db\Internal\Decimal;
use Rasuvaeff\Context\Db\Internal\DialectDetector;
use Rasuvaeff\Context\Db\Internal\MariaDbStatement;
use Rasuvaeff\Context\Db\Internal\MySqlHint;
use Rasuvaeff\Context\Db\Internal\ScopeOwner;

/**
 * Turns the remaining {@see Context} budget into a timeout the database
 * server enforces itself, so a query stops on the server when the budget
 * runs out instead of running on after the caller gave up.
 *
 * @api
 */
final readonly class StatementTimeout
{
    /**
     * A server-side timeout that fires this close to the deadline is the
     * budget running out, even if the client clock has not passed it yet.
     *
     * The server can fire *before* our deadline only through rounding: it
     * gets floor(remainingMs), and remainingMs() reports 1.0 for anything
     * under a millisecond — under 1 ms in total. Every other delay (network,
     * the gap between reading the budget and sending the statement, a late
     * server timer) moves the error past the deadline, where isExpired()
     * already holds. A wider window would mistake a stricter limit set
     * elsewhere (kept by apply()/within()) for our budget.
     */
    private const float EXPIRY_TOLERANCE_MS = 5.0;

    private const int MYSQL_QUERY_TIMEOUT = 3024;

    private const int MARIADB_STATEMENT_TIMEOUT = 1969;

    private const string POSTGRESQL_QUERY_CANCELED = '57014';

    private function __construct(
        private \PDO $pdo,
        private Dialect $dialect,
    ) {}

    /**
     * Detects the dialect from the connection: the driver name, and for
     * `mysql` the server version (MariaDB names itself there). Pass
     * `$dialect` when a proxy hides the real server.
     */
    public static function for(\PDO $pdo, ?Dialect $dialect = null): self
    {
        return new self(pdo: $pdo, dialect: $dialect ?? DialectDetector::detect($pdo));
    }

    public function dialect(): Dialect
    {
        return $this->dialect;
    }

    /**
     * Returns `$sql` carrying the remaining budget as a per-statement limit:
     * MySQL — a `MAX_EXECUTION_TIME` hint on the main read-only SELECT (any
     * other statement is returned unchanged); MariaDB — a
     * `SET STATEMENT max_statement_time=… FOR` prefix. A stricter limit
     * already present is kept. Without a deadline `$sql` is returned as is.
     *
     * @throws \LogicException on PostgreSQL, which has no per-statement
     *         syntax — use {@see within()}
     * @throws \Rasuvaeff\Context\ContextException when the context is terminated
     */
    public function apply(string $sql, Context $context): string
    {
        if ($this->dialect === Dialect::PostgreSql) {
            throw new \LogicException('PostgreSQL has no per-statement timeout syntax; use within()');
        }

        $milliseconds = $context->budgetMs();
        if ($milliseconds === null) {
            return $sql;
        }

        return match ($this->dialect) {
            Dialect::MySql => MySqlHint::apply($sql, $milliseconds),
            Dialect::MariaDb => MariaDbStatement::apply($sql, $milliseconds),
            default => $sql,
        };
    }

    /**
     * Runs `$callback` with the remaining budget as the session's statement
     * timeout and restores the previous value afterwards (a previous limit
     * that is not looser than the budget is kept, and then nothing is set or
     * restored). Each statement inside gets the budget measured at
     * entry: keep checking the context between statements. On PostgreSQL
     * inside a transaction `SET LOCAL` is used, which is what a transaction-
     * pooling pgbouncer requires. On PostgreSQL entered outside a
     * transaction, a callback that returns or throws with a transaction it
     * opened still open is refused (with or without a deadline): the restore
     * would not survive that transaction's rollback. A callback that throws
     * gets its error wrapped as `previous`; the transaction is left to the
     * caller, whose rollback leaves the budget on the session — reset the
     * setting or discard the connection. A connection serves one Fiber at a time:
     * scopes nest within one Fiber, but entering while another Fiber is
     * suspended inside its own scope on the same `\PDO` is refused — the
     * session timeout is one value, and overlapping scopes would restore it
     * out of order.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     *
     * @throws \LogicException on PostgreSQL when the callback, entered outside a
     *         transaction, returns or throws with one open (a thrown error is
     *         the `previous`); when another Fiber has a scope open on this
     *         connection
     * @throws \Rasuvaeff\Context\ContextException when the context is terminated
     */
    public function within(Context $context, callable $callback): mixed
    {
        return ScopeOwner::run($this->pdo, fn(): mixed => $this->limited($context, $callback));
    }

    /**
     * Runs `$callback` in a transaction this method opens: committed when the
     * callback returns, rolled back when it throws (the error is rethrown),
     * with the remaining budget as the statement timeout. On PostgreSQL the
     * limit is a `SET LOCAL`, which ends with the transaction whether it
     * commits or rolls back, so nothing can be left on the session; on MySQL
     * and MariaDB the session limit is set and restored as in
     * {@see within()}. The callback must not commit, roll back or open a
     * transaction itself. This drives the transaction through `\PDO`
     * directly: with a database layer that tracks transactions itself
     * (yiisoft/db), call {@see within()} inside that layer's transaction.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     *
     * @throws \LogicException when a transaction is already open — call {@see within()} inside it;
     *         when another Fiber has a scope open on this connection
     * @throws \Rasuvaeff\Context\ContextException when the context is terminated
     */
    public function transaction(Context $context, callable $callback): mixed
    {
        return ScopeOwner::run($this->pdo, fn(): mixed => $this->transactional($context, $callback));
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function transactional(Context $context, callable $callback): mixed
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('A transaction is already open; call within() inside it instead of transaction()');
        }

        $this->pdo->beginTransaction();

        try {
            $result = $this->within($context, $callback);
        } catch (\Throwable $error) {
            try {
                $this->pdo->rollBack();
            } catch (\Throwable) {
                // The callback's error is the one to report; a connection
                // that cannot roll back has lost the transaction anyway.
            }

            throw $error;
        }

        $this->pdo->commit();

        return $result;
    }

    /**
     * Runs `$sql` through `$execute` under the remaining budget, whatever the
     * dialect: MySQL and MariaDB get {@see apply()}'s per-statement limit,
     * PostgreSQL runs `$execute` {@see within()} a session limit, an
     * unsupported dialect runs `$sql` unchanged. Without a deadline `$sql`
     * runs as is.
     *
     * @template T
     *
     * @param callable(string): T $execute receives the SQL to send
     *
     * @return T
     *
     * @throws \Rasuvaeff\Context\ContextException when the context is terminated
     */
    public function run(string $sql, Context $context, callable $execute): mixed
    {
        if ($this->dialect === Dialect::PostgreSql) {
            return $this->within($context, static fn(): mixed => $execute($sql));
        }

        return $execute($this->apply($sql, $context));
    }

    /**
     * Maps the server's own timeout error to {@see ContextExpiredException}
     * (`$error` as `previous`) when it is this context's budget that ran
     * out; any other error — or a timeout while budget remains, i.e. a limit
     * set elsewhere — is returned unchanged. The server error is looked up
     * in the `previous` chain, so a wrapper (yiisoft/db's `Exception`) can be
     * passed as is.
     */
    public function translate(\Throwable $error, Context $context): \Throwable
    {
        $deadlineNs = $context->deadlineNs();
        if ($deadlineNs === null || !$this->isTimeout($error)) {
            return $error;
        }

        if ($context->isExpired() || ($context->remainingMs() ?? \INF) <= self::EXPIRY_TOLERANCE_MS) {
            return new ContextExpiredException($deadlineNs, $error);
        }

        return $error;
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function limited(Context $context, callable $callback): mixed
    {
        $milliseconds = $context->budgetMs();
        if ($milliseconds === null) {
            return $this->dialect === Dialect::PostgreSql ? $this->refusingLeftOpen($callback) : $callback();
        }

        return match ($this->dialect) {
            Dialect::MySql => $this->scoped(
                read: 'SELECT @@SESSION.max_execution_time',
                set: 'SET SESSION max_execution_time = ',
                budget: (string) $milliseconds,
                callback: $callback,
            ),
            Dialect::MariaDb => $this->scoped(
                read: 'SELECT @@SESSION.max_statement_time',
                set: 'SET SESSION max_statement_time = ',
                budget: MariaDbStatement::seconds($milliseconds),
                callback: $callback,
            ),
            Dialect::PostgreSql => $this->postgreSql($milliseconds, $callback),
            default => $callback(),
        };
    }

    /**
     * PostgreSQL has no non-transactional `SET`: entered outside a
     * transaction, the limit is a session `SET`, and a transaction the
     * callback leaves open would take the restore with it on rollback,
     * keeping the budget on the session (#6).
     *
     * @template T
     *
     * @param positive-int $milliseconds
     * @param callable(): T $callback
     *
     * @return T
     */
    private function postgreSql(int $milliseconds, callable $callback): mixed
    {
        $set = $this->pdo->inTransaction() ? 'SET LOCAL statement_timeout = ' : 'SET statement_timeout = ';

        return $this->refusingLeftOpen(fn(): mixed => $this->scoped(
            read: "SELECT setting FROM pg_settings WHERE name = 'statement_timeout'",
            set: $set,
            budget: (string) $milliseconds,
            callback: $callback,
        ));
    }

    /**
     * Refuses a transaction `$callback` opened and left open, after it has
     * run (and the restore with it). Also without a deadline, when nothing
     * was set: the misuse shows up in tests that run without one. A callback
     * that throws with such a transaction open is refused too, its error
     * kept as `previous`: the restore ran inside that transaction (or failed
     * in it), so the caller's rollback leaves the budget on the session. The
     * transaction is not ours and is never rolled back here.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function refusingLeftOpen(callable $callback): mixed
    {
        $inTransaction = $this->pdo->inTransaction();

        try {
            $result = $callback();
        } catch (\Throwable $error) {
            if (!$inTransaction && $this->pdo->inTransaction()) {
                throw new \LogicException(
                    'The within() callback threw with a transaction it opened still open on PostgreSQL: the session statement_timeout cannot be restored past that transaction\'s rollback; reset the setting or discard the connection, and end the transaction inside the callback, call within() inside the transaction, or use transaction()',
                    // not the callback's code: a PDOException's is an SQLSTATE string
                    code: 0,
                    previous: $error,
                );
            }

            throw $error;
        }

        if (!$inTransaction && $this->pdo->inTransaction()) {
            throw new \LogicException(
                'The within() callback left a transaction open on PostgreSQL: a rollback would undo the restore and keep the budget on the session; end the transaction inside the callback, call within() inside the transaction, or use transaction()',
            );
        }

        return $result;
    }

    /**
     * @template T
     *
     * The previous value is restored exactly as read (normalised, never
     * rounded): rounding a MariaDB limit of 0.0004 s to whole milliseconds
     * would restore 0 — no limit at all. It is kept when it is a limit and
     * no looser than the budget, compared exactly in the setting's own unit:
     * rounded up to whole milliseconds, 0.0004 s looked equal to a 1 ms
     * budget and was replaced by the looser 0.001 for the scope.
     *
     * @param string $set the statement prefix the value is appended to
     * @param string $budget the budget in the setting's own unit, as {@see Decimal::normalize()} writes it
     * @param callable(): T $callback
     *
     * @return T
     */
    private function scoped(
        string $read,
        string $set,
        string $budget,
        callable $callback,
    ): mixed {
        $previous = $this->readSetting($read);
        if ($previous !== '0' && Decimal::compare($previous, $budget) <= 0) {
            // A limit at least as strict already holds: setting the budget
            // would loosen it for the scope, and setting it again and
            // restoring it would be two round trips that change nothing.
            return $callback();
        }

        $restore = $set . $previous;
        $this->pdo->exec($set . $budget);

        try {
            $result = $callback();
        } catch (\Throwable $error) {
            try {
                $this->pdo->exec($restore);
            } catch (\Throwable) {
                // The callback's error is the one to report; a restore that
                // fails here (an aborted PostgreSQL transaction) is reset by
                // the rollback the caller has to do anyway.
            }

            throw $error;
        }

        $this->pdo->exec($restore);

        return $result;
    }

    /** The current setting as a normalised plain decimal ("30.25", "0"). */
    private function readSetting(string $sql): string
    {
        $statement = $this->pdo->query($sql);
        $decimal = $this->decimal($statement === false ? false : $statement->fetchColumn());
        if ($decimal === null) {
            throw new \RuntimeException(\sprintf('Cannot read the current statement timeout with "%s"', $sql));
        }

        return $decimal;
    }

    /**
     * pdo_mysql returns native numbers since PHP 8.1: MariaDB's
     * max_statement_time arrives as a float. It is formatted with the
     * server's own precision (microseconds) — a bare (string) cast would
     * give "1.0E-5" for 0.00001 s, which is not a decimal at all.
     */
    private function decimal(mixed $value): ?string
    {
        if (\is_float($value)) {
            $value = \is_finite($value) ? \sprintf('%.6F', $value) : null;
        }

        return \is_int($value) || \is_string($value) ? Decimal::normalize((string) $value) : null;
    }

    private function isTimeout(\Throwable $error): bool
    {
        $cause = $error;
        while (!$cause instanceof \PDOException) {
            $cause = $cause->getPrevious();
            if (!$cause instanceof \Throwable) {
                return false;
            }
        }

        $info = $cause->errorInfo;
        if (!\is_array($info)) {
            return false;
        }

        return match ($this->dialect) {
            Dialect::MySql => ($info[1] ?? null) === self::MYSQL_QUERY_TIMEOUT,
            Dialect::MariaDb => ($info[1] ?? null) === self::MARIADB_STATEMENT_TIMEOUT,
            Dialect::PostgreSql => ($info[0] ?? null) === self::POSTGRESQL_QUERY_CANCELED,
            Dialect::Unsupported => false,
        };
    }
}
