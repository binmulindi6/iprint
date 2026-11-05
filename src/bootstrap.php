<?php
$CONFIG = require __DIR__ . '/../config.php';
require_once __DIR__ . '/pages.php';

define('ROOT_DIR', dirname(__DIR__));
// IPRINT_STORAGE lets tests run against a throw-away folder
define('STORAGE_DIR', getenv('IPRINT_STORAGE') ?: ROOT_DIR . '/storage');
define('UPLOAD_DIR', STORAGE_DIR . '/uploads');
define('DB_FILE', STORAGE_DIR . '/app.sqlite');

// Base URL of the app (works whether it lives in /iprint or at the web root)
$__dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
if (basename($__dir) === 'admin') {
    $__dir = dirname($__dir);
}
define('BASE', rtrim($__dir, '/'));

session_name('iprint_sid');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
session_start();

if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0775, true);
}

function cfg($key) {
    global $CONFIG;
    return $CONFIG[$key];
}

function db() {
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA busy_timeout=5000');
    $pdo->exec('CREATE TABLE IF NOT EXISTS files (
        id TEXT PRIMARY KEY,
        name TEXT NOT NULL,
        stored_name TEXT NOT NULL,
        ext TEXT NOT NULL,
        mime TEXT,
        size INTEGER NOT NULL,
        source TEXT NOT NULL DEFAULT "upload",
        source_url TEXT,
        status TEXT NOT NULL DEFAULT "new",
        uploaded_at INTEGER NOT NULL,
        printed_at INTEGER,
        ip TEXT
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS batches (code TEXT PRIMARY KEY, created_at INTEGER NOT NULL)');
    // Migration: every file belongs to a "family" with a 5-char code (AB123-1, AB123-2 ...)
    $cols = array_column($pdo->query('PRAGMA table_info(files)')->fetchAll(), 'name');
    if (!in_array('code', $cols, true)) {
        $pdo->exec('ALTER TABLE files ADD COLUMN code TEXT');
        $pdo->exec('ALTER TABLE files ADD COLUMN seq INTEGER');
    }
    // Give legacy files (no code yet) a family of their own; safe to re-run
    foreach ($pdo->query('SELECT id FROM files WHERE code IS NULL ORDER BY uploaded_at')->fetchAll() as $r) {
        $c = new_batch_code($pdo);
        $pdo->prepare('UPDATE files SET code = ?, seq = 1 WHERE id = ?')->execute([$c, $r['id']]);
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS files_code ON files (code)');
    // Activity log for reports (survives file deletion: it keeps its own snapshot of the file)
    $pdo->exec('CREATE TABLE IF NOT EXISTS events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ts INTEGER NOT NULL,
        type TEXT NOT NULL,
        file_id TEXT, code TEXT, label TEXT, name TEXT, ext TEXT, size INTEGER, source TEXT, ip TEXT, pages INTEGER
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS events_ts ON events (ts)');
    if ((int)$pdo->query('SELECT COUNT(*) FROM events')->fetchColumn() === 0) {
        // First run with reports: rebuild history from what the files table knows
        $pdo->exec("INSERT INTO events (ts, type, file_id, code, label, name, ext, size, source, ip)
            SELECT uploaded_at, 'upload', id, code, code || '-' || seq, name, ext, size, source, ip FROM files");
        $pdo->exec("INSERT INTO events (ts, type, file_id, code, label, name, ext, size, source, ip)
            SELECT printed_at, 'printed', id, code, code || '-' || seq, name, ext, size, source, ip FROM files WHERE printed_at IS NOT NULL");
    }
    // Migration: page counts (files + activity log). Counts existing files once.
    $fileCols = array_column($pdo->query('PRAGMA table_info(files)')->fetchAll(), 'name');
    $evCols = array_column($pdo->query('PRAGMA table_info(events)')->fetchAll(), 'name');
    $needBackfill = false;
    if (!in_array('pages', $fileCols, true)) {
        $pdo->exec('ALTER TABLE files ADD COLUMN pages INTEGER');
        $needBackfill = true;
    }
    if (!in_array('pages', $evCols, true)) {
        $pdo->exec('ALTER TABLE events ADD COLUMN pages INTEGER');
        $needBackfill = true;
    }
    if ($needBackfill) {
        foreach ($pdo->query('SELECT id, stored_name, ext FROM files WHERE pages IS NULL')->fetchAll() as $f) {
            $path = UPLOAD_DIR . '/' . $f['id'] . '/' . $f['stored_name'];
            $n = is_file($path) ? count_pages($path, $f['ext']) : null;
            if ($n !== null) {
                $pdo->prepare('UPDATE files SET pages = ? WHERE id = ?')->execute([$n, $f['id']]);
            }
        }
        $pdo->exec('UPDATE events SET pages = (SELECT pages FROM files WHERE files.id = events.file_id) WHERE pages IS NULL');
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS login_attempts (ip TEXT NOT NULL, ts INTEGER NOT NULL)');
    return $pdo;
}

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function json_out($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function human_size($b) {
    $u = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($b >= 1024 && $i < 3) {
        $b /= 1024;
        $i++;
    }
    return ($i ? round($b, 1) : $b) . ' ' . $u[$i];
}

// ---- family codes ----
const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // no 0/O/1/I/L to avoid mix-ups

function new_batch_code($pdo) {
    $n = strlen(CODE_ALPHABET);
    for ($try = 0; $try < 50; $try++) {
        $code = '';
        for ($i = 0; $i < 5; $i++) {
            $code .= CODE_ALPHABET[random_int(0, $n - 1)];
        }
        $st = $pdo->prepare('INSERT OR IGNORE INTO batches (code, created_at) VALUES (?, ?)');
        $st->execute([$code, time()]);
        if ($st->rowCount() === 1) {
            return $code;
        }
    }
    throw new RuntimeException('Could not allocate a code');
}

function normalize_code($c) {
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$c));
}

// ---- auth / csrf ----
function is_admin() {
    return !empty($_SESSION['admin']);
}

function require_admin($json = false) {
    if (!is_admin()) {
        if ($json) {
            json_out(['ok' => false, 'error' => 'Not logged in'], 401);
        }
        header('Location: ' . BASE . '/admin/login.php');
        exit;
    }
}

function csrf_token() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_ok($token) {
    return !empty($_SESSION['csrf']) && is_string($token) && hash_equals($_SESSION['csrf'], $token);
}

// ---- files ----
function safe_filename($name) {
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[^\p{L}\p{N}\.\-_ \(\)]+/u', '_', $name);
    $name = trim($name, ". \t\n\r");
    if ($name === '') {
        $name = 'file';
    }
    if (function_exists('mb_substr')) {
        $name = mb_substr($name, 0, 120);
    }
    return $name;
}

function file_ext($name) {
    return strtolower(pathinfo($name, PATHINFO_EXTENSION));
}

function is_previewable($ext) {
    return in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true);
}

function is_image_ext($ext) {
    return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true);
}

function file_path($row) {
    return UPLOAD_DIR . '/' . $row['id'] . '/' . $row['stored_name'];
}

/**
 * Validate and store a file already on disk.
 * Returns [row, null] on success or [null, error message].
 */
function store_file($tmp, $origName, $source, $sourceUrl, $isUploaded, $code = null) {
    $name = safe_filename($origName);
    $ext = file_ext($name);
    if (!in_array($ext, cfg('allowed_ext'), true)) {
        return [null, 'File type ".' . $ext . '" is not allowed'];
    }
    $size = filesize($tmp);
    if ($size === 0) {
        return [null, 'The file is empty'];
    }
    if ($size > cfg('max_size')) {
        return [null, 'File is too large (max ' . human_size(cfg('max_size')) . ')'];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: 'application/octet-stream';
    if ($ext === 'pdf' && $mime !== 'application/pdf') {
        return [null, 'This does not look like a real PDF'];
    }
    if (is_image_ext($ext) && strpos($mime, 'image/') !== 0) {
        return [null, 'This does not look like a real image'];
    }

    $pages = count_pages($tmp, $ext);
    $pdo = db();
    if (!$code) {
        $code = new_batch_code($pdo);
    }
    $id = bin2hex(random_bytes(8));
    $dir = UPLOAD_DIR . '/' . $id;
    mkdir($dir, 0775, true);

    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $st = $pdo->prepare('SELECT COALESCE(MAX(seq), 0) + 1 FROM files WHERE code = ?');
        $st->execute([$code]);
        $seq = (int)$st->fetchColumn();

        // The code is part of the stored name so it shows in the title bar of Word / Preview
        $stored = $code . '-' . $seq . '_' . $name;
        $dest = $dir . '/' . $stored;
        $moved = $isUploaded ? move_uploaded_file($tmp, $dest) : rename($tmp, $dest);
        if (!$moved) {
            throw new RuntimeException('Could not save the file on the server');
        }
        $row = [
            'id' => $id, 'name' => $name, 'stored_name' => $stored, 'ext' => $ext, 'mime' => $mime,
            'size' => $size, 'source' => $source, 'source_url' => $sourceUrl,
            'status' => 'new', 'uploaded_at' => time(), 'printed_at' => null,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'code' => $code, 'seq' => $seq, 'pages' => $pages,
        ];
        $cols = array_keys($row);
        $pdo->prepare('INSERT INTO files (' . implode(',', $cols) . ') VALUES (:' . implode(',:', $cols) . ')')->execute($row);
        log_event('upload', $row);
        $pdo->exec('COMMIT');
    } catch (Exception $e) {
        $pdo->exec('ROLLBACK');
        @unlink($dir . '/' . ($stored ?? ''));
        @rmdir($dir);
        return [null, 'Could not save the file on the server'];
    }
    return [$row, null];
}

function log_event($type, $row) {
    db()->prepare('INSERT INTO events (ts, type, file_id, code, label, name, ext, size, source, ip, pages)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
        time(), $type, $row['id'], $row['code'], $row['code'] . '-' . $row['seq'], $row['name'],
        $row['ext'], (int)$row['size'], $row['source'], $_SERVER['REMOTE_ADDR'] ?? '',
        isset($row['pages']) ? (int)$row['pages'] : null,
    ]);
}

function delete_file_row($row) {
    log_event('delete', $row);
    $dir = UPLOAD_DIR . '/' . $row['id'];
    foreach (glob($dir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
    db()->prepare('DELETE FROM files WHERE id = ?')->execute([$row['id']]);
}

function public_row($r) {
    return [
        'id' => $r['id'], 'name' => $r['name'], 'ext' => $r['ext'],
        'code' => $r['code'], 'seq' => (int)$r['seq'], 'label' => $r['code'] . '-' . $r['seq'], 'size' => (int)$r['size'],
        'size_h' => human_size($r['size']), 'pages' => isset($r['pages']) ? (int)$r['pages'] : null, 'source' => $r['source'], 'status' => $r['status'],
        'uploaded_at' => (int)$r['uploaded_at'], 'printed_at' => $r['printed_at'] ? (int)$r['printed_at'] : null,
        'previewable' => is_previewable($r['ext']), 'image' => is_image_ext($r['ext']),
    ];
}
