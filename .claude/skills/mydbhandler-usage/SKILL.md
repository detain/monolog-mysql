---
name: mydbhandler-usage
description: Instantiates MyDbHandler and wires it into a Monolog Logger stack. Use when user says 'add logging', 'set up monolog', 'use MyDbHandler', or creates a new logger. Covers constructor args ($db, $table, $additionalFields, $level, $bubble, $dateFormat) and pushHandler pattern. Do NOT use for modifying the handler internals or writing new handler features.
---
# MyDbHandler Usage

## Critical

- **Never use PDO** — always use `MyDb\Mysqli\Db`
- Pass `__LINE__, __FILE__` as 2nd/3rd args to every `$this->db->query()` call
- Always call `$db->real_escape($userInput)` before interpolating any user input into queries
- `$additionalFields` columns are **auto-created and auto-dropped** on `initialize()` — removing a field name drops its column

## Instructions

1. **Install the package.** Verify `composer.json` includes the monolog-mydb handler, then run:
   ```bash
   composer install
   ```
   Verify `src/MyDbHandler/MyDbHandler.php` exists before proceeding.

2. **Create the DB connection.** Use `MyDb\Mysqli\Db` directly, or pass `null` to fall back to MyAdmin's `get_module_db('default')`:
   ```php
   use MyDb\Mysqli\Db;
   $db = new Db($host, $user, $pass, $dbname);
   // OR for MyAdmin integration:
   $db = null; // handler calls get_module_db('default') internally
   ```

3. **Instantiate the handler.** Constructor signature:
   ```php
   use MyDbHandler\MyDbHandler;

   $handler = new MyDbHandler(
       $db,                          // MyDb\Mysqli\Db|null
       'logs',                       // table name (string)
       ['username', 'userid'],       // additionalFields (array, default [])
       \Monolog\Logger::DEBUG,       // level (default DEBUG)
       true,                         // bubble (default true)
       'U'                           // dateFormat (default 'U')
   );
   ```
   `$dateFormat` controls the `time` column type:
   | Value | Column type |
   |---|---|
   | `'U'` | `INTEGER` |
   | `'Y-m-d'` | `DATE` |
   | `'Y-m-d H:i:s'` | `DATETIME` |
   | `'YmdHis'` | `TIMESTAMP` |

   Verify no PHP errors are thrown before proceeding.

4. **Wire into a Monolog Logger:**
   ```php
   $logger = new \Monolog\Logger('channel-name');
   $logger->pushHandler($handler);
   ```
   Default table columns created: `id`, `channel`, `level`, `message`, `time`.
   Each entry in `$additionalFields` adds a `TEXT NULL` column automatically.

5. **Log a record** and confirm DB write:
   ```php
   $logger->info('User logged in', ['username' => 'alice', 'userid' => 42]);
   ```
   Verify the row appears in the configured table.

## Examples

**User says:** "Add logging to the payment module using MyDbHandler."

**Actions taken:**
```php
use MyDb\Mysqli\Db;
use MyDbHandler\MyDbHandler;

$db = new Db('localhost', 'root', 'secret', 'myadmin');
$handler = new MyDbHandler(
    $db,
    'payment_logs',
    ['custid', 'invoice_id'],
    \Monolog\Logger::WARNING,
    true,
    'Y-m-d H:i:s'
);
$logger = new \Monolog\Logger('payments');
$logger->pushHandler($handler);

$logger->warning('Payment failed', ['custid' => 101, 'invoice_id' => 9980]);
```

**Result:** Table `payment_logs` is created with columns `id`, `channel`, `level`, `message`, `time` (`DATETIME`), `custid` (`TEXT NULL`), `invoice_id` (`TEXT NULL`). One row inserted.

## Common Issues

- **`Class 'MyDb\Mysqli\Db' not found`** — run `composer install`; confirm the db_abstraction package is in `vendor/`.
- **`SQLSTATE` / connection errors when `$db = null`** — this fallback only works inside MyAdmin where `get_module_db()` is globally defined. Outside MyAdmin, always pass an explicit `Db` instance.
- **Column not appearing after adding to `$additionalFields`** — the `ALTER TABLE` runs in `initialize()` on first log write. Write one log record to trigger it, then verify with `DESCRIBE <table>`.
- **Column unexpectedly dropped** — a field name was removed from `$additionalFields`. Restore the name in the array and reinitialize, or add the column back manually.
- **`Table 'X' doesn't exist` on first run** — expected; handler auto-creates the table on first `write()`. Ensure the DB user has `CREATE TABLE` privilege.
