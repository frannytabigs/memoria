<?php

// Database Backup Endpoint
// GET  List all available backup files
// GET  /backup/{filename}  Download a specific backup file
// POST Create a new backup
// PUT  /backup/{filename}  Restore the database from the filename
//
// PostgreSQL notes:
//  - Restore requires superuser (or a role with SET on session_replication_role,
//    e.g. rds_superuser on RDS / azure_pg_admin on Azure) because Postgres has no
//    non-superuser way to defer foreign-key checks during a bulk load.
//  - Identity columns (GENERATED ALWAYS AS IDENTITY) are exported with
//    OVERRIDING SYSTEM VALUE so their IDs are preserved.
//  - After restore, every sequence is reset to MAX(col) of its owning table.
//    Without this, new inserts would collide with restored IDs.
//  - Manual restore via psql should be run with --single-transaction:
//        psql --single-transaction -h host -U user -d db -f backup.sql

define('ITS_ME_JUSTTOVERIFY', true);

require_once 'checkuser.php';
require_once 'logger.php';

const BACKUP_LIMIT       = 5;
const BACKUP_FILE_REGEX  = '/^[A-Za-z0-9._-]+\.sql$/';
const BACKUP_NAME_PREFIX = 'memoria_db_';

$userData = checkuser();

if (($userData['role'] ?? null) !== ROLE_ADMIN) {
    systemLog("{$userData['name']} ({$userData['username']}) attempted to backup or something here lol", $userData['user_id']);
    Response::error('Forbidden. Insufficient privileges.', 403);
}

if (defined('DB_TIMEZONE') && DB_TIMEZONE !== '' && strtoupper((string) DB_TIMEZONE) !== 'SYSTEM') {
    $tzValue = (string) DB_TIMEZONE;

    if (preg_match('/^([+-])(\d{2}):(\d{2})$/', $tzValue, $m)) {
        $offsetSeconds = ((int) $m[2] * 3600 + (int) $m[3] * 60) * ($m[1] === '-' ? -1 : 1);
        $name = timezone_name_from_abbr('', $offsetSeconds, 0);
        $tzValue = ($name !== false) ? $name : null;
    }

    if ($tzValue !== null && !@date_default_timezone_set($tzValue)) {
        error_log('backup.php: DB_TIMEZONE "' . DB_TIMEZONE . '" is not a valid PHP timezone.');
    }
}

@set_time_limit(0);

$backupDir = __DIR__ . '/backups/';

if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
    Response::error('Unable to create the backup directory.', 500);
}

$backupDir = rtrim((string) realpath($backupDir), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

/**
 * Return the DB session's timezone as a PHP DateTimeZone.
 *
 * PostgreSQL: SHOW timezone returns either a name (UTC, Asia/Manila) or an
 * offset (+08, -05, +08:00). PHP's DateTimeZone accepts names directly; for
 * raw offsets we translate via timezone_name_from_abbr().
 */
function dbTimezone(PDO $pdo): DateTimeZone
{
    static $tz = null;
    if ($tz instanceof DateTimeZone) {
        return $tz;
    }

    try {
        $sessionTz = (string) $pdo->query('SHOW timezone')->fetchColumn();

        if ($sessionTz !== '') {
            // Offset form: +08, -05, +08:00, -05:30
            if (preg_match('/^([+-])(\d{1,2})(?::(\d{2}))?$/', $sessionTz, $m)) {
                $hours   = (int) $m[2];
                $minutes = (int) ($m[3] ?? 0);
                $offsetSeconds = ($hours * 3600 + $minutes * 60) * ($m[1] === '-' ? -1 : 1);

                $name = timezone_name_from_abbr('', $offsetSeconds, 0);
                if ($name !== false) {
                    try {
                        return $tz = new DateTimeZone($name);
                    } catch (Throwable $e) {
                    }
                }
            }

            // Named form: UTC, Asia/Manila, Europe/London, ...
            try {
                return $tz = new DateTimeZone($sessionTz);
            } catch (Throwable $e) {
            }
        }
    } catch (Throwable $e) {
    }

    return $tz = new DateTimeZone('UTC');
}

function parseBackupTimestamp(string $filename): ?string
{
    $pattern = '/^' . preg_quote(BACKUP_NAME_PREFIX, '/')
        . '(\d{4}-\d{2}-\d{2})_(\d{2})-(\d{2})-(\d{2})\.sql$/';

    if (!preg_match($pattern, $filename, $m)) {
        return null;
    }

    return "{$m[1]} {$m[2]}:{$m[3]}:{$m[4]}";
}

function resolveBackupPath(string $name, string $backupDir): ?string
{
    $name = basename(trim($name));

    if ($name === '' || !preg_match(BACKUP_FILE_REGEX, $name)) {
        return null;
    }

    $real = realpath($backupDir . $name);

    if ($real === false || !is_file($real)) {
        return null;
    }

    if (strncmp($real, $backupDir, strlen($backupDir)) !== 0) {
        return null;
    }

    return $real;
}

function sqlLiteral(PDO $pdo, mixed $value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_bool($value)) {
        // PostgreSQL: TRUE / FALSE (MySQL used 1 / 0)
        return $value ? 'TRUE' : 'FALSE';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    return $pdo->quote((string) $value);
}

function sqlIdentifier(string $identifier): string
{
    // PostgreSQL identifier quoting: "col" with embedded " doubled.
    return '"' . str_replace('"', '""', $identifier) . '"';
}

function acquireOperationLock(string $backupDir)
{
    $lockPath = $backupDir . '.operation.lock';
    $fp = fopen($lockPath, 'c+');
    if (!$fp) {
        return null;
    }

    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return null;    // someone else holds it
    }

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode([
        'pid' => getmypid(),
        'at'  => date('c'),
    ]));
    fflush($fp);

    return $fp;
}

function releaseOperationLock($fp): void
{
    if (!is_resource($fp)) {
        return;
    }
    flock($fp, LOCK_UN);
    fclose($fp);
}

/**
 * Return the columns in $table that have a foreign key pointing back to
 * $table itself. For the current schema, this is ['created_by','updated_by']
 * for `users`, and [] for every other table.
 *
 * Uses pg_catalog (more reliable than information_schema for composite
 * multi-column FKs).
 */
function getSelfReferencingForeignKeys(PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare("
        SELECT a.attname AS column_name
        FROM pg_constraint con
        JOIN pg_class     rel   ON rel.oid  = con.conrelid
        JOIN pg_class     frel  ON frel.oid = con.confrelid
        JOIN pg_namespace n     ON n.oid    = rel.relnamespace
        JOIN unnest(con.conkey) AS col(attnum) ON true
        JOIN pg_attribute a     ON a.attrelid = rel.oid AND a.attnum = col.attnum
        WHERE con.contype = 'f'
          AND n.nspname = current_schema()
          AND rel.relname  = :t
          AND frel.relname = :t2
        ORDER BY array_position(con.conkey, col.attnum)
    ");
    $stmt->execute([':t' => $table, ':t2' => $table]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

/**
 * Return the primary-key columns of $table in declared order.
 * Used by the two-pass restore path to emit the phase-2 UPDATEs.
 */
function getPrimaryKeyColumns(PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare("
        SELECT a.attname AS column_name
        FROM pg_constraint con
        JOIN pg_class     rel ON rel.oid = con.conrelid
        JOIN pg_namespace n   ON n.oid   = rel.relnamespace
        JOIN unnest(con.conkey) AS col(attnum) ON true
        JOIN pg_attribute a   ON a.attrelid = rel.oid AND a.attnum = col.attnum
        WHERE con.contype = 'p'
          AND n.nspname = current_schema()
          AND rel.relname = :t
        ORDER BY array_position(con.conkey, col.attnum)
    ");
    $stmt->execute([':t' => $table]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

/**
 * Emit a single INSERT statement for one row, honouring generated columns
 * (already stripped from $row by the caller) and identity-always tables.
 */
function buildInsertStatement(string $quotedTable, array $row, string $overriding, PDO $pdo): string
{
    $columns = implode(', ', array_map(
        static fn(string $c): string => sqlIdentifier($c),
        array_keys($row)
    ));
    $values = implode(', ', array_map(
        static fn(mixed $v): string => sqlLiteral($pdo, $v),
        array_values($row)
    ));
    return "INSERT INTO {$quotedTable} ({$columns}){$overriding} VALUES ({$values});\n";
}

function createBackup(PDO $pdo, string $backupDir): string
{
    // PostgreSQL: TO_CHAR replaces DATE_FORMAT (%i -> MI, %H -> HH24)
    $stamp     = (string) $pdo->query("SELECT TO_CHAR(NOW(), 'YYYY-MM-DD_HH24-MI-SS')")->fetchColumn();
    $filename  = BACKUP_NAME_PREFIX . $stamp . '.sql';
    $tmpPath   = $backupDir . $filename . '.tmp';
    $finalPath = $backupDir . $filename;

    $handle = fopen($tmpPath, 'wb');
    if ($handle === false) {
        throw new RuntimeException('Unable to open the backup file for writing.');
    }

    try {
        $inTx = $pdo->inTransaction();
        if (!$inTx) {
            $pdo->exec("BEGIN ISOLATION LEVEL REPEATABLE READ");
        }

        try {
            $sessionTz  = (string) $pdo->query('SHOW timezone')->fetchColumn();
            $headerTime = (new DateTimeImmutable('now', dbTimezone($pdo)))->format('Y-m-d H:i:s');

            fwrite($handle, "-- Data-only backup generated {$headerTime} (timezone: {$sessionTz})\n");
            fwrite($handle, "-- Restore order respects foreign-key dependencies.\n");
            fwrite($handle, "-- Self-referencing FKs (e.g. users.created_by / users.updated_by)\n");
            fwrite($handle, "-- are inserted with NULL first, then UPDATEd back into place.\n");
            fwrite($handle, "-- No session_replication_role is used.\n");
            fwrite($handle, "SET timezone = " . $pdo->quote($sessionTz) . ";\n\n");

            // ── Table dependency order ──────────────────────────────────────
            // Parent tables MUST come before child tables so INSERTs satisfy
            // foreign keys without needing to disable constraints.
            $orderedTables = [
                'users',
                'blocks',
                'graves',
                'interments',
                'reservations',
                'placement_history',
                'settings',
                'payments',
            ];

            $existingTables = $pdo->query("
                SELECT table_name
                FROM information_schema.tables
                WHERE table_schema = current_schema()
                  AND table_type = 'BASE TABLE'
            ")->fetchAll(PDO::FETCH_COLUMN) ?: [];

            $existingSet = array_flip($existingTables);
            $tables = array_values(array_filter(
                $orderedTables,
                static fn(string $t): bool => isset($existingSet[$t])
            ));

            $extraTables = array_values(array_diff($existingTables, $orderedTables));
            sort($extraTables);
            $tables = array_merge($tables, $extraTables);

            // Which tables have GENERATED ALWAYS AS IDENTITY columns
            $alwaysIdent = [];
            $aiStmt = $pdo->query("
                SELECT DISTINCT table_name
                FROM information_schema.columns
                WHERE table_schema = current_schema()
                  AND is_identity = 'YES'
                  AND identity_generation = 'ALWAYS'
            ");
            foreach ($aiStmt->fetchAll(PDO::FETCH_COLUMN) as $t) {
                $alwaysIdent[$t] = true;
            }

            // Computed columns (GENERATED ALWAYS AS (...) STORED) must be skipped
            $genStmt = $pdo->prepare("
                SELECT column_name
                FROM information_schema.columns
                WHERE table_schema = current_schema()
                  AND table_name = :table
                  AND is_generated = 'ALWAYS'
            ");

            // One-shot TRUNCATE. CASCADE covers FK dependencies; RESTART IDENTITY
            // resets all sequences so the DO block below can set them to MAX after.
            if (!empty($tables)) {
                $tableList = implode(', ', array_map('sqlIdentifier', $tables));
                fwrite($handle, "TRUNCATE TABLE {$tableList} RESTART IDENTITY CASCADE;\n\n");
            }

            foreach ($tables as $table) {
                $quotedTable = sqlIdentifier((string) $table);

                $genStmt->execute([':table' => $table]);
                $generated = $genStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
                $skip      = array_flip($generated);

                $overriding = isset($alwaysIdent[$table])
                    ? ' OVERRIDING SYSTEM VALUE'
                    : '';

                $selfFks = getSelfReferencingForeignKeys($pdo, $table);

                if (empty($selfFks)) {
                    // ── Simple pass: no self-references, stream and insert ──
                    $stmt = $pdo->query("SELECT * FROM {$quotedTable}");

                    while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                        $row = array_filter(
                            $row,
                            static fn(string $col): bool => !isset($skip[$col]),
                            ARRAY_FILTER_USE_KEY
                        );

                        if ($row === []) {
                            continue;
                        }

                        fwrite($handle, buildInsertStatement($quotedTable, $row, $overriding, $pdo));
                    }
                } else {
                    // ── Two-pass: table has self-referencing FKs ────────────
                    // (1) INSERT every row with self-FK columns NULLed out.
                    // (2) UPDATE those columns back to their original values.
                    // By the time step (2) runs, every referenced row exists,
                    // so the FK is satisfiable — even for mutual references.
                    $pkCols = getPrimaryKeyColumns($pdo, $table);
                    if (empty($pkCols)) {
                        throw new RuntimeException(
                            "Cannot restore '$table': it has self-referencing FKs but no primary key."
                        );
                    }

                    $rows = $pdo->query("SELECT * FROM {$quotedTable}")->fetchAll(PDO::FETCH_ASSOC);

                    $pendingUpdates = [];

                    // Phase 1: INSERT with NULL self-FKs
                    foreach ($rows as $row) {
                        $originalSelfFks = [];
                        foreach ($selfFks as $col) {
                            $originalSelfFks[$col] = $row[$col] ?? null;
                            $row[$col] = null;
                        }

                        $row = array_filter(
                            $row,
                            static fn(string $col): bool => !isset($skip[$col]),
                            ARRAY_FILTER_USE_KEY
                        );

                        if ($row === []) {
                            continue;
                        }

                        fwrite($handle, buildInsertStatement($quotedTable, $row, $overriding, $pdo));

                        // Remember the PK + the FK values we NULLed so phase 2
                        // can restore them.
                        $pkValues = [];
                        foreach ($pkCols as $pkCol) {
                            $pkValues[$pkCol] = $row[$pkCol];
                        }
                        $pendingUpdates[] = ['pk' => $pkValues, 'fks' => $originalSelfFks];
                    }

                    // Phase 2: UPDATE the self-FKs back into place
                    foreach ($pendingUpdates as $upd) {
                        $setParts = [];
                        foreach ($upd['fks'] as $col => $val) {
                            if ($val === null) {
                                continue;   // Was NULL originally — nothing to restore
                            }
                            $setParts[] = sqlIdentifier($col) . ' = ' . sqlLiteral($pdo, $val);
                        }
                        if (empty($setParts)) {
                            continue;
                        }

                        $whereParts = [];
                        foreach ($upd['pk'] as $col => $val) {
                            $whereParts[] = sqlIdentifier($col) . ' = ' . sqlLiteral($pdo, $val);
                        }

                        fwrite(
                            $handle,
                            "UPDATE {$quotedTable} SET " . implode(', ', $setParts)
                                . " WHERE " . implode(' AND ', $whereParts) . ";\n"
                        );
                    }
                }

                fwrite($handle, "\n");
            }

            // Reset every sequence to the MAX of its owning column.
            fwrite($handle, <<<'SQL'

DO $reset_seq$
DECLARE
    r RECORD;
    max_val BIGINT;
BEGIN
    FOR r IN
        SELECT
            c.relname::text AS tbl,
            a.attname::text AS col
        FROM pg_attribute a
        JOIN pg_class     c ON c.oid = a.attrelid
        JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE a.attnum > 0
          AND NOT a.attisdropped
          AND c.relkind = 'r'
          AND n.nspname = current_schema()
          AND pg_get_serial_sequence(
                  quote_ident(n.nspname) || '.' || quote_ident(c.relname),
                  a.attname
              ) IS NOT NULL
    LOOP
        EXECUTE format(
            'SELECT COALESCE(MAX(%I), 0) FROM %I.%I',
            r.col, current_schema(), r.tbl
        ) INTO max_val;

        PERFORM setval(
            pg_get_serial_sequence(
                quote_ident(current_schema()) || '.' || quote_ident(r.tbl),
                r.col
            ),
            GREATEST(max_val, 1),
            max_val > 0
        );
    END LOOP;
END $reset_seq$;

SQL);
        } finally {
            if (!$inTx) {
                try {
                    $pdo->exec("COMMIT");
                } catch (Throwable $e) {
                }
            }
        }
    } finally {
        fclose($handle);
    }

    if (!rename($tmpPath, $finalPath)) {
        @unlink($tmpPath);
        throw new RuntimeException('Unable to finalise the backup file.');
    }

    return $filename;
}

function pruneBackups(string $backupDir, ?string $keep = null): array
{
    $files = glob($backupDir . '*.sql') ?: [];

    usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));

    $deleted = [];
    $kept    = 0;

    foreach ($files as $file) {
        // Never delete the file we are currently restoring from.
        if ($keep !== null && basename($file) === $keep) {
            continue;
        }

        if ($kept < BACKUP_LIMIT) {
            $kept++;
            continue;
        }

        if (is_file($file) && @unlink($file)) {
            $deleted[] = basename($file);
        }
    }

    return $deleted;
}

function splitSqlStatements(string $sql): array
{
    $statements = [];
    $buffer     = '';
    $length     = strlen($sql);

    $inSingle = $inDouble = $inBacktick = false;
    $inLineComment = $inBlockComment = false;

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $sql[$i + 1] ?? '';

        // Dollar-quoted strings: $tag$ ... $tag$  (PostgreSQL functions / DO blocks)
        if (
            !$inSingle && !$inDouble && !$inBacktick
            && !$inLineComment && !$inBlockComment && $char === '$'
        ) {
            if (preg_match('/\$([A-Za-z_][A-Za-z0-9_]*)?\$/', substr($sql, $i), $m)) {
                $tag = $m[0];
                $endPos = strpos($sql, $tag, $i + strlen($tag));
                if ($endPos !== false) {
                    $buffer .= substr($sql, $i, $endPos + strlen($tag) - $i);
                    $i = $endPos + strlen($tag) - 1;
                    continue;
                }
            }
        }

        if ($inLineComment) {
            if ($char === "\n") {
                $inLineComment = false;
                $buffer .= $char;
            }
            continue;
        }

        if ($inBlockComment) {
            if ($char === '*' && $next === '/') {
                $inBlockComment = false;
                $i++;
            }
            continue;
        }

        if ($inSingle || $inDouble || $inBacktick) {
            $buffer .= $char;

            if ($char === '\\' && !$inBacktick && $next !== '') {
                $buffer .= $next;
                $i++;
                continue;
            }

            $closing = $inSingle ? "'" : ($inDouble ? '"' : '`');

            if ($char === $closing) {
                if ($next === $closing) {
                    $buffer .= $next;
                    $i++;
                    continue;
                }
                $inSingle = $inDouble = $inBacktick = false;
            }
            continue;
        }

        if ($char === '-' && $next === '-') {
            $after = $sql[$i + 2] ?? ' ';
            if ($after === ' ' || $after === "\t" || $after === "\n") {
                $inLineComment = true;
                $i++;
                continue;
            }
        }

        if ($char === '#') {
            $inLineComment = true;
            continue;
        }

        if ($char === '/' && $next === '*') {
            $inBlockComment = true;
            $i++;
            continue;
        }

        if ($char === "'") {
            $inSingle  = true;
            $buffer .= $char;
            continue;
        }
        if ($char === '"') {
            $inDouble  = true;
            $buffer .= $char;
            continue;
        }
        if ($char === '`') {
            $inBacktick = true;
            $buffer .= $char;
            continue;
        }

        if ($char === ';') {
            $statement = trim($buffer);
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $buffer = '';
            continue;
        }

        $buffer .= $char;
    }

    $statement = trim($buffer);
    if ($statement !== '') {
        $statements[] = $statement;
    }

    return $statements;
}

function restoreBackup(PDO $pdo, string $filePath): int
{
    $sql = @file_get_contents($filePath);

    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException('The backup file is empty or unreadable.');
    }

    $statements = splitSqlStatements($sql);
    $executed   = 0;

    // Restore runs inside a single transaction so partial failures roll back cleanly.
    $inTx = $pdo->inTransaction();
    if (!$inTx) {
        $pdo->beginTransaction();
    }

    try {
        foreach ($statements as $index => $statement) {
            try {
                $pdo->exec($statement);
                $executed++;
            } catch (PDOException $e) {
                $msg = sprintf(
                    'Restore aborted at statement #%d: %s',
                    $index + 1,
                    $e->getMessage()
                );
                if (stripos($msg, 'session_replication_role') !== false) {
                    $msg .= ' | Hint: this backup requires superuser '
                        . '(or a role with SET on session_replication_role, '
                        . 'e.g. rds_superuser on AWS RDS or azure_pg_admin on Azure).';
                }
                throw new RuntimeException($msg, 0, $e);
            }
        }

        if (!$inTx) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if (!$inTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return $executed;
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';

// Path: /backup/{filename}  (also accepts ?file= as a fallback)
$pathInfo  = $_GET['path_info'] ?? $_SERVER['PATH_INFO'] ?? '';
$pathParts = array_values(array_filter(explode('/', trim((string) $pathInfo, '/'))));

$resourceId = $pathParts[0] ?? ($_GET['file'] ?? null);
$resourceId = is_string($resourceId) ? trim($resourceId) : null;

// GET — download a single file, or list everything
if ($method === 'GET') {

    // 4a. Download a specific backup
    if ($resourceId !== null && $resourceId !== '') {
        $filePath = resolveBackupPath($resourceId, $backupDir);

        if ($filePath === null) {
            Response::error('Backup file not found.', 404);
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $downloadName = basename($filePath);

        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Content-Transfer-Encoding: binary');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');

        readfile($filePath);
        exit;
    }

    $files = glob($backupDir . '*.sql') ?: [];

    usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));

    $tz      = dbTimezone($pdo);
    $backups = [];

    foreach ($files as $file) {
        $name = basename($file);

        $date = parseBackupTimestamp($name);

        if ($date === null) {
            $mtime = filemtime($file);
            $date  = $mtime !== false
                ? (new DateTimeImmutable('@' . $mtime))->setTimezone($tz)->format('Y-m-d H:i:s')
                : 'unknown';
        }

        $backups[] = [
            'filename'      => $name,
            'size_in_bytes' => filesize($file),
            'date'          => $date,
            'download'      => '/api/backup/' . rawurlencode($name),
        ];
    }

    Response::success('Backups retrieved successfully.', $backups);
}

// POST — create a new backup and enforce the retention limit
if ($method === 'POST') {
    $lock = acquireOperationLock($backupDir);
    if ($lock === null) {
        Response::error('Another backup or restore operation is already in progress.', 409);
    }

    try {
        $filename = createBackup($pdo, $backupDir);
        $deleted  = pruneBackups($backupDir);
        systemLog("{$userData['name']} ({$userData['username']}) created a database backup: " . $filename, $userData['user_id']);

        Response::success('Snapshot created successfully: ' . $filename, [
            'filename'      => $filename,
            'size_in_bytes' => filesize($backupDir . $filename),
            'pruned'        => $deleted,
        ]);
    } catch (Throwable $e) {
        Response::error('Backup failed: ' . $e->getMessage(), 500);
    } finally {
        releaseOperationLock($lock);
    }
}

// PUT — restore the database from a backup file
if ($method === 'PUT') {
    if ($resourceId === null || $resourceId === '') {
        Response::error('Filename is required to restore a backup.', 400);
    }

    $filePath = resolveBackupPath($resourceId, $backupDir);

    if ($filePath === null) {
        Response::error('Backup file not found.', 404);
    }

    $lock = acquireOperationLock($backupDir);
    if ($lock === null) {
        Response::error('Another backup or restore operation is already in progress.', 409);
    }

    $safetyFile = null;

    try {
        $safetyFile = createBackup($pdo, $backupDir);
        pruneBackups($backupDir, basename($filePath));

        $executed = restoreBackup($pdo, $filePath);

        systemLog(
            "{$userData['name']} ({$userData['username']}) restored a database backup: " .
                basename($filePath) . " (safety snapshot: {$safetyFile})",
            $userData['user_id']
        );

        Response::success('Database restored successfully: ' . basename($filePath), [
            'filename'      => basename($filePath),
            'statements'    => $executed,
            'safety_backup' => $safetyFile,
        ]);
    } catch (Throwable $e) {
        systemLog(
            "Restore failed: " . $e->getMessage() .
                ($safetyFile !== null ? " | Safety backup: {$safetyFile}" : ''),
            $userData['user_id']
        );

        $msg = 'Restore failed: ' . $e->getMessage();
        if ($safetyFile !== null) {
            $msg .= '. Pre-restore state was saved as: ' . $safetyFile .
                '. You can restore it to return to the previous state.';
        }

        Response::error($msg, 500);
    } finally {
        releaseOperationLock($lock);
    }
}

// Anything else
header('Allow: GET, POST, PUT');
Response::error('Method not allowed.', 405);
