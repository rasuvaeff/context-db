---
name: rasuvaeff-context-db
description: >-
  Database-side statement timeouts from a rasuvaeff/context budget with
  rasuvaeff/context-db — StatementTimeout::for(PDO), apply() (MySQL
  MAX_EXECUTION_TIME hint, MariaDB SET STATEMENT max_statement_time),
  within() (session / SET LOCAL statement_timeout on PostgreSQL),
  transaction() (owns a transaction, SET LOCAL on PostgreSQL), translate()
  of server timeout errors into ContextExpiredException, and the yiisoft/db
  recipe. Use when a request or job budget must stop a slow SQL query on the
  server, or when reviewing such code.
---

# rasuvaeff/context-db

Server-enforced statement timeouts from the remaining context budget.
Namespace `Rasuvaeff\Context\Db\`.

## Safety rules — verify these on every change

1. **Know what each server can limit.** MySQL: only read-only SELECT (hint on
   the main SELECT; DML, `INSERT … SELECT`, DDL run unlimited). MariaDB: any
   statement. PostgreSQL: no per-statement syntax — `apply()` throws, use
   `within()`. Others: nothing — cooperative `assertActive()` only.

2. **Translate the driver error, don't swallow it.**
   ```php
   try { ... } catch (\PDOException $e) { throw $timeout->translate($e, $ctx); }
   ```
   Only a server timeout near the deadline becomes `ContextExpiredException`;
   anything else is returned unchanged.

3. **PostgreSQL timeouts abort the transaction.** Roll back yourself; behind a
   transaction-pooling pgbouncer call `within()` inside a transaction so it
   uses `SET LOCAL`. `within()` entered outside a transaction throws
   `LogicException` when the callback returns with a transaction open (a
   rollback would undo the restore and keep the budget); a callback that
   throws with one open keeps its error and may still lose the restore.
   Safe forms: `$timeout->transaction($ctx, fn)` (owns the transaction,
   `SET LOCAL`), `within()` inside your transaction, or open and close the
   transaction inside the callback. With yiisoft/db use `within()`/`run()`
   inside `$db->transaction()`, not `transaction()`.

   MySQL under `sql_mode=NO_BACKSLASH_ESCAPES`: do not use `apply()` — its
   scanner treats `\` as an escape and can put the hint inside a literal.

4. **`within()` gives each statement the budget measured at entry.** Keep
   calling `$ctx->assertActive()` between statements of a long scope.

5. **Never test the MySQL limit with `SELECT SLEEP(n)`** — an interrupted
   sleep returns 1 instead of failing. Use real work.

## Canonical usage

```php
use Rasuvaeff\Context\Db\StatementTimeout;

$timeout = StatementTimeout::for($pdo);   // or $db->getActivePdo() for yiisoft/db

try {
    $rows = $pdo->query($timeout->apply($sql, $ctx))->fetchAll();         // MySQL / MariaDB
    $rows = $timeout->within($ctx, fn () => $pdo->query($sql)->fetchAll()); // all three
} catch (\PDOException $e) {
    throw $timeout->translate($e, $ctx);
}
```

yiisoft/db wraps driver errors: unwrap `$dbException->getPrevious()` (a
`PDOException`) before `translate()`.

## Full API

The complete reference ships with the package: read
`vendor/rasuvaeff/context-db/llms.txt` and the README "Limitations" table
before guessing a method name.
