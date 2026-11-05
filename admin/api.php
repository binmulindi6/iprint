<?php
require __DIR__ . '/../src/bootstrap.php';
require_admin(true);

$action = $_REQUEST['action'] ?? '';

if ($action === 'list') {
    $rows = db()->query('SELECT * FROM files ORDER BY uploaded_at DESC')->fetchAll();
    json_out(['ok' => true, 'files' => array_map('public_row', $rows)]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_ok($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    json_out(['ok' => false, 'error' => 'Invalid request'], 403);
}

// One id ("id") or many ("ids[]"), processed in the order given
$ids = isset($_POST['ids']) && is_array($_POST['ids']) ? $_POST['ids'] : [$_POST['id'] ?? ''];
$ids = array_values(array_unique(array_slice(array_map('strval', $ids), 0, 500)));
$st = db()->prepare('SELECT * FROM files WHERE id = ?');
$rows = [];
foreach ($ids as $id) {
    $st->execute([$id]);
    if ($r = $st->fetch()) {
        $rows[] = $r;
    }
}
if (!$rows) {
    json_out(['ok' => false, 'error' => 'File not found'], 404);
}

switch ($action) {
    case 'mark':
        // Printing can't be undone: only "printed" is accepted
        if (($_POST['status'] ?? '') !== 'printed') {
            json_out(['ok' => false, 'error' => 'A printed file cannot be marked as new'], 400);
        }
        $printed = true;
        $up = db()->prepare('UPDATE files SET status = ?, printed_at = ? WHERE id = ?');
        foreach ($rows as $row) {
            if ($row['status'] === ($printed ? 'printed' : 'new')) {
                continue; // no change, nothing to log
            }
            $up->execute([$printed ? 'printed' : 'new', $printed ? time() : null, $row['id']]);
            log_event($printed ? 'printed' : 'unprinted', $row);
        }
        json_out(['ok' => true, 'count' => count($rows)]);

    case 'delete':
        foreach ($rows as $row) {
            delete_file_row($row);
        }
        json_out(['ok' => true, 'count' => count($rows)]);

    case 'open':
        if (!cfg('allow_remote_open')) {
            $remote = $_SERVER['REMOTE_ADDR'] ?? '';
            $local = in_array($remote, ['127.0.0.1', '::1'], true) || $remote === ($_SERVER['SERVER_ADDR'] ?? null);
            if (!$local) {
                json_out(['ok' => false, 'error' => 'Opening is only allowed from the server computer itself'], 403);
            }
        }
        $opened = 0;
        $missing = 0;
        foreach ($rows as $row) {
            $path = file_path($row);
            if (!is_file($path)) {
                $missing++;
                continue;
            }
            $arg = escapeshellarg($path);
            if (stripos(PHP_OS, 'WIN') === 0) {
                pclose(popen('start "" ' . $arg, 'r'));
            } elseif (PHP_OS === 'Darwin') {
                exec('open ' . $arg . ' > /dev/null 2>&1 &');
            } else {
                exec('xdg-open ' . $arg . ' > /dev/null 2>&1 &');
            }
            log_event('open', $row);
            $opened++;
            if (count($rows) > 1) {
                usleep(250000); // let each app start, keeps the window order
            }
        }
        if (!$opened) {
            json_out(['ok' => false, 'error' => 'File is missing on disk'], 404);
        }
        json_out(['ok' => true, 'count' => $opened, 'missing' => $missing]);

    default:
        json_out(['ok' => false, 'error' => 'Unknown action'], 400);
}
