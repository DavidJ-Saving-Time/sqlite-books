<?php
require_once 'db.php';
requireLogin();
$initAuthor = htmlspecialchars(trim($_GET['author'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="manifest" href="/manifest.json">
  <meta name="theme-color" content="#212529">
  <link rel="apple-touch-icon" href="/app-icons/icon-192.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Find Missing Books</title>
    <link rel="stylesheet" href="/theme.css.php">
    <link rel="stylesheet" href="/css/all.min.css" crossorigin="anonymous">
    <style>
        .phase-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.35rem 0.9rem;
            border-radius: 2rem;
            font-size: 0.8rem;
            font-weight: 500;
            border: 1px solid;
            transition: all 0.25s;
            white-space: nowrap;
        }
        .phase-pill.pending { color: var(--bs-secondary-color); border-color: var(--bs-secondary-color); opacity: 0.45; }
        .phase-pill.active  { color: var(--bs-primary); border-color: var(--bs-primary); background: var(--bs-primary-bg-subtle); }
        .phase-pill.done    { color: var(--bs-success); border-color: var(--bs-success); background: var(--bs-success-bg-subtle); }
        .phase-pill.error   { color: var(--bs-danger);  border-color: var(--bs-danger);  background: var(--bs-danger-bg-subtle); }
        .phase-arrow { color: var(--bs-secondary-color); opacity: 0.4; line-height: 2; font-size: 0.9rem; }

        #liveLog {
            background: var(--bs-body-bg);
            border: 1px solid var(--bs-border-color);
            border-radius: 0.4rem;
            padding: 0.6rem 0.8rem;
            max-height: 280px;
            overflow-y: auto;
            font-family: monospace;
            font-size: 0.78rem;
            line-height: 1.6;
        }
        .ll-info     { color: var(--bs-secondary-color); }
        .ll-found    { color: var(--bs-info-text-emphasis); }
        .ll-verified { color: var(--bs-success-text-emphasis); }
        .ll-dropped  { color: var(--bs-secondary-color); opacity: 0.6; text-decoration: line-through; }
        .ll-summary  { color: var(--bs-body-color); font-weight: 600; border-top: 1px solid var(--bs-border-color); margin-top: 0.25rem; padding-top: 0.25rem; }
        .ll-error    { color: var(--bs-danger); }

        .result-item {
            display: flex;
            align-items: flex-start;
            gap: 0.6rem;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--bs-border-color);
            font-size: 0.82rem;
        }
        .result-item:last-child { border-bottom: none; }
        .result-item code { word-break: break-all; color: var(--bs-body-color); background: none; font-size: 0.78rem; }
        .result-meta { color: var(--bs-secondary-color); font-size: 0.72rem; white-space: nowrap; }

        #sendLog {
            background: var(--bs-body-bg);
            border: 1px solid var(--bs-border-color);
            border-radius: 0.4rem;
            padding: 0.6rem 0.8rem;
            max-height: 340px;
            overflow-y: auto;
            font-family: monospace;
            font-size: 0.78rem;
            line-height: 1.7;
        }
        .sl-sending  { color: var(--bs-info-text-emphasis); font-weight: 600; }
        .sl-ok       { color: var(--bs-success-text-emphasis); }
        .sl-waiting  { color: var(--bs-warning-text-emphasis); }
        .sl-received { color: var(--bs-success-text-emphasis); font-weight: 600; }
        .sl-timeout  { color: var(--bs-warning-text-emphasis); }
        .sl-skipped  { color: var(--bs-secondary-color); text-decoration: line-through; }
        .sl-stopped  { color: var(--bs-danger); font-weight: 600; }
        .sl-done     { color: var(--bs-body-color); font-weight: 600; border-top: 1px solid var(--bs-border-color); margin-top: 0.25rem; padding-top: 0.25rem; }
        .sl-error    { color: var(--bs-danger); }
        .sl-transient { color: var(--bs-secondary-color); font-style: italic; }
    </style>
</head>
<body style="padding-top:80px">
<?php include 'navbar_other.php'; ?>

<div class="container my-4" style="max-width:860px">
    <h2 class="mb-4">
        <i class="fa-solid fa-magnifying-glass-plus me-2"></i>Find Missing Books by Author
    </h2>

    <!-- Author input -->
    <div class="d-flex gap-2 mb-4">
        <input type="text" id="authorInput" class="form-control form-control-lg"
               value="<?= $initAuthor ?>" placeholder="Author name (e.g. Terry Brooks)">
        <button id="startBtn" class="btn btn-primary btn-lg px-4">
            <i class="fa-solid fa-magnifying-glass me-1"></i> Find
        </button>
    </div>

    <!-- Phase indicators -->
    <div id="phaseBar" class="d-flex align-items-center gap-2 mb-3" style="display:none!important">
        <span id="ph-find"   class="phase-pill pending"><i class="fa-solid fa-database fa-fw"></i>IRC Search</span>
        <span class="phase-arrow">›</span>
        <span id="ph-verify" class="phase-pill pending"><i class="fa-solid fa-check-double fa-fw"></i>OL Verify</span>
        <span class="phase-arrow">›</span>
        <span id="ph-done"   class="phase-pill pending"><i class="fa-solid fa-list-check fa-fw"></i>Results</span>
        <span id="statusText" class="text-muted small ms-3"></span>
    </div>

    <!-- Live log -->
    <div id="liveLog" class="mb-4" style="display:none"></div>

    <!-- Results -->
    <div id="resultsSection" style="display:none">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h5 class="mb-0">Verified Missing Books</h5>
            <button id="selectAllBtn" class="btn btn-sm btn-outline-secondary">Deselect all</button>
        </div>
        <div id="resultsList" class="mb-3"></div>
        <div class="d-flex align-items-center gap-3">
            <button id="queueBtn" class="btn btn-success">
                <i class="fa-solid fa-plus me-1"></i> Add to Send Queue
            </button>
            <span id="queueStatus" class="text-muted small"></span>
        </div>
    </div>

    <!-- Empty state -->
    <div id="emptyState" style="display:none" class="text-center text-muted py-5">
        <i class="fa-solid fa-circle-check fa-2x mb-3" style="color:var(--bs-success)"></i>
        <p class="mb-0">No missing books found — your library looks complete for this author.</p>
    </div>

    <hr class="my-5">

    <!-- Send Queue Section -->
    <div id="sendSection">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h5 class="mb-0"><i class="fa-solid fa-paper-plane me-2"></i>Send Queue</h5>
            <div class="d-flex gap-2">
                <button id="viewQueueBtn" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#queueViewModal">
                    <i class="fa-solid fa-list me-1"></i> View / Edit Queue
                </button>
                <button id="refreshQueueBtn" class="btn btn-sm btn-outline-secondary">
                    <i class="fa-solid fa-arrows-rotate me-1"></i> Refresh
                </button>
            </div>
        </div>

        <div id="queueInfoBar" class="mb-3 text-muted small">Loading queue status…</div>

        <div class="d-flex align-items-center gap-3 mb-3">
            <div class="d-flex align-items-center gap-2">
                <label for="sendLimit" class="form-label mb-0 small">Limit</label>
                <input type="number" id="sendLimit" class="form-control form-control-sm"
                       value="10" min="1" max="50" style="width:70px">
            </div>
            <button id="sendStartBtn" class="btn btn-warning" disabled>
                <i class="fa-solid fa-play me-1"></i> Start Sending
            </button>
            <button id="sendStopBtn" class="btn btn-danger" style="display:none">
                <i class="fa-solid fa-stop me-1"></i> Stop
            </button>
            <span id="sendStatusText" class="text-muted small"></span>
        </div>

        <div id="sendLog" style="display:none"></div>
    </div>
</div>

<!-- Queue View Modal -->
<div class="modal fade" id="queueViewModal" tabindex="-1" aria-labelledby="queueViewModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="queueViewModalLabel"><i class="fa-solid fa-list me-2"></i>Send Queue</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="queueViewBody"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script>
const authorInput   = document.getElementById('authorInput');
const startBtn      = document.getElementById('startBtn');
const phaseBar      = document.getElementById('phaseBar');
const statusText    = document.getElementById('statusText');
const liveLog       = document.getElementById('liveLog');
const resultsSection= document.getElementById('resultsSection');
const resultsList   = document.getElementById('resultsList');
const emptyState    = document.getElementById('emptyState');
const queueBtn      = document.getElementById('queueBtn');
const queueStatus   = document.getElementById('queueStatus');
const selectAllBtn  = document.getElementById('selectAllBtn');

let source         = null;
let verifiedLines  = [];

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function setPhase(id, state) {
    const el = document.getElementById('ph-' + id);
    if (!el) return;
    el.className = 'phase-pill ' + state;
    const icon = el.querySelector('i');
    if (icon) {
        icon.className = icon.className.replace(/fa-spin\s*/g, '');
        if (state === 'active') icon.className += ' fa-spin';
    }
}

function log(msg, cls = 'info') {
    const div = document.createElement('div');
    div.className = 'll-' + cls;
    div.textContent = msg;
    liveLog.appendChild(div);
    liveLog.scrollTop = liveLog.scrollHeight;
}

function reset() {
    verifiedLines = [];
    liveLog.innerHTML = '';
    resultsList.innerHTML = '';
    liveLog.style.display = 'none';
    resultsSection.style.display = 'none';
    emptyState.style.display = 'none';
    queueStatus.textContent = '';
    setPhase('find',   'pending');
    setPhase('verify', 'pending');
    setPhase('done',   'pending');
    statusText.textContent = '';
    phaseBar.style.display = '';  // override display:none!important
}

startBtn.addEventListener('click', () => {
    const author = authorInput.value.trim();
    if (!author) { authorInput.focus(); return; }

    if (source) { source.close(); source = null; }
    reset();

    liveLog.style.display = 'block';
    startBtn.disabled = true;
    startBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Running…';
    setPhase('find', 'active');

    source = new EventSource('json_endpoints/missing_author_stream.php?author=' + encodeURIComponent(author));

    source.addEventListener('library_loaded', e => {
        const d = JSON.parse(e.data);
        log(`Library: ${d.count} book${d.count !== 1 ? 's' : ''} by "${d.author}" already owned`, 'info');
    });

    source.addEventListener('status', e => {
        const d = JSON.parse(e.data);
        statusText.textContent = d.message;
    });

    source.addEventListener('find_done', e => {
        const d = JSON.parse(e.data);
        log(`── IRC search done: ${d.count} new candidate${d.count !== 1 ? 's' : ''} found, ${d.owned} already owned ──`, 'summary');
        setPhase('find', 'done');
        setPhase('verify', 'active');
    });

    source.addEventListener('found', e => {
        const d = JSON.parse(e.data);
        log(`  + ${d.title}  [${d.ext}]`, 'found');
    });

    source.addEventListener('verify_progress', e => {
        const d = JSON.parse(e.data);
        statusText.textContent = `OL verify ${d.n}/${d.total}: ${d.title}`;
    });

    source.addEventListener('verify_result', e => {
        const d = JSON.parse(e.data);
        if (d.kept) {
            log(`  ✓ ${d.title}  →  ${d.worksKey}`, 'verified');
        } else {
            log(`  ✗ ${d.title}`, 'dropped');
        }
    });

    source.addEventListener('done', e => {
        const d = JSON.parse(e.data);
        source.close(); source = null;

        log(`── Done: ${d.stats.verified} verified, ${d.stats.dropped} dropped ──`, 'summary');
        setPhase('verify', 'done');
        setPhase('done',   'done');
        statusText.textContent = `${d.stats.verified} verified / ${d.stats.dropped} dropped`;

        startBtn.disabled = false;
        startBtn.innerHTML = '<i class="fa-solid fa-magnifying-glass me-1"></i> Find';

        verifiedLines = d.kept;
        if (d.kept.length > 0) {
            showResults(d.kept);
        } else {
            emptyState.style.display = 'block';
        }
    });

    source.addEventListener('error', e => {
        try {
            const d = JSON.parse(e.data);
            log('Error: ' + (d.message || 'Unknown error'), 'error');
        } catch {}
        if (source) { source.close(); source = null; }
        setPhase('find', 'error');
        startBtn.disabled = false;
        startBtn.innerHTML = '<i class="fa-solid fa-magnifying-glass me-1"></i> Find';
    });

    source.onerror = () => {
        if (source && source.readyState === EventSource.CLOSED) {
            // Stream ended cleanly — no action needed
            source = null;
        }
    };
});

function showResults(lines) {
    resultsList.innerHTML = lines.map((line, i) => {
        // Extract a readable title+ext snippet for display
        const m = line.match(/^!\S+\s+.+?\s+-\s+(.+)$/);
        const label = m ? m[1] : line;
        return `
        <div class="result-item">
            <input type="checkbox" class="form-check-input result-check flex-shrink-0 mt-1"
                   checked data-idx="${i}">
            <div>
                <code>${escHtml(label)}</code>
                <div class="result-meta">${escHtml(line.split(/\s+/)[0])}</div>
            </div>
        </div>`;
    }).join('');
    resultsSection.style.display = 'block';
    selectAllBtn.textContent = 'Deselect all';
}

selectAllBtn.addEventListener('click', () => {
    const checks    = [...document.querySelectorAll('.result-check')];
    const allChecked = checks.every(c => c.checked);
    checks.forEach(c => c.checked = !allChecked);
    selectAllBtn.textContent = allChecked ? 'Select all' : 'Deselect all';
});

queueBtn.addEventListener('click', async () => {
    const selected = [...document.querySelectorAll('.result-check:checked')]
        .map(cb => verifiedLines[+cb.dataset.idx])
        .filter(Boolean);

    if (!selected.length) {
        queueStatus.textContent = 'Nothing selected.';
        return;
    }

    queueBtn.disabled = true;
    queueStatus.textContent = 'Saving…';

    const fd = new FormData();
    selected.forEach(l => fd.append('lines[]', l));

    try {
        const resp = await fetch('json_endpoints/missing_author_queue.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.ok) {
            queueStatus.textContent = `✓ Added ${data.added} book${data.added !== 1 ? 's' : ''} to send queue`;
            document.querySelectorAll('.result-check:checked').forEach(cb => {
                cb.checked = false;
                cb.closest('.result-item').style.opacity = '0.4';
            });
            selectAllBtn.textContent = 'Select all';
        } else {
            queueStatus.textContent = 'Error: ' + (data.error || 'unknown');
        }
    } catch {
        queueStatus.textContent = 'Request failed.';
    }

    queueBtn.disabled = false;
});

// Auto-start if author pre-filled from context menu
<?php if ($initAuthor): ?>
window.addEventListener('DOMContentLoaded', () => startBtn.click());
<?php endif; ?>

// ── Send Queue ──────────────────────────────────────────────────────────────

const sendStartBtn   = document.getElementById('sendStartBtn');
const sendStopBtn    = document.getElementById('sendStopBtn');
const sendLimitInput = document.getElementById('sendLimit');
const sendLog        = document.getElementById('sendLog');
const sendStatusText = document.getElementById('sendStatusText');
const queueInfoBar   = document.getElementById('queueInfoBar');
const refreshQueueBtn= document.getElementById('refreshQueueBtn');

let sendSource    = null;
let sendToken     = null;
let transientEl   = null; // element updated in-place for waiting/countdown

function sendLog_append(text, cls) {
    transientEl = null;
    const div = document.createElement('div');
    div.className = cls;
    div.textContent = text;
    sendLog.appendChild(div);
    sendLog.scrollTop = sendLog.scrollHeight;
    return div;
}

function sendLog_transient(text, cls) {
    if (!transientEl) {
        transientEl = document.createElement('div');
        sendLog.appendChild(transientEl);
    }
    transientEl.className = cls;
    transientEl.textContent = text;
    sendLog.scrollTop = sendLog.scrollHeight;
}

async function loadQueueStatus() {
    try {
        const r = await fetch('json_endpoints/missing_queue_status.php');
        const d = await r.json();
        queueInfoBar.textContent =
            `Queue: ${d.pending} pending  ·  ${d.sent} already sent  ·  ${d.total} total`;
        sendStartBtn.disabled = d.pending === 0 || sendSource !== null;
    } catch {
        queueInfoBar.textContent = 'Could not load queue status.';
    }
}

refreshQueueBtn.addEventListener('click', loadQueueStatus);
loadQueueStatus();

// Refresh queue status after successfully adding books to queue
const _origQueueClick = queueBtn.onclick;
queueBtn.addEventListener('click', () => {
    // slight delay so the queue file is written first
    setTimeout(loadQueueStatus, 600);
});

sendStartBtn.addEventListener('click', () => {
    if (sendSource) return;

    const limit = parseInt(sendLimitInput.value, 10) || 10;
    sendToken = Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2);

    sendLog.innerHTML = '';
    transientEl = null;
    sendLog.style.display = 'block';
    sendStartBtn.style.display = 'none';
    sendStopBtn.style.display  = 'inline-block';
    sendStatusText.textContent = 'Starting…';

    const url = `json_endpoints/missing_send_stream.php?token=${encodeURIComponent(sendToken)}&limit=${limit}`;
    sendSource = new EventSource(url);

    sendSource.addEventListener('queue_loaded', e => {
        const d = JSON.parse(e.data);
        sendLog_append(`Queue: ${d.pending} pending, sending up to ${limit}`, 'sl-done');
        sendStatusText.textContent = `0 / ${Math.min(d.pending, limit)} sent`;
    });

    sendSource.addEventListener('sending', e => {
        const d = JSON.parse(e.data);
        sendLog_append(`→ [${d.n}/${d.total}] ${d.cmd}`, 'sl-sending');
        sendStatusText.textContent = `Sending ${d.n} / ${d.total}…`;
    });

    sendSource.addEventListener('send_result', e => {
        const d = JSON.parse(e.data);
        sendLog_append(`  ${d.ok ? '✓' : '✗'} ${d.status}`, d.ok ? 'sl-ok' : 'sl-error');
    });

    sendSource.addEventListener('waiting', e => {
        const d = JSON.parse(e.data);
        sendLog_transient(`  ⏳ Waiting for transfer… ${d.elapsed}s elapsed, ${d.remaining}s remaining`, 'sl-waiting');
    });

    sendSource.addEventListener('transfer_received', e => {
        const d = JSON.parse(e.data);
        sendLog_append(`  ✓ Received: ${d.name}  (${d.elapsed}s)`, 'sl-received');
    });

    sendSource.addEventListener('transfer_timeout', () => {
        sendLog_append('  ⚠ No transfer detected — moving on', 'sl-timeout');
    });

    sendSource.addEventListener('skipped', e => {
        const d = JSON.parse(e.data);
        sendLog_append(`  — Skipped (request failed)`, 'sl-skipped');
    });

    sendSource.addEventListener('countdown', e => {
        const d = JSON.parse(e.data);
        if (d.seconds > 0)
            sendLog_transient(`  Next request in ${d.seconds}s…`, 'sl-transient');
        else
            transientEl = null;
    });

    sendSource.addEventListener('stopped', () => {
        sendLog_append('■ Stopped', 'sl-stopped');
        finishSend();
    });

    sendSource.addEventListener('send_done', e => {
        const d = JSON.parse(e.data);
        sendLog_append(
            `── Done: ${d.sent_count} sent this run, ${d.remaining} still in queue ──`,
            'sl-done'
        );
        finishSend();
        loadQueueStatus();
    });

    sendSource.addEventListener('error', e => {
        try { sendLog_append('Error: ' + JSON.parse(e.data).message, 'sl-error'); } catch {}
        finishSend();
    });

    sendSource.onerror = () => {
        if (sendSource && sendSource.readyState === EventSource.CLOSED) finishSend();
    };
});

sendStopBtn.addEventListener('click', async () => {
    if (!sendToken) return;
    sendStopBtn.disabled = true;
    sendStatusText.textContent = 'Stopping…';
    const fd = new FormData();
    fd.append('token', sendToken);
    await fetch('json_endpoints/missing_send_stop.php', { method: 'POST', body: fd });
});

function finishSend() {
    if (sendSource) { sendSource.close(); sendSource = null; }
    sendToken = null;
    transientEl = null;
    sendStartBtn.style.display = 'inline-block';
    sendStartBtn.disabled = false;
    sendStopBtn.style.display = 'none';
    sendStopBtn.disabled = false;
    sendStatusText.textContent = '';
}

// ── Queue View Modal ──────────────────────────────────────────────────────────
const queueViewModal = document.getElementById('queueViewModal');
const queueViewBody  = document.getElementById('queueViewBody');

queueViewModal.addEventListener('show.bs.modal', loadQueueItems);

async function loadQueueItems() {
    queueViewBody.innerHTML = '<p class="text-muted">Loading…</p>';
    try {
        const r = await fetch('json_endpoints/missing_queue_items.php');
        const d = await r.json();
        if (!d.items || d.items.length === 0) {
            queueViewBody.innerHTML = '<p class="text-muted fst-italic">Queue is empty.</p>';
            return;
        }
        queueViewBody.innerHTML = d.items.map((line, i) => {
            // Parse bot name and filename from IRC line
            const parts = line.match(/^(\S+)\s+(.+?)(\s+::INFO::.+)?$/);
            const bot  = parts ? parts[1] : '';
            const file = parts ? parts[2].replace(/\s*\|[^|]+\|/, '').trim() : line;
            return `<div class="d-flex align-items-start gap-2 border-bottom py-2" data-line="${escHtml(line)}">
                <button class="btn btn-sm btn-outline-danger flex-shrink-0 remove-queue-item" title="Remove">
                    <i class="fa-solid fa-trash"></i>
                </button>
                <div class="min-width-0">
                    <span class="badge bg-secondary me-1">${escHtml(bot)}</span>
                    <span class="small">${escHtml(file)}</span>
                </div>
            </div>`;
        }).join('');

        queueViewBody.querySelectorAll('.remove-queue-item').forEach(btn => {
            btn.addEventListener('click', async () => {
                const row  = btn.closest('[data-line]');
                const line = row.dataset.line;
                const fd   = new FormData();
                fd.append('lines[]', line);
                const r2 = await fetch('json_endpoints/missing_queue_remove.php', { method: 'POST', body: fd });
                const d2 = await r2.json();
                if (d2.ok) {
                    row.remove();
                    loadQueueStatus();
                    if (!queueViewBody.querySelector('[data-line]')) {
                        queueViewBody.innerHTML = '<p class="text-muted fst-italic">Queue is empty.</p>';
                    }
                }
            });
        });
    } catch {
        queueViewBody.innerHTML = '<p class="text-danger">Failed to load queue.</p>';
    }
}
</script>


</body>
</html>
