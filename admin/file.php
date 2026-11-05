<?php
require __DIR__ . '/../src/bootstrap.php';
require_admin();

$st = db()->prepare('SELECT * FROM files WHERE id = ?');
$st->execute([$_GET['id'] ?? '']);
$row = $st->fetch();
$path = $row ? file_path($row) : '';
if (!$row || !is_file($path)) {
    http_response_code(404);
    exit('Not found');
}

$inline = !empty($_GET['inline']) && is_previewable($row['ext']);
$types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
          'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp'];
header('Content-Type: ' . ($inline ? $types[$row['ext']] : 'application/octet-stream'));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'");
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
    . '; filename="' . str_replace('"', '', $row['name']) . '"; filename*=UTF-8\'\'' . rawurlencode($row['name']));
readfile($path);
