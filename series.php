<?php
require_once 'db.php';
requireLogin();

$pdo = getDatabaseConnection();

$hasSubseries = false;
$subseriesIsCustom = false;
$subseriesLinkTable = '';
$subseriesValueTable = '';
$allSubseries = [];

try {
    $subseriesColumnId = getCustomColumnId($pdo, 'subseries');
    if ($subseriesColumnId) {
        $hasSubseries = true;
        $subseriesIsCustom = true;
        $subseriesValueTable = "custom_column_{$subseriesColumnId}";
        $subseriesLinkTable  = "books_custom_column_{$subseriesColumnId}_link";
    } else {
        $subTable = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='subseries'")->fetchColumn();
        $subLinkTable = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='books_subseries_link'")->fetchColumn();
        if ($subTable && $subLinkTable) {
            $hasSubseries = true;
        }
    }

    if ($hasSubseries) {
        if ($subseriesIsCustom) {
            $sql = "SELECT s.id, s.name, COUNT(DISTINCT bsl.book) AS book_count, REPLACE(GROUP_CONCAT(DISTINCT ss.id || ':' || ss.value), ',', '|') AS subseries_list
                    FROM series s
                    LEFT JOIN books_series_link bsl ON bsl.series = s.id
                    LEFT JOIN $subseriesLinkTable bssl ON bssl.book = bsl.book
                    LEFT JOIN $subseriesValueTable ss ON bssl.value = ss.id
                    GROUP BY s.id, s.name
                    ORDER BY s.sort";
        } else {
            $sql = "SELECT s.id, s.name, COUNT(DISTINCT bsl.book) AS book_count, REPLACE(GROUP_CONCAT(DISTINCT ss.id || ':' || ss.name), ',', '|') AS subseries_list
                    FROM series s
                    LEFT JOIN books_series_link bsl ON bsl.series = s.id
                    LEFT JOIN books_subseries_link bssl ON bssl.book = bsl.book
                    LEFT JOIN subseries ss ON bssl.subseries = ss.id
                    GROUP BY s.id, s.name
                    ORDER BY s.sort";
        }
        $series = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $allSubseries = [];
        foreach ($series as $row) {
            if (!empty($row['subseries_list'])) {
                foreach (explode('|', $row['subseries_list']) as $item) {
                    list($sid, $sname) = explode(':', $item, 2);
                    $allSubseries[$sid] = $sname;
                }
            }
        }
    } else {
        $series = $pdo->query('SELECT s.id, s.name, COUNT(DISTINCT bsl.book) AS book_count FROM series s LEFT JOIN books_series_link bsl ON bsl.series = s.id GROUP BY s.id, s.name ORDER BY s.sort')->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $series = $pdo->query('SELECT s.id, s.name, COUNT(DISTINCT bsl.book) AS book_count FROM series s LEFT JOIN books_series_link bsl ON bsl.series = s.id GROUP BY s.id, s.name ORDER BY s.sort')->fetchAll(PDO::FETCH_ASSOC);
    $hasSubseries = false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Series</title>
    <link id="themeStylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous">
    <script src="js/theme.js"></script>
</head>
<body class="pt-5 bg-light">
<?php include 'navbar_other.php'; ?>
<div class="container my-4">
    <h1>Series</h1>
    <div class="mb-4">
        <h2 class="h4">Manage Series</h2>
        <div class="row g-2 mb-3">
            <div class="col">
                <input id="newSeries" type="text" class="form-control" placeholder="New series">
            </div>
            <div class="col-auto">
                <button id="addSeriesBtn" class="btn btn-primary">Add Series</button>
            </div>
        </div>
        <?php if ($hasSubseries): ?>
        <h2 class="h4">Manage Subseries</h2>
        <div class="row g-2 mb-3">
            <div class="col">
                <select id="subseriesSelect" class="form-select">
                    <option value="">Select subseries</option>
                    <?php foreach ($allSubseries as $sid => $sname): ?>
                        <option value="<?= (int)$sid ?>"><?= htmlspecialchars($sname) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button id="editSubseriesBtn" class="btn btn-outline-secondary">Rename</button>
            </div>
            <div class="col-auto">
                <button id="deleteSubseriesBtn" class="btn btn-outline-danger">Delete</button>
            </div>
        </div>
        <div class="row g-2 mb-3">
            <div class="col">
                <input id="newSubseries" type="text" class="form-control" placeholder="New subseries">
            </div>
            <div class="col-auto">
                <button id="addSubseriesBtn" class="btn btn-secondary">Add Subseries</button>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php if (empty($series)): ?>
        <p class="text-muted">No series found.</p>
    <?php else: ?>
        <ul class="list-group">
            <?php foreach ($series as $s): ?>
                <?php $subs = ($hasSubseries && isset($s['subseries_list']) && $s['subseries_list'] !== '') ? explode('|', $s['subseries_list']) : []; ?>
                <li class="list-group-item<?= ((int)$s['book_count'] === 0) ? ' list-group-item-warning' : '' ?>">
                    <div class="d-flex justify-content-between align-items-center">
                        <a href="list_books.php?series_id=<?= (int)$s['id'] ?>">
                            <?= htmlspecialchars($s['name']) ?>
                        </a>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge rounded-pill <?= ((int)$s['book_count'] === 0) ? 'bg-warning text-dark' : 'bg-secondary' ?>"><?= (int)$s['book_count'] ?></span>
                            <button class="btn btn-sm btn-outline-secondary rename-series" data-id="<?= (int)$s['id'] ?>" data-name="<?= htmlspecialchars($s['name'], ENT_QUOTES) ?>">Rename</button>
                            <button class="btn btn-sm btn-outline-danger delete-series" data-id="<?= (int)$s['id'] ?>">Delete</button>
                        </div>
                    </div>
                    <?php if (!empty($subs)): ?>
                        <ul class="mt-2">
                            <?php foreach ($subs as $sub): ?>
                                <?php list($sid, $sname) = explode(':', $sub, 2); ?>
                                <li><?= htmlspecialchars($sname) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const addSeriesBtn = document.getElementById('addSeriesBtn');
    const newSeriesInput = document.getElementById('newSeries');

    if (addSeriesBtn) {
        addSeriesBtn.addEventListener('click', async () => {
            const name = newSeriesInput.value.trim();
            if (!name) return;
            try {
                const res = await fetch('json_endpoints/add_series.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ name })
                });
                const data = await res.json();
                if (data.status === 'ok') location.reload();
            } catch (err) { console.error(err); }
        });
    }

    document.querySelectorAll('.rename-series').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id = btn.dataset.id;
            const current = btn.dataset.name || '';
            let name = prompt('Rename series:', current);
            if (name === null) return;
            name = name.trim();
            if (!name || name === current) return;
            try {
                const res = await fetch('json_endpoints/rename_series.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ id, new: name })
                });
                const data = await res.json();
                if (data.status === 'ok') location.reload();
            } catch (err) { console.error(err); }
        });
    });

    document.querySelectorAll('.delete-series').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id = btn.dataset.id;
            if (!id) return;
            if (!confirm('Delete series?')) return;
            try {
                const res = await fetch('json_endpoints/delete_series.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ id })
                });
                const data = await res.json();
                if (data.status === 'ok') location.reload();
            } catch (err) { console.error(err); }
        });
    });

    const subseriesSelect = document.getElementById('subseriesSelect');
    const addSubBtn = document.getElementById('addSubseriesBtn');
    const editSubBtn = document.getElementById('editSubseriesBtn');
    const deleteSubBtn = document.getElementById('deleteSubseriesBtn');
    const newSubInput = document.getElementById('newSubseries');

    if (addSubBtn) {
        addSubBtn.addEventListener('click', async () => {
            const name = newSubInput.value.trim();
            if (!name) return;
            try {
                const res = await fetch('json_endpoints/add_subseries.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ name })
                });
                const data = await res.json();
                if (data.status === 'ok') location.reload();
            } catch (err) { console.error(err); }
        });
    }

    if (editSubBtn) {
        editSubBtn.addEventListener('click', async () => {
            const id = subseriesSelect.value;
            if (!id) return;
            const option = subseriesSelect.options[subseriesSelect.selectedIndex];
            let name = prompt('Rename subseries:', option.textContent);
            if (name === null) return;
            name = name.trim();
            if (!name || name === option.textContent) return;
            try {
                const res = await fetch('json_endpoints/rename_subseries.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ id, new: name })
                });
                const data = await res.json();
                if (data.status === 'ok') location.reload();
            } catch (err) { console.error(err); }
        });
    }

    if (deleteSubBtn) {
        deleteSubBtn.addEventListener('click', async () => {
            const id = subseriesSelect.value;
            if (!id) return;
            if (!confirm('Delete subseries?')) return;
            try {
                const res = await fetch('json_endpoints/delete_subseries.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ id })
                });
                const data = await res.json();
                if (data.status === 'ok') location.reload();
            } catch (err) { console.error(err); }
        });
    }
});
</script>
</body>
</html>
