<?php
require_once 'db.php';
requireLogin();

$pdo = getDatabaseConnection();

// ── Subseries schema detection ────────────────────────────────────────────────
$hasSubseries        = false;
$subseriesIsCustom   = false;
$subseriesValueTable = '';
$subseriesLinkTable  = '';

try {
    $subseriesColumnId = getCustomColumnId($pdo, 'subseries');
    if ($subseriesColumnId) {
        $hasSubseries        = true;
        $subseriesIsCustom   = true;
        $subseriesValueTable = "custom_column_{$subseriesColumnId}";
        $subseriesLinkTable  = "books_custom_column_{$subseriesColumnId}_link";
    } else {
        $subTable     = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='subseries'")->fetchColumn();
        $subLinkTable = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='books_subseries_link'")->fetchColumn();
        if ($subTable && $subLinkTable) $hasSubseries = true;
    }
} catch (PDOException $e) {}

// ── Series query ──────────────────────────────────────────────────────────────
try {
    if ($hasSubseries) {
        $subJoin = $subseriesIsCustom
            ? "LEFT JOIN $subseriesLinkTable bssl ON bssl.book = bsl.book
               LEFT JOIN $subseriesValueTable ss  ON bssl.value = ss.id"
            : "LEFT JOIN books_subseries_link bssl ON bssl.book = bsl.book
               LEFT JOIN subseries ss ON bssl.subseries = ss.id";
        $subCol = $subseriesIsCustom ? 'ss.id || \':\' || ss.value' : 'ss.id || \':\' || ss.name';
        $sql = "SELECT s.id, s.name, COUNT(DISTINCT bsl.book) AS book_count,
                       REPLACE(GROUP_CONCAT(DISTINCT $subCol), ',', '|') AS subseries_list
                FROM series s
                LEFT JOIN books_series_link bsl ON bsl.series = s.id
                $subJoin
                GROUP BY s.id, s.name
                ORDER BY s.sort";
    } else {
        $sql = "SELECT s.id, s.name, COUNT(DISTINCT bsl.book) AS book_count, NULL AS subseries_list
                FROM series s
                LEFT JOIN books_series_link bsl ON bsl.series = s.id
                GROUP BY s.id, s.name
                ORDER BY s.sort";
    }
    $series = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $series = [];
    $hasSubseries = false;
}

// ── Subseries book counts (separate query for accuracy) ───────────────────────
$subseriesBookCounts = [];
if ($hasSubseries) {
    try {
        $countSql = $subseriesIsCustom
            ? "SELECT bssl.value AS id, COUNT(DISTINCT bssl.book) AS cnt FROM $subseriesLinkTable bssl GROUP BY bssl.value"
            : "SELECT bssl.subseries AS id, COUNT(DISTINCT bssl.book) AS cnt FROM books_subseries_link bssl GROUP BY bssl.subseries";
        foreach ($pdo->query($countSql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $subseriesBookCounts[(int)$row['id']] = (int)$row['cnt'];
        }
    } catch (PDOException $e) {}
}

// ── Authors per series ────────────────────────────────────────────────────────
$seriesAuthors = [];
try {
    $authorRows = $pdo->query("
        SELECT bsl.series, a.name
        FROM books_series_link bsl
        JOIN books_authors_link bal ON bal.book = bsl.book
        JOIN authors a ON a.id = bal.author
        GROUP BY bsl.series, a.id
        ORDER BY bsl.series, a.sort
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($authorRows as $row) {
        $seriesAuthors[(int)$row['series']][] = $row['name'];
    }
} catch (PDOException $e) {}

// ── Letter set ────────────────────────────────────────────────────────────────
$seriesLetters = [];
foreach ($series as $s) {
    $f = strtoupper(mb_substr($s['name'], 0, 1));
    $seriesLetters[ctype_alpha($f) ? $f : '#'] = true;
}
ksort($seriesLetters);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="manifest" href="/manifest.json">
  <meta name="theme-color" content="#212529">
  <link rel="apple-touch-icon" href="/app-icons/icon-192.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Series</title>
    <link rel="stylesheet" href="/theme.css.php">
    <link rel="stylesheet" href="/css/all.min.css" crossorigin="anonymous">
    <style>
        body { padding-bottom: 3.5rem; }

        #seriesList .list-group-item { border-color: var(--bs-border-color); }
        #seriesList .list-group-item:nth-child(odd)  { background-color: var(--row-stripe-a, transparent); }
        #seriesList .list-group-item:nth-child(even) { background-color: var(--row-stripe-b, rgba(0,0,0,0.04)); }

        .series-authors { font-size: 0.78rem; color: var(--bs-secondary-color); }
        .filter-hidden  { display: none !important; }

        /* Inline subseries */
        .subseries-list { list-style: none; padding-left: 1rem; margin: 0.3rem 0 0; }
        .subseries-list li {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.15rem 0;
            font-size: 0.875rem;
            border-top: 1px solid var(--bs-border-color);
        }
        .subseries-list li:first-child { border-top: none; }
        .subseries-list a { color: rgba(var(--bs-link-color-rgb), 1); text-decoration: none; }
        .subseries-list a:hover { text-decoration: underline; }

        /* Floating toolbar */
        #seriesToolbar {
            position: fixed;
            bottom: 2.5rem;
            left: 50%;
            transform: translateX(-50%);
            z-index: 1040;
            display: flex;
            align-items: center;
            gap: 0.4rem;
            flex-wrap: nowrap;
            padding: 0.45rem 0.9rem;
            background: var(--bs-body-bg);
            border: 1px solid var(--bs-border-color);
            border-radius: 0.6rem;
            box-shadow: 0 4px 18px rgba(0,0,0,0.2);
            white-space: nowrap;
        }
        #seriesToolbar input.form-control {
            background: var(--bs-body-bg);
            color: var(--bs-body-color);
            border-color: var(--bs-border-color);
        }

        /* Fixed alphabet footer */
        #seriesAlphabetBar { z-index: 1030; }
        #seriesAlphabetBar .letter-btn {
            color: var(--accent, #fd8c00);
            font-size: 0.85rem;
        }
        #seriesAlphabetBar .letter-btn.active {
            font-weight: 700;
            font-size: 1rem;
            color: #fff;
        }
    </style>
</head>
<body class="pt-5">
<?php include 'navbar.php'; ?>
<div class="container my-4">
    <h1>Series</h1>

    <!-- ── Manage ─────────────────────────────────────────────────────────── -->
    <div class="mb-4">
        <h2 class="h4">Manage Series</h2>
        <div class="row g-2 mb-3">
            <div class="col">
                <input id="newSeries" type="text" class="form-control" placeholder="New series name">
            </div>
            <div class="col-auto">
                <button id="addSeriesBtn" class="btn btn-primary">Add Series</button>
            </div>
        </div>

        <?php if ($hasSubseries): ?>
        <h2 class="h4">Add Subseries</h2>
        <div class="row g-2 mb-3">
            <div class="col">
                <input id="newSubseries" type="text" class="form-control" placeholder="New subseries name">
            </div>
            <div class="col-auto">
                <button id="addSubseriesBtn" class="btn btn-secondary">Add Subseries</button>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Series list ────────────────────────────────────────────────────── -->
    <?php if (empty($series)): ?>
        <p class="text-muted">No series found.</p>
    <?php else: ?>
    <ul class="list-group" id="seriesList">
        <?php foreach ($series as $s):
            $subs      = (!empty($s['subseries_list'])) ? explode('|', $s['subseries_list']) : [];
            $firstChar = strtoupper(mb_substr($s['name'], 0, 1));
            $authors   = $seriesAuthors[(int)$s['id']] ?? [];
            $authorStr = '';
            if ($authors) {
                $shown     = array_slice($authors, 0, 2);
                $extra     = count($authors) - count($shown);
                $authorStr = implode(', ', $shown) . ($extra > 0 ? " +{$extra} more" : '');
            }
        ?>
            <li class="list-group-item<?= ((int)$s['book_count'] === 0) ? ' list-group-item-warning' : '' ?>"
                data-name="<?= htmlspecialchars(strtolower($s['name'])) ?>"
                data-letter="<?= htmlspecialchars(ctype_alpha($firstChar) ? $firstChar : '#') ?>"
                data-empty="<?= (int)$s['book_count'] === 0 ? '1' : '0' ?>">
                <div class="d-flex justify-content-between align-items-center gap-2">
                    <div>
                        <a href="list_books.php?series_id=<?= (int)$s['id'] ?>" class="fw-semibold">
                            <?= htmlspecialchars($s['name']) ?>
                        </a>
                        <?php if ($authorStr): ?>
                            <span class="series-authors ms-2">
                                <i class="fa-solid fa-user fa-xs"></i> <?= htmlspecialchars($authorStr) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-shrink-0">
                        <span class="badge rounded-pill <?= ((int)$s['book_count'] === 0) ? 'bg-warning text-dark' : 'bg-primary' ?>">
                        <i class="fa-duotone fa-solid fa-books"></i> <?= (int)$s['book_count'] ?>
                        </span>
                        <button class="btn btn-sm btn-secondary rename-series"
                                data-id="<?= (int)$s['id'] ?>"
                                data-name="<?= htmlspecialchars($s['name'], ENT_QUOTES) ?>">Rename</button>
                        <button class="btn btn-sm btn-danger delete-series"
                                data-id="<?= (int)$s['id'] ?>" title="Delete series">
                            <i class="fa-solid fa-trash"></i></button>
                    </div>
                </div>
                <?php if (!empty($subs)): ?>
                    <ul class="subseries-list">
                        <?php foreach ($subs as $sub):
                            [$ssid, $ssname] = explode(':', $sub, 2);
                            $ssid  = (int)$ssid;
                            $sscnt = $subseriesBookCounts[$ssid] ?? 0;
                        ?>
                            <li>
                                <a href="list_books.php?subseries_id=<?= $ssid ?>">
                                    <i class="fa-duotone fa-solid fa-arrow-turn-down-right fa-xs me-1 text-muted"></i><?= htmlspecialchars($ssname) ?>
                                </a>
                                <span class="badge rounded-pill <?= $sscnt === 0 ? 'bg-warning text-dark' : 'bg-secondary' ?> ms-1">
                                    <?= $sscnt ?>
                                </span>
                                <button class="btn btn-sm btn-secondary rename-subseries ms-auto"
                                        data-id="<?= $ssid ?>"
                                        data-name="<?= htmlspecialchars($ssname, ENT_QUOTES) ?>">Rename</button>
                                <button class="btn btn-sm btn-danger delete-subseries"
                                        data-id="<?= $ssid ?>" title="Delete subseries">
                                    <i class="fa-solid fa-trash"></i></button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>

<!-- Floating search/filter toolbar -->
<div id="seriesToolbar">
    <i class="fa-solid fa-magnifying-glass text-muted fa-sm"></i>
    <input type="search" id="seriesFilter" class="form-control form-control-sm" style="width:15rem;" placeholder="Filter series…" autocomplete="off">
    <button type="button" id="zeroBooksToggle" class="btn btn-sm btn-outline-warning">0 books only</button>
    <span class="text-muted small" id="seriesCount"></span>
</div>

<!-- Fixed alphabet footer -->
<div id="seriesAlphabetBar" class="position-fixed bottom-0 start-0 end-0 bg-dark d-flex align-items-center px-3 py-1">
    <div class="flex-grow-1 text-center">
        <a class="mx-1 text-decoration-none letter-btn" data-letter="" href="#">All</a>
        <?php foreach ($seriesLetters as $letter => $_): ?>
            <a class="mx-1 text-decoration-none letter-btn" data-letter="<?= htmlspecialchars($letter) ?>" href="#"><?= htmlspecialchars($letter) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="js/search.js"></script>
<script>
(function () {
    const filterInput     = document.getElementById('seriesFilter');
    const zeroBooksToggle = document.getElementById('zeroBooksToggle');
    const countEl         = document.getElementById('seriesCount');
    const alphabetBar     = document.getElementById('seriesAlphabetBar');
    if (!filterInput) return;

    let activeLetter = '';
    let zeroOnly     = false;

    function applyFilters() {
        const rows = document.querySelectorAll('#seriesList li.list-group-item');
        const q    = filterInput.value.trim().toLowerCase();
        let visible = 0;
        rows.forEach(row => {
            const show = (!q            || (row.dataset.name   || '').includes(q))
                      && (!activeLetter || (row.dataset.letter || '') === activeLetter)
                      && (!zeroOnly     || row.dataset.empty === '1');
            row.classList.toggle('filter-hidden', !show);
            if (show) visible++;
        });
        countEl.textContent = visible + ' of ' + rows.length;
    }

    filterInput.addEventListener('input', applyFilters);

    zeroBooksToggle.addEventListener('click', () => {
        zeroOnly = !zeroOnly;
        zeroBooksToggle.classList.toggle('btn-outline-warning', !zeroOnly);
        zeroBooksToggle.classList.toggle('btn-warning',         zeroOnly);
        applyFilters();
    });

    alphabetBar.addEventListener('click', e => {
        const btn = e.target.closest('.letter-btn');
        if (!btn) return;
        e.preventDefault();
        activeLetter = btn.dataset.letter;
        alphabetBar.querySelectorAll('.letter-btn').forEach(b => b.classList.toggle('active', b === btn));
        applyFilters();
    });

    document.addEventListener('list-changed', applyFilters);

    applyFilters();
})();
</script>
<script>
document.addEventListener('DOMContentLoaded', () => {

    // ── Add series ────────────────────────────────────────────────────────
    document.getElementById('addSeriesBtn')?.addEventListener('click', async () => {
        const name = document.getElementById('newSeries').value.trim();
        if (!name) return;
        const res = await fetch('json_endpoints/add_series.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ name })
        });
        if ((await res.json()).status === 'ok') location.reload();
    });

    // ── Add subseries ─────────────────────────────────────────────────────
    document.getElementById('addSubseriesBtn')?.addEventListener('click', async () => {
        const name = document.getElementById('newSubseries').value.trim();
        if (!name) return;
        const res = await fetch('json_endpoints/add_subseries.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ name })
        });
        if ((await res.json()).status === 'ok') location.reload();
    });

    // ── Rename series ─────────────────────────────────────────────────────
    document.addEventListener('click', async e => {
        const btn = e.target.closest('.rename-series');
        if (!btn) return;
        let name = prompt('Rename series:', btn.dataset.name || '');
        if (!name || !(name = name.trim()) || name === btn.dataset.name) return;
        const res = await fetch('json_endpoints/rename_series.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ id: btn.dataset.id, new: name })
        });
        if ((await res.json()).status === 'ok') location.reload();
    });

    // ── Delete series ─────────────────────────────────────────────────────
    document.addEventListener('click', async e => {
        const btn = e.target.closest('.delete-series');
        if (!btn || !confirm('Delete series?')) return;
        const res  = await fetch('json_endpoints/delete_series.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ id: btn.dataset.id })
        });
        const data = await res.json();
        if (data.status === 'ok') {
            btn.closest('li.list-group-item').remove();
            document.dispatchEvent(new Event('list-changed'));
        } else { alert(data.error || 'Delete failed'); }
    });

    // ── Rename subseries ──────────────────────────────────────────────────
    document.addEventListener('click', async e => {
        const btn = e.target.closest('.rename-subseries');
        if (!btn) return;
        let name = prompt('Rename subseries:', btn.dataset.name || '');
        if (!name || !(name = name.trim()) || name === btn.dataset.name) return;
        const res = await fetch('json_endpoints/rename_subseries.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ id: btn.dataset.id, new: name })
        });
        if ((await res.json()).status === 'ok') location.reload();
    });

    // ── Delete subseries ──────────────────────────────────────────────────
    document.addEventListener('click', async e => {
        const btn = e.target.closest('.delete-subseries');
        if (!btn || !confirm('Delete subseries?')) return;
        const res  = await fetch('json_endpoints/delete_subseries.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ id: btn.dataset.id })
        });
        const data = await res.json();
        if (data.status === 'ok') {
            btn.closest('li').remove();   // removes the subseries <li> within the series row
            document.dispatchEvent(new Event('list-changed'));
        } else { alert(data.error || 'Delete failed'); }
    });
});
</script>
</body>
</html>
