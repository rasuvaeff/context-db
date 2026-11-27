<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Db\Tests;

use Rasuvaeff\Context\Db\Internal\Decimal;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(Decimal::class)]
final class DecimalTest
{
    #[DataProvider('compareProvider')]
    public function comparesExactly(string $a, string $b, int $expected): void
    {
        Assert::same(Decimal::compare($a, $b), $expected);
        Assert::same(Decimal::compare($b, $a), -$expected);
    }

    public static function compareProvider(): iterable
    {
        yield 'sub-millisecond under one millisecond' => ['0.0004', '0.001', -1];
        yield 'a fraction of a millisecond under the budget' => ['1.4995', '1.5', -1];
        yield 'trailing zeros are equal' => ['1.5', '1.500', 0];
        yield 'zero against zero with a fraction' => ['0', '0.0', 0];
        yield 'more whole digits are greater' => ['10', '9', 1];
        yield 'same width compares the digits' => ['1501', '1500', 1];
        yield 'whole part decides before the fraction' => ['2', '1.999', 1];
        yield 'fraction decides with equal whole parts' => ['30.25', '30.3', -1];
        yield 'leading zeros do not count' => ['007', '7', 0];
        yield 'beyond int' => ['99999999999999999999', '99999999999999999998.9', 1];
        yield 'equal' => ['32.002', '32.002', 0];
    }

    #[DataProvider('normalizeProvider')]
    public function normalizes(string $value, ?string $expected): void
    {
        Assert::same(Decimal::normalize($value), $expected);
    }

    public static function normalizeProvider(): iterable
    {
        yield 'trailing zeros' => ['30.250000', '30.25'];
        yield 'zero' => ['0.000000', '0'];
        yield 'integer' => ['9000', '9000'];
        yield 'leading zeros' => ['007', '7'];
        yield 'no whole part' => ['.5', '0.5'];
        yield 'sub-millisecond' => ['0.000400', '0.0004'];
        yield 'not a decimal' => ['1e3', null];
        yield 'empty' => ['', null];
        yield 'only whitespace' => ["  \n", null];
    }

    /**
     * Seconds with millisecond precision, padded with any number of zeros,
     * compare exactly as the millisecond counts do — the float route made
     * 32.002 s look like 32003 ms, and rounding up made 0.0004 s look like
     * 1 ms.
     */
    #[Property(runs: 500)]
    public function comparesAsTheMillisecondsDo(int $a, int $delta, int $padding): void
    {
        $b = max(0, $a + $delta);
        Classify::cover($a === $b, 'equal', 10.0);
        $seconds = static fn(int $ms, int $zeros): string => \sprintf('%d.%03d', intdiv($ms, 1_000), $ms % 1_000) . str_repeat('0', $zeros);
        $left = Decimal::normalize($seconds($a, $padding)) ?? '';
        $right = Decimal::normalize($seconds($b, 0)) ?? '';

        Assert::same(Decimal::compare($left, $right), $a <=> $b);
        Assert::same(Decimal::compare(Decimal::normalize($seconds($a, $padding) . '1') ?? '', $right), $a < $b ? -1 : 1);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function comparesAsTheMillisecondsDoGenerators(): array
    {
        return [
            'a' => Gen::intBetween(0, 100_000_000),
            'delta' => Gen::intBetween(-2, 2),
            'padding' => Gen::intBetween(0, 6),
        ];
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function comparesAsTheMillisecondsDoExamples(): iterable
    {
        yield 'the float trap' => [32_002, 0, 3];
        yield 'zero' => [0, 0, 0];
        yield 'one millisecond apart' => [1_499, 1, 2];
    }
}
