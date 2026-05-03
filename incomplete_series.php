<?php
require_once 'db.php';
requireLogin();

$pdo = getDatabaseConnection();

// Series with no book at index 1, but at least one book at index > 1.
// Index 0 books (prequels/companions) are excluded from the check but still shown.
$rows = $pdo->query("
    SELECT
        s.id   AS series_id,
        s.name AS series_name,
        b.id   AS book_id,
        b.title,
        b.series_index,
        a.name AS author
    FROM series s
    JOIN books_series_link bsl ON bsl.series = s.id
    JOIN books b ON b.id = bsl.book
    LEFT JOIN books_authors_link bal
           ON bal.book = b.id
          AND bal.id = (SELECT MIN(id) FROM books_authors_link WHERE book = b.id)
    LEFT JOIN authors a ON a.id = bal.author
    WHERE s.id IN (
        SELECT bsl2.series
        FROM books_series_link bsl2
        JOIN books b2 ON b2.id = bsl2.book
        WHERE b2.series_index > 0
        GROUP BY bsl2.series
        HAVING SUM(CASE WHEN b2.series_index = 1 THEN 1 ELSE 0 END) = 0
           AND SUM(CASE WHEN b2.series_index > 1 THEN 1 ELSE 0 END) > 0
    )
    ORDER BY s.name COLLATE NOCASE, b.series_index, b.title
")->fetchAll(PDO::FETCH_ASSOC);

// Group rows by series
$series = [];
foreach ($rows as $row) {
    $sid = (int)$row['series_id'];
    if (!isset($series[$sid])) {
        $series[$sid] = [
            'id'     => $sid,
            'name'   => $row['series_name'],
            'author' => $row['author'] ?? '',
            'books'  => [],
        ];
    }
    $series[$sid]['books'][] = [
        'id'    => (int)$row['book_id'],
        'title' => $row['title'],
        'index' => (float)$row['series_index'],
    ];
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
  <title>Incomplete Series</title>
  <link rel="stylesheet" href="/theme.css.php">
  <link rel="stylesheet" href="/css/all.min.css" crossorigin="anonymous">
  <style>
    body { padding-bottom: 3rem; }

    .series-row {
        border-bottom: 1px solid var(--bs-border-color);
        padding: .5rem .25rem;
    }
    .series-row:nth-child(odd)  { background: var(--row-stripe-a, transparent); }
    .series-row:nth-child(even) { background: var(--row-stripe-b, rgba(0,0,0,.04)); }
    .series-row.filter-hidden   { display: none !important; }

    .series-name { font-weight: 600; }
    .series-author { color: var(--bs-secondary-color); font-size: .82rem; }

    .book-list { list-style: none; padding: 0; margin: .25rem 0 0; }
    .book-list li { font-size: .82rem; padding: .1rem 0; }
    .idx-badge {
        display: inline-block;
        min-width: 2.2rem;
        text-align: center;
        padding: .1rem .4rem;
        border-radius: .3rem;
        font-size: .72rem;
        font-weight: 600;
        margin-right: .3rem;
        background: var(--bs-secondary-bg);
        color: var(--bs-secondary-color);
    }
    .idx-badge.missing-start {
        background: rgba(var(--bs-warning-rgb), .15);
        color: var(--bs-warning-text-emphasis);
        border: 1px solid rgba(var(--bs-warning-rgb), .4);
    }

    #toolbar {
        position: fixed;
        bottom: 0;
        left: 50%;
        transform: translateX(-50%);
        z-index: 1040;
        display: flex;
        align-items: center;
        gap: .5rem;
        padding: .4rem .9rem;
        background: var(--bs-body-bg);
        border: 1px solid var(--bs-border-color);
        border-radius: .6rem .6rem 0 0;
        box-shadow: 0 -2px 12px rgba(0,0,0,.15);
        white-space: nowrap;
    }
    #toolbar input.form-control {
        background: var(--bs-body-bg);
        color: var(--bs-body-color);
        border-color: var(--bs-border-color);
    }
  </style>
</head>
<body class="pt-5">
<?php include 'navbar.php'; ?>

<div class="container my-4">
  <h1 class="mb-1">
    <i class="fa-solid fa-circle-exclamation me-2 text-warning"></i>Incomplete Series
  </h1>
  <p class="text-muted small mb-4">Series where book #1 is missing from your library (index&nbsp;0 entries ignored).</p>

  <?php if (empty($series)): ?>
    <p class="text-muted">No incomplete series found — every series in your library starts at #1.</p>
  <?php else: ?>

  <div id="seriesList">
    <?php foreach ($series as $s): ?>
      <?php
        $minIndex = min(array_column($s['books'], 'index'));
        $firstChar = strtoupper(mb_substr($s['name'], 0, 1));
        $letter    = ctype_alpha($firstChar) ? $firstChar : '#';
      ?>
      <div class="series-row"
           data-name="<?= htmlspecialchars(strtolower($s['name'])) ?>"
           data-author="<?= htmlspecialchars(strtolower($s['author'])) ?>"
           data-letter="<?= htmlspecialchars($letter) ?>">
        <div class="d-flex align-items-start gap-2 flex-wrap">
          <div class="flex-grow-1">
            <a href="/list_books.php?series_id=<?= $s['id'] ?>"
               class="series-name text-decoration-none">
              <?= htmlspecialchars($s['name']) ?>
            </a>
            <?php if ($s['author']): ?>
              <span class="series-author ms-2"><?= htmlspecialchars($s['author']) ?></span>
            <?php endif; ?>

            <ul class="book-list">
              <?php foreach ($s['books'] as $book): ?>
                <?php
                  $idx = $book['index'];
                  $idxLabel = ($idx == (int)$idx) ? (string)(int)$idx : (string)$idx;
                  $isZero   = $idx == 0;
                ?>
                <li>
                  <span class="idx-badge<?= !$isZero ? ' missing-start' : '' ?>">#<?= htmlspecialchars($idxLabel) ?></span>
                  <a href="/book.php?id=<?= $book['id'] ?>" class="text-decoration-none">
                    <?= htmlspecialchars($book['title']) ?>
                  </a>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
          <div class="flex-shrink-0">
            <span class="badge bg-warning text-dark" title="Lowest non-zero index in library">
              starts at #<?= htmlspecialchars($minIndex == (int)$minIndex ? (string)(int)$minIndex : (string)$minIndex) ?>
            </span>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php endif; ?>
</div>

<div id="toolbar">
  <i class="fa-solid fa-magnifying-glass text-muted fa-sm"></i>
  <input type="search" id="filterInput" class="form-control form-control-sm"
         style="width:16rem" placeholder="Filter series or author…" autocomplete="off">
  <span class="text-muted small" id="countEl"></span>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script>
(function () {
    const input   = document.getElementById('filterInput');
    const countEl = document.getElementById('countEl');
    const rows    = Array.from(document.querySelectorAll('.series-row'));
    if (!input || !rows.length) return;

    function applyFilter() {
        const q = input.value.trim().toLowerCase();
        let visible = 0;
        rows.forEach(row => {
            const show = !q
                || (row.dataset.name   || '').includes(q)
                || (row.dataset.author || '').includes(q);
            row.classList.toggle('filter-hidden', !show);
            if (show) visible++;
        });
        countEl.textContent = visible + ' of ' + rows.length;
    }

    input.addEventListener('input', applyFilter);
    applyFilter();
})();
</script>
</body>
</html>
