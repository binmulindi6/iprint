<?php
require __DIR__ . '/../src/bootstrap.php';
require_admin();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#2563eb">
<title>iPrint – Reports</title>
<link rel="stylesheet" href="<?= BASE ?>/assets/style.css">
</head>
<body class="rep">
<?php $navActive = 'reports'; ?>
<header class="bar noprint">
  <div class="brand"><span class="logo">🖨</span> iPrint <span class="muted">/ Reports</span></div>
  <a class="btn hide-m" href="<?= BASE ?>/admin/">← Files</a>
  <form method="post" action="<?= BASE ?>/admin/logout.php">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <button class="btn" type="submit">Sign out</button>
  </form>
</header>

<main class="wrap wide">
  <div class="print-head">
    <h1>iPrint activity report</h1>
    <p id="phRange"></p>
  </div>

  <section class="filters noprint">
    <div class="seg" id="presets">
      <button data-p="today">Today</button>
      <button data-p="yesterday">Yesterday</button>
      <button data-p="7" class="on">7 days</button>
      <button data-p="30">30 days</button>
      <button data-p="month">This month</button>
      <button data-p="all">All time</button>
    </div>
    <label class="dt">From <input type="date" id="from"></label>
    <label class="dt">To <input type="date" id="to"></label>
    <span class="grow"></span>
    <button class="btn" id="csv">⬇ CSV</button>
    <button class="btn primary" id="printBtn">🖨 Print report</button>
  </section>

  <p class="rangeLine muted" id="rangeLine"></p>
  <section id="kpis" class="kpis"></section>

  <section class="card block">
    <div class="blockHead"><h2>Activity over time</h2>
      <div class="legend"><i class="s-up"></i>Uploaded <i class="s-op"></i>Opened <i class="s-pr"></i>Marked printed</div></div>
    <div id="chart"></div>
  </section>

  <div class="two">
    <section class="card block"><h2>Busiest hours <span class="muted small">(uploads)</span></h2><div id="hours"></div></section>
    <section class="card block"><h2>File types</h2><div id="types"></div>
      <h2 class="mt">Source</h2><div id="sources"></div></section>
  </div>

  <section class="card block">
    <div class="blockHead"><h2>Activity log</h2>
      <div class="chips noprint" id="chips"></div>
    </div>
    <input id="q" class="search noprint" type="search" placeholder="Filter by code or file name…">
    <div class="tableWrap"><table id="tbl"></table></div>
    <p class="center noprint"><button class="btn" id="more" hidden>Show more</button></p>
  </section>
</main>
<?php include __DIR__ . '/_nav.php'; ?>
<script>window.IPRINT = { base: <?= json_encode(BASE) ?> };</script>
<script src="<?= BASE ?>/assets/report.js"></script>
</body>
</html>
