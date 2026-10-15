<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Db\Internal;

/**
 * Adds `MAX_EXECUTION_TIME(ms)` to the optimizer hint of the main SELECT.
 *
 * MySQL honours the hint only right after the outermost SELECT keyword — for
 * `WITH … SELECT` that is the SELECT after the CTE list, not one inside it —
 * and only for read-only SELECT. Anything the scanner does not recognise
 * (DML, a versioned comment `/*!…*\/`, an unbalanced CTE) is returned
 * unchanged: a statement is never rewritten into something MySQL rejects.
 * The first `MAX_EXECUTION_TIME` in a hint wins in MySQL, so an existing one
 * is replaced by the stricter value instead of appending a second.
 *
 * @internal
 */
final class MySqlHint
{
    private const string HINT = '/\A(\s*)\/\*\+(.*?)\*\//s';

    private const string MAX_EXECUTION_TIME = '/\bMAX_EXECUTION_TIME\s*\(\s*([0-9]+)\s*\)/i';

    /** @param positive-int $milliseconds */
    public static function apply(string $sql, int $milliseconds): string
    {
        $position = (new self($sql))->mainSelectEnd();
        if ($position === null) {
            return $sql;
        }

        $head = substr($sql, 0, $position);
        $rest = substr($sql, $position);

        if (preg_match(self::HINT, $rest, $hint) !== 1) {
            return $head . ' /*+ MAX_EXECUTION_TIME(' . $milliseconds . ') */' . $rest;
        }

        [$whole, $space, $body] = $hint;
        $tail = substr($rest, \strlen($whole));

        if (preg_match(self::MAX_EXECUTION_TIME, $body, $existing) === 1) {
            $existingMs = (int) $existing[1];
            $value = $existingMs > 0 ? min($existingMs, $milliseconds) : $milliseconds;
            $body = (string) preg_replace(self::MAX_EXECUTION_TIME, 'MAX_EXECUTION_TIME(' . $value . ')', $body, 1);
        } else {
            $body = rtrim($body) . ' MAX_EXECUTION_TIME(' . $milliseconds . ') ';
        }

        return $head . $space . '/*+' . $body . '*/' . $tail;
    }

    private int $offset = 0;

    private readonly int $length;

    private function __construct(private readonly string $sql)
    {
        $this->length = \strlen($sql);
    }

    /** Offset right after the main SELECT keyword, or null when not a SELECT. */
    private function mainSelectEnd(): ?int
    {
        if (!$this->skipTriviaAndParentheses()) {
            return null;
        }

        if ($this->keyword('WITH')) {
            if (!$this->skipCommonTableExpressions() || !$this->skipTriviaAndParentheses()) {
                return null;
            }
        }

        return $this->keyword('SELECT') ? $this->offset : null;
    }

    private function skipCommonTableExpressions(): bool
    {
        $this->skipTrivia();
        $this->keyword('RECURSIVE');

        do {
            $this->skipTrivia();
            if (!$this->identifier()) {
                return false;
            }

            $this->skipTrivia();
            if ($this->peek() === '(' && !$this->skipBalanced()) {
                return false;
            }

            $this->skipTrivia();
            if (!$this->keyword('AS')) {
                return false;
            }

            $this->skipTrivia();
            if ($this->peek() !== '(' || !$this->skipBalanced()) {
                return false;
            }

            $this->skipTrivia();
            $more = $this->peek() === ',';
            if ($more) {
                $this->offset++;
            }
        } while ($more);

        return true;
    }

    /** False on something that must not be rewritten (`/*!`, `/*+`). */
    private function skipTriviaAndParentheses(): bool
    {
        do {
            if (!$this->skipTrivia()) {
                return false;
            }
            $parenthesis = $this->peek() === '(';
            if ($parenthesis) {
                $this->offset++;
            }
        } while ($parenthesis);

        return true;
    }

    /** Whitespace and comments; false on an executable or hint comment. */
    private function skipTrivia(): bool
    {
        while ($this->offset < $this->length) {
            $char = $this->sql[$this->offset];

            if (ctype_space($char)) {
                $this->offset++;

                continue;
            }

            if (substr_compare($this->sql, '/*', $this->offset, 2) === 0) {
                $marker = $this->sql[$this->offset + 2] ?? '';
                if ($marker === '!' || $marker === '+') {
                    return false;
                }
                if (!$this->skipBlockComment()) {
                    return false;
                }

                continue;
            }

            if ($char === '#' || $this->isDashComment()) {
                $this->skipLineComment();

                continue;
            }

            break;
        }

        return true;
    }

    private function isDashComment(): bool
    {
        if (substr_compare($this->sql, '--', $this->offset, 2) !== 0) {
            return false;
        }

        $next = $this->sql[$this->offset + 2] ?? "\n";

        return ctype_space($next) || ctype_cntrl($next);
    }

    private function skipBlockComment(): bool
    {
        $end = strpos($this->sql, '*/', $this->offset + 2);
        if ($end === false) {
            return false;
        }

        $this->offset = $end + 2;

        return true;
    }

    private function skipLineComment(): void
    {
        $end = strpos($this->sql, "\n", $this->offset);
        $this->offset = $end === false ? $this->length : $end + 1;
    }

    /** Skips a parenthesised group, honouring quotes and comments. */
    private function skipBalanced(): bool
    {
        $depth = 0;

        while ($this->offset < $this->length) {
            $char = $this->sql[$this->offset];

            if (in_array($char, ["'", '"', '`'], strict: true)) {
                if (!$this->skipQuoted($char)) {
                    return false;
                }

                continue;
            }

            if (substr_compare($this->sql, '/*', $this->offset, 2) === 0) {
                if (!$this->skipBlockComment()) {
                    return false;
                }

                continue;
            }

            if ($char === '#' || $this->isDashComment()) {
                $this->skipLineComment();

                continue;
            }

            $this->offset++;
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    private function skipQuoted(string $quote): bool
    {
        $this->offset++;

        while ($this->offset < $this->length) {
            $char = $this->sql[$this->offset];

            if ($char === '\\' && $quote !== '`') {
                $this->offset += 2;

                continue;
            }

            $this->offset++;
            if ($char === $quote) {
                if (($this->sql[$this->offset] ?? '') !== $quote) {
                    return true;
                }
                // a doubled quote is an escaped quote
                $this->offset++;
            }
        }

        return false;
    }

    private function identifier(): bool
    {
        if ($this->peek() === '`') {
            return $this->skipQuoted('`');
        }

        $start = $this->offset;
        while ($this->offset < $this->length && $this->isWordChar($this->sql[$this->offset])) {
            $this->offset++;
        }

        return $this->offset > $start;
    }

    private function keyword(string $word): bool
    {
        $size = \strlen($word);
        if ($this->offset + $size > $this->length) {
            return false;
        }

        if (substr_compare($this->sql, $word, $this->offset, $size, case_insensitive: true) !== 0) {
            return false;
        }

        $next = $this->sql[$this->offset + $size] ?? ' ';
        if ($this->isWordChar($next)) {
            return false;
        }

        $this->offset += $size;

        return true;
    }

    private function peek(): string
    {
        return $this->sql[$this->offset] ?? '';
    }

    private function isWordChar(string $char): bool
    {
        return ctype_alnum($char) || $char === '_' || $char === '$';
    }
}
