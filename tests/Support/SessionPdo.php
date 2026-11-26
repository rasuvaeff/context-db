<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Db\Tests\Support;

/**
 * Keeps the statement timeout as a real session would: a `SET … = X`
 * changes what the next read returns.
 */
final class SessionPdo extends \PDO
{
    public function __construct(
        private readonly string $driver,
        public string $timeout = '0',
    ) {
        parent::__construct('sqlite::memory:');
    }

    #[\Override]
    public function getAttribute(int $attribute): mixed
    {
        return $attribute === \PDO::ATTR_DRIVER_NAME ? $this->driver : parent::getAttribute($attribute);
    }

    #[\Override]
    public function exec(string $statement): int|false
    {
        if (preg_match('/=\s*(\S+)\z/', $statement, $match) === 1) {
            $this->timeout = $match[1];
        }

        return 0;
    }

    #[\Override]
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        return parent::query('SELECT ' . $this->quote($this->timeout));
    }

    #[\Override]
    public function inTransaction(): bool
    {
        return false;
    }
}
