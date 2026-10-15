<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Db\Tests;

use Rasuvaeff\Context\Db\Internal\Decimal;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
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
    #[DataProvider('ceilProvider')]
    public function shiftsAndRoundsUpExactly(string $value, int $shift, ?int $expected): void
    {
        /** @var int<0, max> $shift */
        Assert::same(Decimal::ceilShifted($value, $shift), $expected);
    }

    public static function ceilProvider(): iterable
    {
        yield 'float trap' => ['32.002000', 3, 32_002];
        yield 'sub-millisecond' => ['0.000400', 3, 1];
        yield 'exact' => ['30.25', 3, 30_250];
        yield 'zero' => ['0.000000', 3, 0];
        yield 'integer setting' => ['9000', 0, 9_000];
        yield 'fraction of a millisecond' => ['9000.1', 0, 9_001];
        yield 'no whole part' => ['.5', 3, 500];
        yield 'leading zeros' => ['007', 0, 7];
        yield 'surrounding whitespace' => [" 12\n", 0, 12];
        yield 'eighteen digits are exact' => ['999999999999999999', 0, 999_999_999_999_999_999];
        yield 'leading zeros do not count as digits' => ['00000000000000000000001', 0, 1];
        yield 'beyond int' => ['99999999999999999999', 0, \PHP_INT_MAX];
        yield 'empty' => ['', 0, null];
        yield 'only a dot' => ['.', 0, null];
        yield 'negative' => ['-1', 0, null];
        yield 'exponent' => ['1e3', 0, null];
        yield 'two dots' => ['1.2.3', 0, null];
        yield 'letters' => ['abc', 0, null];
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
    }

    /**
     * Any millisecond count written as seconds with trailing zeros comes back
     * exactly — the float route turned 32.002 s into 32003 ms.
     */
    #[Property(runs: 500)]
    public function millisecondsSurviveTheRoundTrip(int $milliseconds, int $padding): void
    {
        $seconds = \sprintf('%d.%03d', intdiv($milliseconds, 1_000), $milliseconds % 1_000) . str_repeat('0', $padding);

        Assert::same(Decimal::ceilShifted($seconds, 3), $milliseconds);
        Assert::same(Decimal::ceilShifted($seconds . '1', 3), $milliseconds + 1);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function millisecondsSurviveTheRoundTripGenerators(): array
    {
        return [
            'milliseconds' => Gen::intBetween(0, 100_000_000),
            'padding' => Gen::intBetween(0, 6),
        ];
    }

    /** @return iterable<string, array{int, int}> */
    public static function millisecondsSurviveTheRoundTripExamples(): iterable
    {
        yield 'the float trap' => [32_002, 3];
        yield 'zero' => [0, 0];
    }
}
