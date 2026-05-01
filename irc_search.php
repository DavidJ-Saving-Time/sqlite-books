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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IRC Book Search</title>
    <link rel="stylesheet" href="/theme.css.php">
    <link rel="stylesheet" href="/css/all.min.css" crossorigin="anonymous">
    <style>
        mark {
            background-color: color-mix(in srgb, var(--accent, #fd8c00) 35%, transparent);
            color: inherit;
            border-radius: 2px;
            padding: 0 1px;
        }
    </style>
</head>
<body class="pt-5">
<?php include 'navbar.php'; ?>

<!-- Toast notifications -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1100">
    <div id="toastEl" class="toast align-items-center text-white border-0" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body" id="toastBody"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
</div>

<div class="container my-4">
    <h1 class="mb-4"><i class="fa-solid fa-magnifying-glass me-2"></i>IRC Book Search</h1>

    <div id="queueStatus" class="alert alert-info mt-4" style="display: none;">
        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <div>
                    <strong>Queue:</strong>
                    <span id="queueCount">0</span> item(s) &mdash;
                    <span id="queueState">Paused</span>
                </div>
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" id="autoSendToggle" checked>
                    <label class="form-check-label small" for="autoSendToggle">Auto-send</label>
                </div>
            </div>
            <div>
                <button class="btn btn-sm btn-success me-1" onclick="startQueue()"><i class="fa-solid fa-play"></i> Start</button>
                <button class="btn btn-sm btn-warning me-1" onclick="stopQueue()"><i class="fa-solid fa-pause"></i> Pause</button>
                <button class="btn btn-sm btn-danger" onclick="clearQueue()"><i class="fa-solid fa-trash"></i> Clear</button>
            </div>
        </div>
        <ul id="queueList" class="list-group list-group-flush small"></ul>
    </div>

    <form id="searchForm" class="mb-4">
        <div class="input-group">
            <input type="text" id="query" class="form-control" placeholder="e.g. John Doe" required>
            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-magnifying-glass"></i> Search
            </button>
        </div>
        <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" id="requireAllWords">
            <label class="form-check-label" for="requireAllWords">Must contain all words</label>
        </div>
    </form>

    <div id="recent" class="mb-4">
        <h6 class="text-muted">Recent searches:</h6>
        <div id="recentList" class="d-flex flex-wrap gap-2"></div>
    </div>

    <div id="results"></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script>
const API_BASE   = "https://node2.nilla.local";
const form       = document.getElementById('searchForm');
const queryInput = document.getElementById('query');
const resultsDiv = document.getElementById('results');
const recentList = document.getElementById('recentList');

// ── Toast ──────────────────────────────────────────────────────────────────
const toastEl   = document.getElementById('toastEl');
const toastBody = document.getElementById('toastBody');
const bsToast   = new bootstrap.Toast(toastEl, { delay: 3000 });

function showToast(message, type = 'success') {
    toastEl.className = `toast align-items-center text-white border-0 bg-${type}`;
    toastBody.textContent = message;
    bsToast.show();
}

// ── Helpers ────────────────────────────────────────────────────────────────
function escapeHTML(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function escapeRegExp(str) {
    return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function highlightText(text, term) {
    let escaped = escapeHTML(text);
    term.split(/\s+/).filter(Boolean).forEach(word => {
        escaped = escaped.replace(new RegExp(`(${escapeRegExp(word)})`, 'gi'), '<mark>$1</mark>');
    });
    return escaped;
}

function debounce(fn, ms) {
    let timer;
    return (...args) => { clearTimeout(timer); timer = setTimeout(() => fn(...args), ms); };
}

// ── Command extraction ─────────────────────────────────────────────────────
function extractCommand(text) {
    text = text.replace(/^\[[\d\-: ]+\]\s+IRC\s*>>\s*/i, '');
    const privmsgMatch = text.match(/PRIVMSG\s+\S+\s+:(.+)/i);
    if (privmsgMatch) text = privmsgMatch[1];
    const bang = text.indexOf('!');
    if (bang !== -1) text = text.slice(bang);
    text = text.split(' ::')[0].replace(/\s+\[\d[\d.,]*\s*\w*\]$/, '');
    return text.trim();
}

// ── Result card ───────────────────────────────────────────────────────────
function renderCard(line) {
    const tmp = document.createElement('div');
    tmp.innerHTML = line;
    const cmd = extractCommand(tmp.textContent.trim());

    const card = document.createElement('div');
    card.className = 'card mb-1 shadow-sm';

    const cardBody = document.createElement('div');
    cardBody.className = 'card-body d-flex align-items-center p-2';

    const textDiv = document.createElement('p');
    textDiv.className = 'card-text mb-0 flex-grow-1';
    textDiv.innerHTML = line;

    const dlBtn = document.createElement('button');
    dlBtn.className = 'btn btn-sm btn-primary';
    dlBtn.innerHTML = '<i class="fa-solid fa-download me-1"></i>Request';
    dlBtn.onclick = async () => {
        dlBtn.disabled = true;
        dlBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
        try {
            const res  = await fetch(`${API_BASE}/request-file?cmd=${encodeURIComponent(cmd)}`);
            const data = await res.json();
            showToast(data.status || data.error || 'No response', data.error ? 'danger' : 'success');
        } catch (e) {
            showToast('Error sending request.', 'danger');
            console.error('Error:', e);
        } finally {
            dlBtn.disabled = false;
            dlBtn.innerHTML = '<i class="fa-solid fa-download me-1"></i>Request';
        }
    };

    const queueBtn = document.createElement('button');
    queueBtn.className = 'btn btn-sm btn-secondary ms-1';
    queueBtn.innerHTML = '<i class="fa-solid fa-clock me-1"></i>Queue';
    queueBtn.onclick = () => {
        addToQueue(cmd);
        showToast(`Queued: ${cmd.slice(0, 50)}`, 'secondary');
    };

    const btnGroup = document.createElement('div');
    btnGroup.className = 'd-flex gap-1';
    btnGroup.append(dlBtn, queueBtn);
    cardBody.append(textDiv, btnGroup);
    card.appendChild(cardBody);
    return card;
}

// ── Autocomplete (debounced) ───────────────────────────────────────────────
queryInput.addEventListener('input', debounce(async () => {
    const term = queryInput.value.trim();
    resultsDiv.innerHTML = '';
    if (term.length < 2) return;
    try {
        const res  = await fetch(`json_endpoints/irc_search.php?autocomplete=1&q=${encodeURIComponent(term)}`);
        const data = await res.json();
        if (!data.length) {
            resultsDiv.innerHTML = '<p class="text-muted small">No suggestions found.</p>';
            return;
        }
        data.forEach(text => resultsDiv.appendChild(renderCard(highlightText(text, term))));
    } catch (err) {
        console.error(err);
    }
}, 300));

// ── Queue ──────────────────────────────────────────────────────────────────
let queue          = [];
let isQueueRunning = false;
let queueTimer     = null;
let countdownMs    = 0;
let countdownStart = 0;
let tickInterval   = null;

const queueStatus    = document.getElementById('queueStatus');
const queueCount     = document.getElementById('queueCount');
const queueState     = document.getElementById('queueState');
const queueList      = document.getElementById('queueList');
const autoSendToggle = document.getElementById('autoSendToggle');

autoSendToggle.checked = localStorage.getItem('ircAutoSend') !== 'false';
autoSendToggle.addEventListener('change', () => {
    localStorage.setItem('ircAutoSend', autoSendToggle.checked);
});

function getRandomDelay() {
    return Math.floor(Math.random() * (75 - 35 + 1) + 35) * 1000;
}

function saveQueue() { localStorage.setItem('ircQueue', JSON.stringify(queue)); }
function loadQueue() {
    try { queue = JSON.parse(localStorage.getItem('ircQueue') || '[]'); }
    catch { queue = []; }
    queue = queue.map(item => ({
        id:    item.id    || crypto.randomUUID(),
        cmd:   item.cmd   || '',
        delay: item.delay ?? getRandomDelay(),
    })).filter(item => item.cmd !== '');
}

function updateQueueDisplay() {
    queueCount.textContent = queue.length;
    queueState.textContent = isQueueRunning ? 'Running' : 'Paused';
    queueStatus.style.display = queue.length > 0 ? 'block' : 'none';
    queueList.innerHTML = '';

    queue.forEach((item, idx) => {
        const li = document.createElement('li');
        li.className = 'list-group-item d-flex justify-content-between align-items-center py-2';

        let badge;
        if (idx === 0) {
            const secs = Math.max(0, Math.ceil(countdownMs / 1000));
            if (isQueueRunning) {
                badge = `<span class="badge bg-primary" id="activeCountdown">fires in ${secs}s</span>`;
            } else {
                badge = `<span class="badge bg-secondary">${secs > 0 ? `paused &mdash; ${secs}s left` : 'ready'}</span>`;
            }
        } else {
            badge = `<span class="text-muted small">then +${Math.round(item.delay / 1000)}s</span>`;
        }

        li.innerHTML = `
            <div class="flex-grow-1 me-2 text-truncate">
                <div class="fw-semibold text-truncate">${escapeHTML(item.cmd)}</div>
                <div class="mt-1">${badge}</div>
            </div>
            <button class="btn btn-sm btn-outline-danger flex-shrink-0"
                    onclick="removeFromQueueById('${escapeHTML(item.id)}')">
                <i class="fa-solid fa-times"></i>
            </button>`;
        queueList.appendChild(li);
    });
}

function startTick() {
    if (tickInterval) clearInterval(tickInterval);
    tickInterval = setInterval(() => {
        if (!isQueueRunning) { clearInterval(tickInterval); tickInterval = null; return; }
        const remaining = Math.max(0, countdownMs - (Date.now() - countdownStart));
        const el = document.getElementById('activeCountdown');
        if (el) el.textContent = `fires in ${Math.ceil(remaining / 1000)}s`;
    }, 500);
}

function stopTick() {
    if (tickInterval) { clearInterval(tickInterval); tickInterval = null; }
}

function scheduleItem(delayMs) {
    countdownMs    = delayMs;
    countdownStart = Date.now();
    startTick();
    queueTimer = setTimeout(fireNext, delayMs);
    updateQueueDisplay();
}

async function fireNext() {
    stopTick();
    if (!isQueueRunning || queue.length === 0) { isQueueRunning = false; updateQueueDisplay(); return; }

    const { cmd } = queue.shift();
    saveQueue();
    updateQueueDisplay();

    try {
        const res  = await fetch(`${API_BASE}/request-file?cmd=${encodeURIComponent(cmd)}`);
        const data = await res.json();
        showToast(`Sent: ${cmd.slice(0, 60)}`, data.error ? 'warning' : 'success');
    } catch (err) {
        showToast('Queue send failed — check console.', 'danger');
        console.error('Queue error:', err);
    }

    if (queue.length > 0) {
        scheduleItem(queue[0].delay);
    } else {
        isQueueRunning = false;
        countdownMs    = 0;
        updateQueueDisplay();
    }
}

function addToQueue(cmd) {
    queue.push({ id: crypto.randomUUID(), cmd, delay: getRandomDelay() });
    saveQueue();
    updateQueueDisplay();
    if (autoSendToggle.checked && !isQueueRunning) startQueue();
}

function startQueue() {
    if (isQueueRunning || queue.length === 0) return;
    isQueueRunning = true;
    const delay = countdownMs > 0 ? countdownMs : queue[0].delay;
    scheduleItem(delay);
}

function stopQueue() {
    if (queueTimer) { clearTimeout(queueTimer); queueTimer = null; }
    stopTick();
    if (isQueueRunning) {
        countdownMs = Math.max(0, countdownMs - (Date.now() - countdownStart));
    }
    isQueueRunning = false;
    updateQueueDisplay();
}

function clearQueue() {
    if (queueTimer) { clearTimeout(queueTimer); queueTimer = null; }
    stopTick();
    queue          = [];
    isQueueRunning = false;
    countdownMs    = 0;
    saveQueue();
    updateQueueDisplay();
}

function removeFromQueueById(id) {
    const wasFirst = queue.length > 0 && queue[0].id === id;
    queue = queue.filter(item => item.id !== id);
    saveQueue();
    if (wasFirst && isQueueRunning) {
        if (queueTimer) { clearTimeout(queueTimer); queueTimer = null; }
        stopTick();
        if (queue.length > 0) {
            scheduleItem(queue[0].delay);
        } else {
            isQueueRunning = false;
            countdownMs    = 0;
        }
    }
    updateQueueDisplay();
}

// ── Recent searches ────────────────────────────────────────────────────────
function saveRecentSearch(term) {
    let recents = JSON.parse(localStorage.getItem('recentSearches') || '[]');
    recents = [term, ...recents.filter(t => t !== term)].slice(0, 10);
    localStorage.setItem('recentSearches', JSON.stringify(recents));
    renderRecentSearches();
}

function renderRecentSearches() {
    recentList.innerHTML = '';
    JSON.parse(localStorage.getItem('recentSearches') || '[]').forEach(term => {
        const span = document.createElement('span');
        span.className = 'badge rounded-pill bg-secondary text-light px-3 py-2';
        span.style.cursor = 'pointer';
        span.textContent = term;
        span.onclick = () => { queryInput.value = term; form.dispatchEvent(new Event('submit')); };
        recentList.appendChild(span);
    });
}

// ── Search submit ──────────────────────────────────────────────────────────
form.addEventListener('submit', function(e) {
    e.preventDefault();
    const query = queryInput.value.trim();
    if (!query) return;
    saveRecentSearch(query);
    resultsDiv.innerHTML = '<div class="text-muted"><i class="fa-solid fa-spinner fa-spin"></i> Searching…</div>';

    fetch('json_endpoints/irc_search.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            q: query,
            requireAllWords: document.getElementById('requireAllWords').checked ? '1' : '0'
        })
    })
    .then(res => res.json())
    .then(data => {
        resultsDiv.innerHTML = '';
        if (!data.matches.length) {
            resultsDiv.innerHTML = '<div class="alert alert-warning">No matches found.</div>';
            return;
        }
        resultsDiv.insertAdjacentHTML('beforeend', `<p class="text-muted">Found ${data.matches.length} match(es)</p>`);
        data.matches.forEach(line => resultsDiv.appendChild(renderCard(line)));
    })
    .catch(err => {
        resultsDiv.innerHTML = `<div class="alert alert-danger">Error: ${escapeHTML(String(err))}</div>`;
    });
});

// ── Init ───────────────────────────────────────────────────────────────────
loadQueue();
renderRecentSearches();
updateQueueDisplay();
</script>
<script src="js/search.js"></script>
</body>
</html>
