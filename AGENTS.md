# AGENTS.md — context-db

Guidance for AI agents working on this package. Read before changing code.

## What this is

`rasuvaeff/context-db` turns the remaining `rasuvaeff/context` budget into a
statement timeout enforced by the database server: a MySQL optimizer hint, a
MariaDB `SET STATEMENT` prefix, or a session/`SET LOCAL` timeout on
PostgreSQL. Public API: `Rasuvaeff\Context\Db\StatementTimeout` and the
`Dialect` enum; SQL rewriting lives in `Internal\MySqlHint` and
`Internal\MariaDbStatement`.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **Never emit an unlimited or wrong statement.** Read the budget before
   `assertActive()` (a later read can be `0.0`, and 0 means *no limit* to all
   three servers); the minimum emitted value is 1 ms. A rewrite the scanner is
   not sure about returns the SQL unchanged — never guess.
4. **Preserve the public contract.** Update README + tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Or with Make:

```bash
make build
make cs-fix
make psalm
make test
make test-coverage
make mutation
make release-check
```

`make test-coverage` and `make mutation` bootstrap `pcov` inside the
`composer:2` container because the base image has no coverage driver.

The Integration suite needs live servers and `pdo_mysql`/`pdo_pgsql`, which
the `composer:2` image lacks — use an image with them, e.g. one built from
`php:8.3-cli` with `docker-php-ext-install pdo_mysql pdo_pgsql`:

```bash
docker run --rm --network host -v "$PWD":/app -w /app \
  -e CONTEXT_DB_TEST=mysql -e CONTEXT_DB_PORT=3306 <php-with-pdo-image> \
  vendor/bin/testo --suite=Integration
```

## Invariants & gotchas

- **MySQL honours `MAX_EXECUTION_TIME` only on the outermost SELECT** (after
  the CTE list for `WITH`) and only for read-only SELECT; a hint inside a CTE
  is ignored, a hint on DML is ignored. `INSERT … SELECT` runs unlimited.
- **The first `MAX_EXECUTION_TIME` in a hint wins in MySQL**, the **last**
  `max_statement_time` in a MariaDB `SET STATEMENT` list wins. Both rewriters
  therefore replace existing values with the stricter one — never append.
- **An interrupted MySQL `SLEEP()` returns 1, not error 3024.** Integration
  tests use a cross join over `information_schema.columns`; they set a session
  cap first so a broken limit fails in seconds instead of hanging CI.
- MariaDB accepts `SET STATEMENT … FOR` on any statement, including another
  `SET STATEMENT` (nesting) and prepared statements.
- **Never route a server's decimal through a float.** `32.002 * 1000` is
  `32002.000000000004` in floating point, and rounding it up gave 32003 ms;
  rounding `0.0004 s` to the nearest millisecond gave 0 — *no limit* — and
  `within()` restored that onto the session. `Internal\Decimal` shifts and
  rounds the decimal string exactly; the previous session value is restored
  as the normalised string it was read as. Both bugs were found by property
  and mutation tests before the first release.
- PostgreSQL: the previous value is read from `pg_settings.setting` (plain
  milliseconds, unlike `SHOW`'s "300ms"). Inside a PDO transaction
  `SET LOCAL` is used; a restore failing in an aborted transaction is
  swallowed only when the callback already threw.
- Facts above were verified 2026-10-15 on MySQL 8.0.46, MariaDB 11.8.9 and
  PostgreSQL 16.15; CI runs MySQL 8.4, MariaDB 11.8, PostgreSQL 17.
- `tests/Support/FakePdo` extends `PDO` over `sqlite::memory:` and fakes the
  driver name, server version and the timeout read — unit tests never need a
  server.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types.
- **Validation patterns anchor with `\z`, never `$`.**
- `examples/` is part of the public contract: keep scripts runnable and update
  `examples/README.md` when example usage changes.
- **CI workflows are SHA-pinned.** Every `uses:` in `.github/workflows/*.yml`
  references a 40-char commit SHA with a `# vN` trailing comment
  (e.g. `actions/checkout@<sha> # v4`). Never revert to floating `@vN` tags.
  Updates go through Dependabot, which bumps the SHA and preserves the comment.
  Workflows also carry `permissions: { contents: read }` at workflow level and
  `persist-credentials: false` on every `actions/checkout` step. Verify with
  `zizmor --persona=auditor .github/` — must report no `unpinned-uses`,
  `excessive-permissions`, or `artipacked` findings.
- The `database-integration` matrix job is deliberately **not** gated by the
  `changes` filter: a skipped matrix job reports one check under the raw
  unexpanded name and wedges a PR whose required checks name the expanded ones.

## When you finish

- Update `README.md` **and `README.ru.md`** (both languages, same commit; and
  `examples/` if usage changed); update `CHANGELOG.md` when releasing.
- Re-run `composer build`; if the change affects public API or release safety,
  also run `make release-check`. Paste the output.
