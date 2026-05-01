<?php
require_once 'db.php';
requireLogin();

$device     = getUserPreference(currentUser(), 'DEVICE',     getPreference('DEVICE', ''));
$remoteDir  = getUserPreference(currentUser(), 'REMOTE_DIR', getPreference('REMOTE_DIR', ''));
$configured = $device !== '' && $remoteDir !== '';

// Load last sync from cache if it exists
$cacheFile = __DIR__ . '/cache/' . currentUser() . '/device_books.json';
$lastSync  = null;
if (file_exists($cacheFile)) {
    $lastSync = json_decode(file_get_contents($cacheFile), true);
}

$sort          = $_GET['sort'] ?? 'author_series';
$showOffcanvas = false;

function relativeDir(string $path, string $remoteDir): string {
    $base = rtrim($remoteDir, '/') . '/';
    $rel  = str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    // Strip the filename, keep only the directory portion
    $dir  = ltrim(dirname($rel), '/');
    return $dir === '.' ? '' : $dir . '/';
}

function syncRowHtml(array $b, string $remoteDir): string {
    $author = htmlspecialchars($b['author']);
    $series = htmlspecialchars($b['series']);
    $genre  = htmlspecialchars($b['genre']);
    $fmt    = htmlspecialchars($b['format']);
    $dir    = htmlspecialchars(relativeDir($b['path'] ?? '', $remoteDir));

    if (!empty($b['library_id'])) {
        $titleHtml  = '<a href="book.php?id=' . (int)$b['library_id'] . '" class="text-decoration-none">'
                    . htmlspecialchars($b['title']) . '</a>';
        $matchBadge = '<span class="badge bg-success ms-1" title="' . htmlspecialchars($b['library_title'] ?? '') . '">✓</span>';
    } else {
        $titleHtml  = htmlspecialchars($b['title']);
        $matchBadge = '<span class="badge bg-secondary ms-1" title="Not found in library">?</span>';
    }

    $dirHtml = $dir ? '<br><small class="text-muted">' . $dir . '</small>' : '';

    if (!empty($b['lua_exists'])) {
        $pct          = $b['lua_percent'] !== null ? round($b['lua_percent'] * 100, 1) : null;
        $fill         = $pct !== null ? (int)round($pct) : 0;
        $progressHtml = '<div class="lua-progress">' . ($pct !== null ? $pct . '%' : '?%') . '</div>'
                      . '<div class="lua-bar"><div class="lua-bar-fill" style="width:' . $fill . '%"></div></div>';
        $pagesHtml    = $b['lua_pages'] !== null
                      ? '<span class="text-muted small">' . (int)$b['lua_pages'] . '</span>'
                      : '<span class="text-muted">—</span>';
    } else {
        $progressHtml = '<span class="text-muted">—</span>';
        $pagesHtml    = '<span class="text-muted">—</span>';
    }

    return '<tr>'
         . '<td>' . $author . '</td>'
         . '<td>' . $titleHtml . ' ' . $matchBadge . $dirHtml . '</td>'
         . '<td>' . $series . '</td>'
         . '<td>' . $genre . '</td>'
         . '<td class="text-center"><span class="badge bg-secondary">' . $fmt . '</span></td>'
         . '<td class="text-center">' . $pagesHtml . '</td>'
         . '<td class="text-center">' . $progressHtml . '</td>'
         . '<td class="text-center">' . htmlspecialchars($b['lua_last_accessed'] ?? '—') . '</td>'
         . '</tr>';
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
    <title>Device Sync</title>
    <link rel="stylesheet" href="/theme.css.php">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous">
    <style>
        body { padding-top: 4.5rem; }
        .sync-table th, .sync-table td { padding: 0.25rem 0.5rem; font-size: 0.875rem; vertical-align: middle; }
        .lua-progress { font-size: 0.75rem; white-space: nowrap; }
        .lua-bar { height: 4px; border-radius: 2px; background: #dee2e6; overflow: hidden; margin-top: 2px; }
        .lua-bar-fill { height: 100%; background: #0d6efd; }
        #filterInput { max-width: 22rem; }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container-fluid mt-3">
    <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
        <h4 class="mb-0"><i class="fa-solid fa-rotate me-2"></i>Device Sync</h4>

        <?php if (!$configured): ?>
            <div class="alert alert-warning mb-0 py-1 px-3 small">
                Device not configured. Set <strong>DEVICE</strong> and <strong>REMOTE_DIR</strong> in
                <a href="preferences.php">Preferences</a>.
            </div>
        <?php else: ?>
            <button type="button" id="syncBtn" class="btn btn-primary">
                <i class="fa-solid fa-rotate me-1"></i> Sync
            </button>
            <span id="syncStatus" class="text-muted small"></span>
        <?php endif; ?>

        <?php if ($lastSync): ?>
            <span class="text-muted small ms-auto">
                Last sync: <?= htmlspecialchars($lastSync['synced_at']) ?>
                &mdash; <?= (int)$lastSync['count'] ?> books on
                <strong><?= htmlspecialchars($lastSync['device']) ?></strong>
            </span>
        <?php endif; ?>
    </div>

    <!-- Filter row -->
    <div id="resultsArea" <?= $lastSync ? '' : 'style="display:none"' ?>>
        <div class="d-flex align-items-center gap-2 mb-2">
            <input type="search" id="filterInput" class="form-control form-control-sm" placeholder="Filter by title, author, series…">
            <label class="form-check-label small ms-2 me-1" for="unmatchedOnly">Unmatched only</label>
            <input type="checkbox" class="form-check-input" id="unmatchedOnly">
            <span id="countBadge" class="badge bg-secondary ms-2"><?= $lastSync ? (int)$lastSync['count'] : 0 ?></span>
        </div>
        <table class="table table-sm table-hover table-bordered sync-table w-100">
            <thead class="table-dark sticky-top">
                <tr>
                    <th>Author</th>
                    <th>Title</th>
                    <th>Series</th>
                    <th>Genre</th>
                    <th class="text-center">Fmt</th>
                    <th class="text-center">Pgs</th>
                    <th class="text-center">Progress</th>
                    <th class="text-center">Last Read</th>
                </tr>
            </thead>
            <tbody id="bookTableBody">
                <?php if ($lastSync): ?>
                    <?php foreach ($lastSync['books'] as $b): ?>
                        <?= syncRowHtml($b, $remoteDir) ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script>
const syncBtn       = document.getElementById('syncBtn');
const syncStatus    = document.getElementById('syncStatus');
const resultsArea   = document.getElementById('resultsArea');
const tableBody     = document.getElementById('bookTableBody');
const filterInput   = document.getElementById('filterInput');
const unmatchedOnly = document.getElementById('unmatchedOnly');
const countBadge    = document.getElementById('countBadge');

function escHtml(str) {
    return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function relDir(path) {
    if (!path || !path.startsWith(remoteDir)) return '';
    const rel = path.slice(remoteDir.length);
    const dir = rel.includes('/') ? rel.slice(0, rel.lastIndexOf('/') + 1) : '';
    return dir;
}

function luaPagesHtml(b) {
    if (!b.lua_exists || b.lua_pages == null) return '<span class="text-muted">—</span>';
    return `<span class="text-muted small">${b.lua_pages}</span>`;
}

function luaProgressHtml(b) {
    if (!b.lua_exists) return '<span class="text-muted">—</span>';
    const pct  = b.lua_percent != null ? Math.round(b.lua_percent * 1000) / 10 : null;
    const fill = pct != null ? Math.round(pct) : 0;
    return `<div class="lua-progress">${pct != null ? pct + '%' : '?%'}</div>`
         + `<div class="lua-bar"><div class="lua-bar-fill" style="width:${fill}%"></div></div>`;
}

function renderRows(books) {
    tableBody.innerHTML = books.map(b => {
        const dir = relDir(b.path || '');
        const dirHtml = dir ? `<br><small class="text-muted">${escHtml(dir)}</small>` : '';
        const titleCell = b.library_id
            ? `<a href="book.php?id=${b.library_id}" class="text-decoration-none">${escHtml(b.title)}</a> <span class="badge bg-success ms-1" title="${escHtml(b.library_title ?? '')}">✓</span>${dirHtml}`
            : `${escHtml(b.title)} <span class="badge bg-secondary ms-1" title="Not found in library">?</span>${dirHtml}`;
        return `<tr>
            <td>${escHtml(b.author)}</td>
            <td>${titleCell}</td>
            <td>${escHtml(b.series)}</td>
            <td>${escHtml(b.genre)}</td>
            <td class="text-center"><span class="badge bg-secondary">${escHtml(b.format)}</span></td>
            <td class="text-center">${luaPagesHtml(b)}</td>
            <td class="text-center">${luaProgressHtml(b)}</td>
            <td class="text-center"><span class="text-muted small">${escHtml(b.lua_last_accessed ?? '—')}</span></td>
        </tr>`;
    }).join('');
    countBadge.textContent = books.length;
}

function applyFilter() {
    const q         = (filterInput?.value ?? '').toLowerCase();
    const unmatched = unmatchedOnly?.checked ?? false;
    const filtered  = allBooks.filter(b => {
        if (unmatched && b.library_id) return false;
        if (!q) return true;
        return b.title.toLowerCase().includes(q) ||
               b.author.toLowerCase().includes(q) ||
               b.series.toLowerCase().includes(q);
    });
    renderRows(filtered);
}

let allBooks  = <?= $lastSync ? json_encode($lastSync['books']) : '[]' ?>;
const remoteDir = <?= json_encode(rtrim($remoteDir, '/') . '/') ?>;

if (syncBtn) {
    syncBtn.addEventListener('click', async () => {
        syncBtn.disabled = true;
        syncBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Syncing…';
        if (syncStatus) syncStatus.textContent = 'Connecting to device…';

        try {
            const res  = await fetch('json_endpoints/sync_device.php', { method: 'POST' });
            const data = await res.json();

            if (data.error) {
                syncStatus.textContent = '✗ ' + data.error + (data.detail ? ' — ' + data.detail : '');
            } else {
                allBooks = data.books;
                if (filterInput) filterInput.value = '';
                if (unmatchedOnly) unmatchedOnly.checked = false;
                applyFilter();
                resultsArea.style.display = '';
                const matched = allBooks.filter(b => b.library_id).length;
                let msg = '✓ ' + data.count + ' books (' + matched + ' matched) — '
                    + new Date(data.synced_at).toLocaleTimeString();
                if (data.marked_read > 0) msg += ' — ' + data.marked_read + ' marked Read';
                syncStatus.textContent = msg;
            }
        } catch (err) {
            syncStatus.textContent = '✗ Request error: ' + err.message;
        }

        syncBtn.disabled = false;
        syncBtn.innerHTML = '<i class="fa-solid fa-rotate me-1"></i> Sync';
    });
}

if (filterInput)   filterInput.addEventListener('input', applyFilter);
if (unmatchedOnly) unmatchedOnly.addEventListener('change', applyFilter);
</script>
</body>
</html>
