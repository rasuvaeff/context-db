<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Db\Tests\Support;

use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\Testing\FakeTimeSource;

final class Contexts
{
    /** A context with exactly `$milliseconds` left on a frozen clock. */
    public static function withBudget(int $milliseconds): Context
    {
        return Context::background(new FakeTimeSource())->withTimeout($milliseconds / 1000.0)[0];
    }
}
