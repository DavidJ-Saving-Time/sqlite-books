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
$genres   = getCachedGenres($pdo);

$authorLetters = [];
$authorsJson   = [];
foreach ($authors as $a) {
    $first  = strtoupper(mb_substr($a['name'], 0, 1));
    $letter = ctype_alpha($first) ? $first : '#';
    $authorLetters[$letter] = true;

    $series = [];
    if ($a['series_list'] !== null && $a['series_list'] !== '') {
        foreach (explode('|', $a['series_list']) as $s) {
            [$sid, $sname] = explode(':', $s, 2);
            $series[] = ['id' => (int)$sid, 'name' => $sname];
        }
    }
    $authorsJson[] = [
        'id'     => (int)$a['id'],
        'name'   => $a['name'],
        'books'  => (int)$a['book_count'],
        'letter' => $letter,
        'series' => $series,
    ];
}
ksort($authorLetters);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="manifest" href="/manifest.json">
  <meta name="theme-color" content="#212529">
  <link rel="apple-touch-icon" href="/app-icons/icon-192.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Authors</title>
    <link rel="stylesheet" href="/theme.css.php">
    <link rel="stylesheet" href="/css/all.min.css" crossorigin="anonymous">
    <style>
        body { padding-bottom: 3.5rem; }

        .author-row { border-bottom: 1px solid var(--bs-border-color); padding: .4rem .5rem; }
        .author-link { color: rgba(var(--bs-link-color-rgb), 1) !important; }
        .author-row:nth-child(odd)  { background-color: var(--row-stripe-a, transparent); }
        .author-row:nth-child(even) { background-color: var(--row-stripe-b, rgba(0,0,0,0.04)); }
        .author-row[data-books="0"] { background-color: rgba(255, 193, 7, 0.1) !important; }
        .author-series-badge { font-size: .72rem; }

        /* Floating search/filter toolbar — same pattern as #bulkToolbar in list_books.php */
        #authorToolbar {
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
        #authorToolbar input.form-control {
            background: var(--bs-body-bg);
            color: var(--bs-body-color);
            border-color: var(--bs-border-color);
        }

        /* Fixed alphabet footer — same pattern as #alphabetBar in list_books.php */
        #authorAlphabetBar {
            z-index: 1030;
        }
        #authorAlphabetBar .letter-btn {
            color: var(--accent, #fd8c00);
            font-size: 0.85rem;
        }
        #authorAlphabetBar .letter-btn.active {
            font-weight: 700;
            font-size: 1rem;
            color: #fff;
        }

        .save-flash { animation: saveFlash .6s ease; }
        @keyframes saveFlash { 0%,100%{ opacity:1; } 50%{ opacity:.4; } }
    </style>
</head>
<body class="pt-5">
<?php include 'navbar.php'; ?>
<div class="container bg-dark my-4">
    <h1>Authors</h1>
    <?php if (empty($authors)): ?>
        <p class="text-muted">No authors found.</p>
    <?php else: ?>

    <div id="authorList"></div>
    <div id="authorSentinel"></div>

    <?php endif; ?>
</div>

<!-- Floating search/filter toolbar -->
<div id="authorToolbar">
    <i class="fa-solid fa-magnifying-glass text-muted fa-sm"></i>
    <input type="search" id="authorFilter" class="form-control form-control-sm" style="width:15rem;" placeholder="Filter authors…" autocomplete="off">
    <button type="button" id="zeroBooksToggle" class="btn btn-sm btn-outline-warning">0 books only</button>
    <span class="text-muted small" id="authorCount"></span>
</div>

<!-- Fixed alphabet footer -->
<div id="authorAlphabetBar" class="position-fixed bottom-0 start-0 end-0 bg-dark d-flex align-items-center px-3 py-1">
    <div class="flex-grow-1 text-center">
        <a class="mx-1 text-decoration-none letter-btn" data-letter="" href="#">All</a>
        <?php foreach ($authorLetters as $letter => $_): ?>
            <a class="mx-1 text-decoration-none letter-btn" data-letter="<?= htmlspecialchars($letter) ?>" href="#"><?= htmlspecialchars($letter) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<script>
window.authorsData    = <?= json_encode($authorsJson,                    JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;
window.authorGenres   = <?= json_encode(array_column($genres,   'value'), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;
window.authorStatuses = <?= json_encode(array_column($statuses, 'value'), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="js/search.js"></script>
<script src="js/authors.js"></script>
</body>
</html>
