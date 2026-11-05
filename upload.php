<?php
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/fetch.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'POST only'], 405);
}

$errors = [
    UPLOAD_ERR_INI_SIZE => 'File exceeds the server upload limit',
    UPLOAD_ERR_FORM_SIZE => 'File is too large',
    UPLOAD_ERR_PARTIAL => 'Upload was interrupted',
    UPLOAD_ERR_NO_FILE => 'No file received',
];

// Step 1 (optional): reserve a code for a multi-file drop so all files share one family
if (($_POST['action'] ?? '') === 'reserve') {
    $code = new_batch_code(db());
    $_SESSION['batches'][$code] = time();
    json_out(['ok' => true, 'code' => $code]);
}

// A given code is only accepted from the session that reserved it (nobody can append to others' families)
$code = normalize_code($_POST['code'] ?? '');
if ($code !== '' && empty($_SESSION['batches'][$code])) {
    json_out(['ok' => false, 'error' => 'Unknown or expired code, please retry'], 400);
}
$code = $code ?: null;

if (!empty($_FILES['file'])) {
    $f = $_FILES['file'];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        json_out(['ok' => false, 'error' => $errors[$f['error']] ?? 'Upload failed'], 400);
    }
    list($row, $err) = store_file($f['tmp_name'], $f['name'], 'upload', null, true, $code);
} elseif (!empty($_POST['url'])) {
    list($row, $err) = fetch_remote_file($_POST['url'], $code);
} else {
    // Post larger than post_max_size arrives empty
    json_out(['ok' => false, 'error' => 'No file received (maybe larger than the server limit)'], 400);
}

if ($err) {
    json_out(['ok' => false, 'error' => $err], 422);
}
json_out(['ok' => true, 'name' => $row['name'], 'size' => human_size($row['size']), 'code' => $row['code'], 'label' => $row['code'] . '-' . $row['seq'], 'pages' => $row['pages']]);
