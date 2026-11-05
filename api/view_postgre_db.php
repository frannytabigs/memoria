<?php

/**
 * view_postgre_db.php
 *
 * Single-file PostgreSQL viewer / CRUD for admins.
 * Deploy next to checkuser.php. Access via /view_postgre_db.php
 *
 * SECURITY: this is a DANGEROUS tool by design — it can read, write, and
 * run arbitrary SQL. It is gated to ROLE_ADMIN and CSRF-protected, but
 * you should still keep it OUT of public reach if you can (e.g. via a
 * reverse-proxy IP allowlist on Render, or by removing it after use).
 */

define('ITS_ME_JUSTTOVERIFY', true);
require_once 'checkuser.php';

$userData = checkuser();
if (($userData['role'] ?? null) !== ROLE_ADMIN) {
    http_response_code(403);
    die('Forbidden: only administrators can access the database viewer.');
}

// ---- CSRF session ----
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['dbviewer_csrf'])) {
    $_SESSION['dbviewer_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['dbviewer_csrf'];

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
               is_identity, is_generated, identity_generation
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

/** Actual row count (accurate, may be slow on huge tables) */
function tableRowCount(PDO $pdo, string $table): int
{
    return (int)$pdo->query("SELECT COUNT(*) FROM " . qi($table))->fetchColumn();
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
$action = (string)($_POST['action'] ?? $_GET['action'] ?? 'tables');
$table  = (string)($_POST['table']  ?? $_GET['table']  ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = max(10, min(500, (int)($_GET['limit'] ?? 50)));

if ($table !== '' && !in_array($table, $allTables, true)) {
    http_response_code(400);
    die('Invalid table name.');
}

$flash     = null;
$flashType = 'info';

// ---- POST handlers (write paths) ----
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

                        // Identity column + user left blank = let the DB assign the next value
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
                            // Empty + nullable -> NULL
                            if ($col['is_nullable'] === 'YES') {
                                $colNames[]     = qi($name);
                                $placeholders[] = 'NULL';
                                continue;
                            }
                            // Empty + not-null + has default -> let the DB fill it
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

    // PRG pattern — never render on POST except SQL result which lives in session
    if ($action !== 'run_sql' || $flashType === 'error') {
        $back = 'view_postgre_db.php?action=' . urlencode($action === 'run_sql' ? 'sql' : $action);
        if ($table !== '') $back .= '&table=' . urlencode($table);
        if ($flash !== null) {
            $back .= '&msg=' . urlencode($flash) . '&msgtype=' . urlencode($flashType);
        }
        header('Location: ' . $back);
        exit;
    }
}

// Pick up flash from query string (GET side)
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
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>DB Viewer — Memoria</title>
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

        nav {
            background: #1e293b;
            padding: 0 24px;
            display: flex;
            gap: 4px;
        }

        nav a {
            color: #cbd5e1;
            text-decoration: none;
            padding: 10px 16px;
            font-size: 13px;
            border-bottom: 2px solid transparent;
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
            max-width: 1400px;
            margin: 0 auto;
        }

        .flash {
            padding: 10px 14px;
            border-radius: 6px;
            margin-bottom: 16px;
            font-size: 13px;
            word-wrap: break-word;
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

        .tabs {
            display: flex;
            gap: 4px;
            border-bottom: 1px solid #e2e8f0;
            margin-bottom: 16px;
        }

        .tabs a {
            padding: 8px 16px;
            font-size: 13px;
            color: #64748b;
            text-decoration: none;
            border-bottom: 2px solid transparent;
        }

        .tabs a:hover {
            color: #0f172a;
        }

        .tabs a.active {
            color: #0f172a;
            border-bottom-color: #0f172a;
            font-weight: 600;
        }
    </style>
</head>

<body>

    <header>
        <h1>PostgreSQL Viewer — <?= h('memoria_db') ?></h1>
        <span class="user"><?= h($userData['username'] ?? '') ?> (<?= h($userData['role'] ?? '') ?>)</span>
    </header>

    <nav>
        <a href="?action=tables" class="<?= $action === 'tables' ? 'active' : '' ?>">Tables</a>
        <a href="?action=sql" class="<?= $action === 'sql'    ? 'active' : '' ?>">SQL Runner</a>
        <a href="?action=sequences" class="<?= $action === 'sequences' ? 'active' : '' ?>">Sequences</a>
    </nav>

    <main>
        <?php if ($flash !== null): ?>
            <div class="flash <?= h($flashType) ?>"><?= h($flash) ?></div>
        <?php endif; ?>

        <?php if ($action === 'tables'): ?>
            <!-- ===== TABLE LIST ===== -->
            <div class="card">
                <h2>Tables (<?= count($allTables) ?>)</h2>
                <div class="wrap">
                    <table class="grid">
                        <thead>
                            <tr>
                                <th>Table</th>
                                <th>Est. rows</th>
                                <th>Primary key</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allTables as $t):
                                $meta = tableMeta($pdo, $t);
                                $pk   = $meta['pk'];
                            ?>
                                <tr>
                                    <td><code><?= h($t) ?></code></td>
                                    <td class="meta"><?= h(number_format(tableRowEstimate($pdo, $t))) ?></td>
                                    <td class="meta">
                                        <?php if (empty($pk)): ?>
                                            <em>(none — edit/delete disabled)</em>
                                        <?php else: ?>
                                            <?= h(implode(', ', $pk)) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a class="btn small" href="?action=view&table=<?= h(urlencode($t)) ?>">View rows</a>
                                        <a class="btn small muted" href="?action=insert&table=<?= h(urlencode($t)) ?>">Insert</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif ($action === 'view'):
            $meta    = tableMeta($pdo, $table);
            $pk      = $meta['pk'];
            $total   = tableRowCount($pdo, $table);
            $pages   = max(1, (int)ceil($total / $limit));
            $page    = min($page, $pages);
            $offset  = ($page - 1) * $limit;

            $stmt = $pdo->prepare("SELECT * FROM " . qi($table) . " LIMIT {$limit} OFFSET {$offset}");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $cols = !empty($rows) ? array_keys($rows[0]) : array_column($meta['columns'], 'column_name');

            // Long text detection for cell truncation
            $longCols = [];
            foreach ($meta['columns'] as $c) {
                if (in_array($c['data_type'], ['text', 'character varying'], true)) {
                    $longCols[$c['column_name']] = true;
                }
            }
        ?>
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                    <div>
                        <h2 style="display:inline; margin-right:12px;"><?= h($table) ?></h2>
                        <span class="meta"><?= h(number_format($total)) ?> total row(s)</span>
                    </div>
                    <div style="display:flex; gap:6px;">
                        <a class="btn small" href="?action=insert&table=<?= h(urlencode($table)) ?>">+ Insert row</a>
                        <?php if (!empty($pk)): ?>
                            <form method="post" action="view_postgre_db.php" style="display:inline"
                                onsubmit="return confirm('Reset sequence for <?= h(addslashes($table . '.' . $pk[0])) ?> to MAX(<?= h($pk[0]) ?>)? This is safe and useful after a restore.')">
                                <input type="hidden" name="action" value="fix_sequence">
                                <input type="hidden" name="table" value="<?= h($table) ?>">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <button class="btn small muted" type="submit">Fix sequence</button>
                            </form>
                        <?php endif; ?>
                        <a class="btn small muted" href="?action=tables">← Tables</a>
                    </div>
                </div>

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
                                    <td colspan="<?= count($cols) + 1 ?>" class="meta">No rows.</td>
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
                                                <a class="btn small" href="?action=edit&table=<?= h(urlencode($table)) ?>&<?php
                                                                                                                            foreach ($pk as $pkCol) {
                                                                                                                                echo h(urlencode('pk[' . $pkCol . ']') . '=' . urlencode((string)$row[$pkCol]) . '&');
                                                                                                                            }
                                                                                                                            ?>">Edit</a>
                                                <form method="post" action="view_postgre_db.php" style="display:inline"
                                                    onsubmit="return confirm('Delete this row from <?= h(addslashes($table)) ?>? This cannot be undone.')">
                                                    <input type="hidden" name="action" value="delete_row">
                                                    <input type="hidden" name="table" value="<?= h($table) ?>">
                                                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
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
                        <a href="?action=view&table=<?= h(urlencode($table)) ?>&page=<?= $page - 1 ?>&limit=<?= $limit ?>">← Prev</a>
                    <?php endif; ?>
                    <span class="cur"><?= $page ?> / <?= $pages ?></span>
                    <?php if ($page < $pages): ?>
                        <a href="?action=view&table=<?= h(urlencode($table)) ?>&page=<?= $page + 1 ?>&limit=<?= $limit ?>">Next →</a>
                    <?php endif; ?>
                    <span class="meta">per page: <?= $limit ?></span>
                </div>
            </div>

            <?php elseif ($action === 'edit'):
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

        <?php elseif ($action === 'insert'):
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

        <?php elseif ($action === 'sql'): ?>
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

        <?php elseif ($action === 'sequences'):
            // All sequences owned by columns in current schema, with drift detection
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

            // Compute actual MAX for each in a loop
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
                    If <code>last_value</code> &lt; <code>MAX(col)</code>, new inserts will collide with existing IDs
                    — use the table's "Fix sequence" button to reset it.
                </p>
                <div class="wrap">
                    <table class="grid">
                        <thead>
                            <tr>
                                <th>Sequence</th>
                                <th>Owns</th>
                                <th>last_value</th>
                                <th>MAX(col)</th>
                                <th>Drift</th>
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