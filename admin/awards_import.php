<?php
require_once '../db.php';
requireLogin();

$sourceDir = __DIR__ . '/../data/awards';
$sourceFiles = [
    'hugo-awards.txt'                               => 'Hugo Award',
    'nebular-awards.txt'                            => 'Nebula Award',
    'BSFA-Award-for-Best-Novel.txt'                 => 'BSFA Best Novel',
    'BFA-Award-for-Best-Novel.txt'                  => 'BFA Best Novel',
    'Arthur-C-Clarke-award.txt'                     => 'Arthur C. Clarke Award',
    'Philip-K-Dick-Award.txt'                       => 'Philip K. Dick Award',
    'Locus-Award-for-Best-Fantasy-Novel.txt'        => 'Locus Best Fantasy Novel',
    'Locus-Award-for-Best-Science-Fiction-Novel.txt'=> 'Locus Best SF Novel',
    'Locus-Best-Horror-Novel.txt'                   => 'Locus Best Horror Novel',
    'gemmell-award-winners.txt'                     => 'Gemmell Award',
    'world-fantasy-award.txt'                       => 'World Fantasy Award',
    'Goodreads-Choice-Awards.txt'                   => 'Goodreads Choice Awards',
    'goodreads-fantasy-science-fiction-paranormal-fantasy.txt' => 'Goodreads Choice (scraped)',
    'John-W.-Campbell-Memorial-Award.txt'           => 'Campbell Memorial',
    'booker-prize.txt'                              => 'Booker Prize',
    'Pulitzer-Prize-1910-1970.txt'                  => 'Pulitzer Prize (1910–1970)',
    'Pulitzer-Prize-1980s.txt'                      => 'Pulitzer Prize (1980s+)',
];

$masterTsv   = __DIR__ . '/../data/awards-master.tsv';
$masterMtime = file_exists($masterTsv) ? filemtime($masterTsv) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="manifest" href="/manifest.json">
  <meta name="theme-color" content="#212529">
  <link rel="apple-touch-icon" href="/app-icons/icon-192.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Awards Import</title>
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
            max-height: 360px;
            overflow-y: auto;
            font-family: monospace;
            font-size: 0.76rem;
            line-height: 1.65;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .aw-insert   { color: var(--bs-success-text-emphasis); }
        .aw-skip     { color: var(--bs-secondary-color); opacity: 0.6; }
        .aw-dryrun   { color: var(--bs-warning-text-emphasis); font-weight: 600; }
        .aw-notfound { color: var(--bs-danger-text-emphasis); }
        .aw-heading  { color: var(--bs-body-color); font-weight: 600; }
        .aw-summary  { color: var(--bs-body-color); font-weight: 700; border-top: 1px solid var(--bs-border-color); margin-top: 0.3rem; padding-top: 0.3rem; display: block; }
        .aw-done     { color: var(--bs-success-text-emphasis); font-weight: 700; display: block; margin-top: 0.3rem; }
        .aw-detail   { color: var(--bs-secondary-color); }

        /* Source files grid */
        .source-file-row { font-size: 0.82rem; padding: 0.25rem 0; border-bottom: 1px solid var(--bs-border-color); }
        .source-file-row:last-child { border-bottom: none; }
    </style>
</head>
<body class="pt-5">
<?php include '../navbar.php'; ?>

<div class="container mt-5 pt-4" style="max-width: 860px">

    <div class="d-flex align-items-center mb-4 gap-3">
        <div>
            <h3 class="mb-0"><i class="fa-solid fa-trophy me-2 text-warning"></i>Awards Import</h3>
            <div class="text-muted small mt-1">Parse award source files into a master TSV, then import matches into your library.</div>
        </div>
        <div class="ms-auto d-flex gap-2">
            <a href="../awards.php" class="btn btn-outline-secondary btn-sm">
                <i class="fa-solid fa-list me-1"></i> Awards List
            </a>
            <button class="btn btn-outline-secondary btn-sm" id="editTsvBtn" onclick="toggleTsvEditor()">
                <i class="fa-solid fa-pen-to-square me-1"></i> Edit TSV
            </button>
            <button class="btn btn-primary" id="runAllBtn" onclick="runAll()">
                <i class="fa-solid fa-play me-1"></i> Run All Steps
            </button>
        </div>
    </div>

    <!-- TSV Editor -->
    <div class="card mb-4" id="tsvEditorCard" style="display:none">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="fa-solid fa-pen-to-square fa-sm"></i>
            <span class="fw-semibold">Edit Master TSV</span>
            <span class="text-muted small ms-2">Changes here are applied immediately to <code>awards-master.tsv</code> — re-run Step 2 to update the library.</span>
            <button type="button" class="btn-close ms-auto" onclick="toggleTsvEditor()"></button>
        </div>
        <div class="card-body">
            <input type="text" id="tsvSearch" class="form-control form-control-sm mb-2"
                   placeholder="Search by title, author or award…" autocomplete="off">
            <div class="text-muted small mb-3">Up to 50 results shown — narrow your search to find specific entries.</div>
            <div id="tsvTableWrap" style="display:none; overflow-x:auto">
                <table class="table table-sm table-hover mb-1" style="font-size:0.8rem; min-width:700px">
                    <thead class="table-light">
                        <tr>
                            <th>Award</th>
                            <th style="width:4.5rem">Year</th>
                            <th>Author</th>
                            <th>Title</th>
                            <th style="width:7rem">Result</th>
                            <th style="width:6rem"></th>
                        </tr>
                    </thead>
                    <tbody id="tsvTbody"></tbody>
                </table>
                <div id="tsvOverflow" class="text-muted small" style="display:none">
                    <i class="fa-solid fa-triangle-exclamation me-1 text-warning"></i>More than 50 results — refine your search.
                </div>
            </div>
            <div id="tsvEmpty"   class="text-muted small py-2" style="display:none">No matching entries.</div>
            <div id="tsvLoading" class="text-muted small py-2" style="display:none"><i class="fa-solid fa-spinner fa-spin me-1"></i>Searching…</div>
        </div>
    </div>

    <!-- Source files status -->
    <div class="card mb-4">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="fa-solid fa-folder-open fa-sm"></i>
            <span class="fw-semibold">Source Files</span>
            <?php
            $missing = 0;
            foreach ($sourceFiles as $file => $_) {
                if (!file_exists($sourceDir . '/' . $file)) $missing++;
            }
            ?>
            <?php if ($missing > 0): ?>
                <span class="badge bg-danger ms-auto"><?= $missing ?> missing</span>
            <?php else: ?>
                <span class="badge bg-success ms-auto">All present</span>
            <?php endif; ?>
        </div>
        <div class="card-body py-2">
            <?php foreach ($sourceFiles as $file => $label):
                $path    = $sourceDir . '/' . $file;
                $exists  = file_exists($path);
                $mtime   = $exists ? filemtime($path) : null;
                $age     = $mtime ? date('Y-m-d', $mtime) : null;
            ?>
            <div class="source-file-row d-flex align-items-center gap-2">
                <?php if ($exists): ?>
                    <i class="fa-solid fa-circle-check text-success fa-xs"></i>
                <?php else: ?>
                    <i class="fa-solid fa-circle-xmark text-danger fa-xs"></i>
                <?php endif; ?>
                <span class="flex-grow-1"><?= htmlspecialchars($label) ?></span>
                <span class="text-muted" style="font-size:0.75rem"><?= htmlspecialchars($file) ?></span>
                <?php if ($age): ?>
                    <span class="text-muted" style="font-size:0.75rem;min-width:6rem;text-align:right"><?= $age ?></span>
                <?php else: ?>
                    <span class="text-danger" style="font-size:0.75rem;min-width:6rem;text-align:right">not found</span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <?php if ($masterMtime): ?>
            <div class="mt-2 pt-2 border-top text-muted small">
                <i class="fa-solid fa-file-csv me-1"></i>
                <strong>awards-master.tsv</strong> last generated: <?= date('Y-m-d H:i', $masterMtime) ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Step 1: Generate Master TSV -->
    <div class="card mb-3" id="card1">
        <div class="card-header d-flex align-items-center gap-2">
            <span class="step-badge">1</span>
            <span class="fw-semibold">Generate Master TSV</span>
            <span class="phase-pill idle ms-auto" id="pill1"><i class="fa-solid fa-circle-dot fa-xs"></i> Idle</span>
        </div>
        <div class="card-body">
            <p class="text-muted small mb-3">Parses all award source files and writes <code>awards-master.tsv</code> containing every winner and nominee across all awards.</p>
            <div class="d-flex gap-2 mb-3">
                <button class="btn btn-sm btn-primary" id="start1" onclick="runStep(1)">
                    <i class="fa-solid fa-play fa-xs me-1"></i> Start
                </button>
            </div>
            <div class="step-log d-none" id="log1"></div>
        </div>
    </div>

    <!-- Step 2: Import -->
    <div class="card mb-3" id="card2">
        <div class="card-header d-flex align-items-center gap-2">
            <span class="step-badge">2</span>
            <span class="fw-semibold">Import into Library</span>
            <span class="phase-pill idle ms-auto" id="pill2"><i class="fa-solid fa-circle-dot fa-xs"></i> Idle</span>
        </div>
        <div class="card-body">
            <p class="text-muted small mb-3">Reads <code>awards-master.tsv</code> and matches entries against your library by title, inserting records into <code>book_awards</code>. Safe to re-run — already-existing entries are skipped.</p>
            <div class="d-flex flex-wrap gap-4 align-items-end mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="dryRun2">
                    <label class="form-check-label small" for="dryRun2">Dry run (preview only, no DB writes)</label>
                </div>
            </div>
            <div class="d-flex gap-2 mb-3">
                <button class="btn btn-sm btn-primary" id="start2" onclick="runStep(2)">
                    <i class="fa-solid fa-play fa-xs me-1"></i> Start
                </button>
            </div>
            <div class="progress mb-2 d-none" id="prog2" style="height:5px">
                <div class="progress-bar" id="bar2" role="progressbar" style="width:0%"></div>
            </div>
            <div class="step-log d-none" id="log2"></div>
        </div>
    </div>

</div><!-- /container -->

<script>
const stepTokens = {1: null, 2: null};
const stepES     = {1: null, 2: null};
const stepDone   = {1: false, 2: false};

function genToken() {
    return Math.random().toString(36).slice(2) + Date.now().toString(36);
}

function setPill(n, state, label) {
    const pill = document.getElementById('pill' + n);
    pill.className = 'phase-pill ' + state + ' ms-auto';
    const icons = { idle: 'circle-dot', running: 'circle-notch fa-spin', done: 'circle-check', error: 'circle-xmark', stopped: 'circle-pause' };
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
    if (stepDone[n]) return;
    stepDone[n] = true;
    const es = stepES[n];
    if (es) es.close();
    stepES[n]     = null;
    stepTokens[n] = null;
    setPill(n, state, label);
    document.getElementById('start' + n).disabled = false;
    document.getElementById('start' + n).classList.remove('d-none');
    const stop = document.getElementById('stop' + n);
    if (stop) stop.classList.add('d-none');
    document.getElementById('runAllBtn').disabled = false;
    if (state === 'done' && autoNext && n < 2) {
        runStep(n + 1, autoNext);
    }
}

function runStep(n, autoNext) {
    if (stepES[n]) return;

    stepDone[n] = false;
    const log = document.getElementById('log' + n);
    log.innerHTML = '';
    log.classList.add('d-none');
    const prog = document.getElementById('prog' + n);
    if (prog) { prog.classList.add('d-none'); document.getElementById('bar' + n).style.width = '0%'; }

    const token = genToken();
    stepTokens[n] = token;

    document.getElementById('start' + n).disabled = true;
    const stop = document.getElementById('stop' + n);
    if (stop) stop.classList.remove('d-none');
    document.getElementById('runAllBtn').disabled = true;
    setPill(n, 'running', 'Starting…');

    const params = new URLSearchParams({ step: n, token });
    if (n === 2 && document.getElementById('dryRun2').checked) params.set('dry_run', '1');

    const es = new EventSource('../json_endpoints/awards_import_stream.php?' + params.toString());
    stepES[n] = es;

    es.addEventListener('started', () => setPill(n, 'running', 'Running…'));

    es.addEventListener('line', e => {
        const d = JSON.parse(e.data);
        logLine(n, d.text, d.cls);
        if (d.progress) setProgress(n, Math.round(d.progress.n / d.progress.total * 100));
    });

    es.addEventListener('done', e => {
        const d = JSON.parse(e.data);
        const ok = d.exit_code === 0;
        finishStep(n, ok ? 'done' : 'error', ok ? 'Done' : 'Error (see log)', autoNext);
    });

    es.addEventListener('stopped', () => finishStep(n, 'stopped', 'Stopped', false));

    es.addEventListener('error', e => {
        if (e.data) {
            try { logLine(n, '✗ ' + JSON.parse(e.data).message, 'aw-notfound'); } catch (_) {}
            finishStep(n, 'error', 'Error', false);
        } else {
            finishStep(n, 'error', 'Connection lost', false);
        }
    });
}

function stopStep(n) {
    const token = stepTokens[n];
    if (!token) return;
    fetch('../json_endpoints/missing_send_stop.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'token=' + encodeURIComponent(token),
    });
}

function runAll() {
    runStep(1, true);
}

// ── TSV Editor ────────────────────────────────────────────────────────────────

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

let tsvRows    = [];  // current search results [{index,award,year,author,title,result}]
let tsvSearchT = null;

function toggleTsvEditor() {
    const card = document.getElementById('tsvEditorCard');
    const btn  = document.getElementById('editTsvBtn');
    const open = card.style.display === 'none';
    card.style.display = open ? '' : 'none';
    btn.classList.toggle('active', open);
    if (open) {
        document.getElementById('tsvSearch').focus();
        tsvDoSearch();
    }
}

document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('tsvSearch').addEventListener('input', () => {
        clearTimeout(tsvSearchT);
        tsvSearchT = setTimeout(tsvDoSearch, 280);
    });
});

function tsvDoSearch() {
    const q     = document.getElementById('tsvSearch').value.trim();
    const wrap  = document.getElementById('tsvTableWrap');
    const empty = document.getElementById('tsvEmpty');
    const load  = document.getElementById('tsvLoading');

    wrap.style.display  = 'none';
    empty.style.display = 'none';
    load.style.display  = '';

    fetch('../json_endpoints/edit_awards_tsv.php?q=' + encodeURIComponent(q))
        .then(r => r.json())
        .then(data => {
            load.style.display = 'none';
            tsvRows = data.rows ?? [];
            document.getElementById('tsvOverflow').style.display = data.overflow ? '' : 'none';
            if (tsvRows.length === 0) {
                empty.style.display = '';
            } else {
                tsvRender();
                wrap.style.display = '';
            }
        })
        .catch(() => { load.style.display = 'none'; });
}

function tsvRender() {
    const tbody = document.getElementById('tsvTbody');
    tbody.innerHTML = '';
    tsvRows.forEach(row => tbody.appendChild(tsvViewRow(row)));
}

const RESULTS = ['won','nominated','special citation'];

function tsvViewRow(row) {
    const tr = document.createElement('tr');
    tr.dataset.idx = row.index;
    tr.innerHTML = `
        <td class="text-truncate" style="max-width:180px" title="${escHtml(row.award)}">${escHtml(row.award)}</td>
        <td>${row.year}</td>
        <td class="text-truncate" style="max-width:140px" title="${escHtml(row.author)}">${escHtml(row.author)}</td>
        <td class="text-truncate" style="max-width:200px" title="${escHtml(row.title)}">${escHtml(row.title)}</td>
        <td><span class="badge ${row.result==='won'?'bg-success':row.result==='nominated'?'bg-secondary':'bg-warning text-dark'}">${escHtml(row.result)}</span></td>
        <td class="text-end">
            <button class="btn btn-sm btn-outline-secondary py-0 px-1" onclick="tsvEditRow(${row.index})">
                <i class="fa-solid fa-pen fa-xs"></i>
            </button>
            <button class="btn btn-sm btn-outline-danger py-0 px-1 ms-1" onclick="tsvDeleteRow(${row.index})">
                <i class="fa-solid fa-trash fa-xs"></i>
            </button>
        </td>`;
    return tr;
}

function tsvEditRow(idx) {
    const row = tsvRows.find(r => r.index === idx);
    if (!row) return;
    const tr = document.querySelector(`#tsvTbody tr[data-idx="${idx}"]`);
    if (!tr) return;
    tr.classList.add('table-warning');
    tr.innerHTML = `
        <td><input class="form-control form-control-sm p-0 px-1" id="te_award_${idx}"  value="${escHtml(row.award)}"></td>
        <td><input class="form-control form-control-sm p-0 px-1" id="te_year_${idx}"   value="${row.year}" type="number" style="width:4.5rem"></td>
        <td><input class="form-control form-control-sm p-0 px-1" id="te_author_${idx}" value="${escHtml(row.author)}"></td>
        <td><input class="form-control form-control-sm p-0 px-1" id="te_title_${idx}"  value="${escHtml(row.title)}"></td>
        <td>
            <select class="form-select form-select-sm p-0 px-1" id="te_result_${idx}">
                ${RESULTS.map(r => `<option${r===row.result?' selected':''}>${r}</option>`).join('')}
            </select>
        </td>
        <td class="text-end text-nowrap">
            <button class="btn btn-sm btn-success py-0 px-1" onclick="tsvSaveRow(${idx})">
                <i class="fa-solid fa-check fa-xs"></i>
            </button>
            <button class="btn btn-sm btn-outline-secondary py-0 px-1 ms-1" onclick="tsvCancelRow(${idx})">
                <i class="fa-solid fa-xmark fa-xs"></i>
            </button>
        </td>`;
    document.getElementById(`te_title_${idx}`).focus();
}

function tsvCancelRow(idx) {
    const row = tsvRows.find(r => r.index === idx);
    if (!row) return;
    const tr = document.querySelector(`#tsvTbody tr[data-idx="${idx}"]`);
    if (!tr) return;
    tr.classList.remove('table-warning');
    tr.replaceWith(tsvViewRow(row));
}

function tsvSaveRow(idx) {
    const payload = {
        index:  idx,
        award:  document.getElementById(`te_award_${idx}`).value,
        year:   parseInt(document.getElementById(`te_year_${idx}`).value),
        author: document.getElementById(`te_author_${idx}`).value,
        title:  document.getElementById(`te_title_${idx}`).value,
        result: document.getElementById(`te_result_${idx}`).value,
    };
    fetch('../json_endpoints/edit_awards_tsv.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload),
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            // Update local cache and re-render the row
            const row = tsvRows.find(r => r.index === idx);
            if (row) Object.assign(row, payload);
            const tr = document.querySelector(`#tsvTbody tr[data-idx="${idx}"]`);
            if (tr) tr.replaceWith(tsvViewRow(row));
        } else {
            alert('Save failed: ' + (data.error ?? 'unknown error'));
        }
    })
    .catch(() => alert('Save failed — network error'));
}

function tsvDeleteRow(idx) {
    if (!confirm('Delete this entry from awards-master.tsv?')) return;
    fetch('../json_endpoints/edit_awards_tsv.php?index=' + idx, { method: 'DELETE' })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            tsvRows = tsvRows.filter(r => r.index !== idx);
            document.querySelector(`#tsvTbody tr[data-idx="${idx}"]`)?.remove();
            if (tsvRows.length === 0) {
                document.getElementById('tsvTableWrap').style.display = 'none';
                document.getElementById('tsvEmpty').style.display = '';
            }
        } else {
            alert('Delete failed: ' + (data.error ?? 'unknown error'));
        }
    })
    .catch(() => alert('Delete failed — network error'));
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="js/search.js"></script>
</body>
</html>
