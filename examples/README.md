# Examples

| Script | Shows | Needs server? |
|---|---|---|
| `rewrite.php` | What `apply()` produces for MySQL, MariaDB, an unsupported driver and PostgreSQL | no |
| `server.php` | A 300 ms budget stopping a heavy query on a live server through `within()` and `translate()` | yes |

```bash
php examples/rewrite.php

CONTEXT_DB_DSN='mysql:host=127.0.0.1;port=3306;dbname=app' \
CONTEXT_DB_USER=root CONTEXT_DB_PASSWORD=secret php examples/server.php
```

`server.php` prints a hint and exits 0 when `CONTEXT_DB_DSN` is not set.
