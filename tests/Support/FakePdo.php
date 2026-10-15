<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Db\Tests\Support;

/**
 * Records the SQL sent to it and answers the "current timeout" read with a
 * canned value; reports any driver name and server version.
 */
final class FakePdo extends \PDO
{
    /** @var list<string> */
    public array $statements = [];

    /**
     * @param string|int|float|null $current the value the timeout read returns; null makes query() fail
     * @param (\Closure(string): void)|null $onExec
     */
    public function __construct(
        private readonly string $driver,
        private readonly string $version = '',
        private readonly string|int|float|null $current = 0,
        public bool $transaction = false,
        private readonly ?\Closure $onExec = null,
    ) {
        parent::__construct('sqlite::memory:');
    }

    #[\Override]
    public function getAttribute(int $attribute): mixed
    {
        return match ($attribute) {
            \PDO::ATTR_DRIVER_NAME => $this->driver,
            \PDO::ATTR_SERVER_VERSION => $this->version,
            default => parent::getAttribute($attribute),
        };
    }

    #[\Override]
    public function exec(string $statement): int|false
    {
        $this->statements[] = $statement;
        if ($this->onExec instanceof \Closure) {
            ($this->onExec)($statement);
        }

        return 0;
    }

    #[\Override]
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        $this->statements[] = $query;
        if ($this->current === null) {
            return false;
        }

        return parent::query('SELECT ' . $this->quote((string) $this->current));
    }

    #[\Override]
    public function inTransaction(): bool
    {
        return $this->transaction;
    }
}
