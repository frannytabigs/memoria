<?php

/**
 * view_render_files.php — Emergency file browser / CRUD for Render
 *
 * ⚠⚠⚠ DANGEROUS TOOL ⚠⚠⚠
 *
 * Admin-only file browser, editor, uploader, deleter. In the wrong hands
 * this is a webshell. In the right hands it's a diagnostic lifeline on
 * Render free tier (no SSH) or a hotfix tool.
 *
 * Render's app filesystem is EPHEMERAL. Any change you make here is
 * LOST on the next deploy / restart / (free tier) spin-down. Use git for
 * real changes. Use this to:
 *   - inspect what's really on prod
 *   - download logs / backups
 *   - apply emergency 2-minute fixes
 *
 * If you have a Persistent Disk mounted at /var/data, set ROOT_DIR to
 * '/var/data' to browse that instead (it survives deploys).
 */

define('ITS_ME_JUSTTOVERIFY', true);
require_once 'checkuser.php';

$userData = checkuser();
if (($userData['role'] ?? null) !== ROLE_ADMIN) {
    Response::error("Forbidden.", 403);
}

// ---- CSRF + session state ----
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['filebrowser_csrf'])) {
    $_SESSION['filebrowser_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['filebrowser_csrf'];

// Read-only toggle (defaults to SAFE)
if (isset($_GET['set_readonly'])) {
    $_SESSION['filebrowser_readonly'] = ($_GET['set_readonly'] === '1');
    $q = strtok($_SERVER['REQUEST_URI'], '?');
    header('Location: ' . $q);
    exit;
}
$readOnly = $_SESSION['filebrowser_readonly'] ?? true;

// ---- Configuration ----
const ROOT_DIR         = __DIR__;             // ← change to '/var/data' to browse the persistent disk
const MAX_VIEW_BYTES   = 2 * 1024 * 1024;     // 2 MB
const MAX_EDIT_BYTES   = 1 * 1024 * 1024;     // 1 MB
const MAX_UPLOAD_BYTES = 50 * 1024 * 1024;    // 50 MB

const SECRET_PATTERNS = [
    '/^notallowed\.php$/',
    '/^database\.php$/',
    '/^config\.php$/',
    '/^\.env(\..+)?$/',
];

const BLOCKED_READ_PATTERNS = [
    '/^\.env(\..+)?$/',
    '/^\.htaccess$/',
    '/\.pem$/i',
    '/\.key$/i',
    '/\.p12$/i',
    '/\.pfx$/i',
    '/^\.git$/',
];

const BLOCKED_EDIT_PATTERNS = [
    '/^\.env(\..+)?$/',
    '/^\.htaccess$/',
    '/\.pem$/i',
    '/\.key$/i',
    '/\.p12$/i',
    '/\.pfx$/i',
    '/^checkuser\.php$/',
];

const BLOCKED_DELETE_PATTERNS = [
    '/^\.env(\..+)?$/',
    '/^\.htaccess$/',
    '/^checkuser\.php$/',
    '/^notallowed\.php$/',
    '/^database\.php$/',
    '/^config\.php$/',
    '/^responses\.php$/',
    '/^view_render_files\.php$/',
    '/^view_postgre_db\.php$/',
];

const TEXT_EXTENSIONS = [
    'php',
    'txt',
    'md',
    'json',
    'html',
    'htm',
    'css',
    'js',
    'sql',
    'ini',
    'conf',
    'yml',
    'yaml',
    'xml',
    'log',
    'csv',
    'sh',
    'gitignore',
    'env',
    'env.example',
    'htaccess',
];

// ---- Helpers ----
function h($v)
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}
function matchesAny(string $name, array $patterns): bool
{
    foreach ($patterns as $p) if (preg_match($p, $name)) return true;
    return false;
}
function extOf(string $name): string
{
    $parts = explode('.', $name);
    return strtolower(end($parts));
}
function isTextFile(string $path): bool
{
    if (in_array(extOf(basename($path)), TEXT_EXTENSIONS, true)) return true;
    if (function_exists('finfo_open')) {
        $f = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($f, $path);
        finfo_close($f);
        if (strpos((string)$mime, 'text/') === 0) return true;
        if (in_array($mime, ['application/json', 'application/xml', 'application/javascript', 'application/x-empty'], true)) return true;
    }
    return false;
}
function formatBytes(int $b): string
{
    if ($b < 1024) return $b . ' B';
    if ($b < 1048576) return round($b / 1024, 1) . ' KB';
    if ($b < 1073741824) return round($b / 1048576, 2) . ' MB';
    return round($b / 1073741824, 2) . ' GB';
}
function safePath(string $userPath, bool $mustExist = false): ?string
{
    $base = realpath(ROOT_DIR);
    if ($base === false) return null;
    $userPath = str_replace('\\', '/', $userPath);
    $userPath = ltrim($userPath, '/');
    $segments = [];
    foreach (explode('/', $userPath) as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') {
            if (empty($segments)) return null;
            array_pop($segments);
            continue;
        }
        if (strpos($seg, "\0") !== false) return null;
        $segments[] = $seg;
    }
    if (empty($segments)) return $base;   // root itself
    $candidate = $base . '/' . implode('/', $segments);
    if ($mustExist) {
        $real = realpath($candidate);
        if ($real === false) return null;
        if ($real !== $base && strpos($real . '/', $base . '/') !== 0) return null;
        return $real;
    }
    $parent = dirname($candidate);
    $realParent = realpath($parent);
    if ($realParent === false) return null;
    if ($realParent !== $base && strpos($realParent . '/', $base . '/') !== 0) return null;
    return $candidate;
}
function relPath(string $abs): string
{
    $base = realpath(ROOT_DIR);
    if ($base === false) return $abs;
    if ($abs === $base) return '';
    if (strpos($abs . '/', $base . '/') === 0) return substr($abs, strlen($base) + 1);
    return $abs;
}
function checkPhpSyntax(string $content): array
{
    if (!function_exists('exec')) return [true, ''];
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (in_array('exec', $disabled, true)) return [true, ''];
    $phpBin = PHP_BINARY;
    if (!is_file($phpBin)) return [true, ''];
    $tmp = tempnam(sys_get_temp_dir(), 'phpcheck_');
    if ($tmp === false) return [true, ''];
    file_put_contents($tmp, $content);
    $output = [];
    $code = 0;
    @exec(escapeshellarg($phpBin) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $output, $code);
    @unlink($tmp);
    if ($code === 0) return [true, ''];
    return [false, implode("\n", $output)];
}

// ---- Request inputs ----
$action   = (string)($_POST['action'] ?? $_GET['action'] ?? 'browse');
$userPath = (string)($_POST['path']   ?? $_GET['path']   ?? '');
$reveal   = isset($_GET['reveal']) && $_GET['reveal'] === '1';

$flash = null;
$flashType = 'info';

// ---- POST handlers ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        die('CSRF token mismatch.');
    }
    if ($readOnly) {
        http_response_code(403);
        die('Write mode is disabled. Toggle it at the top of the page.');
    }

    try {
        switch ($action) {

            case 'save_edit': {
                    $target = safePath($userPath, true);
                    if ($target === null) throw new RuntimeException('Invalid path.');
                    if (!is_file($target)) throw new RuntimeException('Not a file.');
                    $name = basename($target);
                    if (matchesAny($name, BLOCKED_EDIT_PATTERNS)) throw new RuntimeException("'$name' is protected from editing.");
                    if (filesize($target) > MAX_EDIT_BYTES) throw new RuntimeException('File too large to edit.');

                    $content = (string)($_POST['content'] ?? '');

                    if (extOf($name) === 'php') {
                        [$ok, $err] = checkPhpSyntax($content);
                        if (!$ok) throw new RuntimeException("PHP syntax error, NOT saved:\n" . $err);
                    }

                    // Write to a temp file in the same directory, then atomic rename
                    $tmp = $target . '.saving_' . bin2hex(random_bytes(4));
                    if (file_put_contents($tmp, $content) === false) {
                        throw new RuntimeException('Cannot write temp file (check permissions).');
                    }
                    if (!rename($tmp, $target)) {
                        @unlink($tmp);
                        throw new RuntimeException('Cannot rename temp file into place.');
                    }
                    $flash = "Saved $name (" . formatBytes(strlen($content)) . ")";
                    $flashType = 'success';
                    break;
                }

            case 'delete_file': {
                    $target = safePath($userPath, true);
                    if ($target === null) throw new RuntimeException('Invalid path.');
                    $name = basename($target);
                    if (matchesAny($name, BLOCKED_DELETE_PATTERNS)) throw new RuntimeException("'$name' is protected from deletion.");
                    if (is_dir($target)) {
                        if (!@rmdir($target)) throw new RuntimeException('Cannot delete directory (must be empty).');
                    } else {
                        if (!@unlink($target)) throw new RuntimeException('Cannot delete file.');
                    }
                    $flash = "Deleted: $name";
                    $flashType = 'success';
                    break;
                }

            case 'rename': {
                    $target = safePath($userPath, true);
                    if ($target === null) throw new RuntimeException('Invalid path.');
                    $newName = trim((string)($_POST['new_name'] ?? ''));
                    if ($newName === '' || strpos($newName, '/') !== false || strpos($newName, '\\') !== false) {
                        throw new RuntimeException('New name must not contain slashes.');
                    }
                    $newTarget = safePath(relPath(dirname($target)) . '/' . $newName, false);
                    if ($newTarget === null) throw new RuntimeException('Invalid new path.');
                    if (file_exists($newTarget)) throw new RuntimeException('A file with that name already exists.');
                    if (!rename($target, $newTarget)) throw new RuntimeException('Rename failed.');
                    $flash = "Renamed to: $newName";
                    $flashType = 'success';
                    break;
                }

            case 'new_file':
            case 'new_folder': {
                    $name = trim((string)($_POST['new_name'] ?? ''));
                    if ($name === '' || strpos($name, '/') !== false || strpos($name, '\\') !== false) {
                        throw new RuntimeException('Name must not contain slashes.');
                    }
                    $parent = safePath($userPath, true);
                    if ($parent === null) throw new RuntimeException('Invalid parent path.');
                    if (!is_dir($parent)) throw new RuntimeException('Parent is not a directory.');

                    $newTarget = $parent . '/' . $name;
                    if (file_exists($newTarget)) throw new RuntimeException('Already exists.');

                    if ($action === 'new_folder') {
                        if (!mkdir($newTarget, 0755)) throw new RuntimeException('mkdir failed.');
                        $flash = "Created directory: $name";
                    } else {
                        if (file_put_contents($newTarget, '') === false) throw new RuntimeException('Create failed.');
                        $flash = "Created file: $name";
                    }
                    $flashType = 'success';
                    break;
                }

            case 'upload': {
                    $parent = safePath($userPath, true);
                    if ($parent === null) throw new RuntimeException('Invalid target directory.');
                    if (!is_dir($parent)) throw new RuntimeException('Target is not a directory.');

                    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                        throw new RuntimeException('Upload failed (no file or upload error).');
                    }
                    if ($_FILES['file']['size'] > MAX_UPLOAD_BYTES) {
                        throw new RuntimeException('File too large.');
                    }
                    $name = basename((string)$_FILES['file']['name']);
                    if ($name === '' || matchesAny($name, BLOCKED_EDIT_PATTERNS)) {
                        throw new RuntimeException("'$name' is not allowed.");
                    }
                    $dest = $parent . '/' . $name;
                    if (file_exists($dest) && ($_POST['overwrite'] ?? '') !== '1') {
                        throw new RuntimeException("'$name' already exists. Tick 'overwrite' to replace it.");
                    }
                    if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
                        throw new RuntimeException('move_uploaded_file failed (check permissions).');
                    }
                    $flash = "Uploaded: $name (" . formatBytes((int)$_FILES['file']['size']) . ")";
                    $flashType = 'success';
                    break;
                }
        }
    } catch (Throwable $e) {
        $flash = 'Error: ' . $e->getMessage();
        $flashType = 'error';
    }

    // PRG pattern
    if ($flashType === 'error') {
        // stay on same page to show error
    } else {
        $viewMap = [
            'save_edit'   => 'view',
            'delete_file' => 'browse',
            'rename'      => 'browse',
            'new_file'    => 'browse',
            'new_folder'  => 'browse',
            'upload'      => 'browse',
        ];
        $view = $viewMap[$action] ?? 'browse';
        $dir  = is_dir(safePath($userPath, true) ?? '') ? $userPath : dirname($userPath);
        if ($dir === '.' || $dir === '\\') $dir = '';
        $params = ['action' => $view, 'path' => $dir, 'msg' => $flash, 'msgtype' => $flashType];
        header('Location: view_render_files.php?' . http_build_query($params));
        exit;
    }
}

// Pick up flash from GET
if ($flash === null && isset($_GET['msg'])) {
    $flash = (string)$_GET['msg'];
    $flashType = (string)($_GET['msgtype'] ?? 'info');
}

// ---- Environment info banner ----
$rootReal = realpath(ROOT_DIR);
$diskAttached = is_dir('/var/data');
$phpVersion = PHP_VERSION;
$isReadOnly = $readOnly;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>File Browser — Memoria</title>
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
            font-size: 15px;
            margin: 0;
            font-weight: 600;
        }

        header .info {
            font-size: 12px;
            color: #94a3b8;
        }

        .banner {
            padding: 10px 24px;
            font-size: 13px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .banner.warn {
            background: #fef3c7;
            color: #78350f;
        }

        .banner.readon {
            background: #dcfce7;
            color: #166534;
        }

        .banner.write {
            background: #fee2e2;
            color: #991b1b;
            font-weight: 600;
        }

        .banner a {
            color: inherit;
            font-weight: 600;
        }

        nav {
            background: #1e293b;
            padding: 0 24px;
            display: flex;
            gap: 4px;
            font-size: 13px;
        }

        nav a {
            color: #cbd5e1;
            text-decoration: none;
            padding: 10px 16px;
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

        .btn.safe {
            background: #16a34a;
        }

        .btn.safe:hover {
            background: #15803d;
        }

        .btn.warn {
            background: #f59e0b;
        }

        .btn.warn:hover {
            background: #d97706;
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

        table.grid td.icon {
            width: 30px;
            color: #64748b;
        }

        table.grid td.size {
            width: 100px;
            text-align: right;
            color: #64748b;
            font-family: ui-monospace, monospace;
            font-size: 12px;
        }

        table.grid td.mtime {
            width: 160px;
            color: #64748b;
            font-size: 12px;
        }

        table.grid td.actions {
            width: 260px;
            white-space: nowrap;
            text-align: right;
        }

        .wrap {
            overflow-x: auto;
        }

        .breadcrumb {
            font-size: 13px;
            margin-bottom: 12px;
            color: #64748b;
            word-break: break-all;
        }

        .breadcrumb a {
            color: #0f172a;
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .breadcrumb .sep {
            color: #cbd5e1;
            margin: 0 6px;
        }

        code,
        pre {
            font-family: ui-monospace, "SF Mono", Consolas, monospace;
            font-size: 12px;
            background: #f1f5f9;
            padding: 2px 6px;
            border-radius: 3px;
        }

        pre.file {
            padding: 16px;
            overflow-x: auto;
            background: #0f172a;
            color: #e2e8f0;
            border-radius: 6px;
            font-size: 12px;
            line-height: 1.6;
            max-height: 800px;
            overflow-y: auto;
        }

        pre.file.php span {
            display: inline;
        }

        textarea.edit {
            width: 100%;
            min-height: 500px;
            padding: 12px;
            font-family: ui-monospace, monospace;
            font-size: 13px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            background: #0f172a;
            color: #e2e8f0;
            line-height: 1.6;
        }

        .form-row {
            display: flex;
            gap: 12px;
            align-items: center;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }

        .form-row input[type=text] {
            padding: 6px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            font-family: inherit;
            font-size: 13px;
        }

        .meta {
            color: #64748b;
            font-size: 12px;
        }

        .badge {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 3px;
            font-size: 11px;
            background: #e2e8f0;
            color: #475569;
            margin-left: 6px;
        }

        .badge.dir {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge.php {
            background: #ede9fe;
            color: #5b21b6;
        }

        .badge.json {
            background: #fef3c7;
            color: #78350f;
        }

        .badge.warn {
            background: #fee2e2;
            color: #991b1b;
        }
    </style>
</head>

<body>

    <header>
        <h1>📁 File Browser — <?= h($rootReal ?: ROOT_DIR) ?></h1>
        <span class="info">PHP <?= h($phpVersion) ?> · <?= $diskAttached ? '✅ /var/data attached' : '⚠ no /var/data disk' ?></span>
    </header>

    <?php if ($isReadOnly): ?>
        <div class="banner readon">
            <span>🔒 <strong>READ-ONLY MODE</strong> — upload / edit / delete / rename are disabled.</span>
            <a class="btn small warn" href="?set_readonly=0" onclick="return confirm('Enable WRITE MODE? You will be able to edit and delete files. Changes to the app directory are LOST on next deploy.')">Enable write mode</a>
        </div>
    <?php else: ?>
        <div class="banner write">
            <span>⚠ <strong>WRITE MODE</strong> — editing/deleting files. Changes to the app directory are EPHEMERAL and lost on deploy.</span>
            <a class="btn small safe" href="?set_readonly=1">Back to read-only</a>
        </div>
    <?php endif; ?>

    <div class="banner warn">
        <span>💡 Render storage is <strong>ephemeral</strong>. Files you upload/edit here are wiped on the next deploy. Use git for real changes; use a Persistent Disk (<code>/var/data</code>) for uploaded files.</span>
    </div>

    <nav>
        <a href="?action=browse" class="<?= $action === 'browse' ? 'active' : '' ?>">Browse</a>
    </nav>

    <main>
        <?php if ($flash !== null): ?>
            <div class="flash <?= h($flashType) ?>"><?= h($flash) ?></div>
        <?php endif; ?>

        <?php if ($action === 'browse'):
            $dir = safePath($userPath, true);

            if ($dir === null || !is_dir($dir)): ?>
                <div class="flash error">Invalid directory.</div>
            <?php else:
                $rel = relPath($dir);
                $entries = @scandir($dir) ?: [];
                $dirs = [];
                $files = [];
                foreach ($entries as $e) {
                    if ($e === '.' || $e === '..') continue;
                    $p = $dir . '/' . $e;
                    if (is_dir($p)) $dirs[] = $e;
                    else $files[] = $e;
                }
                sort($dirs);
                sort($files);
                $items = array_merge($dirs, $files);
            ?>
                <div class="card">
                    <div class="breadcrumb">
                        <a href="?action=browse">🏠 root</a>
                        <?php
                        $parts = $rel === '' ? [] : explode('/', $rel);
                        $built = '';
                        foreach ($parts as $seg):
                            $built = $built === '' ? $seg : $built . '/' . $seg;
                        ?>
                            <span class="sep">/</span><a href="?action=browse&path=<?= h(urlencode($built)) ?>"><?= h($seg) ?></a>
                        <?php endforeach; ?>
                        <?php if ($rel !== ''): ?>
                            <span class="badge"><?= count($items) ?> items</span>
                        <?php endif; ?>
                    </div>

                    <div class="wrap">
                        <table class="grid">
                            <thead>
                                <tr>
                                    <th class="icon"></th>
                                    <th>Name</th>
                                    <th class="size">Size</th>
                                    <th class="mtime">Modified</th>
                                    <th class="actions"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($rel !== ''): ?>
                                    <tr>
                                        <td class="icon">⬆</td>
                                        <td><a href="?action=browse&path=<?= h(urlencode(dirname($rel) === '.' ? '' : dirname($rel))) ?>">..</a></td>
                                        <td class="size">—</td>
                                        <td class="mtime">—</td>
                                        <td class="actions"></td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($items as $name):
                                    $p = $dir . '/' . $name;
                                    $childRel = $rel === '' ? $name : $rel . '/' . $name;
                                    $isDir = is_dir($p);
                                    $isBlocked = matchesAny($name, BLOCKED_READ_PATTERNS);
                                    $isSecret = matchesAny($name, SECRET_PATTERNS);
                                    $size = $isDir ? '' : formatBytes((int)@filesize($p));
                                    $mtime = @filemtime($p) ? date('Y-m-d H:i', @filemtime($p)) : '—';
                                    $ext = extOf($name);
                                ?>
                                    <tr>
                                        <td class="icon"><?= $isDir ? '📁' : '📄' ?></td>
                                        <td>
                                            <?php if ($isDir): ?>
                                                <a href="?action=browse&path=<?= h(urlencode($childRel)) ?>"><?= h($name) ?>/</a>
                                                <span class="badge dir">dir</span>
                                            <?php elseif ($isBlocked): ?>
                                                <span style="color:#94a3b8;"><?= h($name) ?></span>
                                                <span class="badge warn">blocked</span>
                                            <?php else: ?>
                                                <a href="?action=view&path=<?= h(urlencode($childRel)) ?>"><?= h($name) ?></a>
                                                <?php if ($ext === 'php'): ?><span class="badge php">php</span><?php endif; ?>
                                                <?php if ($ext === 'json'): ?><span class="badge json">json</span><?php endif; ?>
                                                <?php if ($isSecret): ?><span class="badge warn">secrets</span><?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="size"><?= h($size) ?></td>
                                        <td class="mtime"><?= h($mtime) ?></td>
                                        <td class="actions">
                                            <?php if ($isDir): ?>
                                                <a class="btn small muted" href="?action=browse&path=<?= h(urlencode($childRel)) ?>">Open</a>
                                            <?php elseif (!$isBlocked): ?>
                                                <a class="btn small muted" href="?action=view&path=<?= h(urlencode($childRel)) ?>">View</a>
                                                <a class="btn small muted" href="?action=download&path=<?= h(urlencode($childRel)) ?>">⬇</a>
                                                <?php if (!$readOnly && !matchesAny($name, BLOCKED_EDIT_PATTERNS)): ?>
                                                    <a class="btn small" href="?action=edit&path=<?= h(urlencode($childRel)) ?>">Edit</a>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <?php if (!$readOnly && !matchesAny($name, BLOCKED_DELETE_PATTERNS)): ?>
                                                <form method="post" style="display:inline"
                                                    onsubmit="return confirm('DELETE <?= h(addslashes($childRel)) ?>?\n\nThis cannot be undone. On Render, a redeploy will restore the original file.');">
                                                    <input type="hidden" name="action" value="delete_file">
                                                    <input type="hidden" name="path" value="<?= h($childRel) ?>">
                                                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                                    <button class="btn small danger" type="submit">Del</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($items)): ?>
                                    <tr>
                                        <td colspan="5" class="meta" style="text-align:center;padding:24px;">Empty directory.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if (!$readOnly): ?>
                    <div class="card">
                        <h2>Upload / Create here</h2>
                        <form method="post" enctype="multipart/form-data" class="form-row">
                            <input type="hidden" name="action" value="upload">
                            <input type="hidden" name="path" value="<?= h($rel) ?>">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="file" name="file" required>
                            <label style="font-size:12px;color:#64748b;"><input type="checkbox" name="overwrite" value="1"> overwrite if exists</label>
                            <button class="btn" type="submit">Upload</button>
                        </form>
                        <div style="display:flex; gap:16px; flex-wrap:wrap; margin-top:16px;">
                            <form method="post" class="form-row" style="margin:0;">
                                <input type="hidden" name="action" value="new_file">
                                <input type="hidden" name="path" value="<?= h($rel) ?>">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="text" name="new_name" placeholder="newfile.txt" required style="width:200px;">
                                <button class="btn muted" type="submit">+ New file</button>
                            </form>
                            <form method="post" class="form-row" style="margin:0;">
                                <input type="hidden" name="action" value="new_folder">
                                <input type="hidden" name="path" value="<?= h($rel) ?>">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="text" name="new_name" placeholder="newfolder" required style="width:200px;">
                                <button class="btn muted" type="submit">+ New folder</button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php elseif ($action === 'view'):
            $target = safePath($userPath, true);
            $name = ($target !== null) ? basename($target) : '';

            if ($target === null || !is_file($target)): ?>
                <div class="flash error">File not found.</div>
            <?php elseif (matchesAny($name, BLOCKED_READ_PATTERNS)): ?>
                <div class="flash error">Reading <?= h($name) ?> is blocked by policy.</div>
            <?php elseif (matchesAny($name, SECRET_PATTERNS) && !$reveal): ?>
                <div class="card">
                    <h2>⚠ '<?= h($name) ?>' contains secrets</h2>
                    <p>This file typically holds DB credentials, JWT keys, or other sensitive values.
                        Viewing it in a browser can leak those values into browser history, cache, and
                        possibly screenshots or shared screens.</p>
                    <p class="meta">
                        Only reveal this if you're on a trusted device and network. If you just need
                        to know <em>whether</em> a value is set, use the DB viewer or the Shell instead.
                    </p>
                    <a class="btn warn" href="?action=view&path=<?= h(urlencode($userPath)) ?>&reveal=1">I understand — reveal anyway</a>
                    <a class="btn muted" href="?action=browse&path=<?= h(urlencode(dirname($userPath) === '.' ? '' : dirname($userPath))) ?>">Cancel</a>
                </div>
            <?php else:
                $size = filesize($target);
                $tooBig = $size > MAX_VIEW_BYTES;
                $isText = isTextFile($target);
                $ext = extOf($name);
            ?>
                <div class="card">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; gap:8px; flex-wrap:wrap;">
                        <div>
                            <h2 style="display:inline; margin-right:12px;"><?= h($name) ?></h2>
                            <span class="meta"><?= formatBytes($size) ?> · <?= h(date('Y-m-d H:i:s', filemtime($target))) ?></span>
                            <?php if (matchesAny($name, SECRET_PATTERNS)): ?><span class="badge warn">SECRETS</span><?php endif; ?>
                        </div>
                        <div style="display:flex; gap:6px;">
                            <a class="btn small muted" href="?action=download&path=<?= h(urlencode($userPath)) ?>">⬇ Download</a>
                            <?php if (!$readOnly && !matchesAny($name, BLOCKED_EDIT_PATTERNS)): ?>
                                <a class="btn small" href="?action=edit&path=<?= h(urlencode($userPath)) ?>">Edit</a>
                            <?php endif; ?>
                            <a class="btn small muted" href="?action=browse&path=<?= h(urlencode(dirname($userPath) === '.' ? '' : dirname($userPath))) ?>">← Back</a>
                        </div>
                    </div>

                    <?php if ($tooBig): ?>
                        <div class="flash info">File is too large to display (<?= formatBytes($size) ?> > <?= formatBytes(MAX_VIEW_BYTES) ?>). Use Download.</div>
                    <?php elseif (!$isText): ?>
                        <div class="flash info">Binary file. Use Download.</div>
                    <?php else:
                        $content = file_get_contents($target);
                        if ($ext === 'php') {
                            // Safe: highlight_string() escapes content and adds spans
                            echo '<pre class="file">' . highlight_string($content, true) . '</pre>';
                        } elseif ($ext === 'json') {
                            $decoded = json_decode($content, true);
                            if ($decoded !== null) {
                                $content = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                            }
                            echo '<pre class="file">' . h($content) . '</pre>';
                        } else {
                            echo '<pre class="file">' . h($content) . '</pre>';
                        }
                    endif; ?>
                </div>
            <?php endif; ?>

            <?php elseif ($action === 'edit'):
            $target = safePath($userPath, true);
            $name = ($target !== null) ? basename($target) : '';

            if ($target === null || !is_file($target)): ?>
                <div class="flash error">File not found.</div>
            <?php elseif ($readOnly): ?>
                <div class="flash error">Write mode is disabled.</div>
            <?php elseif (matchesAny($name, BLOCKED_EDIT_PATTERNS)): ?>
                <div class="flash error">Editing <?= h($name) ?> is blocked.</div>
            <?php elseif (filesize($target) > MAX_EDIT_BYTES): ?>
                <div class="flash error">File too large to edit.</div>
            <?php elseif (!isTextFile($target)): ?>
                <div class="flash error">Not a text file.</div>
            <?php else:
                $content = file_get_contents($target);
            ?>
                <div class="card">
                    <h2>Edit <code><?= h(relPath($target)) ?></code></h2>
                    <div class="flash warn" style="background:#fef3c7;color:#78350f;border:1px solid #fcd34d;">
                        ⚠ Changes on Render's ephemeral filesystem are lost on the next deploy.
                        <?php if (extOf(basename($target)) === 'php'): ?>
                            <br>PHP files are syntax-checked with <code>php -l</code> before saving — a broken save will be rejected.
                        <?php endif; ?>
                    </div>
                    <form method="post" action="view_render_files.php">
                        <input type="hidden" name="action" value="save_edit">
                        <input type="hidden" name="path" value="<?= h($userPath) ?>">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <textarea name="content" class="edit" spellcheck="false"><?= h($content) ?></textarea>
                        <div style="margin-top:12px; display:flex; gap:8px;">
                            <button class="btn warn" type="submit"
                                onclick="return confirm('Save changes to <?= h(addslashes($userPath)) ?>?\n\nOn Render this will be LOST on the next deploy.')">
                                Save changes
                            </button>
                            <a class="btn muted" href="?action=view&path=<?= h(urlencode($userPath)) ?>">Cancel</a>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <?php elseif ($action === 'download'):
            $target = safePath($userPath, true);
            if ($target === null || !is_file($target)) {
                http_response_code(404);
                die('Not found.');
            }
            $name = basename($target);
            if (matchesAny($name, BLOCKED_READ_PATTERNS)) {
                http_response_code(403);
                die('Blocked.');
            }
            if (matchesAny($name, SECRET_PATTERNS) && !$reveal) {
                // Force reveal-confirm for downloads of secret files too
            ?>
                <main>
                    <div class="card">
                        <h2>⚠ '<?= h($name) ?>' contains secrets</h2>
                        <p>Confirm you want to download this file.</p>
                        <a class="btn warn" href="?action=download&path=<?= h(urlencode($userPath)) ?>&reveal=1">Download anyway</a>
                        <a class="btn muted" href="?action=browse">Cancel</a>
                    </div>
                </main>
        <?php
                exit;
            }
            while (ob_get_level() > 0) ob_end_clean();
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . filesize($target));
            header('X-Content-Type-Options: nosniff');
            readfile($target);
            exit;
        endif; ?>
    </main>
</body>

</html>