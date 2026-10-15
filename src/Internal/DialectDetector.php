<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Db\Internal;

use Rasuvaeff\Context\Db\Dialect;

/**
 * @internal
 */
final class DialectDetector
{
    public static function detect(\PDO $pdo): Dialect
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        return match ($driver) {
            'mysql' => self::isMariaDb($pdo) ? Dialect::MariaDb : Dialect::MySql,
            'pgsql' => Dialect::PostgreSql,
            default => Dialect::Unsupported,
        };
    }

    /**
     * MariaDB reports itself in the server version: "11.8.9-MariaDB-ubu2404",
     * or "5.5.5-10.6.18-MariaDB" through older clients.
     */
    private static function isMariaDb(\PDO $pdo): bool
    {
        $version = $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);

        return \is_string($version) && stripos($version, 'mariadb') !== false;
    }
}
