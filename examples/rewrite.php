<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\Db\Dialect;
use Rasuvaeff\Context\Db\StatementTimeout;
use Rasuvaeff\Context\Testing\FakeTimeSource;

// A frozen clock keeps the printed budget stable: exactly 1.5 s left.
[$context] = Context::background(new FakeTimeSource())->withTimeout(1.5);
$pdo = new PDO('sqlite::memory:');

$statements = [
    'SELECT * FROM report',
    'WITH day AS (SELECT CURDATE() AS d) SELECT * FROM report, day',
    'SELECT /*+ MAX_EXECUTION_TIME(500) */ * FROM report',
    'UPDATE report SET seen = 1',
];

foreach ([Dialect::MySql, Dialect::MariaDb, Dialect::Unsupported] as $dialect) {
    $timeout = StatementTimeout::for($pdo, $dialect);
    echo "== {$dialect->name}\n";
    foreach ($statements as $sql) {
        echo '  ' . $timeout->apply($sql, $context) . "\n";
    }
}

try {
    StatementTimeout::for($pdo, Dialect::PostgreSql)->apply('SELECT 1', $context);
} catch (LogicException $error) {
    echo "== PostgreSql\n  " . $error->getMessage() . "\n";
}
