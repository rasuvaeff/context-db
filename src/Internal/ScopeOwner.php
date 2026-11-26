<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Db\Internal;

/**
 * Tracks which Fiber has a scope open on a connection. The session timeout
 * is one value per connection: a scope opened from a second Fiber while the
 * first is suspended inside its own would save the first scope's temporary
 * budget as "previous" and restore it out of order (#11). Scopes nested in
 * one Fiber exit in order and are allowed.
 *
 * @internal
 */
final class ScopeOwner
{
    /** @var \WeakMap<\PDO, self>|null */
    private static ?\WeakMap $scopes = null;

    private int $depth = 0;

    /**
     * Weak, so that an abandoned suspended Fiber is destroyed — its stack
     * unwinds through the `finally` below and frees the connection.
     *
     * @param \WeakReference<\Fiber>|null $fiber null for the main flow
     */
    private function __construct(
        private readonly ?\WeakReference $fiber,
    ) {}

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     *
     * @throws \LogicException when another Fiber has a scope open on `$pdo`
     */
    public static function run(\PDO $pdo, callable $callback): mixed
    {
        $scopes = self::$scopes ?? self::$scopes = self::map();
        $scope = self::enter($scopes, $pdo);

        try {
            return $callback();
        } finally {
            if (--$scope->depth === 0) {
                $scopes->offsetUnset($pdo);
            }
        }
    }

    /**
     * The current Fiber is held only in this frame: kept in run()'s frame,
     * which lives on that Fiber's own stack, it would keep an abandoned
     * Fiber alive and the connection locked.
     *
     * @param \WeakMap<\PDO, self> $scopes
     */
    private static function enter(\WeakMap $scopes, \PDO $pdo): self
    {
        $fiber = \Fiber::getCurrent();
        $scope = $scopes->offsetExists($pdo) ? $scopes->offsetGet($pdo) : null;
        if ($scope === null) {
            $scope = new self($fiber instanceof \Fiber ? \WeakReference::create($fiber) : null);
            $scopes->offsetSet($pdo, $scope);
        }

        if ($scope->fiber?->get() !== $fiber) {
            throw new \LogicException(
                'Another Fiber has a StatementTimeout scope open on this connection: the session timeout is one value per connection, so overlapping scopes would restore it out of order; give each concurrent unit of work its own connection',
            );
        }

        ++$scope->depth;

        return $scope;
    }

    /** @return \WeakMap<\PDO, self> */
    private static function map(): \WeakMap
    {
        /** @var \WeakMap<\PDO, self> $map */
        $map = new \WeakMap();

        return $map;
    }
}
