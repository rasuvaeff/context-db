<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Db\Internal;

/**
 * Exact handling of the decimal strings servers report ("32.002000"):
 * through a float, 32.002 × 1000 is 32002.000000000004, and rounding that up
 * turns a 32002 ms limit into 32003; rounding 0.0004 s up to whole
 * milliseconds makes it look no stricter than a 1 ms budget.
 *
 * @internal
 */
final class Decimal
{
    private const string PATTERN = '/^\s*([0-9]*)(?:\.([0-9]*))?\s*\z/';

    /**
     * -1, 0 or 1 as `$a` is smaller than, equal to or greater than `$b`,
     * compared exactly; both are plain decimals as {@see normalize()}
     * returns them ("0.0004", "1.5", "1500").
     */
    public static function compare(string $a, string $b): int
    {
        [$aWhole, $aFraction] = self::split($a);
        [$bWhole, $bFraction] = self::split($b);

        $width = max(\strlen($aFraction), \strlen($bFraction));

        return [\strlen($aWhole), $aWhole, str_pad($aFraction, $width, '0')]
            <=> [\strlen($bWhole), $bWhole, str_pad($bFraction, $width, '0')];
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

    /** @return array{string, string} */
    private static function split(string $value): array
    {
        $parts = explode('.', $value, 2);

        return [ltrim($parts[0], '0'), rtrim($parts[1] ?? '', '0')];
    }
}
