<?php
require_once '../db.php';
requireLogin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="manifest" href="/manifest.json">
  <meta name="theme-color" content="#212529">
  <link rel="apple-touch-icon" href="/app-icons/icon-192.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Open Library Import</title>
    <link rel="stylesheet" href="/theme.css.php">
    <link rel="stylesheet" href="/css/all.min.css" crossorigin="anonymous">
    <style>
        .step-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 1.8rem;
            height: 1.8rem;
            border-radius: 50%;
            background: var(--bs-primary);
            color: #fff;
            font-weight: 700;
            font-size: 0.9rem;
            flex-shrink: 0;
        }
        .phase-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.2rem 0.7rem;
            border-radius: 2rem;
            font-size: 0.75rem;
            font-weight: 500;
            border: 1px solid;
            transition: all 0.2s;
        }
        .phase-pill.idle    { color: var(--bs-secondary-color); border-color: var(--bs-secondary-color); opacity: 0.5; }
        .phase-pill.running { color: var(--bs-primary); border-color: var(--bs-primary); background: var(--bs-primary-bg-subtle); }
        .phase-pill.done    { color: var(--bs-success); border-color: var(--bs-success); background: var(--bs-success-bg-subtle); }
        .phase-pill.error   { color: var(--bs-danger);  border-color: var(--bs-danger);  background: var(--bs-danger-bg-subtle); }
        .phase-pill.stopped { color: var(--bs-warning); border-color: var(--bs-warning); background: var(--bs-warning-bg-subtle); }

        .step-log {
            background: var(--bs-body-bg);
            border: 1px solid var(--bs-border-color);
            border-radius: 0.4rem;
            padding: 0.6rem 0.8rem;
            max-height: 320px;
            overflow-y: auto;
            font-family: monospace;
            font-size: 0.76rem;
            line-height: 1.65;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .ol-book    { color: var(--bs-body-color); font-weight: 600; }
        .ol-match   { color: var(--bs-success-text-emphasis); }
        .ol-nomatch { color: var(--bs-secondary-color); }
        .ol-write   { color: var(--bs-success-text-emphasis); }
        .ol-skip    { color: var(--bs-secondary-color); opacity: 0.6; }
        .ol-none    { color: var(--bs-secondary-color); opacity: 0.6; }
        .ol-summary { color: var(--bs-body-color); font-weight: 700; border-top: 1px solid var(--bs-border-color); margin-top: 0.3rem; padding-top: 0.3rem; display: block; }
        .ol-heading { color: var(--bs-body-color); opacity: 0.75; }
        .ol-sep     { color: var(--bs-border-color); user-select: none; }
        .ol-detail  { color: var(--bs-secondary-color); }
    </style>
</head>
<body>
<?php include '../navbar.php'; ?>

<div class="container mt-5 pt-4" style="max-width: 860px">

    <div class="d-flex align-items-center mb-4 gap-3">
        <div>
            <h3 class="mb-0"><i class="fa-solid fa-book-open-reader me-2 text-primary"></i>Open Library Import</h3>
            <div class="text-muted small mt-1">Enrich your library with metadata from Open Library — run all three steps in sequence.</div>
        </div>
        <button class="btn btn-primary ms-auto" id="runAllBtn" onclick="runAll()">
            <i class="fa-solid fa-play me-1"></i> Run All Steps
        </button>
    </div>

    <!-- Step 1: Work IDs -->
    <div class="card mb-3" id="card1">
        <div class="card-header d-flex align-items-center gap-2">
            <span class="step-badge">1</span>
            <span class="fw-semibold">Import Work IDs</span>
            <span class="phase-pill idle ms-auto" id="pill1"><i class="fa-solid fa-circle-dot fa-xs"></i> Idle</span>
        </div>
        <div class="card-body">
            <p class="text-muted small mb-3">Searches Open Library by title + author (or ISBN) and stores the Work ID (<code>olid</code>) for each book. Books already matched are skipped unless forced.</p>
            <div class="d-flex flex-wrap gap-4 align-items-end mb-3">
                <div>
                    <label class="form-label small mb-1">Delay between requests (s)</label>
                    <input type="number" id="delay1" value="2" min="0" max="10" class="form-control form-control-sm" style="width:5rem">
                </div>
                <div class="d-flex flex-column gap-1">
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" id="force1">
                        <label class="form-check-label small" for="force1">Force re-fetch all books</label>
                    </div>
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" id="retry1">
                        <label class="form-check-label small" for="retry1">Retry NOT_FOUND books</label>
                    </div>
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" id="english1" checked>
                        <label class="form-check-label small" for="english1">Prefer English editions</label>
                    </div>
                </div>
            </div>
            <div class="d-flex gap-2 mb-3">
                <button class="btn btn-sm btn-primary" id="start1" onclick="runStep(1)">
                    <i class="fa-solid fa-play fa-xs me-1"></i> Start
                </button>
                <button class="btn btn-sm btn-outline-danger d-none" id="stop1" onclick="stopStep(1)">
                    <i class="fa-solid fa-stop fa-xs me-1"></i> Stop
                </button>
            </div>
            <div class="progress mb-2 d-none" id="prog1" style="height:5px">
                <div class="progress-bar" id="bar1" role="progressbar" style="width:0%"></div>
            </div>
            <div class="step-log d-none" id="log1"></div>
        </div>
    </div>

    <!-- Step 2: Author IDs -->
    <div class="card mb-3" id="card2">
        <div class="card-header d-flex align-items-center gap-2">
            <span class="step-badge">2</span>
            <span class="fw-semibold">Import Author IDs &amp; Metadata</span>
            <span class="phase-pill idle ms-auto" id="pill2"><i class="fa-solid fa-circle-dot fa-xs"></i> Idle</span>
        </div>
        <div class="card-body">
            <p class="text-muted small mb-3">For each author with a known Work ID, fetches their OL Author ID plus bio, photo, Goodreads, Wikidata, ISNI, VIAF, birth/death dates, and works list.</p>
            <div class="d-flex flex-wrap gap-4 align-items-end mb-3">
                <div>
                    <label class="form-label small mb-1">Delay between requests (s)</label>
                    <input type="number" id="delay2" value="2" min="0" max="10" class="form-control form-control-sm" style="width:5rem">
                </div>
                <div class="d-flex flex-column gap-1">
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" id="force2">
                        <label class="form-check-label small" for="force2">Force re-fetch all authors</label>
                    </div>
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" id="partial2" checked>
                        <label class="form-check-label small" for="partial2">Skip authors already with OL Author ID</label>
                    </div>
                </div>
            </div>
            <div class="d-flex gap-2 mb-3">
                <button class="btn btn-sm btn-primary" id="start2" onclick="runStep(2)">
                    <i class="fa-solid fa-play fa-xs me-1"></i> Start
                </button>
                <button class="btn btn-sm btn-outline-danger d-none" id="stop2" onclick="stopStep(2)">
                    <i class="fa-solid fa-stop fa-xs me-1"></i> Stop
                </button>
            </div>
            <div class="progress mb-2 d-none" id="prog2" style="height:5px">
                <div class="progress-bar" id="bar2" role="progressbar" style="width:0%"></div>
            </div>
            <div class="step-log d-none" id="log2"></div>
        </div>
    </div>

    <!-- Step 3: Identifiers -->
    <div class="card mb-3" id="card3">
        <div class="card-header d-flex align-items-center gap-2">
            <span class="step-badge">3</span>
            <span class="fw-semibold">Import Edition Identifiers</span>
            <span class="phase-pill idle ms-auto" id="pill3"><i class="fa-solid fa-circle-dot fa-xs"></i> Idle</span>
        </div>
        <div class="card-body">
            <p class="text-muted small mb-3">For every book with a Work ID, fetches its editions from Open Library and imports any identifiers (ISBN-10, ISBN-13, Goodreads, LibraryThing, Amazon, Google, OCLC) not yet in the database.</p>
            <div class="d-flex flex-wrap gap-4 align-items-end mb-3">
                <div>
                    <label class="form-label small mb-1">Delay between requests (s)</label>
                    <input type="number" id="delay3" value="1" min="0" max="10" class="form-control form-control-sm" style="width:5rem">
                </div>
                <div class="d-flex flex-column gap-1">
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" id="force3">
                        <label class="form-check-label small" for="force3">Force overwrite existing identifiers</label>
                    </div>
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" id="english3" checked>
                        <label class="form-check-label small" for="english3">Prefer English editions</label>
                    </div>
                </div>
            </div>
            <div class="d-flex gap-2 mb-3">
                <button class="btn btn-sm btn-primary" id="start3" onclick="runStep(3)">
                    <i class="fa-solid fa-play fa-xs me-1"></i> Start
                </button>
                <button class="btn btn-sm btn-outline-danger d-none" id="stop3" onclick="stopStep(3)">
                    <i class="fa-solid fa-stop fa-xs me-1"></i> Stop
                </button>
            </div>
            <div class="progress mb-2 d-none" id="prog3" style="height:5px">
                <div class="progress-bar" id="bar3" role="progressbar" style="width:0%"></div>
            </div>
            <div class="step-log d-none" id="log3"></div>
        </div>
    </div>

</div><!-- /container -->

<script>
const stepTokens  = {1: null, 2: null, 3: null};
const stepES      = {1: null, 2: null, 3: null};
// Prevent double-finishStep from overlapping error/onerror events
const stepDone    = {1: false, 2: false, 3: false};

function genToken() {
    return Math.random().toString(36).slice(2) + Date.now().toString(36);
}

function setPill(n, state, label) {
    const pill = document.getElementById('pill' + n);
    pill.className = 'phase-pill ' + state + ' ms-auto';
    const icons = {idle: 'circle-dot', running: 'circle-notch fa-spin', done: 'circle-check', error: 'circle-xmark', stopped: 'circle-pause'};
    pill.innerHTML = `<i class="fa-solid fa-${icons[state] ?? 'circle-dot'} fa-xs"></i> ${label}`;
}

function logLine(n, text, cls) {
    const log = document.getElementById('log' + n);
    if (log.classList.contains('d-none')) log.classList.remove('d-none');
    const span = document.createElement('span');
    span.className = cls || '';
    span.textContent = text + '\n';
    log.appendChild(span);
    log.scrollTop = log.scrollHeight;
}

function setProgress(n, pct) {
    const prog = document.getElementById('prog' + n);
    const bar  = document.getElementById('bar' + n);
    if (prog.classList.contains('d-none')) prog.classList.remove('d-none');
    bar.style.width = pct + '%';
}

function finishStep(n, state, label, autoNext) {
    if (stepDone[n]) return;   // guard against double-call
    stepDone[n] = true;
    const es = stepES[n];
    if (es) { es.close(); }
    stepES[n]     = null;
    stepTokens[n] = null;
    setPill(n, state, label);
    document.getElementById('start' + n).disabled = false;
    document.getElementById('start' + n).classList.remove('d-none');
    document.getElementById('stop' + n).classList.add('d-none');
    document.getElementById('runAllBtn').disabled = false;
    if (state === 'done' && autoNext && n < 3) {
        runStep(n + 1, autoNext);
    }
}

function runStep(n, autoNext) {
    if (stepES[n]) return; // already running

    // Reset state
    stepDone[n] = false;
    const log = document.getElementById('log' + n);
    log.innerHTML = '';
    log.classList.add('d-none');
    const prog = document.getElementById('prog' + n);
    prog.classList.add('d-none');
    document.getElementById('bar' + n).style.width = '0%';

    const token = genToken();
    stepTokens[n] = token;

    document.getElementById('start' + n).disabled = true;
    document.getElementById('stop' + n).classList.remove('d-none');
    document.getElementById('runAllBtn').disabled = true;
    setPill(n, 'running', 'Starting…');

    const params = new URLSearchParams({
        step:  n,
        token: token,
        delay: document.getElementById('delay' + n).value,
    });
    if (n === 1) {
        if (document.getElementById('force1').checked)    params.set('force', '1');
        if (document.getElementById('retry1').checked)    params.set('retry_failed', '1');
        params.set('prefer_english', document.getElementById('english1').checked ? '1' : '0');
    }
    if (n === 2) {
        if (document.getElementById('force2').checked)    params.set('force', '1');
        if (document.getElementById('partial2').checked)  params.set('skip_partial', '1');
    }
    if (n === 3) {
        if (document.getElementById('force3').checked)    params.set('force', '1');
        params.set('prefer_english', document.getElementById('english3').checked ? '1' : '0');
    }

    const es = new EventSource('../json_endpoints/ol_import_stream.php?' + params.toString());
    stepES[n] = es;

    es.addEventListener('started', () => {
        setPill(n, 'running', 'Running…');
    });

    es.addEventListener('line', e => {
        const d = JSON.parse(e.data);
        logLine(n, d.text, d.cls);
        if (d.progress) {
            setProgress(n, Math.round(d.progress.n / d.progress.total * 100));
        }
    });

    es.addEventListener('done', e => {
        const d = JSON.parse(e.data);
        const ok = d.exit_code === 0;
        finishStep(n, ok ? 'done' : 'error', ok ? 'Done' : 'Error (see log)', autoNext);
    });

    es.addEventListener('stopped', () => {
        finishStep(n, 'stopped', 'Stopped', false);
    });

    // Named SSE error event (e.g. script not found, invalid step)
    es.addEventListener('error', e => {
        if (e.data) {
            try { logLine(n, '✗ ' + JSON.parse(e.data).message, 'ol-nomatch'); } catch (_) {}
            finishStep(n, 'error', 'Error', false);
        } else {
            // Transport-level error (connection closed unexpectedly)
            finishStep(n, 'error', 'Connection lost', false);
        }
    });
}

function stopStep(n) {
    const token = stepTokens[n];
    if (!token) return;
    fetch('../json_endpoints/missing_send_stop.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'token=' + encodeURIComponent(token),
    });
}

function runAll() {
    runStep(1, true);
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="/js/search.js"></script>
</body>
</html>
