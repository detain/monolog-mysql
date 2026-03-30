# monolog-mydb

Monolog 2.x handler that writes log records to MySQL. Auto-creates and migrates the log table on first use.

## Commands

```bash
phpunit --configuration phpunit_mysql.xml --coverage-text   # run all tests
composer install                                             # install deps
docker-compose up -d                                        # start MySQL for tests
mysql -e 'CREATE DATABASE monolog_mysql_test;'              # create test DB
```

## Architecture

- **Handler**: `src/MyDbHandler/MyDbHandler.php` · namespace `MyDbHandler\` · extends `Monolog\Handler\AbstractProcessingHandler`
- **DB layer**: `MyDb\Mysqli\Db` — never use PDO directly
- **Tests**: `Tests/CreateTableTest.php` · uses `PHPUnit\DbUnit\TestCaseTrait` · XML fixtures in `Tests/*.xml`
- **Test config**: `phpunit_mysql.xml` · DB `monolog_mysql_test` · host `localhost` · user `root`
- **Docker**: `docker-compose.yml` · services: `php`, `mysql`, `phpmyadmin` (port 8081), `composer`
- **CI**: `.travis.yml` · PHP 7.0, 7.1, nightly

## DB Query Pattern

```php
$this->db->query('SELECT ...', __LINE__, __FILE__);
while ($this->db->next_record(MYSQL_ASSOC)) {
    $row = $this->db->Record;
}
$this->db->real_escape($userInput);  // always escape user input
```

## Handler Constructor

```php
use MyDbHandler\MyDbHandler;
use MyDb\Mysqli\Db;

$db = new Db($host, $user, $pass, $dbname);
$handler = new MyDbHandler($db, 'logs', ['username', 'userid'], \Monolog\Logger::DEBUG, true, 'U');
$logger = new \Monolog\Logger('channel');
$logger->pushHandler($handler);
```

## Key Conventions

- Default table columns: `id`, `channel`, `level`, `message`, `time`
- `$additionalFields` → auto-added as `TEXT NULL` columns via `ALTER TABLE` in `initialize()`
- Columns removed from `$additionalFields` are **dropped** from the table on next init
- `$dateFormat` controls `time` column type: `'U'`→`INTEGER`, `'Y-m-d'`→`DATE`, `'Y-m-d H:i:s'`→`DATETIME`, `'YmdHis'`→`TIMESTAMP`
- Pass `null` as `$db` to fall back to `get_module_db('default')` (MyAdmin integration)
- Always pass `__LINE__, __FILE__` as second/third args to `$this->db->query()`
- Tests use `$GLOBALS['db_dsn']`, `$GLOBALS['db_username']`, `$GLOBALS['db_password']` from `phpunit_mysql.xml`

## Test Fixture Pattern

XML fixtures in `Tests/*.xml` follow mysqldump format with `<table_data name="logging">`. Tests call `assertTableAgainstXMLDump()` to compare DB state. Time column is excluded from fixture comparisons.

## PSR-4 Autoload

`composer.json`: `"MyDbHandler\\"` → `"src/MyDbHandler/"`

<!-- caliber:managed:pre-commit -->
## Before Committing

**IMPORTANT:** Before every git commit, you MUST ensure Caliber syncs agent configs with the latest code changes.

First, check if the pre-commit hook is already installed:
```bash
grep -q "caliber" .git/hooks/pre-commit 2>/dev/null && echo "hook-active" || echo "no-hook"
```

- If **hook-active**: the hook handles sync automatically — just commit normally. Tell the user: "Caliber will sync your agent configs automatically via the pre-commit hook."
- If **no-hook**: run Caliber manually before committing:
  1. Tell the user: "Caliber: Syncing agent configs with your latest changes..."
  2. Run: `caliber refresh && git add CLAUDE.md .claude/ .cursor/ .cursorrules .github/copilot-instructions.md .github/instructions/ AGENTS.md CALIBER_LEARNINGS.md 2>/dev/null`
  3. After it completes, briefly tell the user what Caliber updated. Then proceed with the commit.

If `caliber` is not found, tell the user: "This project uses Caliber for agent config sync. Run /setup-caliber to get set up."
<!-- /caliber:managed:pre-commit -->

<!-- caliber:managed:learnings -->
## Session Learnings

Read `CALIBER_LEARNINGS.md` for patterns and anti-patterns learned from previous sessions.
These are auto-extracted from real tool usage — treat them as project-specific rules.
<!-- /caliber:managed:learnings -->
