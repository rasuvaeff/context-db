# rasuvaeff/context-db

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/context-db/v)](https://packagist.org/packages/rasuvaeff/context-db)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/context-db/downloads)](https://packagist.org/packages/rasuvaeff/context-db)
[![Build](https://github.com/rasuvaeff/context-db/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/context-db/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/context-db/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/context-db/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/context-db/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/context-db/php)](https://packagist.org/packages/rasuvaeff/context-db)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

Превращает оставшийся бюджет [`rasuvaeff/context`](https://github.com/rasuvaeff/context)
в таймаут запроса, который соблюдает сам сервер БД, — MySQL, MariaDB и
PostgreSQL через PDO. Запрос останавливается на сервере, когда бюджет
исчерпан, а не продолжает работать после того, как вызывающий сдался.

> Пользуетесь AI-ассистентом? В [llms.txt](llms.txt) — компактный справочник по API, который можно передать модели.
> Проекты с Composer-плагином [llm/skills](https://github.com/roxblnfk/skills)
> дополнительно получают agent-скилл этого пакета в `.agents/skills/`
> автоматически при установке.

## Требования

- PHP 8.3+, `ext-pdo`
- `rasuvaeff/context` ^0.3
- `ext-pdo_mysql` для MySQL/MariaDB, `ext-pdo_pgsql` для PostgreSQL

## Установка

```bash
composer require rasuvaeff/context-db
```

## Ограничения

У PDO нет собственного таймаута на запрос (только на подключение), поэтому
пакет использует то, что даёт каждый сервер, — а даёт каждый своё:

| Сервер | На запрос (`apply()`) | На область (`within()`) | Ошибка при срабатывании |
|---|---|---|---|
| MySQL 5.7.8+ | `/*+ MAX_EXECUTION_TIME(ms) */` на главном **read-only SELECT** | `SET SESSION max_execution_time` | `HY000` / 3024 |
| MariaDB 10.1+ | `SET STATEMENT max_statement_time=s FOR …` на **любом** запросе | `SET SESSION max_statement_time` | `70100` / 1969 |
| PostgreSQL | — (синтаксиса на запрос нет) | `SET statement_timeout`, `SET LOCAL` в транзакции | `57014` |
| SQLite и прочие | без изменений | только callback | — |

- **MySQL ограничивает только read-only SELECT.** `INSERT … SELECT`,
  `UPDATE`, `DELETE` и DDL выполняются без серверного лимита, что бы вы ни
  выставили: проверяйте контекст между ними. `apply()` возвращает такие
  запросы без изменений, а не добавляет хинт, который MySQL молча проигнорирует.
- **Прерванный `SLEEP()` в MySQL возвращает 1, а не ошибку.** Настоящая работа
  падает с ошибкой 3024; не проверяйте лимит через `SELECT SLEEP(n)`.
- **PostgreSQL за pgbouncer в режиме transaction pooling**: сессионный `SET`
  достаётся тому, кто следующим получит серверное соединение. Вызывайте
  `within()` внутри транзакции — тогда используется `SET LOCAL`, который
  заканчивается вместе с транзакцией.
- **Таймаут в PostgreSQL обрывает транзакцию.** Каждый следующий запрос падает
  с `25P02`, пока вызывающий не сделает rollback; пакет вашу транзакцию не
  трогает.
- `within()` даёт каждому запросу бюджет, измеренный на входе, а не остаток
  после предыдущего запроса: продолжайте вызывать `assertActive()` между
  запросами.

## Использование

```php
use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\Db\StatementTimeout;

[$context, $controller] = Context::background()->withTimeout(2.0);
$timeout = StatementTimeout::for($pdo);              // определяет MySQL / MariaDB / PostgreSQL

try {
    // на запрос (MySQL, MariaDB)
    $rows = $pdo->query($timeout->apply('SELECT * FROM report WHERE day = CURDATE()', $context))->fetchAll();

    // на область (все три; единственный способ в PostgreSQL)
    $rows = $timeout->within($context, static fn(): array => $pdo->query('SELECT * FROM report')->fetchAll());
} catch (\PDOException $error) {
    throw $timeout->translate($error, $context);     // ContextExpiredException, если бюджет исчерпан
} finally {
    $controller->cancel();
}
```

### `apply(string $sql, Context $context): string`

| Диалект | Результат `apply('SELECT 1', …)` при остатке 1,5 с |
|---|---|
| MySQL | `SELECT /*+ MAX_EXECUTION_TIME(1500) */ 1` |
| MariaDB | `SET STATEMENT max_statement_time=1.5 FOR SELECT 1` |
| PostgreSQL | `LogicException` — используйте `within()` |
| Unsupported | `SELECT 1` |

- MySQL: хинт ставится после **главного** SELECT — после списка CTE в
  `WITH … SELECT`, внутри ведущей скобки `(SELECT …) UNION …`, после ведущих
  комментариев. Существующий optimizer hint дополняется; существующий
  `MAX_EXECUTION_TIME` сохраняет более строгое из двух значений. Всё остальное
  (DML, версионный комментарий `/*!…*/`, SQL, который сканер не распознал)
  возвращается без изменений.
- MariaDB: в существующий список `SET STATEMENT` добавляется
  `max_statement_time`, а существующее значение сохраняет более строгое из двух.
- Двойное применение равно однократному с меньшим бюджетом.
- Без дедлайна `$sql` возвращается как есть; завершённый контекст бросает
  исключение.

### `within(Context $context, callable $callback): mixed`

Выставляет оставшийся бюджет таймаутом запроса для сессии, выполняет
callback и восстанавливает прежнее значение — и когда callback бросил (неудача
восстановления никогда не скрывает ошибку callback'а). Более строгое прежнее
значение сохраняется.

### `translate(\PDOException $error, Context $context): \Throwable`

Возвращает `ContextExpiredException` (ошибка драйвера — `previous`), если это
серверный таймаут запроса, а дедлайн контекста прошёл или до него не больше
50 мс; любая другая ошибка — или таймаут при большом остатке бюджета, то есть
лимит, выставленный не вами, — возвращается без изменений.

### yiisoft/db

Соединения `yiisoft/db` отдают PDO и оборачивают ошибки драйвера в своё
исключение с `PDOException` в `previous`:

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

Этот рецепт исполняет `tests/Integration/YiisoftDbIntegrationTest.php` на
MySQL, MariaDB и PostgreSQL.

### Публичный API

| Класс | Описание |
|---|---|
| `StatementTimeout` | `for(PDO, ?Dialect)`, `dialect()`, `apply()`, `within()`, `translate()` |
| `Dialect` | `MySql`, `MariaDb`, `PostgreSql`, `Unsupported` — определяется по имени драйвера, а для `mysql` — по версии сервера |

## Безопасность

`apply()` вставляет только число, которое вычислил сам, — никогда не ввод
вызывающего — и возвращает SQL, который не смог разобрать, без изменений,
поэтому не может превратить корректный запрос в другой. Пользовательские
значения, как обычно, передавайте параметрами. Бюджет, пришедший из другого
процесса, должен быть уже ограничен (см. `WireCodec` в `rasuvaeff/context`).

## Примеры

Исполняемые скрипты — в [examples/](examples/).
Примеры должны выполняться без фатальных ошибок и соответствовать
задокументированному публичному API.

| Скрипт | Что показывает | Нужен сервер? |
|---|---|---|
| `rewrite.php` | Что выдаёт `apply()` для каждого диалекта | нет |
| `server.php` | Бюджет 300 мс останавливает тяжёлый запрос на живом сервере | да (`CONTEXT_DB_DSN`) |

## Разработка

PHP/Composer на хосте нет — всё через Docker-образ `composer:2`:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer install
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Интеграционный набор на живых серверах (`CONTEXT_DB_TEST` = `mysql`,
`mariadb` или `pgsql`; `CONTEXT_DB_HOST`, `CONTEXT_DB_PORT`,
`CONTEXT_DB_NAME`, `CONTEXT_DB_PASSWORD` переопределяют значения по умолчанию
`127.0.0.1`, 3306/3307/5432, `context`, `context`):

```bash
CONTEXT_DB_TEST=mysql vendor/bin/testo --suite=Integration
```

Или через Make:

```bash
make install
make build
make cs-fix
make test
make test-coverage
make mutation
make release-check
```

`make test-coverage` и `make mutation` ставят `pcov` внутри контейнера
`composer:2`, потому что в базовом образе нет драйвера покрытия.

## Лицензия

[BSD-3-Clause](LICENSE.md)
