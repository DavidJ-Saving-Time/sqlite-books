<?php
require_once __DIR__ . '/../db.php';
requireLogin();

$pdo = getDatabaseConnection();

$count = isset($_GET['count']) ? max(1, min(50, (int)$_GET['count'])) : 15;

// Pick random books that have a goodreads identifier
$books = $pdo->prepare("
    SELECT b.id, b.title,
           GROUP_CONCAT(a.name, ', ') AS authors,
           i_gr.val   AS gr_id,
           i_rat.val  AS gr_rating,
           i_cnt.val  AS gr_rating_count,
           i_isbn.val AS isbn
    FROM books b
    JOIN identifiers i_gr ON i_gr.book = b.id AND i_gr.type = 'goodreads'
    LEFT JOIN books_authors_link bal ON bal.book = b.id
    LEFT JOIN authors a ON a.id = bal.author
    LEFT JOIN identifiers i_rat  ON i_rat.book  = b.id AND i_rat.type  = 'gr_rating'
    LEFT JOIN identifiers i_cnt  ON i_cnt.book  = b.id AND i_cnt.type  = 'gr_rating_count'
    LEFT JOIN identifiers i_isbn ON i_isbn.book = b.id AND i_isbn.type IN ('isbn13','isbn')
    GROUP BY b.id
    ORDER BY RANDOM()
    LIMIT :n
");
$books->execute([':n' => $count]);
$books = $books->fetchAll(PDO::FETCH_ASSOC);

$libraryWebPath = getLibraryWebPath();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="manifest" href="/manifest.json">
  <meta name="theme-color" content="#212529">
  <link rel="apple-touch-icon" href="/app-icons/icon-192.png">
    <meta charset="UTF-8">
    <title>GR Spot Check</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="/theme.css.php">
    <link rel="stylesheet" href="/css/all.min.css" crossorigin="anonymous">
    <style>
        body { padding-top: 70px; }
        .book-card { transition: box-shadow .15s; }
        .book-card:hover { box-shadow: 0 0 0 2px var(--bs-primary); }
        .cover-thumb { width: 60px; height: 85px; object-fit: cover; border-radius: 3px; flex-shrink: 0; background: var(--bs-secondary-bg); }
        .cover-placeholder { width: 60px; height: 85px; border-radius: 3px; flex-shrink: 0; background: var(--bs-secondary-bg); display:flex; align-items:center; justify-content:center; color:var(--bs-secondary-color); }
    </style>
</head>
<body>
<?php include __DIR__ . '/../navbar.php'; ?>
<div class="container py-4">
    <div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
        <h4 class="mb-0"><i class="fa-brands fa-goodreads me-2"></i>GR Spot Check</h4>
        <span class="text-muted small">Click a Goodreads link to verify the match is correct.</span>
        <div class="ms-auto d-flex align-items-center gap-2">
            <form method="get" class="d-flex align-items-center gap-2">
                <label class="form-label mb-0 small text-muted">Books:</label>
                <select name="count" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
                    <?php foreach ([10, 15, 25, 50] as $opt): ?>
                    <option value="<?= $opt ?>"<?= $count === $opt ? ' selected' : '' ?>><?= $opt ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <a href="gr_spot_check.php?count=<?= $count ?>" class="btn btn-sm btn-outline-secondary">
                <i class="fa-solid fa-rotate me-1"></i>New sample
            </a>
        </div>
    </div>

    <?php if (empty($books)): ?>
        <div class="alert alert-info">No books with Goodreads IDs found.</div>
    <?php else: ?>
    <div class="row g-3">
        <?php foreach ($books as $b):
            $coverUrl = $b['id'] && !empty($b['id'])
                ? rtrim($libraryWebPath, '/') . '/' . $pdo->query("SELECT path FROM books WHERE id = " . (int)$b['id'])->fetchColumn() . '/cover.jpg'
                : '';
            $grUrl   = 'https://www.goodreads.com/book/show/' . urlencode($b['gr_id']);
            $rating  = $b['gr_rating'] ? '★' . $b['gr_rating'] : '';
            $rCount  = $b['gr_rating_count'] ? number_format((int)$b['gr_rating_count']) . ' ratings' : '';
        ?>
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card book-card h-100">
                <div class="card-body d-flex gap-3">
                    <?php if ($coverUrl): ?>
                        <img src="<?= htmlspecialchars($coverUrl) ?>" class="cover-thumb" alt="" loading="lazy"
                             onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                        <div class="cover-placeholder" style="display:none"><i class="fa-solid fa-book"></i></div>
                    <?php else: ?>
                        <div class="cover-placeholder"><i class="fa-solid fa-book"></i></div>
                    <?php endif; ?>
                    <div class="flex-grow-1 overflow-hidden">
                        <div class="fw-semibold text-truncate" title="<?= htmlspecialchars($b['title']) ?>">
                            <?= htmlspecialchars($b['title']) ?>
                        </div>
                        <?php if ($b['authors']): ?>
                        <div class="text-muted small text-truncate"><?= htmlspecialchars($b['authors']) ?></div>
                        <?php endif; ?>
                        <div class="mt-2 d-flex flex-wrap gap-2 align-items-center">
                            <a href="<?= htmlspecialchars($grUrl) ?>" target="_blank" rel="noopener"
                               class="btn btn-sm btn-outline-secondary">
                                <i class="fa-brands fa-goodreads me-1"></i>GR #<?= htmlspecialchars($b['gr_id']) ?>
                            </a>
                            <a href="../book.php?id=<?= (int)$b['id'] ?>" class="btn btn-sm btn-outline-primary">
                                <i class="fa-solid fa-pen-to-square me-1"></i>Edit
                            </a>
                        </div>
                        <?php if ($rating || $rCount || $b['isbn']): ?>
                        <div class="mt-1 d-flex flex-wrap gap-2" style="font-size:0.75rem">
                            <?php if ($rating): ?>
                                <span class="text-warning"><?= htmlspecialchars($rating) ?></span>
                            <?php endif; ?>
                            <?php if ($rCount): ?>
                                <span class="text-muted"><?= htmlspecialchars($rCount) ?></span>
                            <?php endif; ?>
                            <?php if ($b['isbn']): ?>
                                <span class="text-muted">ISBN <?= htmlspecialchars($b['isbn']) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
