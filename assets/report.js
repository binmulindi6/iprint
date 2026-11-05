(function () {
  var base = window.IPRINT.base;
  var $ = function (id) { return document.getElementById(id); };
  var LABELS = { upload: 'Uploaded', open: 'Opened', printed: 'Marked printed', unprinted: 'Marked new', 'delete': 'Deleted' };
  var GROUPS = { pdf: 'PDF', jpg: 'Images', jpeg: 'Images', png: 'Images', gif: 'Images', webp: 'Images', bmp: 'Images', tif: 'Images', tiff: 'Images',
    doc: 'Word', docx: 'Word', odt: 'Word', rtf: 'Word', xls: 'Excel', xlsx: 'Excel', ods: 'Excel', csv: 'Excel',
    ppt: 'PowerPoint', pptx: 'PowerPoint', odp: 'PowerPoint', txt: 'Text' };

  var state = { from: null, to: null, preset: '7', cur: [], prev: [], snap: null, hasPrev: true, type: 'all', q: '', limit: 100, showAll: false };

  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function parseYmd(s) { var p = s.split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }
  function addDays(d, n) { var x = new Date(d); x.setDate(x.getDate() + n); return x; }
  function bytes(b) { var u = ['B', 'KB', 'MB', 'GB'], i = 0; while (b >= 1024 && i < 3) { b /= 1024; i++; } return (i ? Math.round(b * 10) / 10 : b) + ' ' + u[i]; }
  function longDate(d) { return d.toLocaleDateString([], { year: 'numeric', month: 'short', day: 'numeric' }); }
  function stamp(ts) { return new Date(ts * 1000).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }); }

  // ---- range handling ----
  function setPreset(p) {
    var today = new Date(); today.setHours(0, 0, 0, 0);
    state.preset = p;
    if (p === 'today') { state.from = today; state.to = today; }
    else if (p === 'yesterday') { state.from = addDays(today, -1); state.to = addDays(today, -1); }
    else if (p === '7') { state.from = addDays(today, -6); state.to = today; }
    else if (p === '30') { state.from = addDays(today, -29); state.to = today; }
    else if (p === 'month') { state.from = new Date(today.getFullYear(), today.getMonth(), 1); state.to = today; }
    else if (p === 'all') { state.from = null; state.to = today; }
    syncInputs(); load();
  }
  function syncInputs() {
    $('from').value = state.from ? ymd(state.from) : '';
    $('to').value = ymd(state.to);
    Array.prototype.forEach.call($('presets').children, function (b) { b.classList.toggle('on', b.dataset.p === state.preset); });
    var q = state.from ? '?from=' + ymd(state.from) + '&to=' + ymd(state.to) : '?range=all';
    try { history.replaceState(null, '', location.pathname + q); } catch (e) {}
  }

  function load() {
    var fromTs = state.from ? Math.floor(state.from.getTime() / 1000) : 0;
    var toTs = Math.floor(addDays(state.to, 1).getTime() / 1000);
    var len = toTs - fromTs;
    state.hasPrev = !!state.from;
    var reqFrom = state.hasPrev ? fromTs - len : 0;
    fetch(base + '/admin/report_api.php?from=' + reqFrom + '&to=' + toTs, { credentials: 'same-origin' })
      .then(function (r) {
        if (r.status === 401) { location.href = base + '/admin/login.php'; throw new Error('auth'); }
        return r.json();
      })
      .then(function (d) {
        state.cur = d.events.filter(function (e) { return e.ts >= fromTs; });
        state.prev = state.hasPrev ? d.events.filter(function (e) { return e.ts < fromTs; }) : [];
        state.snap = d.snapshot;
        state.limit = 100;
        render();
      });
  }

  // ---- aggregation ----
  function count(list, type) { return list.filter(function (e) { return e.type === type; }).length; }
  function sumBytes(list) { return list.filter(function (e) { return e.type === 'upload'; }).reduce(function (a, e) { return a + e.size; }, 0); }
  // pages: uploaded; printed = marked printed minus un-marked (so toggling by mistake isn't double counted)
  function pagesOf(list, type) { return list.filter(function (e) { return e.type === type; }).reduce(function (a, e) { return a + (e.pages || 0); }, 0); }
  function printedPages(list) { return Math.max(0, pagesOf(list, 'printed') - pagesOf(list, 'unprinted')); }
  function noPages(list) { return list.filter(function (e) { return e.type === 'printed' && !e.pages; }).length; }
  function families(list) {
    var s = {}; list.forEach(function (e) { if (e.type === 'upload') s[e.code] = 1; }); return Object.keys(s).length;
  }

  function rangeText() {
    if (!state.from) return 'All time · up to ' + longDate(state.to);
    return state.from.getTime() === state.to.getTime() ? longDate(state.from) : longDate(state.from) + ' – ' + longDate(state.to);
  }

  function render() {
    var text = rangeText();
    $('rangeLine').textContent = text + (state.hasPrev ? ' · compared with the previous period of equal length' : '');
    $('phRange').textContent = text + ' · generated ' + new Date().toLocaleString();
    renderKpis(); renderChart(); renderHours(); renderBreakdowns(); renderTable();
  }

  function delta(cur, prev) {
    if (!state.hasPrev) return '';
    if (!prev) return cur ? '<span class="d up">new activity</span>' : '<span class="d">no change</span>';
    var p = Math.round((cur - prev) / prev * 100);
    if (p === 0) return '<span class="d">no change</span>';
    return '<span class="d ' + (p > 0 ? 'up' : 'down') + '">' + (p > 0 ? '▲ ' : '▼ ') + Math.abs(p) + '% vs previous</span>';
  }

  function renderKpis() {
    var c = state.cur, p = state.prev;
    var cards = [
      ['Files uploaded', count(c, 'upload'), delta(count(c, 'upload'), count(p, 'upload'))],
      ['Print codes (families)', families(c), delta(families(c), families(p))],
      ['Marked printed', count(c, 'printed'), delta(count(c, 'printed'), count(p, 'printed'))],
      ['Pages printed', printedPages(c), delta(printedPages(c), printedPages(p)) + (noPages(c) ? '<span class="d">' + noPages(c) + ' printed file(s) without a page count</span>' : '')],
      ['Pages uploaded', pagesOf(c, 'upload'), delta(pagesOf(c, 'upload'), pagesOf(p, 'upload'))],
      ['Opened on server', count(c, 'open'), delta(count(c, 'open'), count(p, 'open'))],
      ['Data received', bytes(sumBytes(c)), delta(sumBytes(c), sumBytes(p))],
      ['Deleted', count(c, 'delete'), ''],
      ['Waiting to print now', state.snap.pending, '<span class="d">of ' + state.snap.total + ' stored · ' + bytes(state.snap.bytes) + '</span>']
    ];
    $('kpis').innerHTML = cards.map(function (k) {
      return '<div class="kpi"><div class="kl">' + k[0] + '</div><div class="kv">' + k[1] + '</div>' + k[2] + '</div>';
    }).join('');
  }

  // ---- charts (plain SVG) ----
  function bucketKey(ts, mode) {
    var d = new Date(ts * 1000);
    if (mode === 'hour') return ymd(d) + ' ' + pad(d.getHours());
    if (mode === 'month') return d.getFullYear() + '-' + pad(d.getMonth() + 1);
    return ymd(d);
  }
  function renderChart() {
    var from = state.from, to = state.to;
    if (!from) {
      var min = state.cur.length ? new Date(state.cur[state.cur.length - 1].ts * 1000) : to;
      from = new Date(min.getFullYear(), min.getMonth(), min.getDate());
    }
    var days = Math.round((to - from) / 86400000) + 1;
    var mode = days <= 1 ? 'hour' : days > 92 ? 'month' : 'day';
    var keys = [], labels = [];
    if (mode === 'hour') { for (var h = 0; h < 24; h++) { keys.push(ymd(from) + ' ' + pad(h)); labels.push(h + 'h'); } }
    else if (mode === 'day') { for (var i = 0; i < days; i++) { var d = addDays(from, i); keys.push(ymd(d)); labels.push(d.toLocaleDateString([], { month: 'short', day: 'numeric' })); } }
    else {
      var m = new Date(from.getFullYear(), from.getMonth(), 1);
      while (m <= to) { keys.push(m.getFullYear() + '-' + pad(m.getMonth() + 1)); labels.push(m.toLocaleDateString([], { month: 'short', year: '2-digit' })); m = new Date(m.getFullYear(), m.getMonth() + 1, 1); }
    }
    var series = { upload: {}, open: {}, printed: {} };
    state.cur.forEach(function (e) { if (series[e.type]) { var k = bucketKey(e.ts, mode); series[e.type][k] = (series[e.type][k] || 0) + 1; } });
    var max = 1;
    keys.forEach(function (k) { ['upload', 'open', 'printed'].forEach(function (t) { max = Math.max(max, series[t][k] || 0); }); });
    max = niceMax(max);

    var W = 900, H = 240, L = 34, B = 26, T = 10, iw = W - L - 8, ih = H - B - T, n = keys.length, gw = iw / n;
    var bw = Math.max(2, Math.min(18, gw / 3.6));
    var svg = '<svg viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="Activity over time">';
    for (var g = 0; g <= 4; g++) {
      var y = T + ih - ih * g / 4;
      svg += '<line class="grid" x1="' + L + '" x2="' + (W - 8) + '" y1="' + y + '" y2="' + y + '"/><text class="ax" x="' + (L - 6) + '" y="' + (y + 4) + '" text-anchor="end">' + Math.round(max * g / 4) + '</text>';
    }
    var every = Math.ceil(n / 14);
    keys.forEach(function (k, idx) {
      var cx = L + gw * idx + gw / 2;
      [['upload', 's-up', -1], ['open', 's-op', 0], ['printed', 's-pr', 1]].forEach(function (s) {
        var v = series[s[0]][k] || 0;
        if (!v) return;
        var bh = ih * v / max;
        svg += '<rect class="' + s[1] + '" x="' + (cx + s[2] * bw - bw / 2) + '" y="' + (T + ih - bh) + '" width="' + bw + '" height="' + bh + '" rx="2"><title>' + labels[idx] + ' · ' + LABELS[s[0]] + ': ' + v + '</title></rect>';
      });
      if (idx % every === 0) svg += '<text class="ax" x="' + cx + '" y="' + (H - 8) + '" text-anchor="middle">' + labels[idx] + '</text>';
    });
    $('chart').innerHTML = state.cur.length ? svg + '</svg>' : '<p class="empty">No activity in this period.</p>';
  }
  function niceMax(v) { if (v <= 4) return 4; var p = Math.pow(10, Math.floor(Math.log10(v))), f = v / p; return (f <= 2 ? 2 : f <= 5 ? 5 : 10) * p; }

  function hbars(el, rows, cls) {
    var max = Math.max.apply(null, rows.map(function (r) { return r[1]; }).concat([1]));
    el.innerHTML = rows.length ? rows.map(function (r) {
      return '<div class="hb"><span class="hl">' + esc(r[0]) + '</span><span class="ht"><i class="' + cls + '" style="width:' + (r[1] / max * 100) + '%"></i></span><span class="hv">' + r[1] + '</span></div>';
    }).join('') : '<p class="muted small">No data.</p>';
  }
  function renderHours() {
    var hrs = []; for (var i = 0; i < 24; i++) hrs.push(0);
    state.cur.forEach(function (e) { if (e.type === 'upload') hrs[new Date(e.ts * 1000).getHours()]++; });
    var max = Math.max.apply(null, hrs.concat([1]));
    $('hours').innerHTML = '<div class="cols">' + hrs.map(function (v, h) {
      return '<div class="col" title="' + h + 'h: ' + v + ' uploads"><i style="height:' + (v / max * 100) + '%"></i><span>' + (h % 3 === 0 ? h : '') + '</span></div>';
    }).join('') + '</div>';
  }
  function renderBreakdowns() {
    var t = {}, s = {};
    state.cur.forEach(function (e) {
      if (e.type !== 'upload') return;
      var g = GROUPS[e.ext] || 'Other'; t[g] = (t[g] || 0) + 1;
      var src = e.source === 'link' ? 'Pasted link' : 'Direct upload'; s[src] = (s[src] || 0) + 1;
    });
    var sort = function (o) { return Object.keys(o).map(function (k) { return [k, o[k]]; }).sort(function (a, b) { return b[1] - a[1]; }); };
    hbars($('types'), sort(t), 's-up');
    hbars($('sources'), sort(s), 's-pr');
  }

  // ---- table ----
  function filtered() {
    var q = state.q.toLowerCase();
    return state.cur.filter(function (e) {
      return (state.type === 'all' || e.type === state.type) &&
        (!q || (e.label || '').toLowerCase().indexOf(q) !== -1 || (e.name || '').toLowerCase().indexOf(q) !== -1);
    });
  }
  function renderChips() {
    var types = ['all', 'upload', 'open', 'printed', 'unprinted', 'delete'];
    $('chips').innerHTML = types.map(function (t) {
      var n = t === 'all' ? state.cur.length : count(state.cur, t);
      return '<button class="chip' + (state.type === t ? ' on' : '') + '" data-t="' + t + '">' + (t === 'all' ? 'All' : LABELS[t]) + ' <b>' + n + '</b></button>';
    }).join('');
  }
  function renderTable() {
    renderChips();
    var rows = filtered(), shown = state.showAll ? rows : rows.slice(0, state.limit);
    $('tbl').innerHTML = '<thead><tr><th>When</th><th>Event</th><th>Code</th><th>File</th><th>Type</th><th class="r">Pages</th><th class="r">Size</th><th>Source</th></tr></thead><tbody>' +
      (shown.length ? shown.map(function (e) {
        return '<tr><td>' + stamp(e.ts) + '</td><td><span class="ev ev-' + e.type + '">' + LABELS[e.type] + '</span></td><td class="mono">' + esc(e.label) + '</td><td class="nm">' + esc(e.name) + '</td><td>' + esc((e.ext || '').toUpperCase()) + '</td><td class="r">' + (e.pages || '—') + '</td><td class="r">' + bytes(e.size) + '</td><td>' + (e.source === 'link' ? 'Link' : 'Upload') + '</td></tr>';
      }).join('') : '<tr><td colspan="8" class="empty">No matching activity.</td></tr>') + '</tbody>';
    var more = $('more');
    more.hidden = state.showAll || rows.length <= shown.length;
    more.textContent = 'Show more (' + (rows.length - shown.length) + ' left)';
  }

  // ---- events ----
  $('presets').addEventListener('click', function (e) { var b = e.target.closest('button'); if (b) setPreset(b.dataset.p); });
  function customRange() {
    if (!$('to').value) return;
    state.preset = 'custom';
    state.to = parseYmd($('to').value);
    state.from = $('from').value ? parseYmd($('from').value) : null;
    if (state.from && state.from > state.to) { var t = state.from; state.from = state.to; state.to = t; }
    syncInputs(); load();
  }
  $('from').addEventListener('change', customRange);
  $('to').addEventListener('change', customRange);
  $('chips').addEventListener('click', function (e) { var b = e.target.closest('[data-t]'); if (b) { state.type = b.dataset.t; state.limit = 100; renderTable(); } });
  $('q').addEventListener('input', function () { state.q = this.value; state.limit = 100; renderTable(); });
  $('more').addEventListener('click', function () { state.limit += 200; renderTable(); });
  $('printBtn').addEventListener('click', function () { window.print(); });
  window.addEventListener('beforeprint', function () { state.showAll = true; renderTable(); });
  window.addEventListener('afterprint', function () { state.showAll = false; renderTable(); });

  $('csv').addEventListener('click', function () {
    var q = function (v) { v = String(v == null ? '' : v); return /[",\n;]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
    var lines = [['Date', 'Time', 'Event', 'Code', 'File', 'Type', 'Pages', 'Size (bytes)', 'Source']];
    filtered().forEach(function (e) {
      var d = new Date(e.ts * 1000);
      lines.push([ymd(d), pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds()), LABELS[e.type], e.label, e.name, e.ext, e.pages == null ? '' : e.pages, e.size, e.source]);
    });
    var blob = new Blob(['﻿' + lines.map(function (r) { return r.map(q).join(','); }).join('\r\n')], { type: 'text/csv;charset=utf-8' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'iprint-report-' + (state.from ? ymd(state.from) + '_' : 'all_') + ymd(state.to) + '.csv';
    document.body.appendChild(a); a.click(); a.remove();
  });

  // initial range from the URL (?from=&to= or ?range=all), default last 7 days
  var qs = new URLSearchParams(location.search);
  if (qs.get('from') && qs.get('to')) {
    state.preset = 'custom'; state.from = parseYmd(qs.get('from')); state.to = parseYmd(qs.get('to')); syncInputs(); load();
  } else if (qs.get('range') === 'all') setPreset('all');
  else setPreset('7');
})();
