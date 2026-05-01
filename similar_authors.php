<?php
require_once 'db.php';
requireLogin();

$pdo      = getDatabaseConnection();
$username = currentUser() ?: 'default';

$cacheDir  = __DIR__ . '/cache/' . $username;
$cacheFile = $cacheDir . '/similar_authors.json';
$cacheTtl  = 86400;

$forceRefresh = isset($_GET['refresh']);
$cacheValid   = !$forceRefresh && file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTtl);

if ($cacheValid) {
    $authors  = json_decode(file_get_contents($cacheFile), true) ?? [];
    $cachedAt = filemtime($cacheFile);
} else {
    // "Dean R. Koontz" → "Dean Koontz"; leaves "R. F. Kuang" unchanged (first part is itself an initial).
    $stripMiddleInitials = function(string $name): string {
        $parts = explode(' ', $name);
        if (count($parts) < 3) return $name;
        if (preg_match('/^[A-Za-z]\.$/', $parts[0])) return $name;
        $kept = [];
        foreach ($parts as $i => $part) {
            if ($i === 0 || !preg_match('/^[A-Za-z]\.$/', $part)) $kept[] = $part;
        }
        return count($kept) < 2 ? $name : implode(' ', $kept);
    };

    $libAuthors = [];
    foreach ($pdo->query("SELECT LOWER(name) FROM authors")->fetchAll(PDO::FETCH_COLUMN) as $name) {
        $libAuthors[normalizeAuthorName($name)] = true;
    }

    $rawRows = $pdo->query("
        SELECT author, COUNT(*) AS book_count
        FROM gr_similar_books
        WHERE author IS NOT NULL AND author != ''
        GROUP BY author
    ")->fetchAll(PDO::FETCH_ASSOC);

    $seen = []; $authors = [];
    foreach ($rawRows as $row) {
        $norm  = normalizeAuthorName($row['author']);
        if ($norm === '') continue;
        $lower = strtolower($norm);
        if (isset($libAuthors[$lower])) continue;
        // Also exclude if the library has the same author without middle initials
        // e.g. gr_similar has "Dean R. Koontz", library has "Dean Koontz"
        $strippedLower = strtolower($stripMiddleInitials($norm));
        if ($strippedLower !== $lower && isset($libAuthors[$strippedLower])) continue;
        if (isset($seen[$lower])) {
            foreach ($authors as &$a) {
                if (strtolower($a['author']) === $lower) { $a['book_count'] += (int)$row['book_count']; break; }
            }
            unset($a);
            continue;
        }
        $seen[$lower] = true;
        $authors[] = ['author' => $norm, 'book_count' => (int)$row['book_count']];
    }
    usort($authors, fn($a, $b) => $b['book_count'] - $a['book_count'] ?: strcmp($a['author'], $b['author']));

    if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);
    file_put_contents($cacheFile, json_encode($authors));
    $cachedAt = time();
}

$totalAuthors = count($authors);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="manifest" href="/manifest.json">
  <meta name="theme-color" content="#212529">
  <link rel="apple-touch-icon" href="/app-icons/icon-192.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Similar Authors — Missing from Library</title>
    <link rel="stylesheet" href="/theme.css.php">
    <link rel="stylesheet" href="/css/all.min.css" crossorigin="anonymous">
    <style>
        .author-row {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.3rem 0.5rem;
            border-radius: 0.3rem;
            transition: background 0.1s;
            font-size: 0.875rem;
        }
        .author-row:hover { background: var(--bs-tertiary-bg); }
        .author-row.active-author { background: var(--bs-primary-bg-subtle); }
        .author-row.done-books  { opacity: 0.55; }
        .author-row.done-none   { opacity: 0.3; }
        .author-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .author-status { flex-shrink: 0; font-size: 0.7rem; }

        #batchLog {
            background: var(--bs-body-bg);
            border: 1px solid var(--bs-border-color);
            border-radius: 0.4rem;
            padding: 0.5rem 0.75rem;
            max-height: 180px;
            overflow-y: auto;
            font-family: monospace;
            font-size: 0.77rem;
            line-height: 1.65;
            scrollbar-width: thin;
        }
        .bl-ok   { color: var(--bs-success-text-emphasis); }
        .bl-none { color: var(--bs-secondary-color); }
        .bl-err  { color: var(--bs-danger-text-emphasis); }
        .bl-cur  { color: var(--bs-info-text-emphasis); font-style: italic; }

        #sendLog {
            background: #b7b7b7;
            border: 1px solid var(--bs-border-color);
            border-radius: 0.4rem;
            padding: 0.6rem 0.8rem;
            max-height: 340px;
            overflow-y: auto;
            font-family: monospace;
            font-size: 0.78rem;
            line-height: 1.7;
            scrollbar-width: thin;
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
        .sl-transient{ color: var(--bs-secondary-color); font-style: italic; }

        #ingestLog {
            background: #b7b7b7;
            border: 1px solid var(--bs-border-color);
            border-radius: 0.4rem;
            padding: 0.5rem 0.75rem;
            max-height: 220px;
            overflow-y: auto;
            font-family: monospace;
            font-size: 0.77rem;
            line-height: 1.65;
            scrollbar-width: thin;
        }
        .il-ok   { color: var(--bs-success-text-emphasis); }
        .il-dup  { color: var(--bs-warning-text-emphasis); }
        .il-err  { color: var(--bs-danger-text-emphasis); }
        .il-cur  { color: var(--bs-info-text-emphasis); font-style: italic; }
        .il-done { color: var(--bs-body-color); font-weight: 600; border-top: 1px solid var(--bs-border-color); margin-top: 0.25rem; padding-top: 0.25rem; }
    </style>
</head>
<body style="padding-top:80px">
<?php include 'navbar_other.php'; ?>

<div class="container my-4" style="max-width:900px">
    <h2 class="mb-1"><i class="fa-solid fa-user-magnifying-glass me-2"></i>Similar Authors — Missing from Library</h2>
    <p class="text-muted small mb-4">
        Authors from the similar-books scraper not yet in your library.
        Batch processing searches IRC, verifies with Open Library, and adds matching books to the send queue automatically.
    </p>

    <!-- ── Batch Processing ──────────────────────────────────────────────────── -->
    <div class="card mb-4">
        <div class="card-header fw-semibold d-flex align-items-center gap-2">
            <i class="fa-solid fa-layer-group fa-sm text-primary"></i> Batch Processing
            <span id="batchPill" class="badge bg-secondary ms-auto">Idle</span>
        </div>
        <div class="card-body">
            <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
                <div class="d-flex align-items-center gap-2">
                    <label class="form-label mb-0 small">Process</label>
                    <input type="number" id="batchSize" class="form-control form-control-sm"
                           value="10" min="1" max="9999" style="width:75px">
                    <span class="small text-muted">authors</span>
                </div>
                <button id="batchStartBtn" class="btn btn-primary btn-sm">
                    <i class="fa-solid fa-play me-1"></i> Start
                </button>
                <button id="batchStopBtn" class="btn btn-danger btn-sm" style="display:none">
                    <i class="fa-solid fa-stop me-1"></i> Stop
                </button>
                <span id="batchStatusText" class="text-muted small"></span>
            </div>

            <div id="batchProgressWrap" style="display:none" class="mb-2">
                <div class="progress mb-1" style="height:5px">
                    <div class="progress-bar" id="batchBar" style="width:0%"></div>
                </div>
                <div class="d-flex justify-content-between">
                    <span id="batchProgressText" class="small text-muted"></span>
                    <span id="batchBooksText" class="small text-muted"></span>
                </div>
            </div>

            <div id="batchLog" style="display:none"></div>
        </div>
    </div>

    <!-- ── Auto Ingest ──────────────────────────────────────────────────────── -->
    <div class="card mb-4">
        <div class="card-header fw-semibold d-flex align-items-center gap-2">
            <i class="fa-solid fa-file-import fa-sm text-success"></i> Auto Ingest from Autobooks
            <span id="ingestPill" class="badge bg-secondary ms-auto">Idle</span>
        </div>
        <div class="card-body">
            <p class="text-muted small mb-3">
                Scans <code>/run/media/david/2Tb One/autobooks</code>, extracts metadata, checks for duplicates,
                and imports each file into the library with an <code>auto_ingest</code> identifier.
                Ingested files are deleted from autobooks on success; duplicates are also removed.
            </p>
            <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
                <button id="ingestStartBtn" class="btn btn-success btn-sm">
                    <i class="fa-solid fa-play me-1"></i> Start Ingest
                </button>
                <button id="ingestStopBtn" class="btn btn-danger btn-sm" style="display:none">
                    <i class="fa-solid fa-stop me-1"></i> Stop
                </button>
                <span id="ingestStatusText" class="text-muted small"></span>
            </div>
            <div id="ingestProgressWrap" style="display:none" class="mb-2">
                <div class="progress mb-1" style="height:5px">
                    <div class="progress-bar bg-success" id="ingestBar" style="width:0%"></div>
                </div>
                <div class="d-flex justify-content-between">
                    <span id="ingestProgressText" class="small text-muted"></span>
                    <span id="ingestSummaryText" class="small text-muted"></span>
                </div>
            </div>
            <div id="ingestLog" style="display:none"></div>
        </div>
    </div>

    <!-- ── Author List ────────────────────────────────────────────────────────── -->
    <div class="card mb-4">
        <div class="card-header d-flex align-items-center gap-2 flex-wrap">
            <i class="fa-solid fa-users fa-sm text-primary"></i>
            <span class="fw-semibold">Authors</span>
            <span class="badge bg-secondary"><?= $totalAuthors ?></span>
            <span id="doneCount" class="text-muted small"></span>
            <span class="text-muted small ms-1" title="Cache generated at <?= date('Y-m-d H:i', $cachedAt) ?>">
                <i class="fa-solid fa-clock fa-xs me-1"></i><?= date('M j, g:ia', $cachedAt) ?>
            </span>
            <a href="?refresh=1" class="btn btn-sm btn-outline-secondary py-0" title="Regenerate author list">
                <i class="fa-solid fa-arrows-rotate fa-xs"></i>
            </a>
            <div class="ms-auto d-flex align-items-center gap-2">
                <div class="form-check form-check-sm mb-0">
                    <input class="form-check-input" type="checkbox" id="hideProcessed">
                    <label class="form-check-label small" for="hideProcessed">Hide processed</label>
                </div>
                <input type="text" id="authorFilter" class="form-control form-control-sm"
                       placeholder="Filter…" autocomplete="off" style="width:160px">
            </div>
        </div>
        <?php if ($totalAuthors === 0): ?>
        <div class="card-body text-muted text-center py-4">
            <i class="fa-solid fa-circle-check fa-lg mb-2" style="color:var(--bs-success)"></i>
            <p class="mb-0">No authors found — run the similar-books scraper first, or all authors are already in your library.</p>
        </div>
        <?php else: ?>
        <div class="card-body p-2" style="max-height:420px;overflow-y:auto" id="authorListWrap">
            <?php foreach ($authors as $a): ?>
            <div class="author-row" data-author="<?= htmlspecialchars($a['author'], ENT_QUOTES) ?>">
                <span class="author-name"><?= htmlspecialchars($a['author']) ?></span>
                <div class="author-meta d-flex align-items-center gap-1">
                    <span class="author-status"></span>
                    <span class="badge bg-secondary opacity-50" style="font-size:0.65rem"
                          title="appearances in similar books"><?= (int)$a['book_count'] ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <hr class="my-5">

    <!-- ── Send Queue ─────────────────────────────────────────────────────────── -->
    <div id="sendSection">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h5 class="mb-0"><i class="fa-solid fa-paper-plane me-2"></i>Send Queue <small class="text-muted fw-normal fs-6">(Similar Authors)</small></h5>
            <div class="d-flex gap-2">
                <button id="viewQueueBtn" class="btn btn-sm btn-outline-primary"
                        data-bs-toggle="modal" data-bs-target="#queueViewModal">
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
<div class="modal fade" id="queueViewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-list me-2"></i>Send Queue — Similar Authors</h5>
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
const USERNAME    = <?= json_encode($username) ?>;
const STORAGE_KEY = 'sim_authors_' + USERNAME;
const TOTAL       = <?= $totalAuthors ?>;

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── localStorage tracking ────────────────────────────────────────────────────

function getDoneMap() {
    try { return JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}'); } catch { return {}; }
}

function saveDoneMap(map) {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(map));
}

function markAuthorDone(author, added) {
    const map = getDoneMap();
    map[author] = { added, at: Date.now() };
    saveDoneMap(map);
    applyRowState(author, added);
    refreshDoneCount();
}

function applyRowState(author, added) {
    const row = document.querySelector(`.author-row[data-author="${CSS.escape(author)}"]`);
    if (!row) return;
    row.classList.remove('active-author', 'done-books', 'done-none');
    const statusEl = row.querySelector('.author-status');
    if (added > 0) {
        row.classList.add('done-books');
        statusEl.innerHTML = `<span class="badge bg-success" style="font-size:0.65rem">${added} queued</span>`;
    } else {
        row.classList.add('done-none');
        statusEl.innerHTML = `<span style="color:var(--bs-secondary-color);font-size:0.75rem">—</span>`;
    }
}

function refreshDoneCount() {
    const doneMap = getDoneMap();
    const n = Object.keys(doneMap).length;
    const el = document.getElementById('doneCount');
    if (el) el.textContent = n > 0 ? `· ${n} processed` : '';
}

// Restore states on load
window.addEventListener('DOMContentLoaded', () => {
    const doneMap = getDoneMap();
    for (const [author, info] of Object.entries(doneMap)) {
        applyRowState(author, info.added);
    }
    refreshDoneCount();
    applyVisibility();
});

// ── Filters ──────────────────────────────────────────────────────────────────

const authorFilter   = document.getElementById('authorFilter');
const hideProcessed  = document.getElementById('hideProcessed');

function applyVisibility() {
    const q    = authorFilter ? authorFilter.value.toLowerCase() : '';
    const hide = hideProcessed ? hideProcessed.checked : false;
    const doneMap = getDoneMap();
    document.querySelectorAll('.author-row').forEach(row => {
        const name = row.dataset.author.toLowerCase();
        const done = !!doneMap[row.dataset.author];
        row.style.display = (name.includes(q) && !(hide && done)) ? '' : 'none';
    });
}

authorFilter?.addEventListener('input', applyVisibility);
hideProcessed?.addEventListener('change', applyVisibility);

// ── Batch processing ─────────────────────────────────────────────────────────

const batchStartBtn    = document.getElementById('batchStartBtn');
const batchStopBtn     = document.getElementById('batchStopBtn');
const batchStatusText  = document.getElementById('batchStatusText');
const batchProgressWrap= document.getElementById('batchProgressWrap');
const batchBar         = document.getElementById('batchBar');
const batchProgressText= document.getElementById('batchProgressText');
const batchBooksText   = document.getElementById('batchBooksText');
const batchLog         = document.getElementById('batchLog');
const batchPill        = document.getElementById('batchPill');

let batchQueue      = [];
let batchTotal      = 0;
let batchDone       = 0;
let batchBooksAdded = 0;
let batchRunning    = false;
let currentSource   = null;
// Tracks the live "currently processing…" line so we can replace it
let batchCurLine    = null;

function batchLogAppend(text, cls) {
    if (batchLog.style.display === 'none') batchLog.style.display = 'block';
    batchCurLine = null;
    const div = document.createElement('div');
    div.className = cls;
    div.textContent = text;
    batchLog.prepend(div);
}

function batchLogCurrent(text) {
    if (batchLog.style.display === 'none') batchLog.style.display = 'block';
    if (!batchCurLine) {
        batchCurLine = document.createElement('div');
        batchCurLine.className = 'bl-cur';
        batchLog.prepend(batchCurLine);
    }
    batchCurLine.textContent = text;
}

function updateBatchProgress() {
    const pct = batchTotal > 0 ? Math.round(batchDone / batchTotal * 100) : 0;
    batchBar.style.width = pct + '%';
    batchProgressText.textContent = `${batchDone} / ${batchTotal} authors`;
    batchBooksText.textContent    = `${batchBooksAdded} books queued`;
}

function setBatchPill(state) {
    const map = { idle: 'bg-secondary', running: 'bg-primary', done: 'bg-success', stopped: 'bg-warning' };
    batchPill.className = 'badge ms-auto ' + (map[state] || 'bg-secondary');
    batchPill.textContent = { idle: 'Idle', running: 'Running', done: 'Done', stopped: 'Stopped' }[state] ?? state;
}

function startBatch() {
    const doneMap  = getDoneMap();
    const size     = Math.max(1, parseInt(document.getElementById('batchSize').value) || 10);

    // Pick unprocessed authors from the visible list (respects current filter)
    batchQueue = [...document.querySelectorAll('.author-row')]
        .filter(r => r.style.display !== 'none'
                  && !r.classList.contains('done-books')
                  && !r.classList.contains('done-none'))
        .map(r => r.dataset.author)
        .filter(a => !doneMap[a])
        .slice(0, size);

    if (batchQueue.length === 0) {
        batchStatusText.textContent = 'No unprocessed authors in the current view.';
        return;
    }

    batchTotal      = batchQueue.length;
    batchDone       = 0;
    batchBooksAdded = 0;
    batchRunning    = true;
    batchCurLine    = null;

    batchStartBtn.style.display = 'none';
    batchStopBtn.style.display  = 'inline-block';
    batchProgressWrap.style.display = 'block';
    batchLog.style.display = 'none';
    batchLog.innerHTML = '';
    setBatchPill('running');
    updateBatchProgress();

    processNextBatchAuthor();
}

function stopBatch() {
    batchRunning = false;
    if (currentSource) { currentSource.close(); currentSource = null; }
    batchLogAppend('■ Stopped', 'bl-err');
    finishBatch('stopped');
}

function finishBatch(state) {
    batchRunning = false;
    currentSource = null;
    batchStartBtn.style.display = 'inline-block';
    batchStopBtn.style.display  = 'none';
    batchStatusText.textContent = '';
    setBatchPill(state || 'done');
    loadQueueStatus();
    applyVisibility();
}

function processNextBatchAuthor() {
    if (!batchRunning || batchQueue.length === 0) {
        batchLogAppend(`── Done: ${batchDone} authors processed, ${batchBooksAdded} books queued ──`, 'bl-ok');
        finishBatch('done');
        return;
    }

    const author = batchQueue.shift();

    // Mark as active in list
    document.querySelectorAll('.author-row.active-author').forEach(r => r.classList.remove('active-author'));
    const activeRow = document.querySelector(`.author-row[data-author="${CSS.escape(author)}"]`);
    if (activeRow) {
        activeRow.classList.add('active-author');
        activeRow.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        const statusEl = activeRow.querySelector('.author-status');
        if (statusEl) statusEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin fa-xs"></i>';
    }

    batchLogCurrent(`Searching: ${author}…`);
    batchStatusText.textContent = `[${batchDone + 1}/${batchTotal}] ${author}`;

    currentSource = new EventSource(
        'json_endpoints/missing_author_stream.php?author=' + encodeURIComponent(author)
    );

    currentSource.addEventListener('status', e => {
        const d = JSON.parse(e.data);
        batchLogCurrent(`${author}: ${d.message}`);
    });

    currentSource.addEventListener('verify_progress', e => {
        const d = JSON.parse(e.data);
        batchLogCurrent(`${author}: verifying ${d.n}/${d.total} — ${d.title}`);
    });

    currentSource.addEventListener('done', async e => {
        currentSource.close(); currentSource = null;
        const d     = JSON.parse(e.data);
        const books = d.kept || [];

        if (books.length > 0) {
            const fd = new FormData();
            books.forEach(l => fd.append('lines[]', l));
            try {
                await fetch('json_endpoints/similar_author_queue.php', { method: 'POST', body: fd });
                batchBooksAdded += books.length;
            } catch { /* queue write failed — continue */ }
        }

        batchDone++;
        markAuthorDone(author, books.length);
        updateBatchProgress();

        if (books.length > 0) {
            batchLogAppend(`✓ ${author} — ${books.length} book${books.length !== 1 ? 's' : ''} queued`, 'bl-ok');
        } else {
            batchLogAppend(`○ ${author} — none found`, 'bl-none');
        }

        if (batchRunning) processNextBatchAuthor();
    });

    currentSource.addEventListener('error', e => {
        if (currentSource) { currentSource.close(); currentSource = null; }
        batchDone++;
        markAuthorDone(author, 0);
        updateBatchProgress();
        batchLogAppend(`✗ ${author} — error`, 'bl-err');
        if (batchRunning) processNextBatchAuthor();
    });

    currentSource.onerror = () => {
        if (currentSource && currentSource.readyState === EventSource.CLOSED && batchRunning) {
            // Stream closed without a 'done' or 'error' event — treat as error
            batchDone++;
            markAuthorDone(author, 0);
            updateBatchProgress();
            batchLogAppend(`✗ ${author} — connection lost`, 'bl-err');
            currentSource = null;
            processNextBatchAuthor();
        }
    };
}

batchStartBtn.addEventListener('click', startBatch);
batchStopBtn.addEventListener('click', stopBatch);

// ── Auto Ingest ───────────────────────────────────────────────────────────────

const ingestStartBtn    = document.getElementById('ingestStartBtn');
const ingestStopBtn     = document.getElementById('ingestStopBtn');
const ingestStatusText  = document.getElementById('ingestStatusText');
const ingestProgressWrap= document.getElementById('ingestProgressWrap');
const ingestBar         = document.getElementById('ingestBar');
const ingestProgressText= document.getElementById('ingestProgressText');
const ingestSummaryText = document.getElementById('ingestSummaryText');
const ingestLog         = document.getElementById('ingestLog');
const ingestPill        = document.getElementById('ingestPill');

let ingestSource  = null;
let ingestCurLine = null;
let ingestStats   = { ingested: 0, skipped: 0, errors: 0 };

function ingestLogAppend(text, cls) {
    if (ingestLog.style.display === 'none') ingestLog.style.display = 'block';
    ingestCurLine = null;
    const div = document.createElement('div');
    div.className = cls;
    div.textContent = text;
    ingestLog.prepend(div);
}

function ingestLogCurrent(text) {
    if (ingestLog.style.display === 'none') ingestLog.style.display = 'block';
    if (!ingestCurLine) {
        ingestCurLine = document.createElement('div');
        ingestCurLine.className = 'il-cur';
        ingestLog.prepend(ingestCurLine);
    }
    ingestCurLine.textContent = text;
}

function setIngestPill(state) {
    const map = { idle: 'bg-secondary', running: 'bg-success', done: 'bg-success', stopped: 'bg-warning', error: 'bg-danger' };
    ingestPill.className = 'badge ms-auto ' + (map[state] || 'bg-secondary');
    ingestPill.textContent = { idle: 'Idle', running: 'Running', done: 'Done', stopped: 'Stopped', error: 'Error' }[state] ?? state;
}

function updateIngestProgress(n, total) {
    const pct = total > 0 ? Math.round(n / total * 100) : 0;
    ingestBar.style.width = pct + '%';
    ingestProgressText.textContent = `${n} / ${total} files`;
    ingestSummaryText.textContent  =
        `${ingestStats.ingested} added · ${ingestStats.skipped} duplicates · ${ingestStats.errors} errors`;
}

function finishIngest(state) {
    if (ingestSource) { ingestSource.close(); ingestSource = null; }
    ingestCurLine = null;
    ingestStartBtn.style.display = 'inline-block';
    ingestStopBtn.style.display  = 'none';
    ingestStatusText.textContent = '';
    setIngestPill(state || 'done');
}

ingestStartBtn.addEventListener('click', () => {
    if (ingestSource) return;

    ingestStats = { ingested: 0, skipped: 0, errors: 0 };
    ingestLog.innerHTML = '';
    ingestCurLine = null;
    ingestLog.style.display = 'none';
    ingestProgressWrap.style.display = 'block';
    ingestBar.style.width = '0%';
    ingestProgressText.textContent = '';
    ingestSummaryText.textContent  = '';
    ingestStartBtn.style.display = 'none';
    ingestStopBtn.style.display  = 'inline-block';
    ingestStatusText.textContent = 'Scanning…';
    setIngestPill('running');

    ingestSource = new EventSource('json_endpoints/auto_ingest_stream.php');

    ingestSource.addEventListener('status', e => {
        const d = JSON.parse(e.data);
        ingestStatusText.textContent = d.message;
        ingestLogCurrent(d.message);
    });

    ingestSource.addEventListener('scan_done', e => {
        const d = JSON.parse(e.data);
        if (d.count === 0) {
            ingestLogAppend('No supported files found in autobooks directory.', 'il-cur');
            finishIngest('done');
        } else {
            ingestStatusText.textContent = `Found ${d.count} file${d.count !== 1 ? 's' : ''} — processing…`;
            updateIngestProgress(0, d.count);
        }
    });

    ingestSource.addEventListener('processing', e => {
        const d = JSON.parse(e.data);
        const note = d.transposed ? ' ⇄ (transposed)' : '';
        ingestLogCurrent(`[${d.n}/${d.total}] ${d.title} — ${d.author}${note}`);
        ingestStatusText.textContent = `[${d.n}/${d.total}] ${d.filename}`;
    });

    ingestSource.addEventListener('ingest_ok', e => {
        const d = JSON.parse(e.data);
        ingestStats.ingested++;
        updateIngestProgress(d.n, d.total);
        const note = d.transposed ? ' ⇄' : '';
        ingestLogAppend(`✓ ${d.title} — ${d.author}${note}  (#${d.book_id})`, 'il-ok');
    });

    ingestSource.addEventListener('duplicate', e => {
        const d = JSON.parse(e.data);
        ingestStats.skipped++;
        updateIngestProgress(d.n, d.total);
        ingestLogAppend(
            `⊘ Duplicate: ${d.title} (already #${d.existing_id}: "${d.existing_title}") — removed from autobooks`,
            'il-dup'
        );
    });

    ingestSource.addEventListener('ingest_error', e => {
        const d = JSON.parse(e.data);
        ingestStats.errors++;
        updateIngestProgress(d.n, d.total);
        ingestLogAppend(`✗ ${d.title || d.filename}: ${d.error}`, 'il-err');
    });

    ingestSource.addEventListener('done', e => {
        const d = JSON.parse(e.data);
        ingestLogAppend(
            `── Done: ${d.ingested} ingested · ${d.skipped} duplicates removed · ${d.errors} errors ──`,
            'il-done'
        );
        finishIngest('done');
    });

    ingestSource.addEventListener('error', e => {
        try { ingestLogAppend('Error: ' + JSON.parse(e.data).message, 'il-err'); } catch {}
        finishIngest('error');
    });

    ingestSource.onerror = () => {
        if (ingestSource && ingestSource.readyState === EventSource.CLOSED) finishIngest('done');
    };
});

ingestStopBtn.addEventListener('click', () => {
    if (ingestSource) { ingestSource.close(); ingestSource = null; }
    ingestLogAppend('■ Stopped by user', 'il-err');
    finishIngest('stopped');
});

// ── Send Queue ────────────────────────────────────────────────────────────────

const sendStartBtn   = document.getElementById('sendStartBtn');
const sendStopBtn    = document.getElementById('sendStopBtn');
const sendLimitInput = document.getElementById('sendLimit');
const sendLog        = document.getElementById('sendLog');
const sendStatusText = document.getElementById('sendStatusText');
const queueInfoBar   = document.getElementById('queueInfoBar');
const refreshQueueBtn= document.getElementById('refreshQueueBtn');

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
        const r = await fetch('json_endpoints/similar_queue_status.php');
        const d = await r.json();
        queueInfoBar.textContent = `Queue: ${d.pending} pending  ·  ${d.sent} already sent  ·  ${d.total} total`;
        sendStartBtn.disabled = d.pending === 0 || sendSource !== null;
    } catch {
        queueInfoBar.textContent = 'Could not load queue status.';
    }
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

    sendSource = new EventSource(
        `json_endpoints/similar_send_stream.php?token=${encodeURIComponent(sendToken)}&limit=${limit}`
    );

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
        sendLog_transient(`  ⏳ Waiting… ${d.elapsed}s elapsed, ${d.remaining}s remaining`, 'sl-waiting');
    });
    sendSource.addEventListener('transfer_received', e => {
        const d = JSON.parse(e.data);
        sendLog_append(`  ✓ Received: ${d.name}  (${d.elapsed}s)`, 'sl-received');
    });
    sendSource.addEventListener('transfer_timeout', () => {
        sendLog_append('  ⚠ No transfer detected — moving on', 'sl-timeout');
    });
    sendSource.addEventListener('skipped', () => {
        sendLog_append('  — Skipped (request failed)', 'sl-skipped');
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
    sendToken = null; transientEl = null;
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
        const r = await fetch('json_endpoints/similar_queue_items.php');
        const d = await r.json();
        if (!d.items || d.items.length === 0) {
            queueViewBody.innerHTML = '<p class="text-muted fst-italic">Queue is empty.</p>';
            return;
        }
        queueViewBody.innerHTML = d.items.map(line => {
            const parts = line.match(/^(\S+)\s+(.+?)(\s+::INFO::.+)?$/);
            const bot  = parts ? parts[1] : '';
            const file = parts ? parts[2].replace(/\s*\|[^|]+\|/, '').trim() : line;
            return `<div class="d-flex align-items-start gap-2 border-bottom py-2" data-line="${escHtml(line)}">
                <button class="btn btn-sm btn-outline-danger flex-shrink-0 remove-queue-item" title="Remove">
                    <i class="fa-solid fa-trash"></i>
                </button>
                <div>
                    <span class="badge bg-secondary me-1">${escHtml(bot)}</span>
                    <span class="small">${escHtml(file)}</span>
                </div>
            </div>`;
        }).join('');

        queueViewBody.querySelectorAll('.remove-queue-item').forEach(btn => {
            btn.addEventListener('click', async () => {
                const row = btn.closest('[data-line]');
                const fd  = new FormData();
                fd.append('lines[]', row.dataset.line);
                const r2 = await fetch('json_endpoints/similar_queue_remove.php', { method: 'POST', body: fd });
                const d2 = await r2.json();
                if (d2.ok) {
                    row.remove();
                    loadQueueStatus();
                    if (!queueViewBody.querySelector('[data-line]'))
                        queueViewBody.innerHTML = '<p class="text-muted fst-italic">Queue is empty.</p>';
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
