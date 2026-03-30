---
name: db-query-pattern
description: Executes queries using the detain/db_abstraction Db class pattern. Use when user says 'query the database', 'fetch rows', 'run a select', or adds DB calls to MyDbHandler.php. Covers $this->db->query(__LINE__, __FILE__), next_record(MYSQL_ASSOC), $this->db->Record, and real_escape(). Do NOT use for PDO-based queries or test fixture assertions.
---
# DB Query Pattern

## Critical

- **Never use PDO directly** — always use `MyDb\Mysqli\Db` (the `$this->db` instance).
- **Always pass `__LINE__, __FILE__`** as the second and third arguments to every `$this->db->query()` call — no exceptions.
- **Always escape user input** with `$this->db->real_escape()` before interpolating into a query string.
- Never build INSERT strings manually — use the `process()` pattern (column/value arrays + `implode`) or `make_insert_query()` in MyAdmin contexts.

## Instructions

1. **Obtain the DB instance.**
   The instance is `$this->db` (set in the constructor from the injected `Db $db` argument or `get_module_db('default')`). Verify `$this->db` is not null before issuing any query.

2. **Run a query.**
   ```php
   $this->db->query('SELECT column FROM `table` WHERE id = ' . intval($id), __LINE__, __FILE__);
   ```
   Verify the query string is complete and all user-supplied values are escaped or cast before proceeding.

3. **Iterate over multiple rows.**
   ```php
   $rows = [];
   while ($this->db->next_record(MYSQL_ASSOC)) {
       $rows[] = $this->db->Record;
   }
   ```
   `$this->db->Record` holds the current row as an associative array. Use this for every multi-row result.

4. **Fetch a single row.**
   ```php
   $this->db->query("SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = '{$this->table}' AND COLUMN_NAME = 'time'", __LINE__, __FILE__);
   $this->db->next_record(MYSQL_ASSOC);
   $rs = $this->db->Record;          // e.g. $rs['DATA_TYPE']
   ```
   Call `next_record()` exactly once; access fields via `$this->db->Record['column_name']`.

5. **Escape user input before interpolation.**
   ```php
   $safe = $this->db->real_escape($userInput);
   $this->db->query("INSERT INTO `{$this->table}` (col) VALUES ('" . $safe . "')", __LINE__, __FILE__);
   ```
   Numeric values must be cast with `intval()` / `floatval()` instead of `real_escape()`.

6. **Build INSERT with column/value arrays** (pattern used in `process()`):
   ```php
   $columns = [];
   $fields  = [];
   foreach ($data as $col => $val) {
       $columns[] = $col;
       if (is_null($val)) {
           $fields[] = 'NULL';
       } elseif (is_numeric($val)) {
           $fields[] = $val;
       } else {
           $fields[] = "'" . $this->db->real_escape($val) . "'";
       }
   }
   $this->db->query(
       'INSERT INTO `' . $this->table . '` (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $fields) . ')',
       __LINE__, __FILE__
   );
   ```

7. **Schema inspection / ALTER queries** follow the same pattern:
   ```php
   $this->db->query('DESCRIBE `' . $this->table . '`', __LINE__, __FILE__);
   while ($this->db->next_record(MYSQL_ASSOC)) {
       $actualFields[] = $this->db->Record['Field'];
   }
   $this->db->query('ALTER TABLE `' . $this->table . '` ADD `' . $col . '` TEXT NULL DEFAULT NULL;', __LINE__, __FILE__);
   ```

## Examples

**User says:** "Add a method that loads all rows from the log table where level equals a given value."

**Actions taken:**
```php
public function getByLevel(int $level): array
{
    $rows = [];
    $this->db->query(
        'SELECT * FROM `' . $this->table . '` WHERE level = ' . intval($level),
        __LINE__, __FILE__
    );
    while ($this->db->next_record(MYSQL_ASSOC)) {
        $rows[] = $this->db->Record;
    }
    return $rows;
}
```

**Result:** Uses `$this->db->query()` with `__LINE__, __FILE__`, iterates with `next_record(MYSQL_ASSOC)`, reads via `$this->db->Record`. No PDO, no raw `$_GET` interpolation.

## Common Issues

- **`Call to a member function query() on null`** — `$this->db` was not set. Verify the constructor received a non-null `Db` instance or that `get_module_db('default')` returned a valid object.
- **Missing `__LINE__, __FILE__` args cause silent failures in some Db versions** — the abstraction layer logs query errors using those values. Always include them; omitting them is a code-review failure.
- **`MYSQL_ASSOC` undefined** — ensure the db_abstraction package is installed (`composer install`) and autoloaded. The constant is defined by that package, not by PHP's native `mysqli_*` functions.
- **Stale `$this->db->Record` after loop** — `Record` is overwritten on each `next_record()` call. Copy values out of the loop: `$rows[] = $this->db->Record;` not `$rows[] = &$this->db->Record;`.
- **Test DB not available:** `SQLSTATE[HY000] [2002] Connection refused` — run `docker-compose up -d` and confirm `mysql -u root monolog_mysql_test` connects before running `phpunit --configuration phpunit_mysql.xml`.
