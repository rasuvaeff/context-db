<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Db\Internal;

/**
 * Prefixes a statement with `SET STATEMENT max_statement_time=… FOR`.
 * MariaDB accepts the prefix on any statement and nests it; within one
 * variable list the last assignment wins, so an existing
 * `max_statement_time` is replaced (by the stricter value), never appended.
 *
 * @internal
 */
final class MariaDbStatement
{
    private const string SET_STATEMENT = '/\A(\s*SET\s+STATEMENT\s+)(.*?)(\s+FOR\s)/is';

    private const string VARIABLE = '/\bmax_statement_time\s*=\s*([0-9]+(?:\.[0-9]+)?|\.[0-9]+)/i';

    /** @param positive-int $milliseconds */
    public static function apply(string $sql, int $milliseconds): string
    {
        if (trim($sql) === '') {
            return $sql;
        }

        if (preg_match(self::SET_STATEMENT, $sql, $match) !== 1) {
            return 'SET STATEMENT max_statement_time=' . self::seconds($milliseconds) . ' FOR ' . $sql;
        }

        [$whole, $head, $variables, $for] = $match;
        $tail = substr($sql, \strlen($whole));

        if (preg_match_all(self::VARIABLE, $variables, $existing) === 0) {
            return $head . 'max_statement_time=' . self::seconds($milliseconds) . ', ' . $variables . $for . $tail;
        }

        $strictest = $milliseconds;
        foreach ($existing[1] as $value) {
            // up, not to the nearest: 0.0004 s is a real limit, never 0 (none)
            $valueMs = Decimal::ceilShifted($value, 3) ?? 0;
            if ($valueMs > 0 && $valueMs < $strictest) {
                $strictest = $valueMs;
            }
        }

        $variables = (string) preg_replace(self::VARIABLE, 'max_statement_time=' . self::seconds($strictest), $variables);

        return $head . $variables . $for . $tail;
    }

    /**
     * Seconds with millisecond precision and no trailing zeros: 300 → "0.3".
     *
     * @param positive-int $milliseconds
     */
    public static function seconds(int $milliseconds): string
    {
        $fraction = rtrim(\sprintf('%03d', $milliseconds % 1_000), '0');

        return intdiv($milliseconds, 1_000) . ($fraction === '' ? '' : '.' . $fraction);
    }
}
