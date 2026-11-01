# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

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

