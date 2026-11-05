(function () {
  var base = window.IPRINT.base, csrf = window.IPRINT.csrf;
  var $files = document.getElementById('files'), $detail = document.getElementById('detail'),
      $toast = document.getElementById('toast'), $search = document.getElementById('search');
  var lastSig = '', files = [], sel = [], order = [], anchor = null, filter = 'all', query = '', toastT;
  // view: "today" = home queue (waiting files uploaded today) · "all" = every file, with date/status/type filters
  var view = 'today', dFrom = '', dTo = '', ftype = 'all', sort = 'new';
  var GROUPS = { pdf: 'PDF', jpg: 'Images', jpeg: 'Images', png: 'Images', gif: 'Images', webp: 'Images', bmp: 'Images', tif: 'Images', tiff: 'Images',
    doc: 'Word', docx: 'Word', odt: 'Word', rtf: 'Word', xls: 'Excel', xlsx: 'Excel', ods: 'Excel', csv: 'Excel',
    ppt: 'PowerPoint', pptx: 'PowerPoint', odp: 'PowerPoint', txt: 'Text' };
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function dayStart(s) { var p = s.split('-'); return new Date(+p[0], +p[1] - 1, +p[2]).getTime() / 1000; }
  function dayEnd(s) { var p = s.split('-'); return new Date(+p[0], +p[1] - 1, +p[2] + 1).getTime() / 1000; } // exclusive
  function todayStart() { var d = new Date(); d.setHours(0, 0, 0, 0); return d.getTime() / 1000; }

  function toast(msg, bad) {
    $toast.textContent = msg;
    $toast.className = 'toast show' + (bad ? ' bad' : '');
    clearTimeout(toastT);
    toastT = setTimeout(function () { $toast.className = 'toast'; }, 2800);
  }
  function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
  function fmt(ts) { return new Date(ts * 1000).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }); }
  function url(id, inline) { return base + '/admin/file.php?id=' + encodeURIComponent(id) + (inline ? '&inline=1' : ''); }

  function api(action, params) {
    var body = new URLSearchParams();
    Object.keys(params || {}).forEach(function (k) {
      var v = params[k];
      if (Array.isArray(v)) v.forEach(function (x) { body.append(k + '[]', x); });
      else body.append(k, v);
    });
    return fetch(base + '/admin/api.php?action=' + action, {
      method: 'POST', headers: { 'X-CSRF-Token': csrf }, body: body, credentials: 'same-origin'
    }).then(function (r) {
      if (r.status === 401) { location.href = base + '/admin/login.php'; throw new Error('auth'); }
      return r.json();
    });
  }

  function load() {
    return fetch(base + '/admin/api.php?action=list', { credentials: 'same-origin' })
      .then(function (r) {
        if (r.status === 401) { location.href = base + '/admin/login.php'; throw new Error('auth'); }
        return r.json();
      })
      .then(function (d) {
        var sig = JSON.stringify(d.files);
        if (sig === lastSig) return; // unchanged: avoid re-rendering (keeps PDF preview stable)
        lastSig = sig; files = d.files;
        sel = sel.filter(function (id) { return files.some(function (f) { return f.id === id; }); });
        render();
      })
      .catch(function () {});
  }

  function matchQ(f) {
    var q = query.trim();
    if (!q) return true;
    var qc = q.replace(/[^a-z0-9]/g, '');
    return f.name.toLowerCase().indexOf(q) !== -1 || f.label.toLowerCase().indexOf(q) !== -1 || (qc.length === 5 && f.code.toLowerCase() === qc);
  }
  function inToday(f) { return f.status === 'new' && f.uploaded_at >= todayStart(); }
  function visible() {
    var list = files.filter(function (f) {
      if (!matchQ(f)) return false;
      if (view === 'today') return inToday(f);
      if (filter !== 'all' && f.status !== filter) return false;
      if (dFrom && f.uploaded_at < dayStart(dFrom)) return false;
      if (dTo && f.uploaded_at >= dayEnd(dTo)) return false;
      if (ftype !== 'all' && (GROUPS[f.ext] || 'Other') !== ftype) return false;
      return true;
    });
    if (view === 'all' && sort === 'old') list = list.slice().sort(function (a, b) { return a.uploaded_at - b.uploaded_at; });
    return list;
  }

  function render() {
    var list = visible();
    var fam = list.length && /^[a-z0-9]{5}$/.test(query.replace(/[^a-z0-9]/g, '')) ? list.filter(function (f) { return f.code.toLowerCase() === query.replace(/[^a-z0-9]/g, ''); }) : [];
    if (fam.length) { list = fam.slice().sort(function (a, b) { return a.seq - b.seq; }); }
    var famBar = fam.length
      ? '<div class="famBar"><span class="code">' + fam[0].code + '</span><span class="grow muted">' + fam.length + ' file' + (fam.length > 1 ? 's' : '') + '</span>' +
        '<button class="btn primary" data-open-all="' + fam[0].code + '">🖨 Open all</button></div>'
      : '';
    order = list.map(function (f) { return f.id; });
    sel = sel.filter(function (id) { return order.indexOf(id) !== -1; }); // e.g. a file just marked printed leaves the queue
    $files.classList.toggle('selecting', sel.length > 0);
    renderSummary(list);
    if (!list.length) {
      var msg;
      if (view === 'today') {
        var elsewhere = query.trim() && files.some(matchQ);
        msg = files.length === 0 ? 'No files yet. Uploaded files will appear here.'
          : elsewhere ? 'No match in today\'s queue. <button class="btn" data-goall>Search all files</button>'
          : query.trim() ? 'No matching files.'
          : 'Nothing waiting. New uploads will appear here automatically.';
      } else msg = files.length ? 'No files match these filters.' : 'No files yet.';
      $files.innerHTML = '<div class="empty">' + msg + '</div>';
    } else {
      $files.innerHTML = famBar + list.map(function (f) {
        var thumb = f.image ? '<img loading="lazy" src="' + url(f.id, true) + '" alt="">' : esc(f.ext.toUpperCase());
        var on = sel.indexOf(f.id) !== -1;
        return '<button class="item' + (on ? ' sel' : '') + '" data-id="' + f.id + '" aria-pressed="' + on + '">' +
          '<span class="chk" aria-hidden="true">' + (on ? '✓' : '') + '</span>' +
          '<div class="thumb">' + thumb + '</div>' +
          '<div class="meta"><span class="lbl">' + f.label + '</span><span class="nm" title="' + esc(f.name) + '">' + esc(f.name) + '</span>' +
          '<span class="sub">' + pg(f) + f.size_h + ' · ' + fmt(f.uploaded_at) + '</span></div>' +
          '<span class="badge ' + f.status + '">' + f.status + '</span></button>';
      }).join('');
    }
    renderDetail();
    renderBulk();
  }

  function renderSummary(list) {
    var pages = list.reduce(function (a, f) { return a + (f.pages || 0); }, 0);
    var n = list.length;
    var nt = files.filter(inToday).length || '';
    $('cToday').textContent = nt; $('nBadge').textContent = nt;
    var nf = (dFrom || dTo ? 1 : 0) + (filter !== 'all' ? 1 : 0) + (ftype !== 'all' ? 1 : 0) + (sort !== 'new' ? 1 : 0);
    $('filtBtn').textContent = '⚙ Filters' + (nf ? ' (' + nf + ')' : '');
    $('summary').textContent = n + (n === 1 ? ' file' : ' files') + (pages ? ' · ' + pages + ' pages' : '') + (view === 'today' ? ' waiting today' : '');
    var older = files.filter(function (f) { return f.status === 'new' && f.uploaded_at < todayStart(); }).length;
    $('homeNote').innerHTML = view === 'today' && older
      ? older + ' older file' + (older > 1 ? 's' : '') + ' still waiting to be printed. <button class="link" data-older>View them</button>'
      : '';
  }
  function $(id) { return document.getElementById(id); }
  function pg(f) { return f.pages ? f.pages + (f.pages === 1 ? ' page · ' : ' pages · ') : ''; }
  function one() { return sel.length === 1 ? sel[0] : null; }
  function byId(id) { return files.filter(function (x) { return x.id === id; })[0]; }
  // selected ids in print order: by family code, then number
  function sortedSel() {
    return sel.map(byId).filter(Boolean).sort(function (a, b) {
      return a.code === b.code ? a.seq - b.seq : (a.code < b.code ? -1 : 1);
    }).map(function (f) { return f.id; });
  }

  var $bulk = document.getElementById('bulk');
  function renderBulk() {
    if (sel.length < 2) { $bulk.classList.add('hidden'); return; }
    $bulk.classList.remove('hidden');
    var pagesSum = sel.map(byId).reduce(function (a, f) { return a + (f && f.pages || 0); }, 0);
    $bulk.innerHTML =
      '<strong>' + sel.length + ' selected</strong>' +
      (pagesSum ? '<span class="muted">' + pagesSum + ' pages</span>' : '') +
      '<button class="btn" data-b="all">Select all (' + order.length + ')</button>' +
      '<button class="btn" data-b="clear">Clear</button><span class="sep"></span>' +
      '<button class="btn primary" data-b="open">🖨 Open</button>' +
      '<button class="btn" data-b="printed">✓ Mark printed</button>' +
      '<button class="btn danger" data-b="delete">Delete</button>';
  }

  function renderDetail() {
    var f = byId(one());
    if (!f) { $detail.classList.add('hidden'); $detail.innerHTML = ''; document.body.classList.remove('sheet-open'); return; }
    var pv = f.image ? '<img src="' + url(f.id, true) + '" alt="">'
      : f.ext === 'pdf' ? '<iframe src="' + url(f.id, true) + '" title="Preview"></iframe>'
      : esc(f.ext.toUpperCase());
    $detail.classList.remove('hidden');
    document.body.classList.add('sheet-open');
    $detail.innerHTML =
      '<div class="dhead"><span class="lbl">' + f.label + '</span><button class="close" data-a="close" aria-label="Close">✕</button></div><div class="pv">' + pv + '</div><h2>' + esc(f.name) + '</h2>' +
      '<dl><dt>Pages</dt><dd>' + (f.pages || '—') + '</dd><dt>Size</dt><dd>' + f.size_h + '</dd><dt>Uploaded</dt><dd>' + fmt(f.uploaded_at) + '</dd>' +
      '<dt>Source</dt><dd>' + (f.source === 'link' ? 'Link' : 'Upload') + '</dd>' +
      '<dt>Status</dt><dd>' + f.status + (f.printed_at ? ' · ' + fmt(f.printed_at) : '') + '</dd></dl>' +
      '<div class="actions">' +
      '<button class="btn primary" data-a="open">🖨 Open on this computer</button>' +
      (f.previewable ? '<a class="btn" target="_blank" href="' + url(f.id, true) + '">Preview in browser ↗</a>' : '') +
      '<a class="btn" href="' + url(f.id) + '">Download</a>' +
      (f.status === 'printed' ? '' : '<button class="btn" data-a="mark">✓ Mark as printed</button>') +
      '<button class="btn danger" data-a="delete">Delete</button></div>';
  }

  function openMany(ids) {
    return api('open', { ids: ids }).then(function (r) {
      toast(r.ok ? 'Opened ' + r.count + ' file' + (r.count > 1 ? 's' : '') + (r.missing ? ' (' + r.missing + ' missing)' : '') : r.error, !r.ok);
    });
  }

  function setView(v) {
    view = v; sel = [];
    document.body.classList.toggle('view-all', v === 'all');
    Array.prototype.forEach.call($('tabs').children, function (b) { b.classList.toggle('on', b.dataset.v === v); });
    Array.prototype.forEach.call(document.querySelectorAll('[data-nav]'), function (a) { a.classList.toggle('on', a.dataset.nav === v); });
    try { history.replaceState(null, '', v === 'all' ? '#all' : '#today'); } catch (e) {}
    render();
  }
  function setStatus(v) {
    filter = v;
    Array.prototype.forEach.call($('filter').children, function (b) { b.classList.toggle('on', b.dataset.f === v); });
  }
  $files.addEventListener('click', function (e) {
    if (e.target.closest('[data-goall]')) { setStatus('all'); setView('all'); return; }
    var all = e.target.closest('[data-open-all]');
    if (all) {
      var fam = files.filter(function (f) { return f.code === all.dataset.openAll; }).sort(function (a, b) { return a.seq - b.seq; });
      openMany(fam.map(function (f) { return f.id; }));
      return;
    }
    var it = e.target.closest('.item');
    if (!it) return;
    var id = it.dataset.id, i = sel.indexOf(id);
    if (e.shiftKey && anchor && order.indexOf(anchor) !== -1) {
      // range from the anchor to this item
      var a = order.indexOf(anchor), b = order.indexOf(id);
      var range = order.slice(Math.min(a, b), Math.max(a, b) + 1);
      sel = e.ctrlKey || e.metaKey ? sel.concat(range.filter(function (x) { return sel.indexOf(x) === -1; })) : range;
    } else if (e.ctrlKey || e.metaKey || e.target.closest('.chk') || sel.length > 1) {
      // toggle one item (checkbox, ctrl/cmd-click, or any click while several are selected)
      if (i === -1) sel.push(id); else sel.splice(i, 1);
      anchor = id;
    } else {
      sel = i === -1 ? [id] : [];
      anchor = id;
    }
    render();
  });

  $bulk.addEventListener('click', function (e) {
    var b = e.target.closest('[data-b]');
    if (!b) return;
    var op = b.dataset.b, ids = sortedSel();
    if (op === 'all') { sel = order.slice(); render(); }
    else if (op === 'clear') { sel = []; render(); }
    else if (op === 'open') { openMany(ids); }
    else if (op === 'printed') {
      api('mark', { ids: ids, status: op }).then(function (r) {
        toast(r.ok ? r.count + ' marked as ' + op : r.error, !r.ok);
        sel = []; lastSig = ''; load();
      });
    } else if (op === 'delete') {
      if (!confirm('Delete ' + ids.length + ' files permanently?')) return;
      api('delete', { ids: ids }).then(function (r) {
        toast(r.ok ? r.count + ' deleted' : r.error, !r.ok);
        sel = []; lastSig = ''; load();
      });
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && sel.length && document.activeElement !== $search) { sel = []; render(); }
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'a' && document.activeElement !== $search) {
      e.preventDefault(); sel = order.slice(); render();
    }
  });

  $detail.addEventListener('click', function (e) {
    var b = e.target.closest('[data-a]');
    var f = byId(one());
    if (!b || !f) return;
    var a = b.dataset.a;
    if (a === 'close') { sel = []; render(); return; }
    if (a === 'open') {
      api('open', { id: f.id }).then(function (r) {
        toast(r.ok ? 'Opened on the server computer' : r.error, !r.ok);
      });
    } else if (a === 'mark') {
      api('mark', { id: f.id, status: 'printed' }).then(function (r) { toast(r.ok ? 'Marked as printed' : r.error, !r.ok); sel = []; lastSig = ''; load(); });
    } else if (a === 'delete') {
      if (!confirm('Delete "' + f.name + '" permanently?')) return;
      api('delete', { id: f.id }).then(function (r) {
        if (r.ok) { sel = []; toast('Deleted'); load(); } else toast(r.error, true);
      });
    }
  });

  $search.addEventListener('input', function () { query = $search.value.toLowerCase(); render(); });
  function seg(id, attr, fn) {
    document.getElementById(id).addEventListener('click', function (e) {
      var b = e.target.closest('button'); if (!b) return;
      Array.prototype.forEach.call(this.children, function (c) { c.classList.toggle('on', c === b); });
      fn(b.dataset[attr]);
    });
  }
  seg('filter', 'f', function (v) { filter = v; render(); });
  seg('tabs', 'v', function (v) { setView(v); });

  // date range (All files)
  function setRange(from, to, preset) {
    dFrom = from; dTo = to;
    $('dFrom').value = from; $('dTo').value = to;
    Array.prototype.forEach.call($('dpresets').children, function (b) { b.classList.toggle('on', b.dataset.d === preset); });
    render();
  }
  $('dpresets').addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) return;
    var t = new Date(), d = b.dataset.d, from = '', to = '';
    if (d === 'today') { from = to = ymd(t); }
    else if (d === 'yesterday') { var y = new Date(t); y.setDate(y.getDate() - 1); from = to = ymd(y); }
    else if (d === '7' || d === '30') { var s0 = new Date(t); s0.setDate(s0.getDate() - (+d - 1)); from = ymd(s0); to = ymd(t); }
    setRange(from, to, d);
  });
  function customDates() {
    dFrom = $('dFrom').value; dTo = $('dTo').value;
    if (dFrom && dTo && dFrom > dTo) { var x = dFrom; dFrom = dTo; dTo = x; $('dFrom').value = dFrom; $('dTo').value = dTo; }
    Array.prototype.forEach.call($('dpresets').children, function (b) { b.classList.remove('on'); });
    render();
  }
  $('dFrom').addEventListener('change', customDates);
  $('dTo').addEventListener('change', customDates);
  $('typeSel').addEventListener('change', function () { ftype = this.value; render(); });
  $('sortSel').addEventListener('change', function () { sort = this.value; render(); });
  $('resetF').addEventListener('click', function () {
    ftype = 'all'; sort = 'new'; $('typeSel').value = 'all'; $('sortSel').value = 'new'; setStatus('all'); setRange('', '', 'any');
    query = ''; $search.value = ''; render();
  });
  $('homeNote').addEventListener('click', function (e) {
    if (e.target.closest('[data-older]')) { setStatus('new'); setRange('', '', 'any'); setView('all'); }
  });
  seg('view', 'v', function (v) {
    $files.className = 'files ' + v;
    try { localStorage.setItem('iprint-view', v); } catch (e) {}
  });
  try {
    var v = localStorage.getItem('iprint-view') || (window.matchMedia('(max-width:760px)').matches ? 'list' : 'grid');
    if (v === 'list') {
      $files.className = 'files list';
      document.querySelectorAll('#view button').forEach(function (b) { b.classList.toggle('on', b.dataset.v === 'list'); });
    }
  } catch (e) {}

  $('sheetBg').addEventListener('click', function () { sel = []; render(); });
  $('filtBtn').addEventListener('click', function () { document.body.classList.toggle('filters-open'); });
  window.addEventListener('hashchange', function () { var v = location.hash === '#all' ? 'all' : 'today'; if (v !== view) setView(v); });
  document.querySelectorAll('[data-nav]').forEach(function (a) { a.classList.toggle('on', a.dataset.nav === 'today'); });
  if (location.hash === '#all') setView('all');
  load();
  setInterval(load, 8000); // pick up new uploads automatically
})();
