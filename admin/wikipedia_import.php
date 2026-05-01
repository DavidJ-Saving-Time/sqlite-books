<?php
require_once '../db.php';
requireLogin();

$message    = '';
$alertClass = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_wikipedia'])) {
    $pdo   = getDatabaseConnection();
    $colId = getCustomColumnId($pdo, 'wiki_book');
    if ($colId) {
        $pdo->exec("DELETE FROM books_custom_column_{$colId}_link");
        $pdo->exec("DELETE FROM custom_column_{$colId}");
        $message = 'All Wikipedia data cleared.';
    } else {
        $message    = 'No Wikipedia data column found.';
        $alertClass = 'warning';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="manifest" href="/manifest.json">
  <meta name="theme-color" content="#212529">
  <link rel="apple-touch-icon" href="/app-icons/icon-192.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Wikipedia Import</title>
    <link rel="stylesheet" href="/theme.css.php">
    <link rel="stylesheet" href="/css/all.min.css" crossorigin="anonymous">
    <style>
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
            max-height: 340px;
            overflow-y: auto;
            font-family: monospace;
            font-size: 0.76rem;
            line-height: 1.65;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .wi-book    { color: var(--bs-body-color); font-weight: 600; }
        .wi-saved   { color: var(--bs-success-text-emphasis); }
        .wi-skip    { color: var(--bs-secondary-color); opacity: 0.65; }
        .wi-error   { color: var(--bs-danger-text-emphasis); }
        .wi-summary { color: var(--bs-body-color); font-weight: 700; border-top: 1px solid var(--bs-border-color); margin-top: 0.3rem; padding-top: 0.3rem; display: block; }
        .wi-info    { color: var(--bs-info-text-emphasis); }
        .wi-heading { color: var(--bs-body-color); opacity: 0.75; }
        .wi-detail  { color: var(--bs-secondary-color); }
    </style>
</head>
<body>
<?php include '../navbar.php'; ?>

<div class="container mt-5 pt-4" style="max-width: 860px">

    <div class="d-flex align-items-center mb-4 gap-3">
        <div>
            <h3 class="mb-0"><i class="fa-brands fa-wikipedia-w me-2 text-primary"></i>Wikipedia Import</h3>
            <div class="text-muted small mt-1">Fetch Wikipedia summaries and plot sections for books in your library.</div>
        </div>
        <button class="btn btn-primary ms-auto" id="startBtn" onclick="runImport()">
            <i class="fa-solid fa-play me-1"></i> Start Import
        </button>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?= $alertClass ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Import card -->
    <div class="card mb-3" id="importCard">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="fa-brands fa-wikipedia-w text-primary"></i>
            <span class="fw-semibold">Fetch Wikipedia Data</span>
            <span class="phase-pill idle ms-auto" id="statusPill">
                <i class="fa-solid fa-circle-dot fa-xs"></i> Idle
            </span>
        </div>
        <div class="card-body">
            <p class="text-muted small mb-3">
                Searches Wikipedia by book title, verifies the result matches the book (title + author check),
                then stores the summary and plot section. Books already processed are skipped unless
                <strong>Refetch</strong> is enabled. Rate-limited to be polite to Wikipedia's servers.
            </p>

            <div class="d-flex flex-wrap gap-4 align-items-end mb-3">
                <div>
                    <label class="form-label small mb-1">Delay between requests (s)</label>
                    <input type="number" id="delay" value="2" min="0" max="30" class="form-control form-control-sm" style="width:5rem">
                </div>
                <div>
                    <label class="form-label small mb-1">Limit (0 = all)</label>
                    <input type="number" id="limit" value="0" min="0" class="form-control form-control-sm" style="width:6rem">
                </div>
                <div class="d-flex flex-column gap-1">
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" id="refetch">
                        <label class="form-check-label small" for="refetch">Refetch already-processed books</label>
                    </div>
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" id="dryRun">
                        <label class="form-check-label small" for="dryRun">Dry run (no changes saved)</label>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2 mb-3">
                <button class="btn btn-sm btn-primary" id="startInner" onclick="runImport()">
                    <i class="fa-solid fa-play fa-xs me-1"></i> Start
                </button>
                <button class="btn btn-sm btn-outline-danger d-none" id="stopBtn" onclick="stopImport()">
                    <i class="fa-solid fa-stop fa-xs me-1"></i> Stop
                </button>
            </div>

            <div class="progress mb-2 d-none" id="progressBar" style="height:5px">
                <div class="progress-bar" id="progressFill" role="progressbar" style="width:0%"></div>
            </div>
            <div class="step-log d-none" id="logArea"></div>
        </div>
    </div>

    <!-- Danger zone -->
    <div class="card border-danger-subtle">
        <div class="card-header text-danger-emphasis">
            <i class="fa-solid fa-triangle-exclamation me-1"></i> Danger Zone
        </div>
        <div class="card-body">
            <p class="text-muted small mb-3">
                Removes all stored Wikipedia summaries from the database. This cannot be undone —
                you will need to re-run the import to restore the data.
            </p>
            <form method="post" onsubmit="return confirm('Delete all Wikipedia data? This cannot be undone.');">
                <button type="submit" name="clear_wikipedia" value="1" class="btn btn-sm btn-danger">
                    <i class="fa-solid fa-trash me-1"></i> Clear All Wikipedia Data
                </button>
            </form>
        </div>
    </div>

</div><!-- /container -->

<script>
let currentES    = null;
let currentToken = null;
let isDone       = false;

function genToken() {
    return Math.random().toString(36).slice(2) + Date.now().toString(36);
}

function setPill(state, label) {
    const pill = document.getElementById('statusPill');
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
    const log = document.getElementById('logArea');
    if (log.classList.contains('d-none')) log.classList.remove('d-none');
    const span = document.createElement('span');
    span.className = cls || '';
    span.textContent = text + '\n';
    log.appendChild(span);
    log.scrollTop = log.scrollHeight;
}

function setProgress(pct) {
    const bar  = document.getElementById('progressBar');
    const fill = document.getElementById('progressFill');
    if (bar.classList.contains('d-none')) bar.classList.remove('d-none');
    fill.style.width = pct + '%';
}

function finish(state, label) {
    if (isDone) return;
    isDone = true;
    if (currentES) { currentES.close(); currentES = null; }
    currentToken = null;
    setPill(state, label);
    document.getElementById('startBtn').disabled   = false;
    document.getElementById('startInner').disabled = false;
    document.getElementById('stopBtn').classList.add('d-none');
    document.getElementById('startInner').classList.remove('d-none');
}

function runImport() {
    if (currentES) return;

    isDone = false;
    const log = document.getElementById('logArea');
    log.innerHTML = '';
    log.classList.add('d-none');
    document.getElementById('progressBar').classList.add('d-none');
    document.getElementById('progressFill').style.width = '0%';

    const token = genToken();
    currentToken = token;

    document.getElementById('startBtn').disabled   = true;
    document.getElementById('startInner').disabled = true;
    document.getElementById('startInner').classList.add('d-none');
    document.getElementById('stopBtn').classList.remove('d-none');
    setPill('running', 'Starting…');

    const params = new URLSearchParams({
        token: token,
        delay: document.getElementById('delay').value,
        limit: document.getElementById('limit').value,
    });
    if (document.getElementById('refetch').checked) params.set('refetch', '1');
    if (document.getElementById('dryRun').checked)  params.set('dry_run', '1');

    const es = new EventSource('../json_endpoints/wikipedia_import_stream.php?' + params.toString());
    currentES = es;

    es.addEventListener('started', () => {
        setPill('running', 'Running…');
    });

    es.addEventListener('line', e => {
        const d = JSON.parse(e.data);
        logLine(d.text, d.cls);
        if (d.progress) {
            setProgress(Math.round(d.progress.n / d.progress.total * 100));
        }
    });

    es.addEventListener('done', e => {
        const d = JSON.parse(e.data);
        const ok = d.exit_code === 0;
        finish(ok ? 'done' : 'error', ok ? 'Done' : 'Error (see log)');
    });

    es.addEventListener('stopped', () => {
        finish('stopped', 'Stopped');
    });

    es.addEventListener('error', e => {
        if (e.data) {
            try { logLine('✗ ' + JSON.parse(e.data).message, 'wi-error'); } catch (_) {}
            finish('error', 'Error');
        } else {
            finish('error', 'Connection lost');
        }
    });
}

function stopImport() {
    if (!currentToken) return;
    fetch('../json_endpoints/missing_send_stop.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'token=' + encodeURIComponent(currentToken),
    });
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="/js/search.js"></script>
</body>
</html>
