-- Squire: MySQL pre-upgrade checks.
--
-- READ-ONLY. Every statement is a SELECT over information_schema or a SET SESSION
-- that changes this connection only. Nothing here writes, locks or alters data.
-- Run it on a copy of the data (a dump restored on a scratch server), never on the
-- shared server itself: information_schema scans can be slow on a busy server, and a
-- copy is where you are free to fix what it finds.
--
-- How to run it, from the root of a Squire checkout (the guide has the details):
--
--   mysql --force --table -h "$COPY_HOST" -P "$COPY_PORT" -u "$COPY_USER" -p < scripts/mysql-preupgrade-checks.sql
--
-- Every check returns the same four columns: check_name, schema_name, object_name,
-- detail. A check with nothing to report prints no table at all. The four checks:
--
--   long_names_over_64     foreign key names and view column names of 64 characters
--                          or more; the 8.0 upgrade refuses names over 64
--   charset_not_utf8mb4    tables whose collation is not utf8mb4_unicode_ci (Laravel's
--                          default); decide each one on purpose before the 8.0 hop
--   rejected_by_8_4        foreign keys that do not reference a whole unique key, and
--                          AUTO_INCREMENT on FLOAT or DOUBLE; 8.4 rejects both
--   old_temporal_columns   TIME, DATETIME and TIMESTAMP columns still in the pre-5.6.4
--                          format; they stop an in-place upgrade to 8.0
--
-- This is a quick first look, not the authority. Run MySQL Shell's upgrade checker
-- (util.checkForServerUpgrade) as well; it knows far more than four checks.
--
-- The old-temporal check runs last on purpose. It needs show_old_temporals, which
-- exists on 5.6 and 5.7 only, so on 8.0 and later that SET fails with "Unknown system
-- variable" and --force carries on. Nothing is lost: 8.0 refuses old temporal
-- columns at upgrade, so an 8.0 or 8.4 server has none to find.
--
-- A squire checks every strap and buckle before the knight mounts. These are four of
-- the straps.

-- Long lists of column names below are joined with GROUP_CONCAT; give it room.
SET SESSION group_concat_max_len = 65535;

-- long_names_over_64
-- information_schema may cut a longer name to 64 characters, so a name shown at
-- exactly 64 is listed too. Confirm each one with SHOW CREATE TABLE.
SELECT 'long_names_over_64' AS check_name,
       n.schema_name AS schema_name,
       n.object_name AS object_name,
       n.detail AS detail
  FROM (
        SELECT rc.CONSTRAINT_SCHEMA AS schema_name,
               CONCAT(rc.TABLE_NAME, '.', rc.CONSTRAINT_NAME) AS object_name,
               CONCAT('foreign key name shows ', CHAR_LENGTH(rc.CONSTRAINT_NAME),
                      ' characters; 8.0 allows 64, confirm with SHOW CREATE TABLE') AS detail
          FROM information_schema.REFERENTIAL_CONSTRAINTS AS rc
         WHERE CHAR_LENGTH(rc.CONSTRAINT_NAME) >= 64
        UNION ALL
        SELECT c.TABLE_SCHEMA,
               CONCAT(c.TABLE_NAME, '.', c.COLUMN_NAME),
               CONCAT('view column name shows ', CHAR_LENGTH(c.COLUMN_NAME),
                      ' characters; 8.0 allows 64, confirm with SHOW CREATE VIEW')
          FROM information_schema.COLUMNS AS c
          JOIN information_schema.VIEWS AS v
            ON v.TABLE_SCHEMA = c.TABLE_SCHEMA AND v.TABLE_NAME = c.TABLE_NAME
         WHERE CHAR_LENGTH(c.COLUMN_NAME) >= 64
       ) AS n
 ORDER BY schema_name, object_name;

-- charset_not_utf8mb4
-- Not a blocker by itself. From 8.0 the server default collation is
-- utf8mb4_0900_ai_ci (NO PAD), so record what each table uses and set it on purpose.
SELECT 'charset_not_utf8mb4' AS check_name,
       t.TABLE_SCHEMA AS schema_name,
       t.TABLE_NAME AS object_name,
       CONCAT('table collation is ', COALESCE(t.TABLE_COLLATION, 'unknown'),
              ', not utf8mb4_unicode_ci') AS detail
  FROM information_schema.TABLES AS t
 WHERE t.TABLE_TYPE = 'BASE TABLE'
   AND t.TABLE_SCHEMA NOT IN ('mysql', 'information_schema', 'performance_schema', 'sys')
   AND COALESCE(t.TABLE_COLLATION, '') <> 'utf8mb4_unicode_ci'
 ORDER BY schema_name, object_name;

-- rejected_by_8_4
-- A foreign key passes when its referenced columns, in order, are exactly the
-- columns of one PRIMARY or UNIQUE index on the referenced table, with no prefix
-- lengths. Anything else (a plain index, part of a composite key, a prefix index)
-- is listed. AUTO_INCREMENT on FLOAT or DOUBLE is listed too.
SELECT 'rejected_by_8_4' AS check_name,
       r.schema_name AS schema_name,
       r.object_name AS object_name,
       r.detail AS detail
  FROM (
        SELECT fk.schema_name AS schema_name,
               CONCAT(fk.table_name, '.', fk.constraint_name) AS object_name,
               CONCAT('foreign key references ', fk.ref_schema, '.', fk.ref_table,
                      ' (', fk.ref_columns, '), which is not a whole PRIMARY or UNIQUE key') AS detail
          FROM (
                SELECT k.CONSTRAINT_SCHEMA AS schema_name,
                       k.TABLE_NAME AS table_name,
                       k.CONSTRAINT_NAME AS constraint_name,
                       k.REFERENCED_TABLE_SCHEMA AS ref_schema,
                       k.REFERENCED_TABLE_NAME AS ref_table,
                       GROUP_CONCAT(k.REFERENCED_COLUMN_NAME ORDER BY k.ORDINAL_POSITION SEPARATOR ',') AS ref_columns
                  FROM information_schema.KEY_COLUMN_USAGE AS k
                 WHERE k.REFERENCED_TABLE_NAME IS NOT NULL
                 GROUP BY k.CONSTRAINT_SCHEMA, k.TABLE_NAME, k.CONSTRAINT_NAME,
                          k.REFERENCED_TABLE_SCHEMA, k.REFERENCED_TABLE_NAME
               ) AS fk
          LEFT JOIN (
                SELECT s.TABLE_SCHEMA AS table_schema,
                       s.TABLE_NAME AS table_name,
                       s.INDEX_NAME AS index_name,
                       GROUP_CONCAT(s.COLUMN_NAME ORDER BY s.SEQ_IN_INDEX SEPARATOR ',') AS key_columns,
                       MAX(s.SUB_PART) AS longest_prefix
                  FROM information_schema.STATISTICS AS s
                 WHERE s.NON_UNIQUE = 0
                 GROUP BY s.TABLE_SCHEMA, s.TABLE_NAME, s.INDEX_NAME
               ) AS uk
            ON uk.table_schema = fk.ref_schema
           AND uk.table_name = fk.ref_table
           AND uk.key_columns = fk.ref_columns
           AND uk.longest_prefix IS NULL
         WHERE uk.index_name IS NULL
        UNION ALL
        SELECT c.TABLE_SCHEMA,
               CONCAT(c.TABLE_NAME, '.', c.COLUMN_NAME),
               CONCAT('AUTO_INCREMENT on ', UPPER(c.DATA_TYPE),
                      '; the 8.4 upgrade fails on it, use an integer type')
          FROM information_schema.COLUMNS AS c
         WHERE c.EXTRA LIKE '%auto_increment%'
           AND c.DATA_TYPE IN ('float', 'double')
           AND c.TABLE_SCHEMA NOT IN ('mysql', 'information_schema', 'performance_schema', 'sys')
       ) AS r
 ORDER BY schema_name, object_name;

-- old_temporal_columns
-- With show_old_temporals on, 5.6 and 5.7 mark an old-format column's type with
-- "5.5 binary format". REPAIR TABLE (or a dump and reload) rebuilds it; do that on
-- the copy first and time it.
SET SESSION show_old_temporals = ON;

SELECT 'old_temporal_columns' AS check_name,
       c.TABLE_SCHEMA AS schema_name,
       CONCAT(c.TABLE_NAME, '.', c.COLUMN_NAME) AS object_name,
       CONCAT(c.COLUMN_TYPE, ' is in the pre-5.6.4 format; rebuild the table before the 8.0 hop') AS detail
  FROM information_schema.COLUMNS AS c
 WHERE c.DATA_TYPE IN ('time', 'datetime', 'timestamp')
   AND c.COLUMN_TYPE LIKE '%5.5 binary format%'
   AND c.TABLE_SCHEMA NOT IN ('mysql', 'information_schema', 'performance_schema', 'sys')
 ORDER BY schema_name, object_name;
