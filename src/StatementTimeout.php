<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Db;

use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextExpiredException;
use Rasuvaeff\Context\Db\Internal\Decimal;
use Rasuvaeff\Context\Db\Internal\DialectDetector;
use Rasuvaeff\Context\Db\Internal\MariaDbStatement;
use Rasuvaeff\Context\Db\Internal\MySqlHint;

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
     */
    private const float EXPIRY_TOLERANCE_MS = 50.0;

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

        $milliseconds = $this->budgetMs($context);
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
     * timeout and restores the previous value afterwards (a stricter previous
     * value is kept). Each statement inside gets the budget measured at
     * entry: keep checking the context between statements. On PostgreSQL
     * inside a transaction `SET LOCAL` is used, which is what a transaction-
     * pooling pgbouncer requires.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     *
     * @throws \Rasuvaeff\Context\ContextException when the context is terminated
     */
    public function within(Context $context, callable $callback): mixed
    {
        $milliseconds = $this->budgetMs($context);
        if ($milliseconds === null) {
            return $callback();
        }

        return match ($this->dialect) {
            Dialect::MySql => $this->scoped(
                read: 'SELECT @@SESSION.max_execution_time',
                set: 'SET SESSION max_execution_time = ',
                budget: (string) $milliseconds,
                milliseconds: $milliseconds,
                shift: 0,
                callback: $callback,
            ),
            Dialect::MariaDb => $this->scoped(
                read: 'SELECT @@SESSION.max_statement_time',
                set: 'SET SESSION max_statement_time = ',
                budget: MariaDbStatement::seconds($milliseconds),
                milliseconds: $milliseconds,
                shift: 3,
                callback: $callback,
            ),
            Dialect::PostgreSql => $this->scoped(
                read: "SELECT setting FROM pg_settings WHERE name = 'statement_timeout'",
                set: $this->pdo->inTransaction() ? 'SET LOCAL statement_timeout = ' : 'SET statement_timeout = ',
                budget: (string) $milliseconds,
                milliseconds: $milliseconds,
                shift: 0,
                callback: $callback,
            ),
            default => $callback(),
        };
    }

    /**
     * Maps the server's own timeout error to {@see ContextExpiredException}
     * (the original as `previous`) when it is this context's budget that ran
     * out; any other error — or a timeout while budget remains, i.e. a limit
     * set elsewhere — is returned unchanged.
     */
    public function translate(\PDOException $error, Context $context): \Throwable
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
     * Reads the budget BEFORE the check: once assertActive() passes, it can
     * never be the 0.0 of an expired context — and 0 means "no limit" to
     * every server here.
     *
     * @return positive-int|null
     */
    private function budgetMs(Context $context): ?int
    {
        $remainingMs = $context->remainingMs();
        $context->assertActive();

        return $remainingMs === null ? null : max(1, (int) $remainingMs);
    }

    /**
     * @template T
     *
     * The previous value is restored exactly as read (normalised, never
     * rounded): rounding a MariaDB limit of 0.0004 s to whole milliseconds
     * would restore 0 — no limit at all. It counts as stricter than the
     * budget when its value, rounded UP to milliseconds, is smaller.
     *
     * @param string $set the statement prefix the value is appended to
     * @param string $budget the budget in the setting's own unit
     * @param positive-int $milliseconds
     * @param int<0, max> $shift decimal places from the setting's unit to milliseconds
     * @param callable(): T $callback
     *
     * @return T
     */
    private function scoped(
        string $read,
        string $set,
        string $budget,
        int $milliseconds,
        int $shift,
        callable $callback,
    ): mixed {
        $previous = $this->readSetting($read);
        $previousMs = Decimal::ceilShifted($previous, $shift) ?? 0;
        $restore = $set . $previous;
        $this->pdo->exec($previousMs > 0 && $previousMs < $milliseconds ? $restore : $set . $budget);

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

    private function isTimeout(\PDOException $error): bool
    {
        $info = $error->errorInfo;
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
