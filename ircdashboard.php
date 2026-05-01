<?php
require_once 'db.php';
requireLogin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="manifest" href="/manifest.json">
  <meta name="theme-color" content="#212529">
  <link rel="apple-touch-icon" href="/app-icons/icon-192.png">
  <meta charset="UTF-8" />
  <title>IRC DCC Dashboard</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="/theme.css.php">
  <link rel="stylesheet" href="/css/all.min.css" crossorigin="anonymous">
</head>
<body class="pt-5">
<?php include 'navbar.php'; ?>

<div class="container py-5">
  <header class="mb-4 text-center">
    <h1 class="display-5 fw-bold">
      <i class="fa-solid fa-terminal me-2"></i>IRC DCC Dashboard
    </h1>
    <a class="btn btn-primary" href="/irc_search.php">
      <i class="fa-solid fa-magnifying-glass me-1"></i>Open the Search
    </a>
  </header>

  <div class="row g-4 mb-4">
    <!-- Daemon Control -->
    <div class="col-md-12">
      <div class="card shadow-sm">
        <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
          <h5 class="mb-0"><i class="fa-solid fa-robot me-2"></i>Daemon Control</h5>
          <span id="daemonBadge" class="badge bg-warning text-dark">Checking...</span>
        </div>
        <div class="card-body d-flex gap-2 align-items-center">
          <button id="startBtn" class="btn btn-success" onclick="daemonAction('start')" disabled>
            <i class="fa-solid fa-play me-1"></i> Start
          </button>
          <button id="stopBtn" class="btn btn-danger" onclick="daemonAction('stop')" disabled>
            <i class="fa-solid fa-stop me-1"></i> Stop
          </button>
          <span id="daemonMsg" class="text-muted small ms-2"></span>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-4">
    <!-- Status -->
    <div class="col-md-6">
      <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
          <h5 class="mb-0"><i class="fa-solid fa-signal me-2"></i>Status</h5>
        </div>
        <div class="card-body">
          <p><strong>Connected:</strong> <span id="connected"><span class="badge bg-warning text-dark">Loading…</span></span></p>
          <p><strong>Server:</strong> <span id="server">Loading…</span></p>
          <p class="mb-0"><strong>Channel:</strong> <span id="channel">Loading…</span></p>
        </div>
      </div>
    </div>

    <!-- Request File -->
    <div class="col-md-6">
      <div class="card shadow-sm">
        <div class="card-header bg-success text-white">
          <h5 class="mb-0"><i class="fa-solid fa-download me-2"></i>Request File</h5>
        </div>
        <div class="card-body">
          <div class="input-group mb-3">
            <input type="text" id="fileCommand" class="form-control" placeholder="!filename" />
            <button id="sendFileBtn" class="btn btn-success" onclick="requestFile()">
              <i class="fa-solid fa-paper-plane"></i> Send
            </button>
          </div>
          <p id="fileRequestStatus" class="text-muted small mb-0"></p>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-4 mt-3">
    <!-- Downloaded Files -->
    <div class="col-md-12">
      <div class="card shadow-sm">
        <div class="card-header bg-info text-white">
          <h5 class="mb-0"><i class="fa-solid fa-folder-open me-2"></i>Downloaded Files</h5>
        </div>
        <div class="card-body">
          <ul id="filesListTop10" class="list-group list-group-flush">
            <li class="list-group-item">Loading…</li>
          </ul>
          <div class="collapse" id="filesListMoreWrapper">
            <ul id="filesListMore" class="list-group list-group-flush"></ul>
          </div>
          <div class="d-grid mt-3">
            <button id="toggleMoreBtn" class="btn btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#filesListMoreWrapper" aria-expanded="false">
              <i class="fa-solid fa-chevron-down me-2"></i>Show More
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-4 mt-3">
    <!-- Logs -->
    <div class="col-md-12">
      <div class="card shadow-sm">
        <div class="card-header bg-dark text-white">
          <h5 class="mb-0"><i class="fa-solid fa-file-lines me-2"></i>Log Viewer (Last 50 Lines)</h5>
        </div>
        <div class="card-body" style="max-height: 300px; overflow-y: auto;">
          <pre id="logContent" class="mb-0 text-body small">Loading…</pre>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script>
  const API_BASE = "https://node2.nilla.local";

  // ── Formatting helpers ────────────────────────────────────────────────────
  function formatBytes(bytes) {
    if (bytes == null) return '—';
    if (bytes < 1024)        return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
  }

  function formatDate(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    if (isNaN(d)) return iso;
    return d.toLocaleString(undefined, { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
  }

  // ── Status ────────────────────────────────────────────────────────────────
  async function loadStatus() {
    try {
      const res  = await fetch(`${API_BASE}/status`);
      const data = await res.json();
      const connEl = document.getElementById('connected');
      if (data.connected) {
        connEl.innerHTML = '<span class="badge bg-success">Yes</span>';
      } else {
        connEl.innerHTML = '<span class="badge bg-danger">No</span>';
      }
      document.getElementById('server').textContent  = data.server  || '—';
      document.getElementById('channel').textContent = data.channel || '—';
    } catch (e) {
      document.getElementById('connected').innerHTML = '<span class="badge bg-secondary">Error</span>';
      document.getElementById('server').textContent  = 'Unavailable';
      document.getElementById('channel').textContent = 'Unavailable';
      console.error('Error loading status:', e);
    }
  }

  // ── Files ─────────────────────────────────────────────────────────────────
  async function loadFiles() {
    try {
      const res   = await fetch(`${API_BASE}/downloaded-files`);
      const files = await res.json();

      files.sort((a, b) => new Date(b.modified) - new Date(a.modified));

      const top10   = files.slice(0, 10);
      const rest    = files.slice(10);
      const topList = document.getElementById('filesListTop10');
      const moreList = document.getElementById('filesListMore');
      const toggleBtn = document.getElementById('toggleMoreBtn');
      const collapseWrapper = document.getElementById('filesListMoreWrapper');

      topList.innerHTML  = '';
      moreList.innerHTML = '';

      if (top10.length === 0) {
        topList.innerHTML = "<li class='list-group-item text-muted'>No files downloaded yet.</li>";
      } else {
        top10.forEach(file => topList.appendChild(createFileListItem(file)));
      }

      if (rest.length === 0) {
        collapseWrapper.classList.remove('show');
        toggleBtn.style.display = 'none';
      } else {
        rest.forEach(file => moreList.appendChild(createFileListItem(file)));
        toggleBtn.style.display = 'block';
      }
    } catch (e) {
      document.getElementById('filesListTop10').innerHTML =
        "<li class='list-group-item text-danger'>Error loading files.</li>";
      console.error('Error loading files:', e);
    }
  }

  function createFileListItem(file) {
    const li = document.createElement('li');
    li.className = 'list-group-item d-flex justify-content-between align-items-center';

    const a = document.createElement('a');
    a.href   = `https://nilla.local/downloads/${encodeURIComponent(file.name)}`;
    a.textContent = file.name;
    a.target = '_blank';

    const meta = document.createElement('span');
    meta.className = 'text-muted small ms-3 text-nowrap';
    meta.textContent = `${formatBytes(file.size)} · ${formatDate(file.modified)}`;

    li.append(a, meta);
    return li;
  }

  // ── Logs ──────────────────────────────────────────────────────────────────
  async function loadLogs() {
    try {
      const res  = await fetch(`${API_BASE}/logs`);
      const logs = await res.json();
      document.getElementById('logContent').textContent = logs.reverse().join('');
    } catch (e) {
      document.getElementById('logContent').textContent = 'Error loading log file.';
      console.error('Error loading logs:', e);
    }
  }

  // ── Request file ──────────────────────────────────────────────────────────
  async function requestFile() {
    const input  = document.getElementById('fileCommand');
    const status = document.getElementById('fileRequestStatus');
    const btn    = document.getElementById('sendFileBtn');
    const cmd    = input.value.trim();
    if (!cmd) { status.textContent = 'Please enter a command.'; return; }

    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
    status.textContent = 'Sending…';

    try {
      const res  = await fetch(`${API_BASE}/request-file?cmd=${encodeURIComponent(cmd)}`);
      const data = await res.json();
      status.textContent = data.status || data.error || 'Done.';
      if (!data.error) input.value = '';
    } catch (e) {
      status.textContent = 'Error sending request.';
      console.error('Error sending request:', e);
    } finally {
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Send';
    }
  }

  // ── Daemon ────────────────────────────────────────────────────────────────
  async function loadDaemonStatus() {
    try {
      const res  = await fetch('/json_endpoints/irc_daemon_control.php?action=status');
      const data = await res.json();
      updateDaemonUI(data.running, data.pid);
    } catch (e) {
      const badge = document.getElementById('daemonBadge');
      badge.textContent = 'Error';
      badge.className   = 'badge bg-danger';
      console.error('Error loading daemon status:', e);
    }
  }

  function updateDaemonUI(running, pid) {
    const badge    = document.getElementById('daemonBadge');
    const startBtn = document.getElementById('startBtn');
    const stopBtn  = document.getElementById('stopBtn');
    if (running) {
      badge.textContent = `Running (PID ${pid})`;
      badge.className   = 'badge bg-success';
      startBtn.disabled = true;
      stopBtn.disabled  = false;
    } else {
      badge.textContent = 'Stopped';
      badge.className   = 'badge bg-danger';
      startBtn.disabled = false;
      stopBtn.disabled  = true;
    }
  }

  let daemonMsgTimer = null;
  async function daemonAction(action) {
    const msg = document.getElementById('daemonMsg');
    msg.textContent = action === 'start' ? 'Starting…' : 'Stopping…';
    document.getElementById('startBtn').disabled = true;
    document.getElementById('stopBtn').disabled  = true;
    if (daemonMsgTimer) clearTimeout(daemonMsgTimer);
    try {
      const res  = await fetch(`/json_endpoints/irc_daemon_control.php?action=${action}`);
      const data = await res.json();
      msg.textContent = data.ok
        ? (action === 'start' ? 'Started.' : 'Stopped.')
        : (data.error || 'Error');
      await loadDaemonStatus();
    } catch (e) {
      msg.textContent = 'Request failed.';
      await loadDaemonStatus();
    }
    daemonMsgTimer = setTimeout(() => { msg.textContent = ''; }, 3000);
  }

  // ── Collapse toggle label ─────────────────────────────────────────────────
  const toggleBtn       = document.getElementById('toggleMoreBtn');
  const collapseWrapper = document.getElementById('filesListMoreWrapper');

  collapseWrapper.addEventListener('shown.bs.collapse', () => {
    toggleBtn.innerHTML = '<i class="fa-solid fa-chevron-up me-2"></i>Show Less';
  });
  collapseWrapper.addEventListener('hidden.bs.collapse', () => {
    toggleBtn.innerHTML = '<i class="fa-solid fa-chevron-down me-2"></i>Show More';
  });

  // ── Polling ───────────────────────────────────────────────────────────────
  function refreshAll() {
    loadStatus();
    loadFiles();
    loadLogs();
    loadDaemonStatus();
  }

  refreshAll();
  setInterval(refreshAll, 5000);
</script>
<script src="js/search.js"></script>

</body>
</html>
