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
    <title>Similar Books Scraper</title>
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
            max-height: 400px;
            overflow-y: auto;
            font-family: monospace;
            font-size: 0.76rem;
            line-height: 1.65;
            white-space: pre-wrap;
            word-break: break-word;
            scrollbar-width: thin;
            scrollbar-color: var(--bs-border-color) transparent;
        }
        .step-log::-webkit-scrollbar       { width: 4px; }
        .step-log::-webkit-scrollbar-track { background: transparent; }
        .step-log::-webkit-scrollbar-thumb { background-color: var(--bs-border-color); border-radius: 4px; }

        .gr-book    { color: var(--bs-body-color); font-weight: 600; }
        .gr-saved   { color: var(--bs-success-text-emphasis); }
        .gr-skip    { color: var(--bs-secondary-color); opacity: 0.6; }
        .gr-error   { color: var(--bs-danger-text-emphasis); }
        .gr-summary { color: var(--bs-body-color); font-weight: 700; border-top: 1px solid var(--bs-border-color); margin-top: 0.3rem; padding-top: 0.3rem; display: block; }
        .gr-detail  { color: var(--bs-secondary-color); }

        .filter-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.15rem 0.6rem;
            background: var(--bs-primary-bg-subtle);
            color: var(--bs-primary-text-emphasis);
            border: 1px solid var(--bs-primary-border-subtle);
            border-radius: 2rem;
            font-size: 0.75rem;
            font-weight: 500;
        }
    </style>
</head>
<body class="pt-5">
<?php include '../navbar.php'; ?>

<div class="container mt-5 pt-4" style="max-width: 860px">

    <div class="d-flex align-items-center mb-4 gap-3">
        <div>
            <h3 class="mb-0"><i class="fa-solid fa-list-ul me-2 text-primary"></i>Similar Books Scraper</h3>
            <div class="text-muted small mt-1">
                Scrapes Goodreads similar-books data for your library.
                Requires step 2 of <a href="gr_import.php">GR Import</a> to have been run first
                (books need a <code>gr_work_id</code>).
            </div>
        </div>
        <a href="gr_import.php" class="btn btn-outline-secondary ms-auto btn-sm">
            <i class="fa-brands fa-goodreads me-1"></i> GR Import
        </a>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex align-items-center gap-2">
            <span class="step-badge"><i class="fa-solid fa-list-ul fa-xs"></i></span>
            <span class="fw-semibold">Scrape Similar Books</span>
            <span class="phase-pill idle ms-auto" id="pill"><i class="fa-solid fa-circle-dot fa-xs"></i> Idle</span>
        </div>
        <div class="card-body">
            <p class="text-muted small mb-2">
                For each eligible book, fetches <code>goodreads.com/book/similar/{work_id}</code>,
                parses the similar-books list, stores results in the database, and downloads covers locally.
                Progress is saved — safe to stop and resume.
            </p>
            <div class="d-flex flex-wrap gap-2 mb-3">
                <span class="filter-pill"><i class="fa-solid fa-star-half-stroke fa-xs"></i> Rating count &gt; 1,000</span>
                <span class="filter-pill"><i class="fa-solid fa-books fa-xs"></i> Series: first book only</span>
            </div>

            <div class="d-flex flex-wrap gap-4 align-items-end mb-3">
                <div>
                    <label class="form-label small mb-1">Delay between requests (s)</label>
                    <input type="number" class="form-control form-control-sm" id="delay" value="5" min="0" max="30" style="width:5rem">
                </div>
                <div class="d-flex flex-column gap-1">
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" id="forceCheck">
                        <label class="form-check-label small" for="forceCheck">Force re-fetch all (discard progress)</label>
                    </div>
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" id="dryRunCheck">
                        <label class="form-check-label small" for="dryRunCheck">Dry run (show eligible books without fetching)</label>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2 mb-3 align-items-center">
                <button class="btn btn-sm btn-primary" id="startBtn" onclick="startScrape()">
                    <i class="fa-solid fa-play fa-xs me-1"></i> Start
                </button>
                <button class="btn btn-sm btn-outline-danger d-none" id="stopBtn" onclick="stopScrape()">
                    <i class="fa-solid fa-stop fa-xs me-1"></i> Stop
                </button>
            </div>

            <div class="progress mb-2 d-none" id="prog" style="height:5px">
                <div class="progress-bar" id="bar" role="progressbar" style="width:0%"></div>
            </div>
            <div class="step-log d-none" id="log"></div>
        </div>
    </div>

</div><!-- /container -->

<script>
let currentToken = null;
let currentES    = null;
let isDone       = false;

function genToken() {
    return Math.random().toString(36).slice(2) + Date.now().toString(36);
}

function setPill(state, label) {
    const pill = document.getElementById('pill');
    pill.className = 'phase-pill ' + state + ' ms-auto';
    const icons = {
        idle:    'circle-dot',
        running: 'circle-notch fa-spin',
        done:    'circle-check',
        error:   'circle-xmark',
        stopped: 'circle-pause',
    };
    pill.innerHTML = `<i class="fa-solid fa-${icons[state] ?? 'circle-dot'} fa-xs"></i> ${label}`;
}

function logLine(text, cls) {
    const log = document.getElementById('log');
    if (log.classList.contains('d-none')) log.classList.remove('d-none');
    const span = document.createElement('span');
    span.className = cls || '';
    span.textContent = text + '\n';
    log.appendChild(span);
    log.scrollTop = log.scrollHeight;
}

function setProgress(pct) {
    const prog = document.getElementById('prog');
    if (prog.classList.contains('d-none')) prog.classList.remove('d-none');
    document.getElementById('bar').style.width = pct + '%';
}

function finish(state, label) {
    if (isDone) return;
    isDone = true;
    if (currentES) currentES.close();
    currentES    = null;
    currentToken = null;
    setPill(state, label);
    document.getElementById('startBtn').disabled = false;
    document.getElementById('startBtn').classList.remove('d-none');
    document.getElementById('stopBtn').classList.add('d-none');
}

function startScrape() {
    if (currentES) return;
    isDone = false;

    const log  = document.getElementById('log');
    const prog = document.getElementById('prog');
    log.innerHTML = '';
    log.classList.add('d-none');
    prog.classList.add('d-none');
    document.getElementById('bar').style.width = '0%';

    const token = genToken();
    currentToken = token;

    document.getElementById('startBtn').disabled = true;
    document.getElementById('stopBtn').classList.remove('d-none');
    setPill('running', 'Starting…');

    const params = new URLSearchParams({ token });
    params.set('delay', document.getElementById('delay').value || '5');
    if (document.getElementById('forceCheck').checked)  params.set('force',   '1');
    if (document.getElementById('dryRunCheck').checked) params.set('dry_run', '1');

    const es = new EventSource('../json_endpoints/similar_import_stream.php?' + params.toString());
    currentES = es;

    es.addEventListener('started', () => setPill('running', 'Running…'));

    es.addEventListener('line', e => {
        const d = JSON.parse(e.data);
        logLine(d.text, d.cls);
        if (d.progress) setProgress(Math.round(d.progress.n / d.progress.total * 100));
    });

    es.addEventListener('done', e => {
        const d = JSON.parse(e.data);
        const ok = d.exit_code === 0;
        finish(ok ? 'done' : 'error', ok ? 'Done' : 'Error (see log)');
    });

    es.addEventListener('stopped', () => finish('stopped', 'Stopped'));

    es.addEventListener('error', e => {
        if (e.data) {
            try { logLine('✗ ' + JSON.parse(e.data).message, 'gr-error'); } catch (_) {}
            finish('error', 'Error');
        } else {
            finish('error', 'Connection lost');
        }
    });
}

function stopScrape() {
    if (!currentToken) return;
    fetch('../json_endpoints/missing_send_stop.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'token=' + encodeURIComponent(currentToken),
    });
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="js/search.js"></script>

</body>
</html>
