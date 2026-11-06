<?php

/**
 * view_postgre_db.php
 *
 * Database Inspector — admin-only read/write tool with schema viewer,
 * relationship map, recent-activity feed, and SQL runner.
 *
 * Built because managed PostgreSQL (Render) has no phpMyAdmin equivalent.
 * Used to demonstrate data persistence and database integrity to stakeholders.
 *
 * SECURITY: admin-only, CSRF-protected, all values parameterized, all
 * identifiers validated against information_schema. Keep out of public reach
 * where possible (reverse-proxy allowlist, or remove after defense).
 */

define('ITS_ME_JUSTTOVERIFY', true);
require_once 'checkuser.php';

$userData = checkuser();
if (($userData['role'] ?? null) !== ROLE_ADMIN) {
    Response::error("Forbidden.", 403);
}

// ---- CSRF session ----
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['dbviewer_csrf'])) {
    $_SESSION['dbviewer_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['dbviewer_csrf'];

// Presentation mode toggle (for projecting on a large screen)
if (isset($_GET['set_presentation'])) {
    $_SESSION['dbviewer_presentation'] = ($_GET['set_presentation'] === '1');
    $q = strtok($_SERVER['REQUEST_URI'], '?');
    $qs = $_GET;
    unset($qs['set_presentation']);
    header('Location: ' . $q . ($qs ? '?' . http_build_query($qs) : ''));
    exit;
}
$presentationMode = (bool)($_SESSION['dbviewer_presentation'] ?? false);

// ---- Helpers ----
function h($v)
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}
function qi($v)
{
    return '"' . str_replace('"', '""', (string)$v) . '"';
}

/** Column metadata for a table */
function tableMeta(PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare("
        SELECT column_name, data_type, is_nullable, column_default,
               is_identity, is_generated, identity_generation,
               character_maximum_length
        FROM information_schema.columns
        WHERE table_schema = current_schema() AND table_name = :t
        ORDER BY ordinal_position
    ");
    $stmt->execute([':t' => $table]);
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmt = $pdo->prepare("
        SELECT a.attname
        FROM pg_constraint con
        JOIN pg_class      rel ON rel.oid = con.conrelid
        JOIN pg_namespace  n   ON n.oid   = rel.relnamespace
        JOIN unnest(con.conkey) AS col(attnum) ON true
        JOIN pg_attribute  a   ON a.attrelid = rel.oid AND a.attnum = col.attnum
        WHERE con.contype = 'p'
          AND n.nspname = current_schema()
          AND rel.relname = :t
        ORDER BY array_position(con.conkey, col.attnum)
    ");
    $stmt->execute([':t' => $table]);
    $pk = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

    return ['columns' => $cols, 'pk' => $pk];
}

/** Estimated row count (fast, does not scan) */
function tableRowEstimate(PDO $pdo, string $table): int
{
    $stmt = $pdo->prepare("
        SELECT c.reltuples::bigint
        FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = current_schema() AND c.relname = :t
    ");
    $stmt->execute([':t' => $table]);
    return (int)($stmt->fetchColumn() ?: 0);
}

/** Exact row count (scans the table) */
function tableRowExact(PDO $pdo, string $table): int
{
    return (int)$pdo->query("SELECT COUNT(*) FROM " . qi($table))->fetchColumn();
}

/** Foreign keys for one table */
function tableForeignKeys(PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare("
        SELECT
            con.conname        AS fk_name,
            src.attname        AS source_column,
            tgt.relname        AS target_table,
            tgt_att.attname    AS target_column
        FROM pg_constraint con
        JOIN pg_class      src     ON src.oid  = con.conrelid
        JOIN pg_class      tgt     ON tgt.oid  = con.confrelid
        JOIN pg_namespace  n       ON n.oid    = src.relnamespace
        JOIN LATERAL unnest(con.conkey, con.confkey)
             AS k(src_attnum, tgt_attnum) ON true
        JOIN pg_attribute  src_att ON src_att.attrelid = src.oid AND src_att.attnum = k.src_attnum
        JOIN pg_attribute  tgt_att ON tgt_att.attrelid = tgt.oid AND tgt_att.attnum = k.tgt_attnum
        WHERE con.contype = 'f'
          AND n.nspname = current_schema()
          AND src.relname = :t
        ORDER BY con.conname, src_att.attnum
    ");
    $stmt->execute([':t' => $table]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** All FKs in the current schema, grouped by source table */
function allForeignKeys(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT
            src.relname     AS source_table,
            src_att.attname AS source_column,
            tgt.relname     AS target_table,
            tgt_att.attname AS target_column,
            con.conname     AS fk_name
        FROM pg_constraint con
        JOIN pg_class      src     ON src.oid  = con.conrelid
        JOIN pg_class      tgt     ON tgt.oid  = con.confrelid
        JOIN pg_namespace  n       ON n.oid    = src.relnamespace
        JOIN LATERAL unnest(con.conkey, con.confkey)
             AS k(src_attnum, tgt_attnum) ON true
        JOIN pg_attribute  src_att ON src_att.attrelid = src.oid AND src_att.attnum = k.src_attnum
        JOIN pg_attribute  tgt_att ON tgt_att.attrelid = tgt.oid AND tgt_att.attnum = k.tgt_attnum
        WHERE con.contype = 'f'
          AND n.nspname = current_schema()
        ORDER BY src.relname, con.conname, src_att.attnum
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Indexes for one table */
function tableIndexes(PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare("
        SELECT indexname AS index_name, indexdef AS index_def
        FROM pg_indexes
        WHERE schemaname = current_schema() AND tablename = :t
        ORDER BY indexname
    ");
    $stmt->execute([':t' => $table]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Latest rows across all timestamped tables */
function recentActivity(PDO $pdo, int $limit = 50): array
{
    // Which tables have a timestamp column we can sort by?
    $stmt = $pdo->query("
        SELECT table_name, column_name
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND column_name IN ('updated_at', 'created_at')
          AND data_type IN ('timestamp without time zone', 'timestamp with time zone')
        ORDER BY table_name,
                 CASE WHEN column_name = 'updated_at' THEN 0 ELSE 1 END
    ");
    $tsCols = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        // Prefer updated_at if both exist
        if (!isset($tsCols[$r['table_name']])) {
            $tsCols[$r['table_name']] = $r['column_name'];
        }
    }

    $rows = [];
    foreach ($tsCols as $tbl => $tsCol) {
        try {
            $q = $pdo->prepare(
                "SELECT * FROM " . qi($tbl)
                    . " ORDER BY " . qi($tsCol) . " DESC NULLS LAST LIMIT 5"
            );
            $q->execute();
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $r['__table'] = $tbl;
                $r['__ts_col'] = $tsCol;
                $r['__ts'] = $r[$tsCol];
                $rows[] = $r;
            }
        } catch (Throwable $e) {
            // Table might have row-level security or other restriction; skip it
        }
    }

    usort($rows, function ($a, $b) {
        return strcmp((string)$b['__ts'], (string)$a['__ts']);
    });

    return array_slice($rows, 0, $limit);
}

/** Database-level summary metrics */
function dbSummary(PDO $pdo): array
{
    $info = [
        'server_version' => '',
        'current_db'     => '',
        'current_schema' => '',
        'timezone'       => '',
        'encoding'       => '',
        'size_bytes'     => 0,
        'size_pretty'    => '',
        'table_count'    => 0,
        'total_rows'     => 0,
        'index_count'    => 0,
        'fk_count'       => 0,
    ];
    try {
        $info['server_version'] = (string)$pdo->query("SELECT version()")->fetchColumn();
    } catch (Throwable $e) {
    }
    try {
        $info['current_db']     = (string)$pdo->query("SELECT current_database()")->fetchColumn();
    } catch (Throwable $e) {
    }
    try {
        $info['current_schema'] = (string)$pdo->query("SELECT current_schema()")->fetchColumn();
    } catch (Throwable $e) {
    }
    try {
        $info['timezone']       = (string)$pdo->query("SHOW timezone")->fetchColumn();
    } catch (Throwable $e) {
    }
    try {
        $info['encoding']       = (string)$pdo->query("SHOW server_encoding")->fetchColumn();
    } catch (Throwable $e) {
    }
    try {
        $info['size_bytes']     = (int)$pdo->query("SELECT pg_database_size(current_database())")->fetchColumn();
    } catch (Throwable $e) {
    }

    $info['size_pretty'] = (function ($b) {
        if ($b < 1024) return $b . ' B';
        if ($b < 1048576) return round($b / 1024, 1) . ' KB';
        if ($b < 1073741824) return round($b / 1048576, 2) . ' MB';
        return round($b / 1073741824, 2) . ' GB';
    })($info['size_bytes']);

    // Table count
    try {
        $tables = $pdo->query("
            SELECT table_name
            FROM information_schema.tables
            WHERE table_schema = current_schema() AND table_type = 'BASE TABLE'
        ")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $info['table_count'] = count($tables);

        $total = 0;
        foreach ($tables as $t) {
            try {
                $total += tableRowExact($pdo, $t);
            } catch (Throwable $e) {
            }
        }
        $info['total_rows'] = $total;
    } catch (Throwable $e) {
    }

    try {
        $info['index_count'] = (int)$pdo->query("
            SELECT COUNT(*) FROM pg_indexes WHERE schemaname = current_schema()
        ")->fetchColumn();
    } catch (Throwable $e) {
    }

    try {
        $info['fk_count'] = (int)$pdo->query("
            SELECT COUNT(*)
            FROM pg_constraint con
            JOIN pg_class      rel ON rel.oid = con.conrelid
            JOIN pg_namespace  n   ON n.oid = rel.relnamespace
            WHERE con.contype = 'f' AND n.nspname = current_schema()
        ")->fetchColumn();
    } catch (Throwable $e) {
    }

    return $info;
}

// ---- Enumerate tables ----
$pdo = $GLOBALS['pdo'];
$allTables = $pdo->query("
    SELECT table_name
    FROM information_schema.tables
    WHERE table_schema = current_schema() AND table_type = 'BASE TABLE'
    ORDER BY table_name
")->fetchAll(PDO::FETCH_COLUMN) ?: [];

// ---- Read request inputs ----
$action     = (string)($_POST['action'] ?? $_GET['action'] ?? 'home');
$table      = (string)($_POST['table']  ?? $_GET['table']  ?? '');
$page       = max(1, (int)($_GET['page'] ?? $_POST['page'] ?? 1));
$limit      = max(10, min(500, (int)($_GET['limit'] ?? $_POST['limit'] ?? 50)));
$searchTerm = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
$exactRows  = (bool)(int)($_GET['exact'] ?? $_POST['exact'] ?? 0);

if ($table !== '' && !in_array($table, $allTables, true)) {
    http_response_code(400);
    die('Invalid table name.');
}

// ---- CSV export (before any POST handling) ----
if ($action === 'export' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($table === '') {
        http_response_code(400);
        die('Table required.');
    }
    $meta = tableMeta($pdo, $table);

    $searchWhere = '';
    $searchParams = [];
    if ($searchTerm !== '') {
        $searchableCols = [];
        foreach ($meta['columns'] as $c) {
            if (in_array($c['data_type'], ['text', 'character varying', 'character', 'json', 'jsonb', 'uuid'], true)) {
                $searchableCols[] = $c['column_name'];
            }
        }
        if (!empty($searchableCols)) {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $searchTerm);
            $like = '%' . $escaped . '%';
            $parts = [];
            foreach ($searchableCols as $i => $col) {
                $ph = ':search_' . $i;
                $parts[] = qi($col) . "::text ILIKE {$ph} ESCAPE '\\'";
                $searchParams[$ph] = $like;
            }
            $searchWhere = ' WHERE (' . implode(' OR ', $parts) . ')';
        }
    }

    while (ob_get_level() > 0) ob_end_clean();
    $filename = $table . '_' . date('Y-m-d_H-i-s') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    $stmt = $pdo->prepare("SELECT * FROM " . qi($table) . $searchWhere);
    $stmt->execute($searchParams);
    $first = true;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($first) {
            fputcsv($out, array_keys($row));
            $first = false;
        }
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

$flash     = null;
$flashType = 'info';

// ---- POST handlers ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        die('CSRF token mismatch.');
    }

    try {
        switch ($action) {

            case 'save_row': {
                    if ($table === '') throw new RuntimeException('No table specified.');
                    $meta = tableMeta($pdo, $table);
                    if (empty($meta['pk'])) throw new RuntimeException('Table has no primary key — cannot edit safely.');

                    $pkWhere  = [];
                    $pkParams = [];
                    foreach ($meta['pk'] as $pkCol) {
                        $val = $_POST['pk'][$pkCol] ?? null;
                        if ($val === null) throw new RuntimeException("Missing PK value for $pkCol.");
                        $pkWhere[] = qi($pkCol) . ' = :pk_' . $pkCol;
                        $pkParams[':pk_' . $pkCol] = $val;
                    }

                    $setParts  = [];
                    $setParams = [];
                    foreach ($meta['columns'] as $col) {
                        $name = $col['column_name'];
                        if ($col['is_generated'] === 'ALWAYS') continue;
                        if (in_array($name, $meta['pk'], true)) continue;
                        if (!array_key_exists('col_' . $name, $_POST)) continue;

                        $isNull = ($_POST['null_' . $name] ?? '') === '1';
                        if ($isNull) {
                            if ($col['is_nullable'] !== 'YES') throw new RuntimeException("$name cannot be NULL.");
                            $setParts[] = qi($name) . ' = NULL';
                        } else {
                            $raw = (string)($_POST['col_' . $name] ?? '');
                            $setParts[] = qi($name) . ' = :set_' . $name;
                            $setParams[':set_' . $name] = ($raw === '') ? null : $raw;
                        }
                    }

                    if (empty($setParts)) {
                        $flash = 'No changes to save.';
                        $flashType = 'info';
                        break;
                    }

                    $sql  = "UPDATE " . qi($table) . " SET " . implode(', ', $setParts)
                        . " WHERE " . implode(' AND ', $pkWhere);
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute(array_merge($setParams, $pkParams));

                    $flash = 'Row updated. Affected: ' . $stmt->rowCount();
                    $flashType = 'success';
                    break;
                }

            case 'delete_row': {
                    if ($table === '') throw new RuntimeException('No table specified.');
                    $meta = tableMeta($pdo, $table);
                    if (empty($meta['pk'])) throw new RuntimeException('Table has no primary key — cannot delete safely.');

                    $pkWhere  = [];
                    $pkParams = [];
                    foreach ($meta['pk'] as $pkCol) {
                        $val = $_POST['pk'][$pkCol] ?? null;
                        if ($val === null) throw new RuntimeException("Missing PK value for $pkCol.");
                        $pkWhere[] = qi($pkCol) . ' = :pk_' . $pkCol;
                        $pkParams[':pk_' . $pkCol] = $val;
                    }

                    $sql  = "DELETE FROM " . qi($table) . " WHERE " . implode(' AND ', $pkWhere);
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($pkParams);

                    $flash = 'Row deleted. Affected: ' . $stmt->rowCount();
                    $flashType = 'success';
                    break;
                }

            case 'insert_row': {
                    if ($table === '') throw new RuntimeException('No table specified.');
                    $meta = tableMeta($pdo, $table);

                    $colNames     = [];
                    $placeholders = [];
                    $params       = [];

                    foreach ($meta['columns'] as $col) {
                        $name = $col['column_name'];
                        if ($col['is_generated'] === 'ALWAYS') continue;

                        $fieldKey = 'col_' . $name;
                        if (!array_key_exists($fieldKey, $_POST)) continue;

                        $isNull = ($_POST['null_' . $name] ?? '') === '1';
                        $raw    = (string)($_POST[$fieldKey] ?? '');

                        if ($col['is_identity'] === 'YES' && !$isNull && $raw === '') {
                            continue;
                        }

                        if ($isNull) {
                            if ($col['is_nullable'] !== 'YES') throw new RuntimeException("$name cannot be NULL.");
                            $colNames[]     = qi($name);
                            $placeholders[] = 'NULL';
                            continue;
                        }

                        if ($raw === '') {
                            if ($col['is_nullable'] === 'YES') {
                                $colNames[]     = qi($name);
                                $placeholders[] = 'NULL';
                                continue;
                            }
                            if ($col['column_default'] !== null) {
                                continue;
                            }
                            throw new RuntimeException("Field $name is required.");
                        }

                        $colNames[]     = qi($name);
                        $ph             = ':v_' . $name;
                        $placeholders[] = $ph;
                        $params[$ph]    = $raw;
                    }

                    $sql = empty($colNames)
                        ? "INSERT INTO " . qi($table) . " DEFAULT VALUES"
                        : "INSERT INTO " . qi($table) . " (" . implode(', ', $colNames) . ") VALUES (" . implode(', ', $placeholders) . ")";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);

                    $flash = 'Row inserted.';
                    $flashType = 'success';
                    break;
                }

            case 'run_sql': {
                    $sqlText = trim((string)($_POST['sql_text'] ?? ''));
                    if ($sqlText === '') throw new RuntimeException('No SQL provided.');

                    $stmt = $pdo->query($sqlText);
                    $hasResultSet = $stmt->columnCount() > 0;

                    if ($hasResultSet) {
                        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        $cols = $rows ? array_keys($rows[0]) : [];
                        $flash = 'Query returned ' . count($rows) . ' row(s).';
                        $flashType = 'success';
                        $_SESSION['dbviewer_sql_result'] = [
                            'cols' => $cols,
                            'rows' => $rows,
                            'sql'  => $sqlText,
                        ];
                    } else {
                        $flash = 'Statement executed. Affected rows: ' . $stmt->rowCount();
                        $flashType = 'success';
                        unset($_SESSION['dbviewer_sql_result']);
                    }
                    break;
                }

            case 'fix_sequence': {
                    if ($table === '') throw new RuntimeException('No table specified.');
                    $meta = tableMeta($pdo, $table);
                    if (empty($meta['pk'])) throw new RuntimeException('Table has no primary key.');
                    $pkCol = $meta['pk'][0];

                    $seqName = $pdo->query("
                        SELECT pg_get_serial_sequence('" . qi($table) . "', " . $pdo->quote($pkCol) . ")
                    ")->fetchColumn();

                    if (!$seqName) throw new RuntimeException("No sequence owns $table.$pkCol.");

                    $max = (int)$pdo->query("SELECT COALESCE(MAX(" . qi($pkCol) . "), 0) FROM " . qi($table))->fetchColumn();
                    $pdo->prepare("SELECT setval(:s, :v, :isC)")
                        ->execute([':s' => $seqName, ':v' => max(1, $max), ':isC' => $max > 0]);

                    $flash = "Sequence reset: $seqName -> " . max(1, $max) . " (is_called=" . ($max > 0 ? 'true' : 'false') . ")";
                    $flashType = 'success';
                    break;
                }
        }
    } catch (Throwable $e) {
        $flash = 'Error: ' . $e->getMessage();
        $flashType = 'error';
    }

    if ($action !== 'run_sql' || $flashType === 'error') {
        $postToView = [
            'save_row'     => 'view',
            'delete_row'   => 'view',
            'insert_row'   => 'view',
            'fix_sequence' => 'view',
            'run_sql'      => 'sql',
        ];
        $redirectView = $postToView[$action] ?? 'tables';

        $redirectParams = ['action' => $redirectView];
        if ($table !== '') {
            $redirectParams['table'] = $table;
        }
        if ($searchTerm !== '' && $redirectView === 'view') {
            $redirectParams['q'] = $searchTerm;
        }
        if ($flash !== null) {
            $redirectParams['msg']     = $flash;
            $redirectParams['msgtype'] = $flashType;
        }
        header('Location: view_postgre_db.php?' . http_build_query($redirectParams));
        exit;
    }
}

// Pick up flash from query string
if ($flash === null && isset($_GET['msg'])) {
    $flash = (string)$_GET['msg'];
    $flashType = (string)($_GET['msgtype'] ?? 'info');
}

// Retrieve SQL result if any
$sqlResult = $_SESSION['dbviewer_sql_result'] ?? null;
if ($action !== 'sql') {
    unset($_SESSION['dbviewer_sql_result']);
    $sqlResult = null;
}

// Home summary is always computed for the header badge
$summary = dbSummary($pdo);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Database Inspector — Memoria</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            margin: 0;
            background: #f7f8fa;
            color: #1a1a1a;
            font-size: 14px;
            line-height: 1.5;
        }

        header {
            background: #0f172a;
            color: #fff;
            padding: 12px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        header h1 {
            font-size: 16px;
            margin: 0;
            font-weight: 600;
        }

        header .user {
            font-size: 12px;
            color: #94a3b8;
        }

        header .user strong {
            color: #e2e8f0;
        }

        nav {
            background: #1e293b;
            padding: 0 24px;
            display: flex;
            gap: 4px;
            overflow-x: auto;
        }

        nav a {
            color: #cbd5e1;
            text-decoration: none;
            padding: 10px 16px;
            font-size: 13px;
            border-bottom: 2px solid transparent;
            white-space: nowrap;
        }

        nav a:hover {
            background: #334155;
        }

        nav a.active {
            color: #fff;
            border-bottom-color: #38bdf8;
        }

        main {
            padding: 24px;
            max-width: 1500px;
            margin: 0 auto;
        }

        .flash {
            padding: 10px 14px;
            border-radius: 6px;
            margin-bottom: 16px;
            font-size: 13px;
            word-wrap: break-word;
            white-space: pre-wrap;
            font-family: ui-monospace, monospace;
        }

        .flash.success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
        }

        .flash.error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .flash.info {
            background: #dbeafe;
            color: #1e40af;
            border: 1px solid #93c5fd;
        }

        .flash.warn {
            background: #fef3c7;
            color: #78350f;
            border: 1px solid #fcd34d;
        }

        table {
            border-collapse: collapse;
            width: 100%;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            overflow: hidden;
        }

        table.grid th,
        table.grid td {
            padding: 8px 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
            font-size: 13px;
        }

        table.grid th {
            background: #f1f5f9;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        table.grid tr:hover {
            background: #f8fafc;
        }

        table.grid td.null {
            color: #94a3b8;
            font-style: italic;
        }

        table.grid td.long {
            max-width: 400px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            cursor: help;
        }

        table.grid td.mono {
            font-family: ui-monospace, monospace;
            font-size: 12px;
        }

        .wrap {
            overflow-x: auto;
        }

        .btn {
            display: inline-block;
            padding: 6px 12px;
            font-size: 13px;
            border-radius: 5px;
            background: #0f172a;
            color: #fff;
            text-decoration: none;
            border: none;
            cursor: pointer;
            font-family: inherit;
        }

        .btn:hover {
            background: #1e293b;
        }

        .btn.small {
            padding: 3px 8px;
            font-size: 12px;
        }

        .btn.danger {
            background: #dc2626;
        }

        .btn.danger:hover {
            background: #b91c1c;
        }

        .btn.muted {
            background: #e2e8f0;
            color: #334155;
        }

        .btn.muted:hover {
            background: #cbd5e1;
        }

        .btn.success {
            background: #16a34a;
        }

        .btn.success:hover {
            background: #15803d;
        }

        .card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 20px;
            margin-bottom: 16px;
        }

        .card h2 {
            margin: 0 0 12px 0;
            font-size: 15px;
            font-weight: 600;
        }

        .card h3 {
            margin: 16px 0 8px 0;
            font-size: 13px;
            font-weight: 600;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-row {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            margin-bottom: 12px;
        }

        .form-row label {
            min-width: 180px;
            font-size: 13px;
            padding-top: 6px;
        }

        .form-row .field {
            flex: 1;
        }

        .form-row input[type=text],
        .form-row textarea {
            width: 100%;
            padding: 6px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            font-family: inherit;
            font-size: 13px;
        }

        .form-row textarea {
            min-height: 60px;
            resize: vertical;
        }

        .form-row .nullbox {
            display: flex;
            gap: 6px;
            align-items: center;
            font-size: 12px;
            color: #64748b;
            padding-top: 6px;
        }

        .form-row input[type=checkbox] {
            margin: 0;
        }

        .meta {
            color: #64748b;
            font-size: 12px;
        }

        .pagination {
            margin-top: 12px;
            display: flex;
            gap: 8px;
            align-items: center;
            font-size: 13px;
        }

        .pagination a,
        .pagination span {
            padding: 4px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            text-decoration: none;
            color: #334155;
            background: #fff;
        }

        .pagination a:hover {
            background: #f1f5f9;
        }

        .pagination span.cur {
            background: #0f172a;
            color: #fff;
            border-color: #0f172a;
        }

        code,
        pre {
            font-family: ui-monospace, "SF Mono", Consolas, monospace;
            font-size: 12px;
            background: #f1f5f9;
            padding: 2px 6px;
            border-radius: 3px;
        }

        pre {
            padding: 12px;
            overflow-x: auto;
            background: #0f172a;
            color: #e2e8f0;
        }

        textarea.sql {
            width: 100%;
            min-height: 140px;
            padding: 12px;
            font-family: ui-monospace, monospace;
            font-size: 13px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            background: #0f172a;
            color: #e2e8f0;
        }

        .search-bar {
            display: flex;
            gap: 6px;
            align-items: center;
        }

        .search-bar input[type=search] {
            padding: 6px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            font-size: 13px;
            width: 260px;
            font-family: inherit;
        }

        /* Dashboard stats grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 16px;
        }

        .stat-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 16px;
        }

        .stat-card .stat-label {
            font-size: 12px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .stat-card .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #0f172a;
        }

        .stat-card .stat-sub {
            font-size: 12px;
            color: #64748b;
            margin-top: 4px;
            font-family: ui-monospace, monospace;
        }

        .stat-card.success .stat-value {
            color: #16a34a;
        }

        /* Schema view */
        .schema-table {
            margin-bottom: 24px;
        }

        .schema-table table.grid th {
            background: #e2e8f0;
        }

        .col-type {
            font-family: ui-monospace, monospace;
            font-size: 12px;
            color: #334155;
        }

        .badge {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 3px;
            font-size: 11px;
            background: #e2e8f0;
            color: #475569;
            margin-left: 4px;
        }

        .badge.pk {
            background: #fef3c7;
            color: #78350f;
        }

        .badge.fk {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge.gen {
            background: #ede9fe;
            color: #5b21b6;
        }

        .badge.id {
            background: #d1fae5;
            color: #065f46;
        }

        .badge.null {
            background: #f1f5f9;
            color: #64748b;
        }

        .badge.notnull {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge.req {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Relationship map */
        .rel-tree {
            font-family: ui-monospace, monospace;
            font-size: 13px;
            background: #0f172a;
            color: #e2e8f0;
            padding: 20px;
            border-radius: 6px;
            overflow-x: auto;
            line-height: 1.8;
        }

        .rel-tree .parent {
            color: #7dd3fc;
            font-weight: 600;
        }

        .rel-tree .child {
            color: #fcd34d;
        }

        .rel-tree .arrow {
            color: #94a3b8;
        }

        /* Presentation mode */
        body.presentation-mode {
            font-size: 18px;
        }

        body.presentation-mode main {
            padding: 32px;
            max-width: 1700px;
        }

        body.presentation-mode header h1 {
            font-size: 20px;
        }

        body.presentation-mode nav a {
            font-size: 16px;
            padding: 14px 20px;
        }

        body.presentation-mode table.grid th,
        body.presentation-mode table.grid td {
            padding: 12px 16px;
            font-size: 16px;
        }

        body.presentation-mode .card {
            padding: 28px;
        }

        body.presentation-mode .card h2 {
            font-size: 19px;
        }

        body.presentation-mode .stat-card .stat-value {
            font-size: 32px;
        }

        body.presentation-mode .stat-card .stat-label {
            font-size: 14px;
        }

        body.presentation-mode .btn {
            padding: 10px 18px;
            font-size: 15px;
        }

        body.presentation-mode .btn.small {
            padding: 6px 12px;
            font-size: 13px;
        }

        body.presentation-mode code,
        body.presentation-mode pre {
            font-size: 15px;
        }
    </style>
</head>

<body class="<?= $presentationMode ? 'presentation-mode' : '' ?>">

    <header>
        <h1>🔍 Database Inspector — <?= h($summary['current_db'] ?: 'memoria_db') ?></h1>
        <span class="user">
            <strong><?= h($userData['username'] ?? '') ?></strong>
            (<?= h($userData['role'] ?? '') ?>)
            &nbsp;·&nbsp;
            <?php if ($presentationMode): ?>
                <a href="?set_presentation=0" style="color:#fbbf24; text-decoration:none;">Exit presentation mode</a>
            <?php else: ?>
                <a href="?set_presentation=1" style="color:#38bdf8; text-decoration:none;">Presentation mode</a>
            <?php endif; ?>
        </span>
    </header>

    <nav>
        <a href="?action=home" class="<?= $action === 'home'            ? 'active' : '' ?>">🏠 Overview</a>
        <a href="?action=tables" class="<?= $action === 'tables'          ? 'active' : '' ?>">Tables</a>
        <a href="?action=schema" class="<?= $action === 'schema'          ? 'active' : '' ?>">Schema</a>
        <a href="?action=relationships" class="<?= $action === 'relationships'   ? 'active' : '' ?>">Relationships</a>
        <a href="?action=recent" class="<?= $action === 'recent'          ? 'active' : '' ?>">Recent Activity</a>
        <a href="?action=sql" class="<?= $action === 'sql'             ? 'active' : '' ?>">SQL Runner</a>
        <a href="?action=sequences" class="<?= $action === 'sequences'       ? 'active' : '' ?>">Sequences</a>
    </nav>

    <main>
        <?php if ($flash !== null): ?>
            <div class="flash <?= h($flashType) ?>"><?= h($flash) ?></div>
        <?php endif; ?>

        <?php /* ============ HOME ============ */
        if ($action === 'home'): ?>
            <div class="card">
                <h2>Database Status</h2>
                <p class="meta" style="margin:0 0 16px 0;">
                    Live connection to the production database. All values below are read from
                    the running PostgreSQL server in real time.
                </p>
                <div class="stats-grid">
                    <div class="stat-card success">
                        <div class="stat-label">Connection</div>
                        <div class="stat-value">✅ Live</div>
                        <div class="stat-sub"><?= h($summary['current_db']) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Tables</div>
                        <div class="stat-value"><?= number_format($summary['table_count']) ?></div>
                        <div class="stat-sub"><?= number_format($summary['fk_count']) ?> foreign keys · <?= number_format($summary['index_count']) ?> indexes</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Total Rows</div>
                        <div class="stat-value"><?= number_format($summary['total_rows']) ?></div>
                        <div class="stat-sub">across all tables</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Database Size</div>
                        <div class="stat-value"><?= h($summary['size_pretty']) ?></div>
                        <div class="stat-sub"><?= number_format($summary['size_bytes']) ?> bytes</div>
                    </div>
                </div>
            </div>

            <div class="card">
                <h2>Server Information</h2>
                <table class="grid">
                    <tbody>
                        <tr>
                            <td style="width:200px; font-weight:600;">PostgreSQL Version</td>
                            <td class="mono"><?= h($summary['server_version']) ?></td>
                        </tr>
                        <tr>
                            <td style="font-weight:600;">Current Database</td>
                            <td class="mono"><?= h($summary['current_db']) ?></td>
                        </tr>
                        <tr>
                            <td style="font-weight:600;">Current Schema</td>
                            <td class="mono"><?= h($summary['current_schema']) ?></td>
                        </tr>
                        <tr>
                            <td style="font-weight:600;">Timezone</td>
                            <td class="mono"><?= h($summary['timezone']) ?></td>
                        </tr>
                        <tr>
                            <td style="font-weight:600;">Encoding</td>
                            <td class="mono"><?= h($summary['encoding']) ?></td>
                        </tr>
                        <tr>
                            <td style="font-weight:600;">PHP Version</td>
                            <td class="mono"><?= h(PHP_VERSION) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="card">
                <h2>Quick Actions</h2>
                <p class="meta" style="margin:0 0 16px 0;">
                    Explore the structure and content of the database.
                </p>
                <a class="btn" href="?action=schema">View schema</a>
                <a class="btn" href="?action=relationships">View relationships</a>
                <a class="btn" href="?action=recent">Recent activity</a>
                <a class="btn muted" href="?action=tables">Browse tables</a>
                <a class="btn muted" href="?action=sql">SQL runner</a>
                <a class="btn muted" href="?action=sequences">Sequences</a>
            </div>

        <?php /* ============ TABLES ============ */
        elseif ($action === 'tables'): ?>
            <div class="card">
                <h2>Tables (<?= count($allTables) ?>)</h2>
                <p class="meta" style="margin:0 0 16px 0;">
                    Click <strong>View rows</strong> to see data, or <strong>Schema</strong> to see column definitions.
                </p>
                <div class="wrap">
                    <table class="grid">
                        <thead>
                            <tr>
                                <th>Table</th>
                                <th>Rows (est.)</th>
                                <th>Primary key</th>
                                <th>Foreign keys</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allTables as $t):
                                $meta = tableMeta($pdo, $t);
                                $pk   = $meta['pk'];
                                $fks  = tableForeignKeys($pdo, $t);
                            ?>
                                <tr>
                                    <td><code><?= h($t) ?></code></td>
                                    <td class="meta"><?= h(number_format(tableRowEstimate($pdo, $t))) ?></td>
                                    <td class="meta"><?= empty($pk) ? '<em>(none)</em>' : h(implode(', ', $pk)) ?></td>
                                    <td class="meta"><?= empty($fks) ? '—' : h(count($fks) . ' FK' . (count($fks) > 1 ? 's' : '')) ?></td>
                                    <td style="white-space:nowrap;">
                                        <a class="btn small" href="?action=view&table=<?= h(urlencode($t)) ?>">View rows</a>
                                        <a class="btn small muted" href="?action=insert&table=<?= h(urlencode($t)) ?>">Insert</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php /* ============ SCHEMA ============ */
        elseif ($action === 'schema'): ?>
            <div class="card">
                <h2>Database Schema</h2>
                <p class="meta" style="margin:0 0 16px 0;">
                    Complete structure of every table: columns, data types, constraints, and indexes.
                </p>
                <div class="flash info">
                    <strong>Tip:</strong> Use this page when the panel asks "show us your database structure."
                    Each table below shows every column with its type, nullability, default value,
                    and constraints — plus all foreign keys and indexes.
                </div>
            </div>

            <?php foreach ($allTables as $t):
                $meta = tableMeta($pdo, $t);
                $pk   = $meta['pk'];
                $fks  = tableForeignKeys($pdo, $t);
                $idx  = tableIndexes($pdo, $t);
                $fkCols = [];
                foreach ($fks as $fk) $fkCols[$fk['source_column']] = $fk;
            ?>
                <div class="card schema-table">
                    <h2><code><?= h($t) ?></code> <span class="meta">— <?= number_format(tableRowExact($pdo, $t)) ?> rows</span></h2>

                    <h3>Columns</h3>
                    <div class="wrap">
                        <table class="grid">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Type</th>
                                    <th>Nullable</th>
                                    <th>Default</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($meta['columns'] as $c):
                                    $name = $c['column_name'];
                                    $isPk = in_array($name, $pk, true);
                                    $isFk = isset($fkCols[$name]);
                                    $isGen = $c['is_generated'] === 'ALWAYS';
                                    $isIdent = $c['is_identity'] === 'YES';
                                    $type = $c['data_type'];
                                    if (!empty($c['character_maximum_length'])) {
                                        $type .= '(' . $c['character_maximum_length'] . ')';
                                    }
                                ?>
                                    <tr>
                                        <td><code><?= h($name) ?></code></td>
                                        <td class="col-type"><?= h($type) ?></td>
                                        <td><?= $c['is_nullable'] === 'YES'
                                                ? '<span class="badge null">nullable</span>'
                                                : '<span class="badge notnull">NOT NULL</span>' ?></td>
                                        <td class="col-type"><?= $c['column_default'] !== null ? h($c['column_default']) : '—' ?></td>
                                        <td>
                                            <?php if ($isPk): ?><span class="badge pk">PRIMARY KEY</span><?php endif; ?>
                                            <?php if ($isFk): ?>
                                                <span class="badge fk">FK → <?= h($fkCols[$name]['target_table'] . '.' . $fkCols[$name]['target_column']) ?></span>
                                            <?php endif; ?>
                                            <?php if ($isGen): ?><span class="badge gen">GENERATED</span><?php endif; ?>
                                            <?php if ($isIdent): ?><span class="badge id">IDENTITY</span><?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (!empty($fks)): ?>
                        <h3>Foreign keys</h3>
                        <div class="wrap">
                            <table class="grid">
                                <thead>
                                    <tr>
                                        <th>Column</th>
                                        <th>References</th>
                                        <th>Constraint name</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($fks as $fk): ?>
                                        <tr>
                                            <td><code><?= h($fk['source_column']) ?></code></td>
                                            <td><code><?= h($fk['target_table']) ?>.<?= h($fk['target_column']) ?></code></td>
                                            <td class="col-type"><?= h($fk['fk_name']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($idx)): ?>
                        <h3>Indexes</h3>
                        <div class="wrap">
                            <table class="grid">
                                <thead>
                                    <tr>
                                        <th>Index name</th>
                                        <th>Definition</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($idx as $ix): ?>
                                        <tr>
                                            <td><code><?= h($ix['index_name']) ?></code></td>
                                            <td class="col-type"><?= h($ix['index_def']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <div style="margin-top:16px;">
                        <a class="btn small" href="?action=view&table=<?= h(urlencode($t)) ?>">View rows</a>
                        <a class="btn small muted" href="?action=export&table=<?= h(urlencode($t)) ?>">⬇ Export CSV</a>
                    </div>
                </div>
            <?php endforeach; ?>

        <?php /* ============ RELATIONSHIPS ============ */
        elseif ($action === 'relationships'):
            $fks = allForeignKeys($pdo);

            // Group by target (parent) → children
            $byParent = [];
            foreach ($fks as $fk) {
                $byParent[$fk['target_table']][] = $fk;
            }
            ksort($byParent);

            // Also group by source (child) → parents
            $byChild = [];
            foreach ($fks as $fk) {
                $byChild[$fk['source_table']][] = $fk;
            }
            ksort($byChild);
        ?>
            <div class="card">
                <h2>Table Relationships</h2>
                <p class="meta" style="margin:0 0 16px 0;">
                    Every foreign key in the database. Use this to demonstrate referential integrity
                    to the panel: every cross-table reference is a real, enforced relationship.
                </p>
                <div class="flash info">
                    <strong>Note:</strong> <code>ON DELETE</code> behavior is shown below.
                    <code>RESTRICT</code> means "block deletion if children exist" (safety feature for graves and users).
                    <code>SET NULL</code> means "clear the FK if the parent is deleted" (used for soft-delete audit columns).
                </div>
            </div>

            <div class="card">
                <h2>Parents → Children</h2>
                <p class="meta" style="margin:0 0 12px 0;">Who references each table.</p>
                <div class="rel-tree">
                    <?php if (empty($byParent)): ?>
                        (no foreign keys in this schema)
                        <?php else: foreach ($byParent as $parent => $children): ?>
                            <span class="parent"><?= h($parent) ?></span>
                            <?php foreach ($children as $c): ?>
                                <span class="arrow"> ←── </span><span class="child"><?= h($c['source_table']) ?>.<?= h($c['source_column']) ?></span>
                            <?php endforeach; ?>
                    <?php endforeach;
                    endif; ?>
                </div>
            </div>

            <div class="card">
                <h2>Children → Parents</h2>
                <p class="meta" style="margin:0 0 12px 0;">What each table depends on.</p>
                <div class="rel-tree">
                    <?php if (empty($byChild)): ?>
                        (no foreign keys in this schema)
                        <?php else: foreach ($byChild as $child => $parents): ?>
                            <span class="child"><?= h($child) ?></span>
                            <?php foreach ($parents as $p): ?>
                                <span class="arrow"> ──→ </span><span class="parent"><?= h($p['target_table']) ?>.<?= h($p['target_column']) ?></span>
                            <?php endforeach; ?>
                    <?php endforeach;
                    endif; ?>
                </div>
            </div>

            <div class="card">
                <h2>All Foreign Keys (<?= count($fks) ?>)</h2>
                <div class="wrap">
                    <table class="grid">
                        <thead>
                            <tr>
                                <th>Source</th>
                                <th>Column</th>
                                <th>Target</th>
                                <th>Target column</th>
                                <th>Constraint</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($fks as $fk): ?>
                                <tr>
                                    <td><code><?= h($fk['source_table']) ?></code></td>
                                    <td><code><?= h($fk['source_column']) ?></code></td>
                                    <td><code><?= h($fk['target_table']) ?></code></td>
                                    <td><code><?= h($fk['target_column']) ?></code></td>
                                    <td class="col-type"><?= h($fk['fk_name']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($fks)): ?>
                                <tr>
                                    <td colspan="5" class="meta">No foreign keys found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php /* ============ RECENT ACTIVITY ============ */
        elseif ($action === 'recent'):
            $recent = recentActivity($pdo, 50);
        ?>
            <div class="card">
                <h2>Recent Activity</h2>
                <p class="meta" style="margin:0 0 16px 0;">
                    The 50 most recently changed rows across all tables, newest first.
                    This is the fastest way to prove that a transaction was actually persisted —
                    create something in the app, refresh this page, and the new row appears at the top.
                </p>
                <div class="flash info">
                    <strong>Demo tip:</strong> Create a payment (or any record) in the main app,
                    then come back here. Your new row is at the top of the list with its exact creation timestamp.
                </div>
            </div>

            <?php if (empty($recent)): ?>
                <div class="card">
                    <p class="meta">No recent activity — no tables have a <code>created_at</code> or <code>updated_at</code> column, or all tables are empty.</p>
                </div>
            <?php else: ?>
                <div class="card">
                    <div class="wrap">
                        <table class="grid">
                            <thead>
                                <tr>
                                    <th>Timestamp</th>
                                    <th>Table</th>
                                    <th>PK / ID</th>
                                    <th>Snapshot</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent as $r):
                                    $tbl = $r['__table'];
                                    $tsVal = (string)$r['__ts'];
                                    $meta = tableMeta($pdo, $tbl);
                                    $pkCols = $meta['pk'];

                                    // Try to find a primary key value for the "View" link
                                    $pkValue = null;
                                    if (!empty($pkCols) && isset($r[$pkCols[0]])) {
                                        $pkValue = $r[$pkCols[0]];
                                    }

                                    // Build snapshot: first 5 non-timestamp columns
                                    $snapshot = [];
                                    foreach ($r as $k => $v) {
                                        if ($k[0] === '_') continue;
                                        if (in_array($k, ['created_at', 'updated_at'], true)) continue;
                                        if (count($snapshot) >= 5) break;
                                        $snapshot[$k] = $v;
                                    }
                                ?>
                                    <tr>
                                        <td class="col-type" style="white-space:nowrap;"><?= h($tsVal) ?></td>
                                        <td><code><?= h($tbl) ?></code></td>
                                        <td class="col-type">
                                            <?php if ($pkValue !== null): ?>
                                                <?= h((string)$pkValue) ?>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                        <td class="col-type" style="max-width:500px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                            <?php $parts = [];
                                            foreach ($snapshot as $k => $v):
                                                $parts[] = $k . '=' . ($v === null ? 'NULL' : (string)$v);
                                            endforeach;
                                            echo h(implode(', ', $parts)); ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($pkCols) && $pkValue !== null): ?>
                                                <a class="btn small" href="?action=view&table=<?= h(urlencode($tbl)) ?>&<?= h(urlencode('pk[' . $pkCols[0] . ']') . '=' . urlencode((string)$pkValue)) ?>">View</a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

        <?php /* ============ VIEW TABLE ============ */
        elseif ($action === 'view'):
            $meta = tableMeta($pdo, $table);
            $pk   = $meta['pk'];

            $searchWhere    = '';
            $searchParams   = [];
            $searchableCols = [];

            foreach ($meta['columns'] as $c) {
                if (in_array($c['data_type'], ['text', 'character varying', 'character', 'json', 'jsonb', 'uuid'], true)) {
                    $searchableCols[] = $c['column_name'];
                }
            }

            if ($searchTerm !== '' && !empty($searchableCols)) {
                $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $searchTerm);
                $like = '%' . $escaped . '%';
                $parts = [];
                foreach ($searchableCols as $i => $col) {
                    $ph = ':search_' . $i;
                    $parts[] = qi($col) . "::text ILIKE {$ph} ESCAPE '\\'";
                    $searchParams[$ph] = $like;
                }
                $searchWhere = ' WHERE (' . implode(' OR ', $parts) . ')';
            }

            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM " . qi($table) . $searchWhere);
            $countStmt->execute($searchParams);
            $total = (int)$countStmt->fetchColumn();

            $pages  = max(1, (int)ceil($total / $limit));
            $page   = min($page, $pages);
            $offset = ($page - 1) * $limit;

            $stmt = $pdo->prepare(
                "SELECT * FROM " . qi($table) . $searchWhere
                    . " LIMIT {$limit} OFFSET {$offset}"
            );
            $stmt->execute($searchParams);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $cols = !empty($rows)
                ? array_keys($rows[0])
                : array_column($meta['columns'], 'column_name');

            $longCols = [];
            foreach ($meta['columns'] as $c) {
                if (in_array($c['data_type'], ['text', 'character varying', 'json', 'jsonb'], true)) {
                    $longCols[$c['column_name']] = true;
                }
            }

            $paginationQuery = 'action=view&table=' . urlencode($table) . '&limit=' . $limit;
            if ($searchTerm !== '') {
                $paginationQuery .= '&q=' . urlencode($searchTerm);
            }
            // Export carries the current filter
            $exportQuery = 'action=export&table=' . urlencode($table);
            if ($searchTerm !== '') {
                $exportQuery .= '&q=' . urlencode($searchTerm);
            }
        ?>
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px; gap:12px; flex-wrap:wrap;">
                    <div>
                        <h2 style="display:inline; margin-right:12px;"><?= h($table) ?></h2>
                        <span class="meta">
                            <?php if ($searchTerm !== ''): ?>
                                <?= h(number_format($total)) ?> match(es) for &ldquo;<?= h($searchTerm) ?>&rdquo;
                            <?php else: ?>
                                <?= h(number_format($total)) ?> total row(s)
                            <?php endif; ?>
                        </span>
                    </div>
                    <div style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
                        <form method="get" action="view_postgre_db.php" class="search-bar">
                            <input type="hidden" name="action" value="view">
                            <input type="hidden" name="table" value="<?= h($table) ?>">
                            <input type="hidden" name="limit" value="<?= $limit ?>">
                            <input type="search" name="q" value="<?= h($searchTerm) ?>"
                                placeholder="Search all text columns…">
                            <button class="btn small" type="submit">Search</button>
                            <?php if ($searchTerm !== ''): ?>
                                <a class="btn small muted" href="?action=view&table=<?= h(urlencode($table)) ?>">Clear</a>
                            <?php endif; ?>
                        </form>
                        <a class="btn small" href="?action=insert&table=<?= h(urlencode($table)) ?>">+ Insert row</a>
                        <a class="btn small muted" href="?<?= h($exportQuery) ?>">⬇ Export CSV</a>
                        <?php if (!empty($pk)): ?>
                            <form method="post" action="view_postgre_db.php" style="display:inline"
                                onsubmit="return confirm('Reset sequence for <?= h(addslashes($table . '.' . $pk[0])) ?> to MAX(<?= h($pk[0]) ?>)? This is safe and useful after a restore.')">
                                <input type="hidden" name="action" value="fix_sequence">
                                <input type="hidden" name="table" value="<?= h($table) ?>">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="q" value="<?= h($searchTerm) ?>">
                                <button class="btn small muted" type="submit">Fix sequence</button>
                            </form>
                        <?php endif; ?>
                        <a class="btn small muted" href="?action=tables">← Tables</a>
                    </div>
                </div>

                <?php if ($searchTerm !== '' && empty($searchableCols)): ?>
                    <div class="flash info" style="margin-bottom:12px;">
                        This table has no text-searchable columns.
                    </div>
                <?php endif; ?>

                <div class="wrap">
                    <table class="grid">
                        <thead>
                            <tr>
                                <?php foreach ($cols as $c): ?>
                                    <th><?= h($c) ?></th>
                                <?php endforeach; ?>
                                <th style="width:120px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr>
                                    <td colspan="<?= count($cols) + 1 ?>" class="meta">
                                        <?= $searchTerm !== ''
                                            ? 'No rows match &ldquo;' . h($searchTerm) . '&rdquo;.'
                                            : 'No rows.' ?>
                                    </td>
                                </tr>
                                <?php else: foreach ($rows as $row): ?>
                                    <tr>
                                        <?php foreach ($cols as $c):
                                            $val = $row[$c] ?? null;
                                            $isLong = isset($longCols[$c]) && strlen((string)$val) > 80;
                                            $isNull = ($val === null);
                                        ?>
                                            <td class="<?= $isNull ? 'null' : '' ?> <?= $isLong ? 'long' : '' ?>"
                                                <?= $isLong ? 'title="' . h($val) . '"' : '' ?>>
                                                <?= $isNull ? 'NULL' : h($val) ?>
                                            </td>
                                        <?php endforeach; ?>
                                        <td style="white-space:nowrap;">
                                            <?php if (!empty($pk)): ?>
                                                <a class="btn small" href="?action=edit&table=<?= h(urlencode($table)) ?>&<?php foreach ($pk as $pkCol) {
                                                                                                                                echo h(urlencode('pk[' . $pkCol . ']') . '=' . urlencode((string)$row[$pkCol]) . '&');
                                                                                                                            } ?>">Edit</a>
                                                <form method="post" action="view_postgre_db.php" style="display:inline"
                                                    onsubmit="return confirm('Delete this row from <?= h(addslashes($table)) ?>? This cannot be undone.')">
                                                    <input type="hidden" name="action" value="delete_row">
                                                    <input type="hidden" name="table" value="<?= h($table) ?>">
                                                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                                    <input type="hidden" name="q" value="<?= h($searchTerm) ?>">
                                                    <?php foreach ($pk as $pkCol): ?>
                                                        <input type="hidden" name="pk[<?= h($pkCol) ?>]" value="<?= h($row[$pkCol]) ?>">
                                                    <?php endforeach; ?>
                                                    <button class="btn small danger" type="submit">Del</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="meta">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                            <?php endforeach;
                            endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?<?= h($paginationQuery) ?>&page=<?= $page - 1 ?>">← Prev</a>
                    <?php endif; ?>
                    <span class="cur"><?= $page ?> / <?= $pages ?></span>
                    <?php if ($page < $pages): ?>
                        <a href="?<?= h($paginationQuery) ?>&page=<?= $page + 1 ?>">Next →</a>
                    <?php endif; ?>
                    <span class="meta">per page: <?= $limit ?></span>
                </div>
            </div>

            <?php /* ============ EDIT ============ */
        elseif ($action === 'edit'):
            $meta = tableMeta($pdo, $table);
            $pk   = $meta['pk'];

            if (empty($pk)): ?>
                <div class="card">
                    <div class="flash error">Table has no primary key — cannot edit or delete safely.</div>
                    <a class="btn muted" href="?action=view&table=<?= h(urlencode($table)) ?>">← Back to <?= h($table) ?></a>
                </div>
            <?php else:
                $pkWhere  = [];
                $pkParams = [];
                foreach ($pk as $pkCol) {
                    $val = $_GET['pk'][$pkCol] ?? null;
                    if ($val === null) die('Missing pk.');
                    $pkWhere[] = qi($pkCol) . ' = :pk_' . $pkCol;
                    $pkParams[':pk_' . $pkCol] = $val;
                }
                $stmt = $pdo->prepare("SELECT * FROM " . qi($table) . " WHERE " . implode(' AND ', $pkWhere) . " LIMIT 1");
                $stmt->execute($pkParams);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) die('Row not found.');
            ?>
                <div class="card">
                    <h2>Edit row in <code><?= h($table) ?></code></h2>
                    <form method="post" action="view_postgre_db.php">
                        <input type="hidden" name="action" value="save_row">
                        <input type="hidden" name="table" value="<?= h($table) ?>">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <?php foreach ($pk as $pkCol): ?>
                            <input type="hidden" name="pk[<?= h($pkCol) ?>]" value="<?= h($row[$pkCol]) ?>">
                        <?php endforeach; ?>

                        <?php foreach ($meta['columns'] as $col):
                            $name     = $col['column_name'];
                            $isPk     = in_array($name, $pk, true);
                            $isGen    = $col['is_generated'] === 'ALWAYS';
                            $isNull   = $row[$name] === null;
                            $readonly = $isPk || $isGen;
                        ?>
                            <div class="form-row">
                                <label>
                                    <code><?= h($name) ?></code><br>
                                    <span class="meta"><?= h($col['data_type']) ?><?= $isPk ? ' · PK' : '' ?><?= $isGen ? ' · generated' : '' ?></span>
                                </label>
                                <div class="field">
                                    <textarea name="col_<?= h($name) ?>"
                                        <?= $readonly ? 'readonly' : '' ?>
                                        placeholder="(empty)"><?= $isGen ? '' : h($isNull ? '' : $row[$name]) ?></textarea>
                                </div>
                                <div class="nullbox">
                                    <?php if (!$isPk && !$isGen && $col['is_nullable'] === 'YES'): ?>
                                        <input type="checkbox" name="null_<?= h($name) ?>" value="1"
                                            id="null_<?= h($name) ?>" <?= $isNull ? 'checked' : '' ?>>
                                        <label for="null_<?= h($name) ?>">NULL</label>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <div style="margin-top:16px;">
                            <button class="btn" type="submit">Save changes</button>
                            <a class="btn muted" href="?action=view&table=<?= h(urlencode($table)) ?>">Cancel</a>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

        <?php /* ============ INSERT ============ */
        elseif ($action === 'insert'):
            $meta = tableMeta($pdo, $table);
        ?>
            <div class="card">
                <h2>Insert row into <code><?= h($table) ?></code></h2>
                <p class="meta">
                    Leave a field empty to use its DB default (if any) or NULL (if nullable).
                    Identity columns left blank will auto-assign the next value.
                </p>
                <form method="post" action="view_postgre_db.php">
                    <input type="hidden" name="action" value="insert_row">
                    <input type="hidden" name="table" value="<?= h($table) ?>">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

                    <?php foreach ($meta['columns'] as $col):
                        $name  = $col['column_name'];
                        $isGen = $col['is_generated'] === 'ALWAYS';
                        if ($isGen) continue;
                        $isIdentity = $col['is_identity'] === 'YES';
                    ?>
                        <div class="form-row">
                            <label>
                                <code><?= h($name) ?></code><br>
                                <span class="meta">
                                    <?= h($col['data_type']) ?>
                                    <?= $isIdentity ? ' · auto' : '' ?>
                                    <?= $col['is_nullable'] === 'NO' ? ' · required' : ' · nullable' ?>
                                    <?= $col['column_default'] !== null ? ' · has default' : '' ?>
                                </span>
                            </label>
                            <div class="field">
                                <textarea name="col_<?= h($name) ?>" placeholder="(empty)"></textarea>
                            </div>
                            <div class="nullbox">
                                <?php if ($col['is_nullable'] === 'YES'): ?>
                                    <input type="checkbox" name="null_<?= h($name) ?>" value="1" id="null_<?= h($name) ?>">
                                    <label for="null_<?= h($name) ?>">NULL</label>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <div style="margin-top:16px;">
                        <button class="btn" type="submit">Insert row</button>
                        <a class="btn muted" href="?action=view&table=<?= h(urlencode($table)) ?>">Cancel</a>
                    </div>
                </form>
            </div>

        <?php /* ============ SQL RUNNER ============ */
        elseif ($action === 'sql'): ?>
            <div class="card">
                <h2>SQL Runner</h2>
                <p class="meta">
                    Runs one statement at a time. SELECT-like statements show a result grid;
                    others show affected rows. This tool runs as the app's DB user — no superuser escalation.
                </p>
                <form method="post" action="view_postgre_db.php">
                    <input type="hidden" name="action" value="run_sql">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <textarea name="sql_text" class="sql" placeholder="SELECT * FROM users LIMIT 5;"><?= h($sqlResult['sql'] ?? '') ?></textarea>
                    <div style="margin-top:12px;">
                        <button class="btn" type="submit">Run statement</button>
                    </div>
                </form>
            </div>

            <?php if (!empty($sqlResult['rows'])): ?>
                <div class="card">
                    <h2>Result: <?= count($sqlResult['rows']) ?> row(s)</h2>
                    <div class="wrap">
                        <table class="grid">
                            <thead>
                                <tr>
                                    <?php foreach ($sqlResult['cols'] as $c): ?>
                                        <th><?= h($c) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sqlResult['rows'] as $r): ?>
                                    <tr>
                                        <?php foreach ($sqlResult['cols'] as $c):
                                            $v = $r[$c] ?? null;
                                            $isLong = is_string($v) && strlen($v) > 80;
                                        ?>
                                            <td class="<?= $v === null ? 'null' : '' ?> <?= $isLong ? 'long' : '' ?>"
                                                <?= $isLong ? 'title="' . h($v) . '"' : '' ?>>
                                                <?= $v === null ? 'NULL' : h($v) ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

        <?php /* ============ SEQUENCES ============ */
        elseif ($action === 'sequences'):
            $stmt = $pdo->query("
                SELECT
                    c.relname AS seq_name,
                    tn.nspname AS table_schema,
                    t.relname AS table_name,
                    a.attname AS column_name,
                    pg_sequence_last_value(c.oid) AS last_value
                FROM pg_class c
                JOIN pg_namespace sn ON sn.oid = c.relnamespace
                JOIN pg_depend dep ON dep.objid = c.oid AND dep.deptype = 'a'
                JOIN pg_class t ON t.oid = dep.refobjid
                JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = dep.refobjsubid
                JOIN pg_namespace tn ON tn.oid = t.relnamespace
                WHERE c.relkind = 'S'
                  AND sn.nspname = current_schema()
                  AND tn.nspname = current_schema()
                ORDER BY tn.nspname, t.relname, a.attname
            ");
            $seqs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($seqs as &$s) {
                $tn = qi($s['table_schema']) . '.' . qi($s['table_name']);
                $max = $pdo->query("SELECT COALESCE(MAX(" . qi($s['column_name']) . "), 0) FROM " . $tn)->fetchColumn();
                $s['actual_max'] = (int)$max;
                $s['drift'] = ((int)$s['last_value'] !== (int)$max) && ((int)$max > 0);
            }
            unset($s);
        ?>
            <div class="card">
                <h2>Sequences</h2>
                <p class="meta">
                    Each sequence should be aligned with <code>MAX(col)</code> in its owning table.
                    If <code>last_value</code> &lt; <code>MAX(col)</code>, new inserts will collide with existing IDs.
                </p>
                <div class="wrap">
                    <table class="grid">
                        <thead>
                            <tr>
                                <th>Sequence</th>
                                <th>Owns</th>
                                <th>last_value</th>
                                <th>MAX(col)</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($seqs as $s): ?>
                                <tr>
                                    <td><code><?= h($s['seq_name']) ?></code></td>
                                    <td class="meta"><?= h($s['table_name'] . '.' . $s['column_name']) ?></td>
                                    <td><?= h((string)$s['last_value']) ?></td>
                                    <td><?= h((string)$s['actual_max']) ?></td>
                                    <td>
                                        <?php if ($s['drift']): ?>
                                            <span style="color:#dc2626; font-weight:600;">⚠ DRIFT</span>
                                        <?php else: ?>
                                            <span style="color:#16a34a;">✓ aligned</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($seqs)): ?>
                                <tr>
                                    <td colspan="5" class="meta">No sequences in current schema.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php endif; ?>
    </main>

</body>

</html>