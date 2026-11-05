<?php // Bottom navigation (shown on phones only). $navActive: 'today' | 'all' | 'reports' ?>
<nav class="bnav" aria-label="Main">
  <a href="<?= BASE ?>/admin/#today" data-nav="today"<?= ($navActive ?? '') === 'today' ? ' class="on"' : '' ?>>
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 13l2.5-7h11L20 13v6H4z"/><path d="M4 13h5l1 2h4l1-2h5"/></svg>
    <span>Queue</span><b id="nBadge"></b>
  </a>
  <a href="<?= BASE ?>/admin/#all" data-nav="all"<?= ($navActive ?? '') === 'all' ? ' class="on"' : '' ?>>
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
    <span>All files</span>
  </a>
  <a href="<?= BASE ?>/admin/report.php"<?= ($navActive ?? '') === 'reports' ? ' class="on"' : '' ?>>
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 20V10M12 20V4M19 20v-7"/></svg>
    <span>Reports</span>
  </a>
  <a href="<?= BASE ?>/">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4M7 9l5-5 5 5M5 20h14"/></svg>
    <span>Upload</span>
  </a>
</nav>
