<?php

use Monolog\Level;
use Monolog\Logger;
use MyDb\Mysqli\Db;
use MyDbHandler\MyDbHandler;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end test of {@see MyDbHandler}.
 *
 * The test exercises the handler against a real MySQL instance — the schema
 * reconciliation logic is too entangled with MySQL DDL to mock usefully. The
 * connection settings are read from PHPUnit's `<var>` blocks (see
 * `phpunit_mysql.xml`) with sensible defaults so the suite can be run with a
 * local MySQL out of the box.
 *
 * The tests intentionally share state through the `logging` table — each test
 * builds on the state left by the previous one. Test order therefore matters
 * and is preserved by PHPUnit's default execution order.
 *
 * @covers \MyDbHandler\MyDbHandler
 */
class CreateTableTest extends TestCase
{
    /** @var string Name of the table used for testing. */
    private $tableName = 'logging';

    /** @var Db|null Connection used by the handler under test. */
    private $db = null;

    /** @var \mysqli|null Direct mysqli link used by the assertion helpers. */
    private $mysqli = null;

    /** @var Logger|null Configured Monolog logger. */
    private $logger = null;

    /**
     * Open a fresh database connection (and a sibling mysqli handle for the
     * assertion helpers) before every test, skipping gracefully when MySQL is
     * unreachable so the suite still runs in environments without it.
     *
     * Connection settings are sourced from (in order): environment variables
     * (`MONOLOG_MYDB_HOST`, `MONOLOG_MYDB_USER`, `MONOLOG_MYDB_PASS`,
     * `MONOLOG_MYDB_DB`), PHPUnit `<var>` blocks (`db_host`, `db_username`,
     * `db_password`, `db_name`), then sensible localhost defaults.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $host     = getenv('MONOLOG_MYDB_HOST') ?: ($GLOBALS['db_host']     ?? 'localhost');
        $user     = getenv('MONOLOG_MYDB_USER') ?: ($GLOBALS['db_username'] ?? 'root');
        $password = getenv('MONOLOG_MYDB_PASS');
        if ($password === false) {
            $password = $GLOBALS['db_password'] ?? '';
        }
        $dbname   = getenv('MONOLOG_MYDB_DB')   ?: ($GLOBALS['db_name']     ?? 'monolog_mysql_test');

        // PHP 8.1+ defaults mysqli to throwing exceptions; suppress that here
        // so we can downgrade an unreachable MySQL to a skipped test rather
        // than a fatal error. Some installations throw a plain Exception
        // (rather than mysqli_sql_exception) when the report mode is left at
        // its default at script start, so catch broadly.
        \mysqli_report(MYSQLI_REPORT_OFF);

        $mysqli = null;
        try {
            $mysqli = @new \mysqli($host, $user, $password, $dbname);
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL is not available: ' . $e->getMessage());
        }
        if (!$mysqli || $mysqli->connect_errno) {
            $this->markTestSkipped('MySQL is not available: ' . ($mysqli->connect_error ?? 'unknown error'));
        }
        $this->mysqli = $mysqli;

        // Db constructor signature: (database, user, password, host).
        $this->db = new Db($dbname, $user, $password, $host);
    }

    /**
     * Close the mysqli link after each test to avoid leaking connections.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if ($this->mysqli instanceof \mysqli) {
            @$this->mysqli->close();
        }
    }

    /**
     * Configure a fresh Monolog logger with a single {@see MyDbHandler}
     * pushed onto the stack.
     *
     * @param string[]              $additionalFields Additional context keys
     *                                                that should be persisted
     *                                                as their own columns.
     * @param int|string|Level      $level            Minimum severity to
     *                                                handle.
     * @param string                $timeFormat       `DateTime::format()`
     *                                                pattern for the `time`
     *                                                column.
     * @return void
     */
    private function setupLogger(array $additionalFields = [], int|string|Level $level = Level::Debug, string $timeFormat = 'U'): void
    {
        $handler = new MyDbHandler($this->db, $this->tableName, $additionalFields, $level, true, $timeFormat);
        $this->logger = new Logger('test_context');
        $this->logger->pushHandler($handler);
    }

    /**
     * Returns the row count of the test table.
     *
     * @return int
     */
    private function rowCount(): int
    {
        $rs = $this->mysqli->query('SELECT COUNT(*) AS c FROM `' . $this->tableName . '`');
        $row = $rs->fetch_assoc();
        return (int) $row['c'];
    }

    /**
     * Returns every row of the test table (excluding `time`, which is too
     * timing-sensitive to assert on) ordered by id ascending and re-indexed
     * with a 1-based offset so the test assertions are independent of the
     * server's `auto_increment_increment` / `auto_increment_offset` settings
     * (which can produce non-sequential ids on replication-aware MySQL).
     *
     * @param string[] $columns Columns to select.
     * @return array<int,array<string,string|null>>
     */
    private function fetchAll(array $columns): array
    {
        $cols = implode(', ', array_map(fn ($c) => '`' . $c . '`', $columns));
        $rs = $this->mysqli->query('SELECT ' . $cols . ' FROM `' . $this->tableName . '` ORDER BY id ASC');
        $rows = [];
        $i = 1;
        while ($row = $rs->fetch_assoc()) {
            // Strip the real id from the comparable payload so non-sequential
            // server-generated ids don't break value comparisons.
            unset($row['id']);
            $rows[$i++] = $row;
        }
        return $rows;
    }

    /**
     * Returns the `Type` reported by `DESCRIBE` for the given column.
     *
     * @param string $column
     * @return string|null
     */
    private function columnType(string $column): ?string
    {
        $rs = $this->mysqli->query('DESCRIBE `' . $this->tableName . '`');
        while ($row = $rs->fetch_assoc()) {
            if ($row['Field'] === $column) {
                return $row['Type'];
            }
        }
        return null;
    }

    /**
     * Tests that the table is absent before the suite runs.
     *
     * @return void
     */
    public function testTableAbsent(): void
    {
        $this->mysqli->query('DROP TABLE IF EXISTS `' . $this->tableName . '`');
        $rs = $this->mysqli->query('SHOW TABLES LIKE \'' . $this->tableName . '\'');
        $this->assertEquals(0, $rs->num_rows, 'Table should not exist at the start of the suite');
    }

    /**
     * Tests that writing a single record auto-creates the table and persists
     * the expected default columns.
     *
     * @return void
     */
    public function testCreateTable(): void
    {
        $this->setupLogger();
        $this->logger->info('Test log message');

        $this->assertEquals(1, $this->rowCount(), 'There should be one row now');

        $rows = $this->fetchAll(['id', 'channel', 'level', 'message']);
        $this->assertSame([
            1 => [
                'channel' => 'test_context',
                'level'   => '200',
                'message' => 'Test log message',
            ],
        ], $rows);
    }

    /**
     * Tests that declaring additional fields adds matching columns and that
     * a record supplying values for them is written correctly.
     *
     * @return void
     */
    public function testAddAdditionalField(): void
    {
        $this->setupLogger(['username', 'userid']);

        $this->assertEquals(1, $this->rowCount(), 'There should still be one row');

        $this->logger->alert('User tried to access area 51 without permission', ['username' => 'waza-ari', 'userid' => 1337]);

        $this->assertEquals(2, $this->rowCount(), 'There should be two rows now');

        $rows = $this->fetchAll(['id', 'channel', 'level', 'message', 'username', 'userid']);
        $this->assertSame([
            1 => [
                'channel'  => 'test_context',
                'level'    => '200',
                'message'  => 'Test log message',
                'username' => null,
                'userid'   => null,
            ],
            2 => [
                'channel'  => 'test_context',
                'level'    => '550',
                'message'  => 'User tried to access area 51 without permission',
                'username' => 'waza-ari',
                'userid'   => '1337',
            ],
        ], $rows);
    }

    /**
     * Tests that omitting one of the additional fields writes `NULL` for the
     * missing column.
     *
     * @return void
     */
    public function testAddEntryWithIncompleteAdditionalFields(): void
    {
        $this->setupLogger(['username', 'userid']);
        $this->assertEquals(2, $this->rowCount());

        $this->logger->alert('User tried to access area 51,5 without permission', ['username' => 'waza-ari']);

        $this->assertEquals(3, $this->rowCount(), 'There should be three rows now');

        $rows = $this->fetchAll(['username', 'userid', 'message']);
        $this->assertSame('waza-ari', $rows[3]['username']);
        $this->assertNull($rows[3]['userid']);
        $this->assertSame('User tried to access area 51,5 without permission', $rows[3]['message']);
    }

    /**
     * Tests that removing a field from `$additionalFields` drops the matching
     * column on next initialization.
     *
     * @return void
     */
    public function testRemoveAdditionalField(): void
    {
        $this->setupLogger(['username']);

        $this->assertEquals(3, $this->rowCount(), 'There should be three rows now');

        // Triggering a write forces the lazy schema reconciliation, which is
        // what actually drops `userid`.
        $this->logger->alert('User tried to access area 52 without permission', ['username' => 'waza-ari']);

        $this->assertNull($this->columnType('userid'), '`userid` column should have been dropped');
        $this->assertEquals(4, $this->rowCount(), 'There should be four rows now');

        $rows = $this->fetchAll(['username', 'message']);
        $this->assertSame('waza-ari', $rows[4]['username']);
        $this->assertSame('User tried to access area 52 without permission', $rows[4]['message']);
    }

    /**
     * Tests that supplying an unknown context key is silently ignored rather
     * than raising a SQL error.
     *
     * @return void
     */
    public function testLogUnknownAdditionalField(): void
    {
        $this->setupLogger(['username']);
        $this->logger->emergency('Schroedinger has opened the box!', ['username' => 'Schroedinger', 'item' => 'Cat']);

        $this->assertEquals(5, $this->rowCount(), 'There should be five rows now');
        $this->assertNull($this->columnType('item'), 'Unknown keys must not result in new columns');

        $rows = $this->fetchAll(['username', 'message', 'level']);
        $this->assertSame('Schroedinger', $rows[5]['username']);
        $this->assertSame('600', $rows[5]['level']);
    }

    /**
     * Tests that records below the configured severity threshold are dropped.
     *
     * @return void
     */
    public function testSeverityHandling(): void
    {
        $this->setupLogger(['username'], Level::Warning);
        $this->assertEquals(5, $this->rowCount(), 'There should be five rows');

        $this->logger->info('Schroedinger found a cat in the box!', ['username' => 'Schroedinger']);
        $this->assertEquals(5, $this->rowCount(), 'INFO records below WARNING must be skipped');

        $this->logger->warning('The cat is dead', ['username' => 'Schroedinger']);
        $this->assertEquals(6, $this->rowCount(), 'WARNING records must be persisted');

        $rows = $this->fetchAll(['level', 'message']);
        $this->assertSame('300', $rows[6]['level']);
        $this->assertSame('The cat is dead', $rows[6]['message']);
    }

    /**
     * Tests the default `time` column type (`INTEGER` for the `U` format).
     *
     * @return void
     */
    public function testDefaultTimeFormat(): void
    {
        $type = $this->columnType('time');
        $this->assertNotNull($type);
        $this->assertStringContainsString('int', strtolower($type));
    }

    /**
     * Tests that changing `$dateFormat` between runs migrates the column to a
     * matching MySQL type and re-writes the existing rows in the new format.
     *
     * @return void
     */
    public function testChangeTimeFormat(): void
    {
        $this->setupLogger(['username'], Level::Debug, 'Y-m-d');

        $this->assertEquals(6, $this->rowCount(), 'There should be six rows');

        $this->logger->debug('User just took a cookie from the cookie jar!', ['username' => 'Steve']);

        $this->assertEquals(7, $this->rowCount(), 'There should be seven rows now');

        $type = $this->columnType('time');
        $this->assertNotNull($type);
        $this->assertSame('date', strtolower($type), '`time` column should have been migrated to DATE');

        $rs = $this->mysqli->query('SELECT time FROM `' . $this->tableName . '` ORDER BY id DESC LIMIT 1');
        $row = $rs->fetch_assoc();
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $row['time']);
    }
}
