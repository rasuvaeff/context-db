<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Db\Internal;

/**
 * Exact arithmetic on the decimal strings servers report ("32.002000"):
 * through a float, 32.002 × 1000 is 32002.000000000004, and rounding that up
 * turns a 32002 ms limit into 32003.
 *
 * @internal
 */
final class Decimal
{
    private const string PATTERN = '/^\s*([0-9]*)(?:\.([0-9]*))?\s*\z/';

    /**
     * The value with its decimal point moved `$shift` places right, rounded
     * up; null when `$value` is not a plain non-negative decimal.
     *
     * @param int<0, max> $shift
     *
     * @return int<0, max>|null
     */
    public static function ceilShifted(string $value, int $shift): ?int
    {
        if (preg_match(self::PATTERN, $value, $match) !== 1 || ($match[1] === '' && ($match[2] ?? '') === '')) {
            return null;
        }

        $fraction = str_pad($match[2] ?? '', $shift, '0');
        $whole = ltrim($match[1] . substr($fraction, 0, $shift), '0');
        $rest = trim(substr($fraction, $shift), '0');

        if (\strlen($whole) > 18) {
            return \PHP_INT_MAX;
        }

        return max(0, (int) $whole) + ($rest === '' ? 0 : 1);
    }

    /** "30.250000" → "30.25", "007" → "7", ".5" → "0.5"; null when not a decimal. */
    public static function normalize(string $value): ?string
    {
        if (preg_match(self::PATTERN, $value, $match) !== 1 || ($match[1] === '' && ($match[2] ?? '') === '')) {
            return null;
        }

        $whole = ltrim($match[1], '0');
        $fraction = rtrim($match[2] ?? '', '0');

        return ($whole === '' ? '0' : $whole) . ($fraction === '' ? '' : '.' . $fraction);
    }
}
