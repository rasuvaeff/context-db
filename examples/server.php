<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextExpiredException;
use Rasuvaeff\Context\Db\Dialect;
use Rasuvaeff\Context\Db\StatementTimeout;

// CONTEXT_DB_DSN=mysql:host=127.0.0.1;port=3306;dbname=app CONTEXT_DB_USER=root CONTEXT_DB_PASSWORD=secret php examples/server.php
$dsn = getenv('CONTEXT_DB_DSN');
if ($dsn === false || $dsn === '') {
    echo "Set CONTEXT_DB_DSN (and CONTEXT_DB_USER, CONTEXT_DB_PASSWORD) to a MySQL, MariaDB or PostgreSQL server.\n";

    exit(0);
}

$pdo = new PDO($dsn, getenv('CONTEXT_DB_USER') ?: null, getenv('CONTEXT_DB_PASSWORD') ?: null);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$timeout = StatementTimeout::for($pdo);

// A query that does real work: an interrupted MySQL SLEEP() returns 1 instead of failing.
$heavy = $timeout->dialect() === Dialect::PostgreSql
    ? 'SELECT pg_sleep(10)'
    : 'SELECT COUNT(*) FROM information_schema.columns a, information_schema.columns b, information_schema.columns c';

[$context, $controller] = Context::background()->withTimeout(0.3);
$startedNs = hrtime(true);

try {
    $timeout->within($context, static fn() => $pdo->query($heavy));
    echo "finished within the budget\n";
} catch (PDOException $error) {
    $translated = $timeout->translate($error, $context);
    printf(
        "%s: the server stopped the query after %.2f s (%s)\n",
        $timeout->dialect()->name,
        (hrtime(true) - $startedNs) / 1e9,
        $translated instanceof ContextExpiredException ? 'ContextExpiredException' : $translated::class,
    );
} finally {
    $controller->cancel();
}
