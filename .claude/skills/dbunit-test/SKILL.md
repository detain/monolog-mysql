---
name: dbunit-test
description: Writes a PHPUnit+DBUnit integration test in Tests/ with XML fixture validation against the logging table. Use when user says 'add a test', 'write a test case', 'test logging behavior', or adds files to Tests/. Covers TestCaseTrait, getConnection(), getDataSet(), assertTableAgainstXMLDump(), and mysqldump XML fixture format. Do NOT use for pure unit tests that don't hit the DB.
---
# dbunit-test

## Critical

- **Never use PDO directly for queries** — PDO is only used to create the DBUnit connection via `new PDO($GLOBALS['db_dsn'], ...)`. All handler queries go through `MyDbHandler` / `MyDb\Mysqli\Db`.
- Tests are **stateful and ordered** — each test method depends on table state left by the prior one. Do not add `@depends` or reset the table between methods unless explicitly testing a reset scenario.
- **Never include the `time` column** in XML fixtures — it is non-deterministic. `assertTableAgainstXMLDump()` already excludes it.
- Run tests with `phpunit --configuration phpunit_mysql.xml --coverage-text`. The DB credentials come from `phpunit_mysql.xml` `<php><var>` entries — do not hardcode them.
- Verify `docker-compose up -d` and the `monolog_mysql_test` DB exist before running tests.

## Instructions

1. **Create the test class** in the `Tests/` directory. Extend `PHPUnit\Framework\TestCase` and use `PHPUnit\DbUnit\TestCaseTrait`. Copy these exact imports:
   ```php
   use MyDbHandler\MyDbHandler;
   use Monolog\Logger;
   use PHPUnit\Framework\TestCase;
   use PHPUnit\DbUnit\TestCaseTrait;
   use PHPUnit\DbUnit\DataSet\DefaultDataSet;
   ```

2. **Declare required properties** — always include these three:
   ```php
   private $pdo = null;
   private $tableName = 'logging';
   private $logger = null;
   ```

3. **Implement `getConnection()`** — reads credentials from `$GLOBALS` set by `phpunit_mysql.xml`:
   ```php
   public function getConnection() {
       $this->pdo = new PDO($GLOBALS['db_dsn'], $GLOBALS['db_username'], $GLOBALS['db_password']);
       return $this->createDefaultDBConnection($this->pdo);
   }
   ```

4. **Implement `getDataSet()`** — return an empty dataset (table is managed by the handler):
   ```php
   public function getDataSet() {
       return new DefaultDataSet();
   }
   ```

5. **Add `setupLogger()` helper** — matches the exact signature used in `CreateTableTest`:
   ```php
   private function setupLogger($additionalFields = [], $level = \Monolog\Logger::DEBUG, $timeFormat = 'U') {
       $myDBHandler = new MyDbHandler($this->pdo, $this->tableName, $additionalFields, $level, true, $timeFormat);
       $this->logger = new Logger('test_context');
       $this->logger->pushHandler($myDBHandler);
   }
   ```
   Verify `$this->pdo` is set (i.e., `getConnection()` was called) before `setupLogger()` runs.

6. **Copy `assertTableAgainstXMLDump()` verbatim** from `Tests/CreateTableTest.php:64-87` into your test class — it introspects columns at runtime and excludes `time`.

7. **Write test methods** using `$this->logger->addInfo()` / `addAlert()` / `warning()` / `debug()` etc., then assert row count and fixture:
   ```php
   public function testMyScenario() {
       $this->setupLogger(['username']);
       $this->logger->addInfo('Something happened', ['username' => 'alice']);
       $this->assertEquals(1, $this->getConnection()->getRowCount($this->tableName), 'Should be one row');
       $this->assertTableAgainstXMLDump('Tests/testMyScenario.xml');
   }
   ```

8. **Create the XML fixture** at `Tests/testMyScenario.xml`. Use the mysqldump format — omit `time`, use `xsi:nil="true"` for NULL fields:
   ```xml
   <?xml version="1.0"?>
   <mysqldump xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
       <database name="monolog_mysql_test">
           <table_data name="logging">
               <row>
                   <field name="id">1</field>
                   <field name="channel">test_context</field>
                   <field name="level">200</field>
                   <field name="message">Something happened</field>
                   <field name="username">alice</field>
               </row>
           </table_data>
       </database>
   </mysqldump>
   ```
   Monolog level integers: DEBUG=100, INFO=200, NOTICE=250, WARNING=300, ERROR=400, CRITICAL=500, ALERT=550, EMERGENCY=600.

9. **Run and verify**: `phpunit --configuration phpunit_mysql.xml --coverage-text`. All prior tests must still pass — remember state carries over.

## Examples

**User says:** "Add a test for logging with a custom time format"

**Actions taken:**
1. Add `testChangeTimeFormat()` to `Tests/CreateTableTest.php` (or a new file) using `$this->setupLogger(['username'], Logger::DEBUG, 'Y-m-d')`.
2. Log a message, assert row count, call `assertTableAgainstXMLDump('Tests/testChangeTimeFormat.xml')`.
3. Create `Tests/testChangeTimeFormat.xml` with all prior rows plus the new row — no `time` field in any `<row>`.

**Result:** Matches the pattern in `Tests/CreateTableTest.php:237-255`.

## Common Issues

- **`SQLSTATE[HY000] [1049] Unknown database 'monolog_mysql_test'`**: Run `mysql -e 'CREATE DATABASE monolog_mysql_test;'` and `docker-compose up -d`.
- **`Class 'PHPUnit\DbUnit\TestCaseTrait' not found`**: Run `composer install` — `phpunit/dbunit` must be in `vendor/`.
- **Fixture mismatch on column count**: You added/removed `$additionalFields` in a prior test — your XML must reflect the current schema including all columns from prior tests (NULL for missing values using `xsi:nil="true"`).
- **`Call to a member function query() on null` in `assertTableAgainstXMLDump`**: `getConnection()` was not called before `setupLogger()`. PHPUnit calls `getConnection()` automatically before each test, but if you call `setupLogger()` in the constructor or `setUpBeforeClass()`, `$this->pdo` will be null.
- **Row count off by one**: Tests share table state — confirm the expected count includes all rows written by all preceding test methods, not just the current one.
