<?php
require __DIR__ . '/../src/bootstrap.php';
require_admin();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#2563eb">
<title>iPrint – Files</title>
<link rel="stylesheet" href="<?= BASE ?>/assets/style.css">
</head>
<body class="app">
<?php $navActive = 'today'; ?>
<header class="bar">
  <div class="brand"><span class="logo">🖨</span> iPrint</div>
  <div class="seg tabs hide-m" id="tabs">
    <button data-v="today" class="on">Today's queue <b id="cToday"></b></button>
    <button data-v="all">All files</button>
  </div>
  <span class="grow"></span>
  <div class="seg" id="view">
    <button data-v="grid" class="on" title="Grid">▦</button>
    <button data-v="list" title="List">☰</button>
  </div>
  <a class="btn hide-m" href="<?= BASE ?>/admin/report.php">📊 Reports</a>
  <a class="btn hide-m" href="<?= BASE ?>/" target="_blank">Upload page ↗</a>
  <form method="post" action="<?= BASE ?>/admin/logout.php">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <button class="btn" type="submit">Sign out</button>
  </form>
</header>

<div class="toolbar">
  <div class="row1">
    <input id="search" class="search" type="search" placeholder="Enter a code or search by file name…" autofocus>
    <span id="summary" class="muted summary"></span>
    <button class="btn filtBtn" id="filtBtn" type="button">⚙ Filters</button>
  </div>
  <div class="row2 allOnly">
    <div class="seg" id="dpresets">
      <button data-d="any" class="on">Any date</button>
      <button data-d="today">Today</button>
      <button data-d="yesterday">Yesterday</button>
      <button data-d="7">7 days</button>
      <button data-d="30">30 days</button>
    </div>
    <label class="dt">From <input type="date" id="dFrom"></label>
    <label class="dt">To <input type="date" id="dTo"></label>
    <div class="seg" id="filter">
      <button data-f="all" class="on">All</button>
      <button data-f="new">Waiting</button>
      <button data-f="printed">Printed</button>
    </div>
    <select id="typeSel" class="selbox" aria-label="File type">
      <option value="all">All types</option><option>PDF</option><option>Images</option><option>Word</option>
      <option>Excel</option><option>PowerPoint</option><option>Text</option><option>Other</option>
    </select>
    <select id="sortSel" class="selbox" aria-label="Sort">
      <option value="new">Newest first</option><option value="old">Oldest first</option>
    </select>
    <button class="btn" id="resetF" type="button">Reset</button>
  </div>
  <div id="homeNote" class="homeNote"></div>
</div>

<div class="layout">
  <section id="files" class="files grid" aria-live="polite"></section>
  <aside id="detail" class="detail hidden"></aside>
</div>
<div id="sheetBg" class="sheetBg"></div>
<div id="bulk" class="bulk hidden"></div>
<?php include __DIR__ . '/_nav.php'; ?>
<div id="toast" class="toast"></div>

<script>window.IPRINT = { base: <?= json_encode(BASE) ?>, csrf: <?= json_encode(csrf_token()) ?> };</script>
<script src="<?= BASE ?>/assets/admin.js"></script>
</body>
</html>
