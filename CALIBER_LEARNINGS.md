# Caliber Learnings

Accumulated patterns and anti-patterns from development sessions.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

- **[gotcha:project]** The MySQL server used for this project's test database has replication settings (`auto_increment_increment=7`, `auto_increment_offset=4`), so `AUTO_INCREMENT` ids are non-sequential (4, 11, 18…). Never assert on specific `id` values in tests — index rows by ordered position (1-based) instead.
- **[gotcha:project]** PHP 8.1+ makes mysqli throw exceptions by default. Call `\mysqli_report(MYSQLI_REPORT_OFF)` before connecting in test `setUp()` so that an unreachable MySQL downgrades to a skipped test rather than a fatal error. Note: "Connection refused" (TCP) may still surface as a fatal even with reporting off — graceful skip is only reliable for auth failures.
- **[pattern:project]** This package has no own `vendor/` when installed inside the mystage project. Run tests using the parent project's autoloader: `MONOLOG_MYDB_HOST=... /home/sites/mystage/vendor/bin/phpunit --bootstrap /home/sites/mystage/vendor/autoload.php Tests/CreateTableTest.php`
- **[pattern:project]** Pass MySQL credentials via env vars (`MONOLOG_MYDB_HOST`, `MONOLOG_MYDB_USER`, `MONOLOG_MYDB_PASS`, `MONOLOG_MYDB_DB`) rather than PHPUnit `-d` flags — `-d` flags don't reliably populate `$GLOBALS` for these tests.
- **[gotcha:project]** The test suite shares state through a single `logging` table and must start with an empty database. Always reset before a full run: `mysql -e "DROP DATABASE IF EXISTS monolog_mysql_test; CREATE DATABASE monolog_mysql_test;"`
- **[gotcha:project]** `MyDbHandler::initialize()` (schema reconciliation, including column drops) is lazy — it only fires on the first `write()` call. To test that a column was dropped, trigger a log write FIRST, then assert on the schema — asserting before any write will always show the old schema.
- **[convention:project]** Use `\Monolog\Level::Debug` (enum, Monolog 3 style) not `\Monolog\Logger::DEBUG` (deprecated constant). The handler constructor accepts a `int|string|Level` union.
- **[fix:project]** When `\mysqli` raises "No such file or directory" for a valid IP host, check the `Db` constructor argument order — it is `(database, user, password, host)`, NOT `(host, user, password, database)`. Passing host as the first arg causes mysqli to treat it as a socket path.
