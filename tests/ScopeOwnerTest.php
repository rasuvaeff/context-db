<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Db\Tests;

use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\Db\Dialect;
use Rasuvaeff\Context\Db\Internal\ScopeOwner;
use Rasuvaeff\Context\Db\StatementTimeout;
use Rasuvaeff\Context\Db\Tests\Support\Contexts;
use Rasuvaeff\Context\Db\Tests\Support\FakePdo;
use Rasuvaeff\Context\Db\Tests\Support\SessionPdo;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * Overlapping scopes on one connection (#11): a second Fiber entering while
 * the first is suspended inside its scope would restore the session timeout
 * out of order.
 */
#[Test]
#[Covers(ScopeOwner::class)]
#[Covers(StatementTimeout::class)]
final class ScopeOwnerTest
{
    #[DataProvider('overlapProvider')]
    public function anOverlappingScopeFromAnotherFiberIsRefused(int $outer, int $inner): void
    {
        $pdo = new SessionPdo('mysql');
        $timeout = StatementTimeout::for($pdo, Dialect::MySql);
        $first = new \Fiber(static fn(): mixed => $timeout->within(Contexts::withBudget($outer), static fn(): mixed => \Fiber::suspend()));
        $first->start();

        Assert::true(self::refusal(static fn(): mixed => $timeout->within(Contexts::withBudget($inner), static fn(): int => 1)));
        Assert::same($pdo->timeout, (string) $outer);

        $first->resume();

        Assert::true($first->isTerminated());
        Assert::same($pdo->timeout, '0');
    }

    public static function overlapProvider(): iterable
    {
        yield 'inner stricter' => [5_000, 1_000];
        yield 'inner looser' => [1_000, 5_000];
    }

    public function aFiberIsRefusedWhileTheMainFlowHasAScopeOpen(): void
    {
        $pdo = new SessionPdo('mysql');
        $timeout = StatementTimeout::for($pdo, Dialect::MySql);

        $refused = $timeout->within(Contexts::withBudget(1_000), static function () use ($timeout): bool {
            $fiber = new \Fiber(static fn(): bool => self::refusal(static fn(): mixed => $timeout->within(Context::background(), static fn(): int => 1)));
            $fiber->start();

            return (bool) $fiber->getReturn();
        });

        Assert::true($refused);
        Assert::same($pdo->timeout, '0');
    }

    public function withoutADeadlineAnOverlapIsRefusedToo(): void
    {
        $timeout = StatementTimeout::for(new SessionPdo('mysql'), Dialect::MySql);
        $first = new \Fiber(static fn(): mixed => $timeout->within(Context::background(), static fn(): mixed => \Fiber::suspend()));
        $first->start();

        Assert::true(self::refusal(static fn(): mixed => $timeout->within(Context::background(), static fn(): int => 1)));

        $first->resume();
    }

    public function transactionFromAnotherFiberIsRefusedBeforeItBegins(): void
    {
        $pdo = new FakePdo('mysql');
        $timeout = StatementTimeout::for($pdo, Dialect::MySql);
        $first = new \Fiber(static fn(): mixed => $timeout->within(Context::background(), static fn(): mixed => \Fiber::suspend()));
        $first->start();

        Assert::true(self::refusal(static fn(): mixed => $timeout->transaction(Contexts::withBudget(1_000), static fn(): int => 1)));
        Assert::same($pdo->statements, []);

        $first->resume();
    }

    public function scopesNestWithinOneFiber(): void
    {
        $pdo = new SessionPdo('mysql');
        $timeout = StatementTimeout::for($pdo, Dialect::MySql);
        $seen = [];
        $fiber = new \Fiber(static function () use ($timeout, $pdo, &$seen): int {
            return $timeout->within(Contexts::withBudget(5_000), static function () use ($timeout, $pdo, &$seen): int {
                $timeout->within(Contexts::withBudget(1_000), static function () use ($pdo, &$seen): void {
                    $seen[] = $pdo->timeout;
                });
                $seen[] = $pdo->timeout;
                \Fiber::suspend();

                return $timeout->within(Contexts::withBudget(2_000), static function () use ($pdo, &$seen): int {
                    $seen[] = $pdo->timeout;

                    return 7;
                });
            });
        });
        $fiber->start();
        $fiber->resume();

        Assert::same($fiber->getReturn(), 7);
        Assert::same($seen, ['1000', '5000', '2000']);
        Assert::same($pdo->timeout, '0');
    }

    public function anOuterScopeStaysOwnedAfterANestedOneExits(): void
    {
        $timeout = StatementTimeout::for(new SessionPdo('mysql'), Dialect::MySql);
        $first = new \Fiber(static fn(): mixed => $timeout->within(Context::background(), static function () use ($timeout): mixed {
            $timeout->within(Context::background(), static fn(): int => 1);

            return \Fiber::suspend();
        }));
        $first->start();

        Assert::true(self::refusal(static fn(): mixed => $timeout->within(Context::background(), static fn(): int => 1)));

        $first->resume();
    }

    public function transactionAndRunNestInsideWithin(): void
    {
        $pdo = new FakePdo('pgsql', current: '0');
        $timeout = StatementTimeout::for($pdo, Dialect::PostgreSql);

        $result = $timeout->within(Contexts::withBudget(5_000), static fn(): array => [
            $timeout->run('SELECT 1', Contexts::withBudget(1_000), static fn(string $sql): string => $sql),
            $timeout->transaction(Contexts::withBudget(1_000), static fn(): int => 2),
        ]);

        Assert::same($result, ['SELECT 1', 2]);
    }

    public function anotherConnectionIsIndependent(): void
    {
        $first = StatementTimeout::for(new SessionPdo('mysql'), Dialect::MySql);
        $second = StatementTimeout::for(new SessionPdo('mysql'), Dialect::MySql);
        $fiber = new \Fiber(static fn(): mixed => $first->within(Context::background(), static fn(): mixed => \Fiber::suspend()));
        $fiber->start();

        Assert::same($second->within(Contexts::withBudget(1_000), static fn(): int => 3), 3);

        $fiber->resume();
    }

    #[DataProvider('releaseProvider')]
    public function theConnectionIsFreeOnceTheScopeEnds(bool $throws): void
    {
        $timeout = StatementTimeout::for(new SessionPdo('mysql'), Dialect::MySql);
        $first = new \Fiber(static function () use ($timeout, $throws): void {
            try {
                $timeout->within(Contexts::withBudget(1_000), static function () use ($throws): void {
                    \Fiber::suspend();
                    if ($throws) {
                        throw new \RuntimeException('query failed');
                    }
                });
            } catch (\RuntimeException) {
            }
        });
        $first->start();
        $first->resume();

        $second = new \Fiber(static fn(): int => $timeout->within(Contexts::withBudget(1_000), static fn(): int => 4));
        $second->start();

        Assert::same($second->getReturn(), 4);
    }

    public static function releaseProvider(): iterable
    {
        yield 'returns' => [false];
        yield 'throws' => [true];
    }

    public function anAbandonedFiberFreesTheConnection(): void
    {
        $timeout = StatementTimeout::for(new SessionPdo('mysql'), Dialect::MySql);
        $first = new \Fiber(static fn(): mixed => $timeout->within(Context::background(), static fn(): mixed => \Fiber::suspend()));
        $first->start();
        unset($first);

        Assert::same($timeout->within(Context::background(), static fn(): int => 5), 5);
    }

    /** @param callable(): mixed $enter */
    private static function refusal(callable $enter): bool
    {
        try {
            $enter();
        } catch (\LogicException $error) {
            Assert::string($error->getMessage())->contains('Another Fiber has a StatementTimeout scope open on this connection');

            return true;
        }

        return false;
    }
}
