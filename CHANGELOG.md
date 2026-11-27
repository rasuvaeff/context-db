# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.4.1 — 2026-11-27

- Docs: the agent skill no longer tells you to unwrap yiisoft/db exceptions before `translate()`: it looks the `PDOException` up in the `previous` chain, and unwrapping lost the original exception on non-timeout errors.

## 0.4.0 — 2026-11-26

- Changed (breaking): `within()` and `transaction()` throw `LogicException`
  when another Fiber has a scope open on the same connection, with or without
  a deadline. Overlapping scopes saved each other's temporary budget as
  "previous" and restored out of order: a stale timeout outlived both scopes,
  or a scope ran unlimited. Scopes nested in one Fiber are unaffected; give
  each concurrent unit of work its own connection (#11).

## 0.3.0 — 2026-11-21

- Added: `StatementTimeout::transaction($context, $callback)` opens a
  transaction, runs the callback under the budget and commits (rolls back and
  rethrows on error). On PostgreSQL the limit is a `SET LOCAL` that ends with
  the transaction, so a rollback — even after a timeout aborted it — leaves
  nothing on the session (#6).
- Changed (breaking): on PostgreSQL, `within()` (and `run()`) entered outside
  a transaction throws `LogicException` when the callback returns with a
  transaction open, instead of silently restoring inside it — a later rollback
  undid that restore and the session kept the budget timeout. A callback that
  throws keeps its own error. MySQL and MariaDB are unaffected: their session
  variables are not transactional (#6).

## 0.2.0 — 2026-11-20

- Added: `StatementTimeout::run($sql, $context, $execute)` picks the
  per-statement limit (MySQL, MariaDB) or `within()` (PostgreSQL) itself, so
  dialect-independent code needs no branch; the yiisoft/db recipe uses it (#3).
- Changed: `translate()` accepts any `\Throwable` and finds the
  `PDOException` in its `previous` chain — a yiisoft/db exception can be
  passed as is. The returned `ContextExpiredException` has the given error as
  `previous` (#4).
- Changed: `within()` sends neither the `SET` nor the restore when the
  session already has a stricter limit — one round trip instead of three (#5).
- Uses `Context::budgetMs()`; requires `rasuvaeff/context` ^0.5.

## 0.1.1 — 2026-11-20

- Docs: PostgreSQL has no non-transactional `SET`, so a transaction the
  `within()` callback leaves open and that is rolled back later undoes the
  restore and the session keeps the budget. README Limitations (both
  languages), `llms.txt` and the agent skill name the pattern and the two safe
  forms; integration tests pin the safe forms and the limitation on a live
  server (refs #6).
- Docs: README Security (both languages), `llms.txt` and the agent skill warn
  that the MySQL hint scanner assumes backslash escapes; under
  `sql_mode=NO_BACKSLASH_ESCAPES` use `within()` instead of `apply()` (#7).
- Mutation testing now counts code the tests execute without naming it in `#[Covers]`
  (`infection --with-uncovered`), with `PublicVisibility` off and default-parameter
  signature lines ignored; tests added for the MySQL hint scanner, `Decimal` and
  `StatementTimeout` budgets. No `src/` changes.

## 0.1.0 — 2026-10-15

- `StatementTimeout::for(PDO, ?Dialect)` with `apply()`, `within()` and
  `translate()`: the remaining `rasuvaeff/context` budget as a server-enforced
  statement timeout on MySQL (`MAX_EXECUTION_TIME` hint), MariaDB
  (`SET STATEMENT max_statement_time`) and PostgreSQL (`statement_timeout`,
  `SET LOCAL` inside a transaction).
- `Dialect` enum, detected from the PDO driver and server version.

