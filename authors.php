<?php
require_once 'db.php';
require_once 'cache.php';
requireLogin();

$pdo = getDatabaseConnection();
$authors = $pdo->query(
    "SELECT a.id, a.name, COUNT(DISTINCT bal.book) AS book_count,\n" .
    "       REPLACE(GROUP_CONCAT(DISTINCT s.id || ':' || s.name), ',', '|') AS series_list\n" .
    "FROM authors a\n" .
    "LEFT JOIN books_authors_link bal ON a.id = bal.author\n" .
    "LEFT JOIN books_series_link bsl ON bal.book = bsl.book\n" .
    "LEFT JOIN series s ON s.id = bsl.series\n" .
    "GROUP BY a.id, a.name\n" .
    "ORDER BY a.sort"
)->fetchAll(PDO::FETCH_ASSOC);
$statuses = getCachedStatuses($pdo);
$genres = getCachedGenres($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Authors</title>
    <link rel="stylesheet" href="/theme.css.php">
<link rel="stylesheet" href="/css/all.min.css" crossorigin="anonymous">
</head>
<body class="pt-5 bg-light">
<?php include 'navbar.php'; ?>
<div class="container my-4">
    <h1>Authors</h1>
    <?php if (empty($authors)): ?>
        <p class="text-muted">No authors found.</p>
    <?php else: ?>
    <?php
        $authorLetters = [];
        foreach ($authors as $a) {
            $first = strtoupper(mb_substr($a['name'], 0, 1));
            if (ctype_alpha($first)) $authorLetters[$first] = true;
            else $authorLetters['#'] = true;
        }
        ksort($authorLetters);
    ?>
    <style>
        .author-row:hover { background-color: #f8f9fa; }
        .author-row { border-bottom: 1px solid #dee2e6; padding: .4rem .5rem; }
        .author-row[data-books="0"] { background-color: rgba(255, 193, 7, 0.15); }
        .author-row[data-books="0"]:hover { background-color: rgba(255, 193, 7, 0.25); }
        .author-series-badge { font-size: .72rem; }
        .letter-btn.active { background-color: #0d6efd; color: #fff; border-color: #0d6efd; }
        .filter-hidden { display: none !important; }
    </style>

    <!-- Filter bar -->
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <input type="search" id="authorFilter" class="form-control form-control-sm" style="max-width:20rem;" placeholder="Filter authors…" autocomplete="off">
        <span class="text-muted small" id="authorCount"></span>
    </div>
    <div class="d-flex flex-wrap gap-1 mb-3" id="authorLetterBar">
        <button class="btn btn-sm btn-outline-secondary letter-btn" data-letter="">All</button>
        <?php foreach ($authorLetters as $letter => $_): ?>
            <button class="btn btn-sm btn-outline-secondary letter-btn" data-letter="<?= htmlspecialchars($letter) ?>"><?= htmlspecialchars($letter) ?></button>
        <?php endforeach; ?>
    </div>

    <div class="list-group" id="authorList">
    <?php foreach ($authors as $a): ?>
        <?php $seriesList = $a['series_list'] !== null && $a['series_list'] !== '' ? explode('|', $a['series_list']) : []; ?>
        <?php $firstChar = strtoupper(mb_substr($a['name'], 0, 1)); ?>
        <div class="author-row d-flex align-items-center gap-2 flex-wrap"
             data-name="<?= htmlspecialchars(strtolower($a['name'])) ?>"
             data-letter="<?= htmlspecialchars(ctype_alpha($firstChar) ? $firstChar : '#') ?>"
             data-books="<?= (int)$a['book_count'] ?>">

            <!-- Name + count -->
            <div class="flex-grow-1 d-flex align-items-center gap-2 flex-wrap">
                <a href="list_books.php?author_id=<?= (int)$a['id'] ?>" class="fw-semibold text-primary text-decoration-none">
                    <i class="fa-solid fa-user fa-xs me-1"></i><?= htmlspecialchars($a['name']) ?>
                </a>
                <span class="text-muted small"><?= (int)$a['book_count'] ?> book<?= ((int)$a['book_count'] === 1) ? '' : 's' ?></span>
                <?php foreach ($seriesList as $s): ?>
                    <?php [$sid, $sname] = explode(':', $s, 2); ?>
                    <a href="list_books.php?series_id=<?= (int)$sid ?>"
                       class="badge bg-secondary text-decoration-none author-series-badge">
                        <?= htmlspecialchars($sname) ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Controls -->
            <div class="d-flex align-items-center gap-1 flex-shrink-0">
                <select class="form-select form-select-sm author-genre" data-author-id="<?= (int)$a['id'] ?>" style="width:9rem;">
                    <option value="">Genre…</option>
                    <?php foreach ($genres as $g): ?>
                        <option value="<?= htmlspecialchars($g['value']) ?>"><?= htmlspecialchars($g['value']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="form-select form-select-sm author-status" data-author-id="<?= (int)$a['id'] ?>" style="width:9rem;">
                    <option value="">Status…</option>
                    <?php foreach ($statuses as $s): ?>
                        <option value="<?= htmlspecialchars($s['value']) ?>"><?= htmlspecialchars($s['value']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-sm btn-outline-danger delete-author" data-author-id="<?= (int)$a['id'] ?>" title="Delete author">
                    <i class="fa-solid fa-trash"></i>
                </button>
            </div>

        </div>
    <?php endforeach; ?>
    </div><!-- #authorList -->
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="js/search.js"></script>
<script>
(function () {
    const filterInput  = document.getElementById('authorFilter');
    const countEl      = document.getElementById('authorCount');
    const letterBar    = document.getElementById('authorLetterBar');
    const rows         = document.querySelectorAll('#authorList .author-row');
    let activeLetter   = '';

    function applyFilters() {
        const q = filterInput.value.trim().toLowerCase();
        let visible = 0;
        rows.forEach(row => {
            const name   = row.dataset.name || '';
            const letter = row.dataset.letter || '';
            const matchText   = !q || name.includes(q);
            const matchLetter = !activeLetter || letter === activeLetter;
            const show = matchText && matchLetter;
            row.classList.toggle('filter-hidden', !show);
            if (show) visible++;
        });
        countEl.textContent = visible + ' of ' + rows.length;
    }

    filterInput.addEventListener('input', applyFilters);

    letterBar.addEventListener('click', e => {
        const btn = e.target.closest('.letter-btn');
        if (!btn) return;
        activeLetter = btn.dataset.letter;
        letterBar.querySelectorAll('.letter-btn').forEach(b => b.classList.toggle('active', b === btn));
        applyFilters();
    });

    applyFilters();
})();
</script>
<script src="js/authors.js"></script>
</body>
</html>
