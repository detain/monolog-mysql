# monolog-mydb

Monolog 2.x/3.x handler that writes log records to MySQL. Auto-creates and migrates the log table on first use.

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
- **Tests**: `Tests/CreateTableTest.php` · extends `PHPUnit\Framework\TestCase` · direct `\mysqli` assertions · helpers `rowCount()`, `fetchAll()`, `columnType()`
- **Test config**: `phpunit_mysql.xml` · DB `monolog_mysql_test` · host `localhost` · user `root`
- **Docker**: `docker-compose.yml` · services: `php`, `mysql`, `phpmyadmin` (port 8081), `composer`
- **CI**: `.travis.yml` · requires PHP 8.2+

## DB Query Pattern

```php
$this->db->query('SELECT ...', __LINE__, __FILE__);
while ($this->db->next_record(MYSQLI_ASSOC)) {
    $row = $this->db->Record;
}
$this->db->real_escape($userInput);  // always escape user input
```

`MYSQL_ASSOC` (from the removed PHP `mysql_*` extension) is not defined on
PHP 7+/8.x — always use `MYSQLI_ASSOC` here.

## Handler Constructor

```php
use MyDbHandler\MyDbHandler;
use MyDb\Mysqli\Db;
use Monolog\Level;

// Db constructor signature: (database, user, password, host).
$db = new Db($dbname, $user, $pass, $host);
$handler = new MyDbHandler($db, 'logs', ['username', 'userid'], Level::Debug, true, 'U');
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
- Use `Level::Debug` / `Level::Warning` (Monolog 3 enum) — not `Logger::DEBUG` / `Logger::WARNING`
- `write()` receives `Monolog\LogRecord` — access fields as properties (`$record->context`, `$record->level->value`, `$record->message`, `$record->datetime`)
- Tests source connection settings from env vars (`MONOLOG_MYDB_HOST`, `_USER`, `_PASS`, `_DB`) or PHPUnit `<var>` blocks (`db_host`, `db_username`, `db_password`, `db_name`) in `phpunit_mysql.xml`; tests skip gracefully when MySQL is unreachable

## PSR-4 Autoload

`composer.json`: `"MyDbHandler\\"` → `"src/MyDbHandler/"`

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
## Session Learnings

Read `CALIBER_LEARNINGS.md` for patterns and anti-patterns learned from previous sessions.
These are auto-extracted from real tool usage — treat them as project-specific rules.

<!-- caliber:managed:model-config -->
## Model Configuration

Recommended default: `claude-sonnet-4-6` with high effort (stronger reasoning; higher cost and latency than smaller models).
Smaller/faster models trade quality for speed and cost — pick what fits the task.
Pin your choice (`/model` in Claude Code, or `CALIBER_MODEL` when using Caliber with an API provider) so upstream default changes do not silently change behavior.

<!-- /caliber:managed:model-config -->

<!-- caliber:managed:sync -->
## Context Sync

This project uses [Caliber](https://github.com/caliber-ai-org/ai-setup) to keep AI agent configs in sync across Claude Code, Cursor, Copilot, and Codex.
Configs update automatically before each commit via `caliber refresh`.
If the pre-commit hook is not set up, run `/setup-caliber` to configure everything automatically.
<!-- /caliber:managed:sync -->
