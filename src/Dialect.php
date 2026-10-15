<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Db;

/**
 * The server-side timeout mechanism a connection supports.
 *
 * @api
 */
enum Dialect: string
{
    /** `/*+ MAX_EXECUTION_TIME(ms) *\/` on read-only SELECT; session `max_execution_time`. */
    case MySql = 'mysql';

    /** `SET STATEMENT max_statement_time=s FOR …`; session `max_statement_time`. */
    case MariaDb = 'mariadb';

    /** `SET [LOCAL] statement_timeout`; no per-statement syntax. */
    case PostgreSql = 'pgsql';

    /** No server-side timeout: only cooperative checks apply. */
    case Unsupported = 'unsupported';
}
