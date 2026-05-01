<?php

namespace MyDbHandler;

use DateTime;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Handler\AbstractProcessingHandler;
use MyDb\Mysqli\Db;

/**
 * Monolog handler that persists log records to a MySQL table.
 *
 * The handler will create the destination table on first use if it does not yet
 * exist, and it will keep the schema in sync with the configured
 * {@see self::$additionalFields} list by adding new columns and dropping ones
 * that are no longer declared.
 *
 * Default columns: `id`, `channel`, `level`, `message`, `time`. Anything passed
 * via `$additionalFields` is added as a `TEXT NULL` column and is populated
 * from a Monolog record's context (or extra) array.
 *
 * @package MyDbHandler
 * @author  Joe Huss <detain@interserver.net>
 * @license MIT
 * @link    https://github.com/detain/monolog-mysql
 */
class MyDbHandler extends AbstractProcessingHandler
{
    /**
     * Whether {@see self::initialize()} has already prepared the schema for
     * this handler instance.
     *
     * @var bool
     */
    private bool $initialized = false;

    /**
     * Database connector used for every read/write performed by the handler.
     *
     * @var Db
     */
    protected $db;

    /**
     * Name of the MySQL table used to store log records.
     *
     * @var string
     */
    private string $table = 'logs';

    /**
     * Columns that are always present in the log table regardless of the
     * configured additional fields.
     *
     * @var string[]
     */
    private array $defaultFields = ['id', 'channel', 'level', 'message', 'time'];

    /**
     * Optional context keys (and matching column names) that callers want to
     * persist alongside the standard log columns.
     *
     * For each entry `$field`, the matching key is read from the Monolog
     * record's `context`/`extra` array and stored in the column named
     * `$field`. The corresponding column is created automatically by
     * {@see self::initialize()} if it does not exist.
     *
     * @var string[]
     */
    private array $additionalFields = [];

    /**
     * Working list of columns used to build the INSERT statement for the
     * current write. Reset on every call to {@see self::write()}.
     *
     * @var string[]
     */
    private array $fields = [];

    /**
     * `DateTime::format()` pattern used when storing the `time` column.
     *
     * Determines the column type chosen by {@see self::getTimeColumnType()}:
     *   - `U`           → INTEGER (unix timestamp, default)
     *   - `Y-m-d`       → DATE
     *   - `Y-m-d H:i:s` → DATETIME
     *   - `YmdHis`      → TIMESTAMP
     *   - `H:i:s`       → TIME
     *   - `Y`           → YEAR
     *   - anything else → VARCHAR(255)
     *
     * @var string
     */
    private string $dateFormat;

    /**
     * Constructor.
     *
     * Standard Monolog log levels:
     *   - DEBUG (100):     Detailed debug information.
     *   - INFO (200):      Interesting events. Examples: User logs in, SQL logs.
     *   - NOTICE (250):    Normal but significant events.
     *   - WARNING (300):   Exceptional occurrences that are not errors.
     *   - ERROR (400):     Runtime errors that do not require immediate action
     *                      but should typically be logged and monitored.
     *   - CRITICAL (500):  Critical conditions.
     *   - ALERT (550):     Action must be taken immediately.
     *   - EMERGENCY (600): System is unusable.
     *
     * @param Db|null              $db               Database connector. When
     *                                               `null`, the handler falls
     *                                               back to
     *                                               `get_module_db('default')`
     *                                               (MyAdmin integration).
     * @param string               $table            Destination table name.
     * @param string[]             $additionalFields Extra context keys to
     *                                               persist as their own
     *                                               columns.
     * @param int|string|Level     $level            Minimum severity that this
     *                                               handler will store.
     * @param bool                 $bubble           Whether the record bubbles
     *                                               to subsequent handlers.
     * @param string               $dateFormat       `DateTime::format()` mask
     *                                               used for the `time`
     *                                               column.
     */
    public function __construct(?Db $db, string $table, array $additionalFields = [], int|string|Level $level = Level::Debug, bool $bubble = true, string $dateFormat = 'U')
    {
        if (!is_null($db)) {
            $this->db = $db;
        } else {
            $this->db = get_module_db('default');
        }
        $this->table = $table;
        $this->additionalFields = $additionalFields;
        $this->dateFormat = $dateFormat;
        parent::__construct($level, $bubble);
    }

    /**
     * Lazily prepares the database schema for this handler.
     *
     * On first invocation this will:
     *   1. Create the destination table if it does not exist.
     *   2. Drop any columns that are no longer declared in
     *      {@see self::$additionalFields}.
     *   3. Add any newly declared additional fields as `TEXT NULL` columns.
     *   4. Convert the `time` column type when {@see self::$dateFormat} no
     *      longer matches the column's current data type.
     *
     * @return void
     */
    private function initialize(): void
    {
        // Create the table if it does not already exist. Without this, the
        // DESCRIBE call below would fail on the very first log write.
        $this->db->query('CREATE TABLE IF NOT EXISTS `' . $this->table . '` (
            id BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            channel VARCHAR(255),
            level INTEGER,
            message LONGTEXT,
            time ' . $this->getTimeColumnType() . ',
            INDEX(channel) USING HASH,
            INDEX(level) USING HASH,
            INDEX(time) USING BTREE
        ) ENGINE=InnoDB', __LINE__, __FILE__);

        // Read out the current set of columns.
        $actualFields = [];
        $this->db->query('DESCRIBE `' . $this->table . '`', __LINE__, __FILE__);
        while ($this->db->next_record(MYSQLI_ASSOC)) {
            $actualFields[] = $this->db->Record['Field'];
        }

        // Calculate which columns need to be dropped and which need to be
        // added so the schema matches the declared additional fields.
        $removedColumns = array_diff(
            $actualFields,
            $this->additionalFields,
            $this->defaultFields
        );
        $addedColumns = array_diff($this->additionalFields, $actualFields);

        foreach ($removedColumns as $c) {
            $this->db->query('ALTER TABLE `' . $this->table . '` DROP `' . $c . '`;', __LINE__, __FILE__);
        }

        foreach ($addedColumns as $c) {
            $this->db->query('ALTER TABLE `' . $this->table . '` ADD `' . $c . '` TEXT NULL DEFAULT NULL;', __LINE__, __FILE__);
        }

        // Convert the `time` column type if the configured dateFormat no
        // longer matches what is in the database.
        $existingTimeFormat = $this->getExistingTimeFormat();
        if ($existingTimeFormat !== false && $this->dateFormat != $existingTimeFormat) {
            $this->updateTimeFormat($existingTimeFormat, $this->dateFormat);
        }

        // Merge default and additional fields into a single working list used
        // by write()/process().
        $this->defaultFields = array_merge($this->defaultFields, $this->additionalFields);

        $this->initialized = true;
    }

    /**
     * Builds the INSERT statement from the prepared fields/values and runs it.
     *
     * Numeric values (other than `level`, which is always quoted as a numeric
     * string) are inserted unquoted. Everything else is escaped via
     * {@see Db::real_escape()} and wrapped in single quotes. NULL values are
     * inserted as the literal `NULL`.
     *
     * @param array<string,mixed> $values Map of column name → value to insert.
     * @return void
     */
    private function process(array $values): void
    {
        $columns = [];
        $fields  = [];
        foreach ($this->fields as $key => $f) {
            if ($f == 'id') {
                continue;
            }
            $columns[] = '`' . $f . '`';
            if (is_null($values[$f])) {
                $fields[] = 'NULL';
            } elseif ($f != 'level' && is_numeric($values[$f])) {
                $fields[] = $values[$f];
            } else {
                $fields[] = "'" . $this->db->real_escape($values[$f]) . "'";
            }
        }
        $this->db->query('INSERT INTO `' . $this->table . '` (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $fields) . ')', __LINE__, __FILE__);
    }


    /**
     * Persists a single log record to the database.
     *
     * Performs lazy schema initialization on the first call, merges the
     * record's `context` and `extra` arrays so processor output is captured,
     * drops any context keys that are not declared as additional fields, and
     * back-fills missing additional fields with `NULL`.
     *
     * @param LogRecord $record The Monolog record being written.
     * @return void
     */
    protected function write(LogRecord $record): void
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        // Reset the working field list to the full set of known columns.
        $this->fields = $this->defaultFields;

        /*
         * Merge $record->context and $record->extra so additional info from
         * Monolog processors (which lands in `extra`) is also persisted.
         * @see https://github.com/Seldaek/monolog/blob/master/doc/02-handlers-formatters-processors.md
         */
        $context = array_merge($record->context, $record->extra);

        $contentArray = array_merge([
            'channel' => $record->channel,
            'level'   => $record->level->value,
            'message' => $record->message,
            'time'    => $record->datetime->format($this->dateFormat),
        ], $context);

        // Drop any context keys that are not declared columns to avoid SQL
        // errors. Only the entry in $contentArray is removed — $this->fields
        // must be left intact so subsequent writes still know about every
        // declared column.
        foreach ($contentArray as $key => $_value) {
            if (!in_array($key, $this->fields, true)) {
                unset($contentArray[$key]);
            }
        }

        // Fill content array with NULL values for any declared additional
        // field that was not supplied by the caller.
        if (!empty($this->additionalFields)) {
            $contentArray = $contentArray + array_combine(
                $this->additionalFields,
                array_fill(0, count($this->additionalFields), null)
            );
        }

        $this->process($contentArray);
    }

    /**
     * Returns the MySQL column type that matches {@see self::$dateFormat}.
     *
     * Falls back to `VARCHAR(255)` for any format that does not have a
     * dedicated MySQL type.
     *
     * @return string MySQL column type, e.g. `INTEGER`, `DATETIME`,
     *                `VARCHAR(255)`.
     */
    private function getTimeColumnType(): string
    {
        $format = $this->dateFormat;

        if ($format == 'U') {
            return 'INTEGER';
        } elseif ($format == 'Y-m-d') {
            return 'DATE';
        } elseif ($format == 'Y-m-d H:i:s') {
            return 'DATETIME';
        } elseif ($format == 'YmdHis') {
            return 'TIMESTAMP';
        } elseif ($format == 'H:i:s') {
            return 'TIME';
        } elseif ($format == 'Y') {
            return 'YEAR';
        }

        return 'VARCHAR(255)';
    }

    /**
     * Inspects the live `time` column to figure out which `DateTime::format()`
     * mask was last used.
     *
     * Used by {@see self::initialize()} to decide whether the column needs to
     * be migrated to a new type.
     *
     * @return string|false The format string (e.g. `U`, `Y-m-d`) or `false`
     *                      when the column type is custom and cannot be
     *                      inferred.
     */
    private function getExistingTimeFormat(): string|false
    {
        $table = $this->db->real_escape($this->table);
        $this->db->query(
            "SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS"
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $table . "' AND COLUMN_NAME = 'time'",
            __LINE__,
            __FILE__
        );
        if (!$this->db->next_record(MYSQLI_ASSOC)) {
            return false;
        }
        $existingColumnType = strtolower((string) $this->db->Record['DATA_TYPE']);

        if ($existingColumnType == 'int') {
            return 'U';
        } elseif ($existingColumnType == 'date') {
            return 'Y-m-d';
        } elseif ($existingColumnType == 'datetime') {
            return 'Y-m-d H:i:s';
        } elseif ($existingColumnType == 'timestamp') {
            return 'YmdHis';
        } elseif ($existingColumnType == 'time') {
            return 'H:i:s';
        } elseif ($existingColumnType == 'year') {
            return 'Y';
        }

        // Custom format — let the caller skip the migration.
        return false;
    }

    /**
     * Migrates the existing `time` column from `$oldFormat` to `$newFormat`.
     *
     * Reads every existing row, converts the time value through
     * {@see DateTime::createFromFormat()}, blanks the column, alters its type
     * to match {@see self::getTimeColumnType()}, and writes the converted
     * values back.
     *
     * @param string $oldFormat The format the existing rows are stored in.
     * @param string $newFormat The format to migrate to.
     * @return void
     */
    private function updateTimeFormat(string $oldFormat, string $newFormat): void
    {
        // Backtick the table name so we don't depend on it being safe to
        // interpolate; the value originates from the developer-supplied
        // constructor argument but defending against typos is cheap.
        $table = '`' . str_replace('`', '``', $this->table) . '`';

        $this->db->query("SELECT id, time FROM {$table}", __LINE__, __FILE__);
        $existingRows = [];
        while ($this->db->next_record(MYSQLI_ASSOC)) {
            $originalTime = DateTime::createFromFormat($oldFormat, (string) $this->db->Record['time']);
            if ($originalTime === false) {
                // Skip rows we cannot reformat rather than aborting the whole
                // migration on a single bad value.
                continue;
            }
            $existingRows[] = [
                'id'   => (int) $this->db->Record['id'],
                'time' => $originalTime->format($newFormat),
            ];
        }

        // Wipe and convert the column type, then re-apply the values in the
        // new format.
        $this->db->query("UPDATE {$table} SET time = NULL", __LINE__, __FILE__);
        $this->db->query("ALTER TABLE {$table} CHANGE time time " . $this->getTimeColumnType(), __LINE__, __FILE__);
        foreach ($existingRows as $row) {
            $time = $this->db->real_escape($row['time']);
            $this->db->query(
                "UPDATE {$table} SET time = '" . $time . "' WHERE id = " . $row['id'],
                __LINE__,
                __FILE__
            );
        }
    }
}
