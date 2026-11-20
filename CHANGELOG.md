# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

