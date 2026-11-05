<?php
require __DIR__ . '/../src/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok($_POST['csrf'] ?? '')) {
    $_SESSION = [];
    session_destroy();
}
header('Location: ' . BASE . '/admin/login.php');
