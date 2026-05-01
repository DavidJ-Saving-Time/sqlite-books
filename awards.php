<?php
require_once 'db.php';
requireLogin();

$pdo = getDatabaseConnection();

$awards = [];
try {
    $awards = $pdo->query(
        "SELECT a.id, a.name,
                COUNT(DISTINCT ba.book_id) AS book_count,
                SUM(CASE WHEN ba.result = 'won' THEN 1 ELSE 0 END) AS won_count
         FROM awards a
         LEFT JOIN book_awards ba ON ba.award_id = a.id
         GROUP BY a.id
         ORDER BY a.name COLLATE NOCASE"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // awards tables not yet created
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
    <title>Awards</title>
    <link rel="stylesheet" href="/theme.css.php">
    <link rel="stylesheet" href="/css/all.min.css" crossorigin="anonymous">
</head>
<body class="pt-5">
<?php include 'navbar.php'; ?>
<div class="container my-4">
    <h1 class="mb-4"><i class="fa-solid fa-trophy me-2 text-warning"></i>Awards</h1>

    <!-- Add award -->
    <div class="card mb-4">
        <div class="card-body">
            <h5 class="card-title">Add Award</h5>
            <div class="row g-2">
                <div class="col">
                    <input type="text" id="newAwardInput" class="form-control" placeholder="e.g. Hugo Award">
                </div>
                <div class="col-auto">
                    <button id="addAwardBtn" class="btn btn-primary">
                        <i class="fa-solid fa-plus me-1"></i>Add
                    </button>
                </div>
            </div>
            <p id="addMsg" class="text-danger small mt-2 mb-0" style="display:none"></p>
        </div>
    </div>

    <?php if (empty($awards)): ?>
        <p class="text-muted">No awards recorded yet. Add one above.</p>
    <?php else: ?>
    <?php
        $letters = [];
        foreach ($awards as $a) {
            $first = strtoupper(mb_substr($a['name'], 0, 1));
            $letters[ctype_alpha($first) ? $first : '#'] = true;
        }
        ksort($letters);
    ?>
    <style>
        .letter-btn.active { background-color: #0d6efd; color: #fff; border-color: #0d6efd; }
        .filter-hidden { display: none !important; }
    </style>

    <!-- Filter bar -->
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <input type="search" id="awardsFilter" class="form-control form-control-sm" style="max-width:20rem;" placeholder="Filter awards…" autocomplete="off">
        <button type="button" id="zeroBooksToggle" class="btn btn-sm btn-outline-warning">0 books only</button>
        <span class="text-muted small" id="awardsCount"></span>
    </div>
    <div class="d-flex flex-wrap gap-1 mb-3" id="awardsLetterBar">
        <button class="btn btn-sm btn-outline-secondary letter-btn" data-letter="">All</button>
        <?php foreach ($letters as $letter => $_): ?>
            <button class="btn btn-sm btn-outline-secondary letter-btn" data-letter="<?= htmlspecialchars($letter) ?>"><?= htmlspecialchars($letter) ?></button>
        <?php endforeach; ?>
    </div>

    <ul class="list-group" id="awardsList">
        <?php foreach ($awards as $a): ?>
            <?php $firstChar = strtoupper(mb_substr($a['name'], 0, 1)); ?>
            <li class="list-group-item<?= (int)$a['book_count'] === 0 ? ' list-group-item-warning' : '' ?>"
                data-id="<?= (int)$a['id'] ?>"
                data-name="<?= htmlspecialchars(strtolower($a['name'])) ?>"
                data-letter="<?= htmlspecialchars(ctype_alpha($firstChar) ? $firstChar : '#') ?>"
                data-empty="<?= (int)$a['book_count'] === 0 ? '1' : '0' ?>">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center gap-2">
                        <a href="list_books.php?award_id=<?= (int)$a['id'] ?>" class="fw-semibold text-decoration-none">
                            <?= htmlspecialchars($a['name']) ?>
                        </a>
                        <?php if ((int)$a['won_count'] > 0): ?>
                            <span class="badge bg-warning text-dark" title="<?= (int)$a['won_count'] ?> winner(s)">
                                <i class="fa-solid fa-trophy me-1"></i><?= (int)$a['won_count'] ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge rounded-pill <?= (int)$a['book_count'] === 0 ? 'bg-warning text-dark' : 'bg-secondary' ?>"
                              title="<?= (int)$a['book_count'] ?> book(s)">
                            <?= (int)$a['book_count'] ?>
                        </span>
                        <button class="btn btn-sm btn-outline-secondary rename-award-btn"
                                data-id="<?= (int)$a['id'] ?>"
                                data-name="<?= htmlspecialchars($a['name'], ENT_QUOTES) ?>">Rename</button>
                        <button class="btn btn-sm btn-outline-danger delete-award-btn"
                                data-id="<?= (int)$a['id'] ?>"
                                data-name="<?= htmlspecialchars($a['name'], ENT_QUOTES) ?>">Delete</button>
                    </div>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="js/search.js"></script>
<script>
(function () {
    const filterInput     = document.getElementById('awardsFilter');
    const zeroBooksToggle = document.getElementById('zeroBooksToggle');
    const countEl         = document.getElementById('awardsCount');
    const letterBar       = document.getElementById('awardsLetterBar');
    if (!filterInput) return;
    const rows = Array.from(document.querySelectorAll('#awardsList li'));
    let activeLetter = '';
    let zeroOnly = false;

    function applyFilters() {
        const q = filterInput.value.trim().toLowerCase();
        let visible = 0;
        rows.forEach(row => {
            const show = (!q || (row.dataset.name || '').includes(q))
                      && (!activeLetter || row.dataset.letter === activeLetter)
                      && (!zeroOnly || row.dataset.empty === '1');
            row.classList.toggle('filter-hidden', !show);
            if (show) visible++;
        });
        countEl.textContent = visible + ' of ' + rows.length;
    }

    filterInput.addEventListener('input', applyFilters);
    zeroBooksToggle.addEventListener('click', () => {
        zeroOnly = !zeroOnly;
        zeroBooksToggle.classList.toggle('btn-outline-warning', !zeroOnly);
        zeroBooksToggle.classList.toggle('btn-warning', zeroOnly);
        applyFilters();
    });
    letterBar.addEventListener('click', e => {
        const btn = e.target.closest('.letter-btn');
        if (!btn) return;
        activeLetter = btn.dataset.letter;
        letterBar.querySelectorAll('.letter-btn').forEach(b => b.classList.toggle('active', b === btn));
        applyFilters();
    });
    applyFilters();
})();

// Add
document.getElementById('addAwardBtn')?.addEventListener('click', async () => {
    const input = document.getElementById('newAwardInput');
    const msg   = document.getElementById('addMsg');
    const name  = input.value.trim();
    msg.style.display = 'none';
    if (!name) return;

    const res  = await fetch('json_endpoints/book_awards.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'add_award', name }),
    });
    const data = await res.json();
    if (data.ok) {
        location.reload();
    } else {
        msg.textContent = data.error || 'Failed to add award.';
        msg.style.display = '';
    }
});

// Rename + Delete (delegated)
document.getElementById('awardsList')?.addEventListener('click', async e => {
    const renameBtn = e.target.closest('.rename-award-btn');
    const deleteBtn = e.target.closest('.delete-award-btn');

    if (renameBtn) {
        const id      = parseInt(renameBtn.dataset.id, 10);
        const current = renameBtn.dataset.name;
        const name    = prompt('Rename award:', current);
        if (!name || name.trim() === current) return;
        const res  = await fetch('json_endpoints/book_awards.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'rename_award', id, name: name.trim() }),
        });
        const data = await res.json();
        if (data.ok) {
            location.reload();
        } else {
            alert(data.error || 'Rename failed.');
        }
    }

    if (deleteBtn) {
        const id   = parseInt(deleteBtn.dataset.id, 10);
        const name = deleteBtn.dataset.name;
        if (!confirm(`Delete "${name}"? This will remove it from all books.`)) return;
        deleteBtn.disabled = true;
        const res  = await fetch('json_endpoints/book_awards.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete_award', id }),
        });
        const data = await res.json();
        if (data.ok) {
            deleteBtn.closest('li').remove();
        } else {
            alert(data.error || 'Delete failed.');
            deleteBtn.disabled = false;
        }
    }
});
</script>
</body>
</html>
