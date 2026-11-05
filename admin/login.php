<?php
require __DIR__ . '/../src/bootstrap.php';
if (is_admin()) {
    header('Location: ' . BASE . '/admin/');
    exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $pdo = db();
    $pdo->prepare('DELETE FROM login_attempts WHERE ts < ?')->execute([time() - cfg('login_window')]);
    $st = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ?');
    $st->execute([$ip]);
    if ((int)$st->fetchColumn() >= cfg('max_login_attempts')) {
        $error = 'Too many attempts. Try again in a few minutes.';
    } elseif (csrf_ok($_POST['csrf'] ?? '')
        && hash_equals(cfg('admin_user'), (string)($_POST['username'] ?? ''))
        && password_verify((string)($_POST['password'] ?? ''), cfg('admin_pass_hash'))) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $pdo->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
        header('Location: ' . BASE . '/admin/');
        exit;
    } else {
        $pdo->prepare('INSERT INTO login_attempts (ip, ts) VALUES (?, ?)')->execute([$ip, time()]);
        $error = 'Wrong username or password.';
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#2563eb">
<title>iPrint – Printer login</title>
<link rel="stylesheet" href="<?= BASE ?>/assets/style.css">
</head>
<body>
<main class="wrap tiny">
  <div class="brand center"><span class="logo">🖨</span> iPrint</div>
  <form method="post" class="card login">
    <h1>Printer side</h1>
    <?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <label>Username<input name="username" autocomplete="username" autofocus required></label>
    <label>Password<input name="password" type="password" autocomplete="current-password" required></label>
    <button class="btn primary block" type="submit">Sign in</button>
    <p class="center"><a class="muted" href="<?= BASE ?>/">← Back to upload</a></p>
  </form>
</main>
</body>
</html>
