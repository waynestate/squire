---
title: "MySQL upgrade path"
description: "How to move an app's database from MySQL 5.6.51 to 8.4 LTS one series at a time: the checks to run before each hop, a backup you can trust, the driver and Laravel floor, a read-only pre-upgrade script, and a rollback for every hop, since MySQL cannot downgrade in place across series."
---

# MySQL upgrade path

The shared database is MySQL 5.6.51, the last 5.6 release (2021-01-05). Oracle moved 5.6 to Sustaining Support on 2021-02-01, so the server has had no fixes for nearly six years, and Laravel 13 does not support it (its floor is MySQL 5.7). This page is the path off it: three hops, 5.6 to 5.7 to 8.0 to 8.4 LTS, and for each one the check to run first, the backup to take, what your app's driver and framework need, and how to get back if the hop goes wrong.

It is written for a developer who has never upgraded MySQL. You will not run the server upgrade yourself; you will get your app and its data ready for it, rehearse it on a copy, and know your way back. A squire does not ride into the joust, but the knight goes nowhere without a squire who checked the straps.

## The path, and why it has three hops

MySQL supports upgrading one release series at a time. Its manual says an upgrade that skips a series is not supported, and names 5.6 straight to 8.0 as the example; 5.7 straight to 8.4 is not a supported step either ([8.0 upgrade paths](https://dev.mysql.com/doc/refman/8.0/en/upgrade-paths.html), [8.4 upgrade paths](https://dev.mysql.com/doc/refman/8.4/en/upgrade-paths.html)). So:

| Hop | From | To | Why this stop |
|---|---|---|---|
| 1 | 5.6.51 | 5.7 | The next series. Laravel 13's floor. |
| 2 | 5.7 | 8.0 | The next series. 8.0 left extended support on 2026-04-30, so it is a stepping stone, never a place to stay. |
| 3 | 8.0 | 8.4 LTS | The target. Premier support to 2029, extended support to 2032 ([endoflife.date](https://endoflife.date/mysql)). |

The path stops at 8.4. MySQL 9.7 became the next LTS on 2026-04-21, and the 8.4 manual lists "next LTS series" as a supported step, so 8.4 to 9.7 would be a fourth hop later. Whether the plan continues to 9.7 is an open decision, and this guide does not make it. Whether PHP's MySQL drivers and Laravel are tested against 9.7 has not been checked.

Whether one app can skip the hops by dumping its own database from 5.6 and loading it into a fresh 8.4 server has not been confirmed. That is an experiment, recorded [at the end of this page](#the-dump-to-fresh-server-experiment); until it has a result, plan on the three hops.

## Before you start

- [ ] You know which schemas on the server are your app's, and who else shares the server.
- [ ] You can make a copy: a scratch server you are allowed to break, where your dump can be restored. A Docker container on your machine is fine.
- [ ] Your app's test suite runs against a real MySQL, not only SQLite. SQLite passes code that MySQL rejects.
- [ ] You have read the hop you are preparing for, below, and the rollback for it.
- [ ] You have taken a backup **and restored it once** on the copy. A backup you have never restored is a hope, not a backup.
- [ ] You have run [the pre-upgrade script](#the-pre-upgrade-script) on that copy and worked through what it lists.

## Three tools you will use

**MySQL Shell's upgrade checker**, `util.checkForServerUpgrade()`, is the authority on what blocks an upgrade. Use the MySQL Shell of the version you are moving *to*: tried on empty test servers for this guide, Shell 8.4 refused to check a server at its own version or higher, and refused a 5.6 server outright ("this tool requires server to be at least at version 5.7"). So it helps from hop 2 on. The official `mysql:8.4` Docker image ships `mysqlsh`.

**`mysqlcheck --all-databases --check-upgrade`** is the table check the 8.0 prerequisites page names beside the Shell checker, and the one of the two that runs on a 5.6 source. The `mysql:5.6.51` image ships it; the `mysql:8.4` image does not.

**Squire's pre-upgrade script**, `scripts/mysql-preupgrade-checks.sql`, lists four things in your schema that the hops are known to trip on. It is a quick first look, not a replacement for the checker.

## The pre-upgrade script

[`scripts/mysql-preupgrade-checks.sql`](https://github.com/waynestate/squire/blob/main/scripts/mysql-preupgrade-checks.sql) is read-only: every statement is a `SELECT` over `information_schema`, or a `SET SESSION` that changes only its own connection. A test in Squire's suite fails if anything else creeps in. Run it on your copy anyway, never on the shared server: on a busy server those scans are slow, and the copy is where you fix what it finds.

Every check returns the same four columns, `check_name`, `schema_name`, `object_name` and `detail`. A check that finds nothing prints nothing.

| Check | What it lists | Matters for |
|---|---|---|
| `old_temporal_columns` | `TIME`, `DATETIME` and `TIMESTAMP` columns still in the pre-5.6.4 storage format. They stop an in-place upgrade to 8.0; `REPAIR TABLE` rebuilds them. | Hops 1 and 2 |
| `long_names_over_64` | Foreign key names and view column names that show 64 characters or more. 8.0 refuses names over 64. `information_schema` may cut a longer name to 64, so anything at 64 is listed too: confirm it with `SHOW CREATE TABLE` or `SHOW CREATE VIEW`. | Hop 2 |
| `charset_not_utf8mb4` | Tables whose collation is not `utf8mb4_unicode_ci`, Laravel's default. Not a blocker by itself, a decision to make on purpose (see hop 2). | Hop 2 |
| `rejected_by_8_4` | Foreign keys whose referenced columns are not exactly one whole `PRIMARY` or `UNIQUE` key (a plain index, part of a composite key, a prefix index), and `AUTO_INCREMENT` on `FLOAT` or `DOUBLE`. | Hop 3 |

Run it from the root of a Squire checkout with any MySQL client. `--force` matters: on 8.0 and later the last check's `SET` fails with "Unknown system variable 'show_old_temporals'" (that variable exists on 5.6 and 5.7 only), and `--force` lets the client carry on. Nothing is lost: an 8.0 server has no old temporal columns to find.

<!-- docs-test: skip needs a real server -->
```sh
# Any mysql client, from the root of a Squire checkout
mysql --force --table -h "$COPY_HOST" -P "$COPY_PORT" -u "$COPY_USER" -p < scripts/mysql-preupgrade-checks.sql

# No client installed: use the one in the mysql:8.4 image (Linux, macOS, or PowerShell)
docker run --rm -it -v "${PWD}/scripts:/checks:ro" mysql:8.4 \
  sh -c 'mysql --force --table -h "$0" -P "$1" -u "$2" -p < /checks/mysql-preupgrade-checks.sql' \
  "$COPY_HOST" "$COPY_PORT" "$COPY_USER"
```

In PowerShell, put the `docker run` on one line (or end each line with a backtick instead of `\`), and set `$COPY_HOST` and the others as PowerShell variables first. PowerShell has no `<` redirection, which is why the redirection runs inside the container.

For this guide the script ran clean against an empty 5.6.51 server and an empty 8.4.13 server, and the 8.4 client ran it against the 5.6 server. On a seeded test schema it listed a `latin1` table, a foreign key on a non-unique index, a foreign key on part of a composite primary key, an `AUTO_INCREMENT DOUBLE` column, and a 64-character foreign key name and view column, and it skipped the foreign keys that reference a whole key. The `old_temporal_columns` check could not be seeded, because 5.6.4 and later only create the new format; the `5.5 binary format` marker it looks for is not verified on a real old table.

## What a backup means here

For each hop, the backup is a **logical dump made with the source server's own client version**: dump a 5.6.51 server with the 5.6.51 `mysqldump`, a 5.7 server with the 5.7 one, and so on. A dump is plain SQL you can read, load into a fresh server, and keep beside the release that was live when you took it. The Docker image of the source version is the easy way to get the matching client:

<!-- docs-test: skip needs a real server -->
```sh
# Before hop 1: a 5.6.51 dump of your app's schema, written to the current folder
docker run --rm -it -v "${PWD}:/backup" mysql:5.6.51 \
  mysqldump --host="$SOURCE_HOST" --user="$SOURCE_USER" --password \
    --single-transaction --routines --triggers --events \
    --databases your_app --result-file=/backup/your_app-5.6.51.sql
```

That command ran against a 5.6.51 test server for this guide. `--single-transaction` gives a consistent snapshot of InnoDB tables without locking them, and `--routines`, `--triggers` and `--events` bring the stored code along; those descriptions come from mysqldump's own help, and whether your schema needs more (users and grants, for one, live in the `mysql` schema, which this dump leaves out) is not verified here. Name each file after the version that made it, so a rollback never loads the wrong one.

Then restore it once, on a fresh server of the **same** version, and run your app's tests against it. That is the rehearsal that makes it a backup.

## Rollback, for every hop

MySQL cannot go back in place. The 8.4 manual's downgrading page allows an in-place downgrade only within one LTS series (8.4.y to an earlier 8.4.x). Going back from 8.4 to 8.0 works only by logical dump and load or by replication, and is "only supported for rollback purposes" when no new server features have been applied to the data. Downgrading to 5.7 or earlier is not supported at all. So there is no "install the old version over the new one": every rollback below is one of two shapes.

1. **Restore the pre-hop dump to a fresh server of the old version.** Simple and always available. Anything written after the hop is not in that dump, so it is lost unless you export it separately; keep the window between the hop and the decision short.
2. **Keep a server one hop behind.** Leave the old-version server (or a replica of it) running, untouched, beside the new one, so going back is a switch of the app's `DB_HOST` and not a restore. It stops receiving writes at the hop. Replication from the new server back to the old one, which would carry those writes, is documented only for the 8.4 to 8.0 rollback; for the earlier hops it is not verified.

Decide which shape each hop uses before the window, write down who flips the switch, and rehearse it on the copy. A rollback nobody has tried is the same as no rollback.

## Hop 1: 5.6.51 to 5.7

The smallest hop for your code and the biggest for your safety: everything after it is a supported Laravel 13 database.

### Check first

- Run `mysqlcheck --all-databases --check-upgrade` with the 5.6.51 client against your copy. MySQL Shell's checker refuses a 5.6 source (see [the tools](#three-tools-you-will-use)).
- Run [the pre-upgrade script](#the-pre-upgrade-script). Rebuild any `old_temporal_columns` now; they block hop 2, and the copy is the place to time how long `REPAIR TABLE` takes on your biggest tables.
- Whether `mysql_upgrade` must run after an in-place 5.6 to 5.7 upgrade is not covered by this guide's sources and is not verified.

### Back up

A `mysqldump` from the 5.6.51 client, [as above](#what-a-backup-means-here), restored once on a fresh 5.6.51 server. Squire's [local parity](local-parity.md) kit already runs MySQL 5.6.51, so it can be that fresh server.

### Driver and Laravel floor

- **Laravel 13** lists MySQL 5.7 and later, and needs PHP 8.3 or later. After this hop your app is on a supported database for the first time.
- **PHP** connects to 5.6 with `mysql_native_password` (the accounts on a stock 5.6.51 server use it), which mysqlnd supports. What 5.7 accounts default to is not covered by this guide's sources.
- **TLS**: 5.6 servers older than 5.6.46 speak only TLS 1.0; 5.6.46 and later add TLS 1.1 and 1.2 on OpenSSL builds. Those facts come from search snippets of the 5.6 manual, so they are partly verified. If your PHP host enforces a TLS 1.2 floor, test the connection on each hop. Laravel only sets TLS options when `MYSQL_ATTR_SSL_CA` is set.
- **What you may start using after the hop** (and not before): JSON columns (5.7.8), generated columns (5.7.6), `renameIndex` (5.7). Keep `Schema::defaultStringLength(191)` in `AppServiceProvider::boot()` for now: a 5.6 server's defaults are `innodb_large_prefix` off and the Antelope file format (both flipped in 5.7.7) and DYNAMIC rows became the default only in 5.7.9, so tables created on 5.6 still have the 767-byte index limit. Whether an in-place upgrade changes their row format is not verified.

### Rollback

Restore the 5.6.51 dump to a fresh 5.6.51 server, or keep the 5.6.51 server running beside the 5.7 one ([the two shapes](#rollback-for-every-hop)). A 5.7 server cannot be turned back into 5.6 in place.

## Hop 2: 5.7 to 8.0

The hop with the most changes, and 8.0 is out of support, so plan hop 3 before you start this one.

### Check first

- Run MySQL Shell's `util.checkForServerUpgrade()` from Shell 8.0 or 8.4 against your 5.7 copy, and `mysqlcheck --all-databases --check-upgrade`. Then work through the [8.0 upgrade prerequisites](https://dev.mysql.com/doc/refman/8.0/en/upgrade-prerequisites.html).
- Run [the pre-upgrade script](#the-pre-upgrade-script). For this hop, `old_temporal_columns` and `long_names_over_64` are blockers; `charset_not_utf8mb4` is a decision.
- Other blockers the prerequisites page names: `ENUM` or `SET` elements over 255 characters, new reserved words, table names that clash with 8.0's data dictionary, partitioned tables on engines other than InnoDB, and obsolete SQL modes, which stop the server from starting. "Only upgrade a MySQL server instance that was properly shut down": a server that crashed needs a clean shutdown (`innodb_fast_shutdown=0`) first. Names over 64 characters could not even be created on 5.6.51 or 8.4 when tried for this guide, so on a 5.6 source `long_names_over_64` should find nothing new; it matters for objects created on 5.7.

### Character set and collation, on purpose

From 8.0 the server's default character set is `utf8mb4` and its default collation `utf8mb4_0900_ai_ci`. The new collations are NO PAD where the old ones are PAD SPACE, so trailing spaces start to matter in `VARCHAR` comparisons. A changed default does not alter existing columns, but converting a column can make two rows equal that were not (MySQL's own example is Örebro and Orebro) and fail with a duplicate-key error, can rebuild indexes under a table lock, and can hit the 3072-byte key limit ([migrating from older collations](https://dev.mysql.com/blog-archive/mysql-8-0-collations-migrating-from-older-collations/)).

So before this hop: record every table's character set and collation (the script's `charset_not_utf8mb4` lists the ones that are not Laravel's default; a stock 5.6.51 server defaults to `latin1`, so tables made without a charset are `latin1`), set `charset` and `collation` explicitly on the app's connection in `config/database.php` and in its migrations, and run MySQL's duplicate-key check before converting anything. Never use the 8.0-only `utf8mb4_0900_*` collations while any server in the path is older than 8.0.

### Back up

A `mysqldump` from the 5.7 client, restored once on a fresh 5.7 server.

### Driver and Laravel floor

- **Authentication**: `caching_sha2_password` is the 8.0 server default. PHP supports it fully from 7.4.4, so PHP 8.3 to 8.5 connects. Connect each app to an 8.0 test server before the move anyway.
- **Laravel 13** keeps working, and some of its paths get better: the database queue uses `FOR UPDATE SKIP LOCKED` on 8.0.1 and later, so parallel workers stop contending. Window functions and CTEs in raw SQL, descending indexes and enforced `CHECK` constraints (8.0.16) arrive here. Do not use any of them until every server your app can land on is 8.0 or later.
- **Tests**: run your suite against 5.7 and 8.0 during the transition. The shared server moves every app at once, so apps must be ready before the move, not after.

### Rollback

Restore the 5.7 dump to a fresh 5.7 server, or keep the 5.7 server running beside the 8.0 one. MySQL does not support downgrading to 5.7 or earlier, and loading an 8.0 dump into 5.7 is not verified.

## Hop 3: 8.0 to 8.4 LTS

The last hop, onto a series with years of fixes ahead of it.

### Check first

- Run MySQL Shell 8.4's `util.checkForServerUpgrade()` against your 8.0 copy (the `mysql:8.4` image has it).
- Run [the pre-upgrade script](#the-pre-upgrade-script) and fix everything `rejected_by_8_4` lists. 8.4 rejects foreign keys on non-unique or partial keys unless `restrict_fk_on_non_standard_key` is `OFF` (it is `ON` on a stock 8.4.13 server), and an `AUTO_INCREMENT` column on `FLOAT` or `DOUBLE` makes the upgrade fail (a stock 8.4.13 server also refuses to create one).
- Search your app for the rest of what 8.4 changes ([what is new in 8.4](https://dev.mysql.com/doc/refman/8.4/en/mysql-nutshell.html)):
  - accounts that use `mysql_native_password`, which 8.4 turns off by default (a stock 8.4.13 server shows the plugin `DISABLED`). On the 8.0 copy, `SELECT user, host FROM mysql.user WHERE plugin = 'mysql_native_password'` lists them;
  - `default_authentication_plugin` in any server config: the option is removed, and a config that sets it fails to start;
  - `MANUAL`, `PARALLEL` and `QUALIFY` used as unquoted names;
  - old replication statements such as `START SLAVE`, which are removed.
- Several InnoDB defaults change, including `innodb_adaptive_hash_index` to `OFF` and `innodb_flush_method` to `O_DIRECT` (both confirmed on a stock 8.4.13 server). They affect performance: time your heaviest pages and jobs on the copy.

### Back up

A `mysqldump` from the 8.0 client, restored once on a fresh 8.0 server.

### Driver and Laravel floor

- **Authentication**: connect each app to an 8.4 test server before the move. A client that can only use `mysql_native_password` fails to connect. PHP 8.3 to 8.5 supports `caching_sha2_password`, so the PHP side is ready; the account on the server must use it too.
- **Laravel 13**: no floor change; it supports 5.7 and later. PHP 8.5 deprecates the `PDO::MYSQL_ATTR_*` constants for `Pdo\Mysql::ATTR_*`, and Laravel 13's config already handles both.

### Rollback

This is the one hop with a documented way back other than a restore. Going from 8.4 to 8.0 works by logical dump and load or by replication, "only supported for rollback purposes" and only while no new 8.4 server features have been applied to the data. So: restore the 8.0 dump to a fresh 8.0 server, or keep an 8.0 server one hop behind, fed by replication from 8.4, until you are sure. Do not adopt 8.4-only features until the rollback window has closed.

## After 8.4

Stay on 8.4 and take its patch releases (the latest was 8.4.13, 2026-09-30); an in-place move within the 8.4 series, up or down, is supported. Whether to continue to 9.7 LTS is an open decision (see [the path](#the-path-and-why-it-has-three-hops)).

## The dump-to-fresh-server experiment

The open question: can one app dump its own schema from 5.6.51 (with the 5.6.51 client) and load it straight into a fresh 8.4 server, skipping the hops for that app's data? MySQL documents the series-by-series path for upgrading a server, and this shortcut is not confirmed. The experiment loads a real app's dump into a fresh 8.4 server, runs its migrations and test suite against it, and records what failed. Until it has a result here, follow the three hops.

### Result

<!-- Not run yet. The result of the 5.6.51 dump into a fresh 8.4 server goes here. -->

## Not verified

These statements on this page are not backed by the sources below or by a run for this guide:

- Whether `mysql_upgrade` must run after the 5.6 to 5.7 in-place upgrade.
- Whether an in-place 5.6 to 5.7 upgrade changes the row format of existing tables.
- Replication from a newer server back to an older one for hops 1 and 2.
- Whether the mysqldump options shown are all a schema needs (users and grants are left out).
- The `5.5 binary format` marker on a real pre-5.6.4 table.
- The 5.6 TLS versions (from search snippets of the manual only).
- PHP driver and Laravel support for MySQL 9.7.
- The dump-to-fresh-server shortcut.

## Sources

- MySQL: [8.0 upgrade paths](https://dev.mysql.com/doc/refman/8.0/en/upgrade-paths.html), [8.4 upgrade paths](https://dev.mysql.com/doc/refman/8.4/en/upgrade-paths.html), [8.4 upgrading](https://dev.mysql.com/doc/refman/8.4/en/upgrading.html), [8.0 upgrade prerequisites](https://dev.mysql.com/doc/refman/8.0/en/upgrade-prerequisites.html), [what is new in 8.4](https://dev.mysql.com/doc/refman/8.4/en/mysql-nutshell.html), [what is new in 8.0](https://dev.mysql.com/doc/refman/8.0/en/mysql-nutshell.html), [migrating from older collations](https://dev.mysql.com/blog-archive/mysql-8-0-collations-migrating-from-older-collations/), [5.7.7 release notes](https://dev.mysql.com/doc/relnotes/mysql/5.7/en/news-5-7-7.html), [5.7.9 release notes](https://dev.mysql.com/doc/relnotes/mysql/5.7/en/news-5-7-9.html), [EOL notices](https://www.mysql.com/support/eol-notice.html).
- Support dates: [endoflife.date MySQL](https://endoflife.date/mysql).
- Laravel 13: [database](https://laravel.com/docs/13.x/database), [release notes](https://laravel.com/docs/13.x/releases).
- PHP: [MySQL 8 and `caching_sha2_password`](https://www.php.net/manual/en/mysqli.requirements.php), [PDO MySQL](https://www.php.net/manual/en/ref.pdo-mysql.php).
- Runs for this guide: throwaway `mysql:5.6.51` and `mysql:8.4` (8.4.13, MySQL Shell 8.4.10) containers, empty and with a small seeded schema, on 2026-11-09. No real server was touched.
