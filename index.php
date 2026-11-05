<?php
require __DIR__ . '/src/bootstrap.php';
$exts = strtoupper(implode(', ', cfg('allowed_ext')));
$maxMb = round(cfg('max_size') / 1048576);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#2563eb">
<title>iPrint – Send a file to print</title>
<link rel="stylesheet" href="<?= BASE ?>/assets/style.css">
</head>
<body>
<div class="dropAll" aria-hidden="true"><div><span>⬆</span>Drop to send to the printer</div></div>
<main class="wrap narrow">
  <header class="top">
    <div class="brand"><span class="logo">🖨</span> iPrint</div>
    <a class="muted" href="<?= BASE ?>/admin/">Printer side →</a>
  </header>

  <h1>Send a file to print</h1>
  <p class="muted">Drop your files here. You'll get a 5-character code &mdash; give it to the person at the printer.</p>

  <div id="drop" class="drop" tabindex="0">
    <div class="drop-ico">⬆</div>
    <strong><span class="dnd">Drag &amp; drop files here</span><span class="tap">Tap to choose files</span></strong>
    <span class="muted dnd">or click to choose</span>
    <input id="picker" type="file" multiple hidden>
  </div>
  <p class="hint">Up to <?= $maxMb ?> MB each · <?= h($exts) ?></p>

  <div class="or"><span>or paste a link</span></div>
  <form id="linkForm" class="row">
    <input id="linkInput" type="url" placeholder="https://example.com/document.pdf" required>
    <button class="btn primary" type="submit">Fetch</button>
  </form>

  <ul id="queue" class="queue"></ul>
</main>
<script src="<?= BASE ?>/assets/upload.js"></script>
</body>
</html>
