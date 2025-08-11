<?php
require_once 'db.php';
requireLogin();

$pdo = getDatabaseConnection();

$subseriesIsCustom = false;
$subseriesValueTable = '';
$subseriesLinkTable = '';
$subseries = [];

try {
    $subseriesColumnId = getCustomColumnId($pdo, 'subseries');
    if ($subseriesColumnId) {
        $subseriesIsCustom = true;
        $subseriesValueTable = "custom_column_{$subseriesColumnId}";
        $subseriesLinkTable  = "books_custom_column_{$subseriesColumnId}_link";
    } else {
        $subTable = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='subseries'")->fetchColumn();
        $subLinkTable = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='books_subseries_link'")->fetchColumn();
        if (!($subTable && $subLinkTable)) {
            $subseriesIsCustom = false;
        }
    }

    if ($subseriesColumnId || ($subTable ?? false) && ($subLinkTable ?? false)) {
        if ($subseriesIsCustom) {
            $sql = "SELECT ss.id, ss.value AS name, COUNT(DISTINCT bssl.book) AS book_count, " .
                   "REPLACE(GROUP_CONCAT(DISTINCT s.id || ':' || s.name), ',', '|') AS series_list " .
                   "FROM $subseriesValueTable ss " .
                   "LEFT JOIN $subseriesLinkTable bssl ON bssl.value = ss.id " .
                   "LEFT JOIN books_series_link bsl ON bssl.book = bsl.book " .
                   "LEFT JOIN series s ON bsl.series = s.id " .
                   "GROUP BY ss.id, ss.value ORDER BY ss.value";
        } else {
            $sql = "SELECT ss.id, ss.name, COUNT(DISTINCT bssl.book) AS book_count, " .
                   "REPLACE(GROUP_CONCAT(DISTINCT s.id || ':' || s.name), ',', '|') AS series_list " .
                   "FROM subseries ss " .
                   "LEFT JOIN books_subseries_link bssl ON bssl.subseries = ss.id " .
                   "LEFT JOIN books_series_link bsl ON bsl.book = bssl.book " .
                   "LEFT JOIN series s ON bsl.series = s.id " .
                   "GROUP BY ss.id, ss.name ORDER BY ss.sort";
        }
        $subseries = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $subseries = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Subseries</title>
    <link id="themeStylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous">
    <script src="js/theme.js"></script>
</head>
<body class="pt-5 bg-light">
<?php include 'navbar_other.php'; ?>
<div class="container my-4">
    <h1>Subseries</h1>
    <div class="mb-4">
        <h2 class="h4">Manage Subseries</h2>
        <div class="row g-2 mb-3">
            <div class="col">
                <select id="subseriesSelect" class="form-select">
                    <option value="">Select subseries</option>
                    <?php foreach ($subseries as $ss): ?>
                        <option value="<?= (int)$ss['id'] ?>"><?= htmlspecialchars($ss['name']) ?></option>
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
    </div>
    <?php if (empty($subseries)): ?>
        <p class="text-muted">No subseries found.</p>
    <?php else: ?>
        <ul class="list-group">
            <?php foreach ($subseries as $s): ?>
                <?php $seriesList = ($s['series_list'] !== null && $s['series_list'] !== '') ? explode('|', $s['series_list']) : []; ?>
                <li class="list-group-item<?= ((int)$s['book_count'] === 0) ? ' list-group-item-warning' : '' ?>">
                    <div class="d-flex justify-content-between align-items-center">
                        <a href="list_books.php?subseries_id=<?= (int)$s['id'] ?>">
                            <?= htmlspecialchars($s['name']) ?>
                        </a>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge rounded-pill <?= ((int)$s['book_count'] === 0) ? 'bg-warning text-dark' : 'bg-secondary' ?>"><?= (int)$s['book_count'] ?></span>
                            <button class="btn btn-sm btn-outline-secondary rename-subseries" data-id="<?= (int)$s['id'] ?>" data-name="<?= htmlspecialchars($s['name'], ENT_QUOTES) ?>">Rename</button>
                            <button class="btn btn-sm btn-outline-danger delete-subseries" data-id="<?= (int)$s['id'] ?>">Delete</button>
                        </div>
                    </div>
                    <?php if (!empty($seriesList)): ?>
                        <ul class="mt-2">
                            <?php foreach ($seriesList as $item): ?>
                                <?php list($sid, $sname) = explode(':', $item, 2); ?>
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

    document.querySelectorAll('.rename-subseries').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id = btn.dataset.id;
            const current = btn.dataset.name || '';
            let name = prompt('Rename subseries:', current);
            if (name === null) return;
            name = name.trim();
            if (!name || name === current) return;
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
    });

    document.querySelectorAll('.delete-subseries').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id = btn.dataset.id;
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
    });
});
</script>
</body>
</html>
