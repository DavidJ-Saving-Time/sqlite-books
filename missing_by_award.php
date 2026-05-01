<?php
require_once 'db.php';
requireLogin();

$pdo    = getDatabaseConnection();
$awards = [];
try {
    $awards = $pdo->query('SELECT name FROM awards ORDER BY name COLLATE NOCASE')
                  ->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="manifest" href="/manifest.json">
  <meta name="theme-color" content="#212529">
  <link rel="apple-touch-icon" href="/app-icons/icon-192.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Find Missing Award Books</title>
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

        /* Award checkboxes */
        .award-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 0.2rem 1rem;
        }
        .award-grid .form-check-label { font-size: 0.85rem; }
    </style>
</head>
<body class="pt-5">
<?php include 'navbar.php'; ?>

<div class="container my-4" style="max-width:900px">
    <h2 class="mb-4">
        <i class="fa-solid fa-trophy me-2 text-warning"></i>Find Missing Award Books
    </h2>

    <!-- Options -->
    <div class="card mb-4">
        <div class="card-body">
            <!-- Award filter -->
            <div class="mb-3">
                <div class="d-flex align-items-center gap-3 mb-2">
                    <label class="form-label mb-0 fw-semibold">Awards to search</label>
                    <a href="#" id="selectAllAwards" class="small">Select all</a>
                    <a href="#" id="selectNoneAwards" class="small">None</a>
                </div>
                <?php if (empty($awards)): ?>
                    <p class="text-muted small mb-0">No awards in database yet — run the Awards Import first.</p>
                <?php else: ?>
                <div class="award-grid" id="awardGrid">
                    <?php foreach ($awards as $award): ?>
                    <div class="form-check">
                        <input class="form-check-input award-check" type="checkbox"
                               name="awards[]"
                               value="<?= htmlspecialchars($award, ENT_QUOTES) ?>"
                               id="aw_<?= md5($award) ?>" checked>
                        <label class="form-check-label" for="aw_<?= md5($award) ?>">
                            <?= htmlspecialchars($award) ?>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="d-flex flex-wrap gap-4 align-items-end mt-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="wonOnly" checked>
                    <label class="form-check-label small" for="wonOnly">Winners only (skip nominees)</label>
                </div>
                <div>
                    <label class="form-label small mb-1">OL verify delay (ms)</label>
                    <input type="number" id="delayInput" value="400" min="100" max="2000"
                           class="form-control form-control-sm" style="width:6rem">
                </div>
                <button id="startBtn" class="btn btn-primary px-4">
                    <i class="fa-solid fa-magnifying-glass me-1"></i> Find Missing Books
                </button>
                <button id="listBtn" class="btn btn-outline-secondary px-3">
                    <i class="fa-solid fa-list me-1"></i> Missing Books List
                </button>
                <button id="stopBtn" class="btn btn-danger" style="display:none">
                    <i class="fa-solid fa-stop me-1"></i> Stop
                </button>
            </div>
        </div>
    </div>

    <!-- Phase indicators -->
    <div id="phaseBar" class="d-flex align-items-center gap-2 mb-3" style="display:none">
        <span id="ph-load"   class="phase-pill pending"><i class="fa-solid fa-file-csv fa-fw"></i>Load</span>
        <span class="phase-arrow">›</span>
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
        <p class="mb-0">No missing books found for the selected awards.</p>
    </div>

    <hr class="my-5">

    <!-- Send Queue -->
    <div id="sendSection">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h5 class="mb-0"><i class="fa-solid fa-paper-plane me-2"></i>Send Queue</h5>
            <button id="refreshQueueBtn" class="btn btn-sm btn-outline-secondary">
                <i class="fa-solid fa-arrows-rotate me-1"></i> Refresh
            </button>
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

<!-- Missing Books List Modal -->
<div class="modal fade" id="missingListModal" tabindex="-1" aria-labelledby="missingListModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="missingListModalLabel">
                    <i class="fa-solid fa-list me-2"></i>Missing Books
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div id="missingListSpinner" class="text-center py-5">
                    <div class="spinner-border text-secondary" role="status"></div>
                    <div class="text-muted mt-2 small">Running awards dry-run…</div>
                </div>
                <pre id="missingListText" class="mb-0 p-3" style="display:none; font-size:0.78rem; line-height:1.6; white-space:pre-wrap; max-height:70vh; overflow-y:auto;"></pre>
            </div>
            <div class="modal-footer justify-content-between">
                <span id="missingListCount" class="text-muted small"></span>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script>
const startBtn      = document.getElementById('startBtn');
const stopBtn       = document.getElementById('stopBtn');
const phaseBar      = document.getElementById('phaseBar');
const statusText    = document.getElementById('statusText');
const liveLog       = document.getElementById('liveLog');
const resultsSection= document.getElementById('resultsSection');
const resultsList   = document.getElementById('resultsList');
const emptyState    = document.getElementById('emptyState');
const queueBtn      = document.getElementById('queueBtn');
const queueStatus   = document.getElementById('queueStatus');
const selectAllBtn  = document.getElementById('selectAllBtn');

let source        = null;
let verifiedLines = [];
let stopToken     = null;

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function setPhase(id, state) {
    const el = document.getElementById('ph-' + id);
    if (!el) return;
    el.className = 'phase-pill ' + state;
    const icon = el.querySelector('i');
    if (icon) {
        icon.className = icon.className.replace(/\s*fa-spin/g, '');
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
    statusText.textContent = '';
    ['load','find','verify','done'].forEach(p => setPhase(p, 'pending'));
    phaseBar.style.display = 'flex';
}

// ── Award filter controls ──────────────────────────────────────────────────────

document.getElementById('selectAllAwards')?.addEventListener('click', e => {
    e.preventDefault();
    document.querySelectorAll('.award-check').forEach(c => c.checked = true);
});
document.getElementById('selectNoneAwards')?.addEventListener('click', e => {
    e.preventDefault();
    document.querySelectorAll('.award-check').forEach(c => c.checked = false);
});

// ── Main search ───────────────────────────────────────────────────────────────

startBtn.addEventListener('click', () => {
    const selectedAwards = [...document.querySelectorAll('.award-check:checked')].map(c => c.value);
    if (!selectedAwards.length) {
        alert('Select at least one award.');
        return;
    }

    if (source) { source.close(); source = null; }
    reset();
    liveLog.style.display = 'block';
    startBtn.style.display = 'none';
    stopBtn.style.display  = 'inline-block';

    stopToken = Math.random().toString(36).slice(2) + Date.now().toString(36);
    setPhase('load', 'active');

    const params = new URLSearchParams({ delay: document.getElementById('delayInput').value, token: stopToken });
    selectedAwards.forEach(a => params.append('awards[]', a));
    if (document.getElementById('wonOnly').checked) params.set('won_only', '1');

    source = new EventSource('json_endpoints/missing_award_stream.php?' + params.toString());

    source.addEventListener('books_loaded', e => {
        const d = JSON.parse(e.data);
        log(`Found ${d.count} missing title${d.count !== 1 ? 's' : ''} from awards data`, 'info');
        setPhase('load', 'done');
        setPhase('find', 'active');
    });

    source.addEventListener('status', e => {
        statusText.textContent = JSON.parse(e.data).message;
    });

    source.addEventListener('find_progress', e => {
        const d = JSON.parse(e.data);
        statusText.textContent = `IRC search ${d.n}/${d.total}: ${d.title}`;
    });

    source.addEventListener('found', e => {
        const d = JSON.parse(e.data);
        log(`  + [${d.year}] ${d.title}  [${d.ext}]  — ${d.award}`, 'found');
    });

    source.addEventListener('verify_progress', e => {
        const d = JSON.parse(e.data);
        if (d.n === 1) { setPhase('find', 'done'); setPhase('verify', 'active'); }
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

        const s = d.stats;
        log(`── Done: ${s.books} missing, ${s.candidates} found in IRC, ${s.verified} verified ──`, 'summary');
        setPhase('verify', 'done');
        setPhase('done',   'done');
        statusText.textContent = `${s.verified} verified / ${s.dropped} dropped`;

        startBtn.style.display = 'inline-block';
        stopBtn.style.display  = 'none';

        verifiedLines = d.kept;
        if (d.kept.length > 0) showResults(d.kept);
        else emptyState.style.display = 'block';
    });

    source.addEventListener('error', e => {
        if (e.data) {
            try { log('Error: ' + JSON.parse(e.data).message, 'error'); } catch {}
            setPhase('load', 'error');
        }
        if (source) { source.close(); source = null; }
        startBtn.style.display = 'inline-block';
        stopBtn.style.display  = 'none';
    });

    source.onerror = () => {
        if (source && source.readyState === EventSource.CLOSED) source = null;
    };
});

stopBtn.addEventListener('click', () => {
    if (stopToken) {
        fetch('json_endpoints/missing_send_stop.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'token=' + encodeURIComponent(stopToken),
        });
    }
    if (source) { source.close(); source = null; }
    startBtn.style.display = 'inline-block';
    stopBtn.style.display  = 'none';
    log('── Stopped ──', 'summary');
});

// ── Results ───────────────────────────────────────────────────────────────────

function showResults(lines) {
    resultsList.innerHTML = lines.map((line, i) => {
        const m = line.match(/^!\S+\s+.+?\s+-\s+(.+)$/);
        const label = m ? m[1] : line;
        return `
        <div class="result-item">
            <input type="checkbox" class="form-check-input result-check flex-shrink-0 mt-1" checked data-idx="${i}">
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
        .map(cb => verifiedLines[+cb.dataset.idx]).filter(Boolean);
    if (!selected.length) { queueStatus.textContent = 'Nothing selected.'; return; }

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
            setTimeout(loadQueueStatus, 600);
        } else {
            queueStatus.textContent = 'Error: ' + (data.error || 'unknown');
        }
    } catch { queueStatus.textContent = 'Request failed.'; }
    queueBtn.disabled = false;
});

// ── Send Queue ────────────────────────────────────────────────────────────────

const sendStartBtn    = document.getElementById('sendStartBtn');
const sendStopBtn     = document.getElementById('sendStopBtn');
const sendLimitInput  = document.getElementById('sendLimit');
const sendLog         = document.getElementById('sendLog');
const sendStatusText  = document.getElementById('sendStatusText');
const queueInfoBar    = document.getElementById('queueInfoBar');
const refreshQueueBtn = document.getElementById('refreshQueueBtn');

let sendSource  = null;
let sendToken   = null;
let transientEl = null;

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
    if (!transientEl) { transientEl = document.createElement('div'); sendLog.appendChild(transientEl); }
    transientEl.className = cls;
    transientEl.textContent = text;
    sendLog.scrollTop = sendLog.scrollHeight;
}

async function loadQueueStatus() {
    try {
        const r = await fetch('json_endpoints/missing_queue_status.php');
        const d = await r.json();
        queueInfoBar.textContent = `Queue: ${d.pending} pending  ·  ${d.sent} already sent  ·  ${d.total} total`;
        sendStartBtn.disabled = d.pending === 0 || sendSource !== null;
    } catch { queueInfoBar.textContent = 'Could not load queue status.'; }
}

refreshQueueBtn.addEventListener('click', loadQueueStatus);
loadQueueStatus();

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
    sendSource.addEventListener('countdown', e => {
        const d = JSON.parse(e.data);
        if (d.seconds > 0) sendLog_transient(`  Next request in ${d.seconds}s…`, 'sl-transient');
        else transientEl = null;
    });
    sendSource.addEventListener('stopped', () => {
        sendLog_append('■ Stopped', 'sl-stopped');
        finishSend();
    });
    sendSource.addEventListener('send_done', e => {
        const d = JSON.parse(e.data);
        sendLog_append(`── Done: ${d.sent_count} sent this run, ${d.remaining} still in queue ──`, 'sl-done');
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

// ── Missing Books List modal ───────────────────────────────────────────────────

const missingListModal   = new bootstrap.Modal(document.getElementById('missingListModal'));
const missingListSpinner = document.getElementById('missingListSpinner');
const missingListText    = document.getElementById('missingListText');
const missingListCount   = document.getElementById('missingListCount');

document.getElementById('listBtn').addEventListener('click', async () => {
    // Show modal with spinner
    missingListSpinner.style.display = 'block';
    missingListText.style.display    = 'none';
    missingListCount.textContent     = '';
    missingListModal.show();

    const selectedAwards = [...document.querySelectorAll('.award-check:checked')].map(c => c.value);
    const params = new URLSearchParams({ won_only: document.getElementById('wonOnly').checked ? '1' : '' });
    selectedAwards.forEach(a => params.append('awards[]', a));

    try {
        const resp = await fetch('json_endpoints/missing_award_list.php?' + params.toString());
        const books = await resp.json();

        if (books.error) {
            missingListText.textContent = 'Error: ' + books.error;
            missingListText.style.display = 'block';
            missingListSpinner.style.display = 'none';
            return;
        }

        // Group by award
        const byAward = {};
        for (const b of books) {
            if (!byAward[b.award]) byAward[b.award] = [];
            byAward[b.award].push(b);
        }

        const lines = [];
        for (const [award, entries] of Object.entries(byAward).sort()) {
            lines.push(award);
            for (const e of entries) {
                lines.push(`  [${e.year}] ${e.title} — ${e.author}`);
            }
            lines.push('');
        }

        missingListText.textContent = lines.join('\n').trimEnd();
        missingListCount.textContent = books.length + ' book' + (books.length !== 1 ? 's' : '') + ' missing';
    } catch (err) {
        missingListText.textContent = 'Request failed: ' + err.message;
    }

    missingListSpinner.style.display = 'none';
    missingListText.style.display    = 'block';
});
</script>
<script src="js/search.js"></script>
</body>
</html>
