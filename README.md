# monolog-mydb

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

A [Monolog](https://github.com/Seldaek/monolog) 2.x / 3.x handler that writes
log records to a MySQL table using the
[`detain/db_abstraction`](https://github.com/detain/db_abstraction) database
layer (`MyDb\Mysqli\Db`).

The handler:

- Auto-creates the destination table on first use.
- Auto-syncs the table schema with a developer-supplied list of additional
  columns — adding new ones, dropping ones that are no longer declared.
- Picks an appropriate column type for the `time` column based on the
  `DateTime::format()` pattern you provide and migrates the column in place if
  the format changes between runs.

## Requirements

- PHP **8.2** or later (the constructor uses `int|string|Level` union types and
  typed properties).
- [`detain/db_abstraction`](https://github.com/detain/db_abstraction) — the
  package provides `MyDb\Mysqli\Db`, the database layer this handler talks to.
- [`monolog/monolog`](https://github.com/Seldaek/monolog) 2.x or 3.x.
- A MySQL/MariaDB server reachable from the application.

## Installation

```bash
composer require detain/monolog-mydb
```

…or add the package to your `composer.json`:

```json
{
  "require": {
    "detain/monolog-mydb": "^1.0"
  }
}
```

## Usage

Push the handler onto a Monolog `Logger` exactly the same way as any other
handler.

```php
use Monolog\Logger;
use Monolog\Level;
use MyDb\Mysqli\Db;
use MyDbHandler\MyDbHandler;

// Db constructor signature: (database, user, password, host).
$db = new Db('app', 'username', 'password', 'localhost');

$handler = new MyDbHandler(
    $db,                          // Db connector (or null to fall back to get_module_db('default'))
    'logs',                       // Destination table name
    ['username', 'userid'],       // Additional context keys → columns
    Level::Debug,                 // Minimum severity to handle
    true,                         // Bubble to subsequent handlers
    'U'                           // DateTime::format() mask for the `time` column
);

$logger = new Logger('app');
$logger->pushHandler($handler);

$logger->warning('User tried to access area 51 without permission', [
    'username' => 'waza-ari',
    'userid'   => 1337,
]);
```

### Constructor parameters

| Name | Type | Default | Description |
| ---- | ---- | ------- | ----------- |
| `$db` | `MyDb\Mysqli\Db\|null` | _required_ | Database connector. When `null`, the handler falls back to `get_module_db('default')` (MyAdmin integration). |
| `$table` | `string` | _required_ | Destination table name. |
| `$additionalFields` | `string[]` | `[]` | Extra context keys to persist as their own `TEXT NULL` columns. Add or remove freely between runs — the schema is reconciled at next initialization. |
| `$level` | `int\|string\|Monolog\Level` | `Level::Debug` | Minimum severity that this handler will store. |
| `$bubble` | `bool` | `true` | Whether records should bubble to subsequent handlers. |
| `$dateFormat` | `string` | `'U'` | `DateTime::format()` mask used for the `time` column. Determines the column type — see below. |

### Time column types

The handler picks the `time` column type based on `$dateFormat`:

| `$dateFormat` | MySQL column type |
| ------------- | ----------------- |
| `U` _(default — unix timestamp)_ | `INTEGER` |
| `Y-m-d` | `DATE` |
| `Y-m-d H:i:s` | `DATETIME` |
| `YmdHis` | `TIMESTAMP` |
| `H:i:s` | `TIME` |
| `Y` | `YEAR` |
| anything else | `VARCHAR(255)` |

If you change `$dateFormat` between runs, existing rows are migrated through
`DateTime::createFromFormat()` and the column is altered to the new type.

### Default columns

| Column | Type | Notes |
| ------ | ---- | ----- |
| `id` | `BIGINT(20)` | Auto-increment primary key. |
| `channel` | `VARCHAR(255)` | Monolog channel name. Indexed (HASH). |
| `level` | `INTEGER` | Monolog level value (e.g. `200` for INFO). Indexed (HASH). |
| `message` | `LONGTEXT` | Pre-formatted message. |
| `time` | _depends on `$dateFormat`_ | Indexed (BTREE). |

Anything in `$additionalFields` is added as `TEXT NULL` and populated from the
context array when present, `NULL` otherwise. Keys passed in context that are
**not** declared in `$additionalFields` are silently dropped to avoid SQL
errors.

## Schema reconciliation

`initialize()` runs once per handler instance, on the first log write. It will:

1. `CREATE TABLE IF NOT EXISTS` so the table is always present.
2. `DESCRIBE` the table and diff its columns against the configured fields.
3. `DROP` any columns that are no longer declared in `$additionalFields`
   (default columns are never dropped).
4. `ADD` any newly declared additional fields as `TEXT NULL DEFAULT NULL`.
5. Migrate the `time` column type if `$dateFormat` no longer matches.

> **Note:** dropped columns lose their data permanently. Be deliberate about
> what you remove from `$additionalFields`.

## Development

### Tests

The test suite is built on plain PHPUnit 9 and exercises the handler against a
real MySQL instance — the schema reconciliation logic is too entangled with
MySQL DDL to mock usefully. Tests skip gracefully when MySQL is unreachable so
the suite can still run in environments without a database.

```bash
composer install
mysql -e 'CREATE DATABASE monolog_mysql_test;'
vendor/bin/phpunit --configuration phpunit_mysql.xml --coverage-text
```

Connection settings are sourced from (in order):

1. Environment variables: `MONOLOG_MYDB_HOST`, `MONOLOG_MYDB_USER`,
   `MONOLOG_MYDB_PASS`, `MONOLOG_MYDB_DB`.
2. PHPUnit `<var>` blocks in `phpunit_mysql.xml`: `db_host`, `db_username`,
   `db_password`, `db_name`.
3. Localhost defaults (`localhost` / `root` / no password /
   `monolog_mysql_test`).

To point the suite at a non-default MySQL without editing the XML:

```bash
MONOLOG_MYDB_HOST=db.example.com \
MONOLOG_MYDB_USER=ci \
MONOLOG_MYDB_PASS=secret \
MONOLOG_MYDB_DB=monolog_mysql_test \
    vendor/bin/phpunit --configuration phpunit_mysql.xml
```

### Docker

A `docker-compose.yml` is shipped for local development:

```bash
docker-compose up -d
# phpMyAdmin is available on http://localhost:8081
```

## License

Released under the MIT license. See [LICENSE](LICENSE) for details.
