(function () {
  var drop = document.getElementById('drop');
  var picker = document.getElementById('picker');
  var queue = document.getElementById('queue');
  var form = document.getElementById('linkForm');
  var input = document.getElementById('linkInput');

  // One drop (or one link) = one family with a shared code: AB123-1, AB123-2 ...
  function newGroup() {
    var li = document.createElement('li');
    li.className = 'group';
    li.innerHTML =
      '<div class="code-row"><div><span class="muted">Your print code</span>' +
      '<div class="code">· · · · ·</div></div>' +
      '<button type="button" class="btn" hidden>Copy</button></div>' +
      '<p class="muted small">Give this code to the person at the printer.</p><ul class="files-in"></ul>';
    queue.prepend(li);
    return li;
  }
  function reserve() {
    var fd = new FormData();
    fd.append('action', 'reserve');
    return fetch('upload.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (r) { if (!r.ok) throw new Error(r.error || 'Failed'); return r.code; });
  }
  function showCode(group, code) {
    group.querySelector('.code').textContent = code;
    var b = group.querySelector('button');
    b.hidden = false;
    b.onclick = function () {
      var done = function () { b.textContent = 'Copied ✓'; setTimeout(function () { b.textContent = 'Copy'; }, 1500); };
      if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(code).then(done);
      else { var t = document.createElement('textarea'); t.value = code; document.body.appendChild(t); t.select(); try { document.execCommand('copy'); done(); } catch (e) {} t.remove(); }
    };
  }
  function addItem(group, name) {
    var li = document.createElement('li');
    li.className = 'q';
    li.innerHTML = '<div class="l"><span class="n"></span><span class="s">Waiting…</span></div><div class="bar"><i></i></div>';
    li.querySelector('.n').textContent = name;
    group.querySelector('.files-in').appendChild(li);
    return li;
  }
  function finish(li, ok, msg, label) {
    li.classList.add(ok ? 'done' : 'fail');
    li.querySelector('.s').textContent = msg;
    if (label) li.querySelector('.n').textContent = label + ' · ' + li.querySelector('.n').textContent;
  }
  function send(fd, li) {
    return new Promise(function (resolve) {
      li.querySelector('.s').textContent = 'Uploading…';
      var xhr = new XMLHttpRequest();
      xhr.open('POST', 'upload.php');
      xhr.upload.onprogress = function (e) {
        if (e.lengthComputable) li.querySelector('i').style.width = (e.loaded / e.total * 100) + '%';
      };
      xhr.onload = function () {
        var r = {};
        try { r = JSON.parse(xhr.responseText); } catch (e) {}
        if (r.ok) finish(li, true, 'Sent ✓ ' + r.size + (r.pages ? ' · ' + r.pages + (r.pages === 1 ? ' page' : ' pages') : ''), r.label);
        else finish(li, false, r.error || 'Upload failed (' + xhr.status + ')');
        resolve();
      };
      xhr.onerror = function () { finish(li, false, 'Network error'); resolve(); };
      xhr.send(fd);
    });
  }

  // jobs: [{name, build(code) -> FormData}]
  function runBatch(jobs) {
    var group = newGroup();
    var items = jobs.map(function (j) { return addItem(group, j.name); });
    reserve().then(function (code) {
      showCode(group, code);
      // sequential, so -1, -2, -3 follow the order the files were dropped
      return jobs.reduce(function (p, j, i) {
        return p.then(function () { return send(j.build(code), items[i]); });
      }, Promise.resolve());
    }).catch(function (e) {
      group.querySelector('.code').textContent = 'Error';
      items.forEach(function (li) { finish(li, false, e.message || 'Could not start upload'); });
    });
  }

  function sendFiles(files) {
    var jobs = Array.prototype.map.call(files, function (f) {
      return { name: f.name, build: function (code) { var fd = new FormData(); fd.append('code', code); fd.append('file', f); return fd; } };
    });
    if (jobs.length) runBatch(jobs);
  }

  drop.addEventListener('click', function () { picker.click(); });
  drop.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); picker.click(); } });
  picker.addEventListener('change', function () { sendFiles(picker.files); picker.value = ''; });
  // The whole page is a drop target: drop files anywhere, or paste them (Ctrl/Cmd+V)
  var depth = 0;
  function hasFiles(e) { return e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') !== -1; }
  function dragging(on) { document.body.classList.toggle('dragging', on); drop.classList.toggle('over', on); }
  window.addEventListener('dragenter', function (e) { if (!hasFiles(e)) return; e.preventDefault(); depth++; dragging(true); });
  window.addEventListener('dragover', function (e) { if (hasFiles(e)) e.preventDefault(); });
  window.addEventListener('dragleave', function (e) { if (!hasFiles(e)) return; depth = Math.max(0, depth - 1); if (!depth) dragging(false); });
  window.addEventListener('drop', function (e) {
    if (!hasFiles(e)) return;
    e.preventDefault(); depth = 0; dragging(false);
    sendFiles(e.dataTransfer.files);
  });
  window.addEventListener('paste', function (e) {
    var fs = e.clipboardData && e.clipboardData.files;
    if (fs && fs.length) { e.preventDefault(); sendFiles(fs); }
  });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var url = input.value.trim();
    if (!url) return;
    input.value = '';
    runBatch([{ name: url, build: function (code) { var fd = new FormData(); fd.append('code', code); fd.append('url', url); return fd; } }]);
  });
})();
