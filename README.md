# rasuvaeff/context-db

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/context-db/v)](https://packagist.org/packages/rasuvaeff/context-db)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/context-db/downloads)](https://packagist.org/packages/rasuvaeff/context-db)
[![Build](https://github.com/rasuvaeff/context-db/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/context-db/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/context-db/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/context-db/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/context-db/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/context-db/php)](https://packagist.org/packages/rasuvaeff/context-db)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

Turns the remaining [`rasuvaeff/context`](https://github.com/rasuvaeff/context)
budget into a statement timeout the database server enforces itself — MySQL,
MariaDB and PostgreSQL over PDO. A query stops on the server when the budget
runs out instead of running on after the caller gave up.

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference you can share with the model.
> Projects using the [llm/skills](https://github.com/roxblnfk/skills) Composer plugin also get this package's agent skill synced into `.agents/skills/` automatically on install.

## Requirements

- PHP 8.3+, `ext-pdo`
- `rasuvaeff/context` ^0.3
- `ext-pdo_mysql` for MySQL/MariaDB, `ext-pdo_pgsql` for PostgreSQL

## Installation

```bash
composer require rasuvaeff/context-db
```

## Limitations

PDO has no per-query timeout of its own (only a connect timeout), so this
package uses what each server offers — and each offers something different:

| Server | Per statement (`apply()`) | Per scope (`within()`) | Error when it fires |
|---|---|---|---|
| MySQL 5.7.8+ | `/*+ MAX_EXECUTION_TIME(ms) */` on the main **read-only SELECT** | `SET SESSION max_execution_time` | `HY000` / 3024 |
| MariaDB 10.1+ | `SET STATEMENT max_statement_time=s FOR …` on **any** statement | `SET SESSION max_statement_time` | `70100` / 1969 |
| PostgreSQL | — (no per-statement syntax) | `SET statement_timeout`, `SET LOCAL` in a transaction | `57014` |
| SQLite and others | unchanged | callback only | — |

- **MySQL limits only read-only SELECT.** `INSERT … SELECT`, `UPDATE`,
  `DELETE` and DDL run without a server-side limit, whatever you set: keep
  checking the context between them. `apply()` returns such statements
  unchanged rather than adding a hint MySQL would silently ignore.
- **An interrupted MySQL `SLEEP()` returns 1 instead of failing.** Real work
  fails with error 3024; do not test the limit with `SELECT SLEEP(n)`.
- **PostgreSQL behind a transaction-pooling pgbouncer**: a session `SET`
  leaks to whoever gets the server connection next. Call `within()` inside a
  transaction — it then uses `SET LOCAL`, which ends with the transaction.
- **A PostgreSQL timeout aborts the transaction.** Every further statement
  fails with `25P02` until the caller rolls back; this package never touches
  your transaction.
- `within()` gives each statement the budget measured at entry, not what is
  left after the previous statement: keep calling `assertActive()` between
  statements.

## Usage

```php
use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\Db\StatementTimeout;

[$context, $controller] = Context::background()->withTimeout(2.0);
$timeout = StatementTimeout::for($pdo);              // detects MySQL / MariaDB / PostgreSQL

try {
    // per statement (MySQL, MariaDB)
    $rows = $pdo->query($timeout->apply('SELECT * FROM report WHERE day = CURDATE()', $context))->fetchAll();

    // per scope (all three; the only way on PostgreSQL)
    $rows = $timeout->within($context, static fn(): array => $pdo->query('SELECT * FROM report')->fetchAll());
} catch (\PDOException $error) {
    throw $timeout->translate($error, $context);     // ContextExpiredException when the budget ran out
} finally {
    $controller->cancel();
}
```

### `apply(string $sql, Context $context): string`

| Dialect | Result for `apply('SELECT 1', …)` with 1.5 s left |
|---|---|
| MySQL | `SELECT /*+ MAX_EXECUTION_TIME(1500) */ 1` |
| MariaDB | `SET STATEMENT max_statement_time=1.5 FOR SELECT 1` |
| PostgreSQL | `LogicException` — use `within()` |
| Unsupported | `SELECT 1` |

- MySQL: the hint goes after the **main** SELECT — after the CTE list for
  `WITH … SELECT`, inside the leading parenthesis of `(SELECT …) UNION …`,
  past leading comments. An existing optimizer hint is extended; an existing
  `MAX_EXECUTION_TIME` keeps the stricter of the two. Anything else (DML, a
  versioned comment `/*!…*/`, SQL the scanner does not recognise) is returned
  unchanged.
- MariaDB: an existing `SET STATEMENT` list gains `max_statement_time`, or its
  existing value keeps the stricter of the two.
- Applying twice equals applying once with the smaller budget.
- Without a deadline `$sql` is returned as is; a terminated context throws.

### `within(Context $context, callable $callback): mixed`

Sets the remaining budget as the session's statement timeout, runs the
callback, and restores the previous value — also when the callback throws (a
failing restore never hides the callback's error). A stricter previous value
is kept.

### `translate(\PDOException $error, Context $context): \Throwable`

Returns `ContextExpiredException` (the driver error as `previous`) when the
error is the server's statement timeout and the context's deadline has passed
or is within 5 ms (the server can fire early only by rounding the budget down
to whole milliseconds); any other error — or a timeout while more budget is
left, i.e. a limit set elsewhere — is returned unchanged.

### yiisoft/db

`yiisoft/db` connections expose the PDO, and wrap driver errors in their own
exception with the `PDOException` as `previous`:

```php
use Rasuvaeff\Context\Db\Dialect;
use Yiisoft\Db\Exception\Exception as DbException;

$timeout = StatementTimeout::for($db->getActivePdo());

try {
    $value = $timeout->dialect() === Dialect::PostgreSql
        ? $timeout->within($context, static fn(): mixed => $db->createCommand($sql)->queryScalar())
        : $db->createCommand($timeout->apply($sql, $context))->queryScalar();
} catch (DbException $error) {
    $driverError = $error->getPrevious();

    throw $driverError instanceof \PDOException ? $timeout->translate($driverError, $context) : $error;
}
```

This recipe is executed by `tests/Integration/YiisoftDbIntegrationTest.php`
on MySQL, MariaDB and PostgreSQL.

### Public API

| Class | Description |
|---|---|
| `StatementTimeout` | `for(PDO, ?Dialect)`, `dialect()`, `apply()`, `within()`, `translate()` |
| `Dialect` | `MySql`, `MariaDb`, `PostgreSql`, `Unsupported` — detected from the driver name and, for `mysql`, the server version |

## Security

`apply()` only inserts a number it computed itself — never caller input — and
returns SQL it cannot parse unchanged, so it cannot turn a valid statement
into a different one. Bind user values as parameters as usual. A budget that
came from another process must already be capped (see
`rasuvaeff/context`'s `WireCodec`).

## Examples

See [examples/](examples/) for runnable scripts.
Examples are expected to execute without fatal errors and stay aligned with the
documented public API.

| Script | Shows | Needs server? |
|---|---|---|
| `rewrite.php` | What `apply()` produces for each dialect | no |
| `server.php` | A 300 ms budget stopping a heavy query on a live server | yes (`CONTEXT_DB_DSN`) |

## Development

No PHP/Composer on the host — run in Docker via the `composer:2` image:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer install
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Integration suite against live servers (`CONTEXT_DB_TEST` = `mysql`,
`mariadb` or `pgsql`; `CONTEXT_DB_HOST`, `CONTEXT_DB_PORT`,
`CONTEXT_DB_NAME`, `CONTEXT_DB_PASSWORD` override the defaults
`127.0.0.1`, 3306/3307/5432, `context`, `context`):

```bash
CONTEXT_DB_TEST=mysql vendor/bin/testo --suite=Integration
```

Or with Make:

```bash
make install
make build
make cs-fix
make test
make test-coverage
make mutation
make release-check
```

`make test-coverage` and `make mutation` bootstrap `pcov` inside the
`composer:2` container because the base image has no coverage driver.

## License

[BSD-3-Clause](LICENSE.md)
