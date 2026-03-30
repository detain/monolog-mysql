---
name: additional-fields
description: Adds dynamic extra columns to the log table via $additionalFields in MyDbHandler. Use when user says 'add a log field', 'store extra context', 'track userid in logs', or needs to expand the logging schema. Covers auto ALTER TABLE add/drop behavior in initialize() and how context array keys map to columns. Do NOT use for default fields (id, channel, level, message, time).
---
# additional-fields

## Critical

- **Never name an additional field** any of: `id`, `channel`, `level`, `message`, `time` — these are reserved default columns and will conflict.
- Additional fields are added as `TEXT NULL DEFAULT NULL` — no other type is supported via this mechanism.
- Columns **not** in `$additionalFields` that exist in the table (beyond defaults) are **dropped automatically** on the next `initialize()` call. Removing a field from the array is a destructive, data-losing operation.
- Context keys passed to the logger that are **not** in `$additionalFields` are silently ignored — no error is thrown.

## Instructions

1. **Declare additional fields in the constructor call.** Pass a flat string array as the third argument to `MyDbHandler`.

   ```php
   use MyDbHandler\MyDbHandler;
   use MyDb\Mysqli\Db;

   $db = new Db($host, $user, $pass, $dbname);
   $handler = new MyDbHandler($db, 'logs', ['username', 'userid'], \Monolog\Logger::DEBUG, true, 'U');
   $logger = new \Monolog\Logger('channel');
   $logger->pushHandler($handler);
   ```

   Verify: field names are lowercase strings with no spaces, not in the default fields list.

2. **The table is auto-migrated on first write.** `initialize()` runs `DESCRIBE` on the table, diffs against `$additionalFields`, then:
   - Issues `ALTER TABLE \`logs\` ADD \`fieldname\` TEXT NULL DEFAULT NULL;` for each new field.
   - Issues `ALTER TABLE \`logs\` DROP \`fieldname\`;` for each field no longer in `$additionalFields`.

   No manual schema migration is needed. Verify by inspecting table structure after the first log write.

3. **Pass context values matching field names when logging.** The array key must exactly match the string in `$additionalFields`.

   ```php
   // Both 'username' and 'userid' are in $additionalFields — both are stored
   $logger->addAlert('User tried to access area 51', ['username' => 'waza-ari', 'userid' => 1337]);

   // Only 'username' provided — 'userid' column is stored as NULL
   $logger->addAlert('Another attempt', ['username' => 'waza-ari']);

   // 'item' is NOT in $additionalFields — silently ignored, not stored
   $logger->addEmergency('Box opened!', ['username' => 'Schroedinger', 'item' => 'Cat']);
   ```

   Verify: after logging, `SELECT username, userid FROM logs ORDER BY id DESC LIMIT 1;` returns expected values.

4. **To remove an additional field**, simply omit it from the `$additionalFields` array in the next `MyDbHandler` instantiation. On the next log write, `initialize()` will `ALTER TABLE ... DROP` that column and all its data.

   ```php
   // Previously: ['username', 'userid'] — 'userid' will be dropped
   $handler = new MyDbHandler($db, 'logs', ['username'], \Monolog\Logger::DEBUG, true, 'U');
   ```

   Verify: `DESCRIBE logs;` no longer shows the dropped column after first write.

## Examples

**User says:** "Track the userid and username in every log entry"

**Actions taken:**
1. Instantiate handler with `['username', 'userid']` as `$additionalFields`.
2. On first log write, `initialize()` runs `ALTER TABLE \`logs\` ADD \`username\` TEXT NULL DEFAULT NULL` and `ALTER TABLE \`logs\` ADD \`userid\` TEXT NULL DEFAULT NULL`.
3. Log with context: `$logger->info('Login', ['username' => 'alice', 'userid' => 42]);`
4. Row stored: `channel=..., level=200, message='Login', time=..., username='alice', userid='42'`.

**Result:** `logs` table has two new `TEXT NULL` columns; existing rows have `NULL` for both.

## Common Issues

- **Field value not saved / column missing after log write:** The context key does not exactly match the string in `$additionalFields`. Keys are case-sensitive — `'userName'` ≠ `'username'`. Fix: ensure the array entry and the context key are identical strings.

- **"Unknown column X in field list" SQL error:** A field was added to `$additionalFields` but `initialize()` has not yet run (i.e., `$initialized` is still `true` from a prior handler instance in the same request). Fix: create a fresh `MyDbHandler` instance so `$initialized` starts as `false` and `initialize()` re-runs.

- **Data unexpectedly deleted from a column:** An additional field was removed from the `$additionalFields` array, triggering `ALTER TABLE ... DROP`. This is intentional but irreversible. Fix: never remove a field from `$additionalFields` unless column deletion is intended; restore from a DB backup if needed.

- **`ALTER TABLE` fails with "Can't DROP ... check that column/key exists":** The column was already dropped externally. Re-run will succeed on next init since `DESCRIBE` won't list it and `removedColumns` will be empty.

- **`phpunit --configuration phpunit_mysql.xml` fails with "Access denied":** Ensure the test DB exists: `mysql -e 'CREATE DATABASE monolog_mysql_test;'` and Docker MySQL is up: `docker-compose up -d`.