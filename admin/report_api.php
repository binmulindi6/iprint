<?php
require __DIR__ . '/../src/bootstrap.php';
require_admin(true);

$from = max(0, (int)($_GET['from'] ?? 0));
$to = (int)($_GET['to'] ?? 0) ?: time() + 86400;

$st = db()->prepare('SELECT ts, type, code, label, name, ext, size, source, pages FROM events
    WHERE ts >= ? AND ts < ? ORDER BY ts DESC LIMIT 50000');
$st->execute([$from, $to]);
$events = $st->fetchAll();
foreach ($events as &$e) {
    $e['ts'] = (int)$e['ts'];
    $e['size'] = (int)$e['size'];
    $e['pages'] = $e['pages'] === null ? null : (int)$e['pages'];
}

$snap = db()->query("SELECT COUNT(*) AS total, COALESCE(SUM(size),0) AS bytes,
    COALESCE(SUM(status='new'),0) AS pending FROM files")->fetch();

json_out([
    'ok' => true,
    'events' => $events,
    'snapshot' => ['total' => (int)$snap['total'], 'bytes' => (int)$snap['bytes'], 'pending' => (int)$snap['pending']],
    'server_time' => time(),
]);
