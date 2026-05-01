<?php
require_once 'db.php';
requireLogin();

$pdo = getDatabaseConnection();
$id  = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) die('Invalid book ID');

// ── Book core ─────────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT b.*,
           (SELECT GROUP_CONCAT(a.id || ':' || a.name, '|')
            FROM books_authors_link bal JOIN authors a ON bal.author = a.id
            WHERE bal.book = b.id) AS author_data,
           s.id   AS series_id,
           s.name AS series,
           (SELECT name FROM publishers
            WHERE id IN (SELECT publisher FROM books_publishers_link WHERE book = b.id)
            LIMIT 1) AS publisher
    FROM books b
    LEFT JOIN books_series_link bsl ON bsl.book = b.id
    LEFT JOIN series s              ON bsl.series = s.id
    WHERE b.id = :id
");
$stmt->execute([':id' => $id]);
$book = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$book) die('Book not found');

// ── Description ───────────────────────────────────────────────────────────────
$desc = $pdo->prepare('SELECT text FROM comments WHERE book = ?');
$desc->execute([$id]);
$description = $desc->fetchColumn() ?: '';

// ── Identifiers ───────────────────────────────────────────────────────────────
$idRows = $pdo->prepare('SELECT type, val FROM identifiers WHERE book = ? ORDER BY type');
$idRows->execute([$id]);
$ids = [];
foreach ($idRows->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $ids[$r['type']] = $r['val'];
}
$grWorkId     = $ids['gr_work_id']     ?? '';
$grRating     = $ids['gr_rating']      ?? '';
$grRatingCount= $ids['gr_rating_count']?? '';
$grPages      = $ids['gr_pages']       ?? '';
$goodreadsId  = $ids['goodreads']      ?? '';
$amazonId     = $ids['amazon']         ?? $ids['asin'] ?? '';

// ── Authors ───────────────────────────────────────────────────────────────────
$authors = [];
foreach (explode('|', $book['author_data'] ?? '') as $pair) {
    if (strpos($pair, ':') === false) continue;
    [$aid, $aname] = explode(':', $pair, 2);
    $authors[] = ['id' => (int)$aid, 'name' => $aname];
}

// ── Tags ──────────────────────────────────────────────────────────────────────
$tagStmt = $pdo->prepare(
    'SELECT t.name FROM books_tags_link btl JOIN tags t ON btl.tag = t.id WHERE btl.book = ? ORDER BY t.name'
);
$tagStmt->execute([$id]);
$tags = $tagStmt->fetchAll(PDO::FETCH_COLUMN);

// ── Reviews ───────────────────────────────────────────────────────────────────
$reviews = [];
try {
    $revStmt = $pdo->prepare(
        'SELECT reviewer, reviewer_url, rating, review_date, text, like_count, spoiler
         FROM book_reviews WHERE book = ? ORDER BY like_count DESC LIMIT 10'
    );
    $revStmt->execute([$id]);
    $reviews = $revStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* table may not exist */ }

// ── Similar books pre-check ───────────────────────────────────────────────────
$similarCount = 0;
if ($grWorkId) {
    $sc = $pdo->prepare('SELECT COUNT(*) FROM gr_similar_books WHERE source_work_id = ?');
    $sc->execute([$grWorkId]);
    $similarCount = (int)$sc->fetchColumn();
}

// ── Pub year ──────────────────────────────────────────────────────────────────
$pubYear = '';
if (!empty($book['pubdate'])) {
    try {
        $pubYear = (new DateTime($book['pubdate']))->format('Y');
    } catch (Exception $e) {
        if (preg_match('/^\d{4}/', $book['pubdate'], $m)) $pubYear = $m[0];
    }
}

$coverUrl   = getLibraryWebPath() . '/' . $book['path'] . '/cover.jpg';
$grUrl      = $goodreadsId
    ? 'https://www.goodreads.com/book/show/' . urlencode($goodreadsId)
    : 'https://www.goodreads.com/search?q=' . urlencode($book['title'] ?? '');
$editUrl    = 'book.php?id=' . $id;
$backUrl    = isset($_GET['back']) ? htmlspecialchars($_GET['back']) : 'list_books.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="manifest" href="/manifest.json">
  <meta name="theme-color" content="#212529">
  <link rel="apple-touch-icon" href="/app-icons/icon-192.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($book['title'] ?? 'Book') ?></title>
    <link rel="stylesheet" href="/theme.css.php">
    <link rel="stylesheet" href="/css/all.min.css" crossorigin="anonymous">
    <style>
        .bv-cover {
            width: 100%;
            max-width: 260px;
            border-radius: 6px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.35);
            display: block;
        }
        .bv-title {
            font-size: 1.75rem;
            font-weight: 700;
            line-height: 1.2;
        }
        .bv-author {
            font-size: 1.1rem;
            color: var(--bs-secondary-color);
        }
        .bv-author a { color: inherit; text-decoration: none; }
        .bv-author a:hover { text-decoration: underline; }
        .bv-series {
            font-size: 0.9rem;
            color: var(--bs-secondary-color);
        }
        .bv-series a { color: var(--bs-link-color); text-decoration: none; }
        .bv-series a:hover { text-decoration: underline; }
        .bv-meta-row {
            font-size: 0.82rem;
            color: var(--bs-secondary-color);
            display: flex;
            flex-wrap: wrap;
            gap: 0.9rem;
            align-items: center;
        }
        .bv-rating { font-size: 1rem; color: var(--bs-warning-text-emphasis); }
        .bv-desc { line-height: 1.75; font-size: 0.95rem; }
        .bv-section-title {
            font-size: 1.1rem;
            font-weight: 600;
            border-bottom: 2px solid var(--bs-border-color);
            padding-bottom: 0.4rem;
            margin-bottom: 1.25rem;
        }
        /* Similar books grid */
        .sim-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1rem;
        }
        .sim-card {
            border: 1px solid var(--bs-border-color);
            border-radius: 8px;
            padding: 0.75rem;
            display: flex;
            gap: 0.75rem;
        }
        .sim-cover {
            width: 70px;
            height: 100px;
            object-fit: cover;
            border-radius: 4px;
            flex-shrink: 0;
            display: block;
        }
        .sim-cover-ph {
            width: 70px;
            height: 100px;
            border-radius: 4px;
            flex-shrink: 0;
            background: var(--bs-secondary-bg);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--bs-secondary-color);
            font-size: 1.3rem;
        }
        .sim-title { font-weight: 600; font-size: 0.88rem; line-height: 1.3; }
        .sim-author { font-size: 0.8rem; color: var(--bs-secondary-color); }
        .sim-series { font-size: 0.75rem; color: var(--bs-secondary-color); }
        .sim-desc {
            font-size: 0.78rem;
            line-height: 1.5;
            color: var(--bs-secondary-color);
            margin-top: 0.35rem;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .sim-desc.expanded { display: block; -webkit-line-clamp: unset; }
        .sim-desc-toggle {
            font-size: 0.72rem;
            cursor: pointer;
            background: none;
            border: none;
            padding: 0;
            margin-top: 0.1rem;
            color: var(--bs-link-color);
        }
        /* Reviews */
        .rev-card { border-bottom: 1px solid var(--bs-border-color); padding-bottom: 1.25rem; margin-bottom: 1.25rem; }
        .rev-card:last-child { border-bottom: none; }
        .rev-body {
            font-size: 0.88rem;
            line-height: 1.7;
            max-height: 10rem;
            overflow: hidden;
            position: relative;
        }
        .rev-body.expanded { max-height: none; }
        .rev-fade {
            position: absolute; bottom: 0; left: 0; right: 0; height: 3rem;
            background: linear-gradient(transparent, var(--bs-body-bg));
        }
        .rev-expand-btn {
            font-size: 0.75rem;
            background: none;
            border: none;
            padding: 0;
            color: var(--bs-link-color);
            cursor: pointer;
            margin-top: 0.25rem;
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container mt-5 pt-4 pb-5" style="max-width: 1000px">

    <!-- Back / Edit bar -->
    <div class="d-flex align-items-center gap-2 mb-4">
        <a href="<?= $backUrl ?>" class="btn btn-sm btn-outline-secondary">
            <i class="fa-solid fa-arrow-left me-1"></i>Back
        </a>
        <a href="<?= $editUrl ?>" class="btn btn-sm btn-outline-secondary ms-auto">
            <i class="fa-solid fa-pen-to-square me-1"></i>Edit
        </a>
    </div>

    <!-- ── Hero: cover + info ──────────────────────────────────────────────── -->
    <div class="row g-4 mb-5">

        <!-- Cover -->
        <div class="col-auto text-center">
            <?php if ($book['has_cover']): ?>
            <img src="<?= htmlspecialchars($coverUrl) ?>"
                 alt="Cover"
                 class="bv-cover"
                 onerror="this.replaceWith(document.getElementById('cover-ph').content.cloneNode(true))">
            <?php else: ?>
            <div class="bv-cover d-flex align-items-center justify-content-center bg-secondary-subtle" style="height:370px">
                <i class="fa-solid fa-book fa-3x text-secondary"></i>
            </div>
            <?php endif; ?>
            <!-- Buttons under cover -->
            <div class="d-flex flex-column gap-2 mt-3" style="max-width:260px">
                <?php if ($goodreadsId || true): ?>
                <a href="<?= htmlspecialchars($grUrl) ?>" target="_blank" rel="noopener"
                   class="btn btn-sm btn-outline-secondary w-100">
                    <i class="fa-brands fa-goodreads me-1"></i>Goodreads<?= $goodreadsId ? '' : ' (search)' ?>
                </a>
                <?php endif; ?>
                <?php if ($amazonId): ?>
                <a href="https://www.amazon.com/dp/<?= urlencode($amazonId) ?>" target="_blank" rel="noopener"
                   class="btn btn-sm btn-outline-secondary w-100">
                    <i class="fa-brands fa-amazon me-1"></i>Amazon
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Info -->
        <div class="col">
            <h1 class="bv-title mb-2"><?= htmlspecialchars($book['title'] ?? '') ?></h1>

            <?php if ($authors): ?>
            <div class="bv-author mb-2">
                <?php foreach ($authors as $i => $a): ?>
                    <?= $i > 0 ? ', ' : '' ?>
                    <a href="list_books.php?author_id=<?= $a['id'] ?>"><?= htmlspecialchars($a['name']) ?></a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($book['series'])): ?>
            <div class="bv-series mb-3">
                <i class="fa-solid fa-books fa-xs me-1"></i>
                <a href="list_books.php?series_id=<?= (int)$book['series_id'] ?>">
                    <?= htmlspecialchars($book['series']) ?><?php if ($book['series_index'] !== null && $book['series_index'] !== ''): ?> #<?= htmlspecialchars($book['series_index']) ?><?php endif; ?>
                </a>
            </div>
            <?php endif; ?>

            <!-- Meta row -->
            <div class="bv-meta-row mb-3">
                <?php if ($grRating): ?>
                <span class="bv-rating">
                    ★ <?= htmlspecialchars($grRating) ?>
                    <?php if ($grRatingCount): ?>
                    <span class="text-muted" style="font-size:0.78rem">
                        (<?php
                            $n = (int)$grRatingCount;
                            echo $n >= 1000000 ? round($n/1000000,1).'M' : ($n >= 1000 ? round($n/1000,1).'k' : $n);
                        ?>)
                    </span>
                    <?php endif; ?>
                </span>
                <?php endif; ?>
                <?php if ($grPages): ?>
                <span><i class="fa-solid fa-file-lines fa-xs me-1"></i><?= (int)$grPages ?> pages</span>
                <?php endif; ?>
                <?php if ($pubYear): ?>
                <span><i class="fa-regular fa-calendar fa-xs me-1"></i><?= htmlspecialchars($pubYear) ?></span>
                <?php endif; ?>
                <?php if (!empty($book['publisher'])): ?>
                <span><i class="fa-solid fa-building fa-xs me-1"></i><?= htmlspecialchars($book['publisher']) ?></span>
                <?php endif; ?>
            </div>

            <?php if ($tags): ?>
            <div class="d-flex flex-wrap gap-1 mb-3">
                <?php foreach ($tags as $tag): ?>
                <a href="list_books.php?genre=<?= urlencode($tag) ?>"
                   class="badge bg-secondary-subtle text-secondary-emphasis text-decoration-none fw-normal">
                    <?= htmlspecialchars($tag) ?>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if ($description): ?>
            <div class="bv-desc"><?= $description ?></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Similar Books ──────────────────────────────────────────────────── -->
    <?php if ($grWorkId): ?>
    <div class="mb-5" id="similarSection">
        <div class="bv-section-title d-flex align-items-center gap-3">
            <span><i class="fa-solid fa-list-ul me-2"></i>Similar Books</span>
            <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" id="simRefreshBtn">
                <i class="fa-solid fa-rotate me-1"></i><?= $similarCount > 0 ? 'Refresh' : 'Fetch' ?>
            </button>
        </div>
        <div id="simContainer">
            <?php if ($similarCount === 0): ?>
            <p class="text-muted small">No similar books fetched yet.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Reviews ────────────────────────────────────────────────────────── -->
    <?php if ($reviews): ?>
    <div class="mb-5">
        <div class="bv-section-title">
            <i class="fa-brands fa-goodreads me-2"></i>Reviews
            <span class="badge bg-secondary ms-2 fw-normal" style="font-size:0.7rem"><?= count($reviews) ?></span>
        </div>
        <?php foreach ($reviews as $rev): ?>
        <div class="rev-card">
            <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                <?php if (!empty($rev['reviewer_url'])): ?>
                    <a href="<?= htmlspecialchars($rev['reviewer_url']) ?>" target="_blank" rel="noopener"
                       class="fw-semibold small text-decoration-none">
                        <?= htmlspecialchars($rev['reviewer'] ?: 'Anonymous') ?>
                    </a>
                <?php else: ?>
                    <span class="fw-semibold small"><?= htmlspecialchars($rev['reviewer'] ?: 'Anonymous') ?></span>
                <?php endif; ?>
                <?php if ($rev['rating']): ?>
                <span class="text-warning" style="font-size:0.8rem">
                    <?= str_repeat('★', (int)$rev['rating']) ?><?= str_repeat('☆', 5 - (int)$rev['rating']) ?>
                </span>
                <?php endif; ?>
                <?php if ($rev['review_date']): ?>
                <span class="text-muted" style="font-size:0.75rem"><?= htmlspecialchars(substr($rev['review_date'], 0, 10)) ?></span>
                <?php endif; ?>
                <?php if ($rev['like_count']): ?>
                <span class="text-muted ms-auto" style="font-size:0.75rem">
                    <i class="fa-regular fa-thumbs-up me-1"></i><?= (int)$rev['like_count'] ?>
                </span>
                <?php endif; ?>
            </div>
            <div class="rev-body">
                <?= $rev['text'] ?>
                <div class="rev-fade"></div>
            </div>
            <button type="button" class="rev-expand-btn">Show more</button>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div><!-- /container -->

<?php if ($grWorkId): ?>
<script>
(function () {
    const bookId     = <?= (int)$id ?>;
    const container  = document.getElementById('simContainer');
    const refreshBtn = document.getElementById('simRefreshBtn');
    const hasCached  = <?= $similarCount > 0 ? 'true' : 'false' ?>;

    function esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function renderSimilar(books) {
        if (!books.length) {
            container.innerHTML = '<p class="text-muted small">No similar books found.</p>';
            return;
        }
        const cards = books.map(b => {
            const cover = b.cover_url
                ? `<img src="${esc(b.cover_url)}" alt="" class="sim-cover" loading="lazy" onerror="this.style.display='none'">`
                : `<div class="sim-cover-ph"><i class="fa-solid fa-book"></i></div>`;
            const series = b.series
                ? `<div class="sim-series">${esc(b.series)}${b.series_position ? ' #' + esc(b.series_position) : ''}</div>`
                : '';
            const rating = b.gr_rating
                ? `<span class="text-warning me-1" style="font-size:0.78rem">★${b.gr_rating.toFixed(2)}</span>`
                : '';
            const rcount = b.gr_rating_count
                ? `<span class="text-muted" style="font-size:0.72rem">${b.gr_rating_count.toLocaleString()}</span>`
                : '';
            const badge = b.in_library
                ? `<a href="book-view.php?id=${b.library_book_id}" class="badge bg-success text-decoration-none">In library</a>`
                : `<a href="https://www.goodreads.com/book/show/${esc(b.gr_book_id)}" target="_blank" rel="noopener" class="badge bg-secondary text-decoration-none">Goodreads</a>`;
            const descHtml = b.description
                ? `<div class="sim-desc">${b.description}</div><button type="button" class="sim-desc-toggle">View more</button>`
                : '';
            return `<div class="sim-card">
                ${cover}
                <div style="min-width:0;flex:1">
                    <div class="sim-title">${esc(b.title || '')}</div>
                    <div class="sim-author">${esc(b.author || '')}</div>
                    ${series}
                    <div class="mt-1">${rating}${rcount}</div>
                    <div class="mt-1">${badge}</div>
                    ${descHtml}
                </div>
            </div>`;
        });
        container.innerHTML = `<div class="sim-grid">${cards.join('')}</div>`;
    }

    async function loadSimilar(refresh) {
        container.innerHTML = '<div class="d-flex justify-content-center py-4"><div class="spinner-border text-secondary" role="status"></div></div>';
        refreshBtn.disabled = true;
        try {
            const url  = `json_endpoints/fetch_gr_similar.php?book_id=${bookId}${refresh ? '&refresh=1' : ''}`;
            const data = await fetch(url).then(r => r.json());
            if (data.error) {
                container.innerHTML = `<div class="alert alert-warning">${esc(data.error)}</div>`;
            } else {
                renderSimilar(data.books || []);
                refreshBtn.textContent = '';
                refreshBtn.innerHTML = '<i class="fa-solid fa-rotate me-1"></i>Refresh';
            }
        } catch (e) {
            container.innerHTML = '<div class="alert alert-danger">Failed to load similar books.</div>';
        } finally {
            refreshBtn.disabled = false;
        }
    }

    refreshBtn.addEventListener('click', () => loadSimilar(true));

    // Delegate "View more / View less" clicks inside the similar grid
    container.addEventListener('click', e => {
        const btn = e.target.closest('.sim-desc-toggle');
        if (!btn) return;
        const desc = btn.previousElementSibling;
        if (!desc) return;
        const expanded = desc.classList.toggle('expanded');
        btn.textContent = expanded ? 'View less' : 'View more';
    });

    if (hasCached) loadSimilar(false);
})();
</script>
<?php endif; ?>

<script>
// Review expand/collapse
document.querySelectorAll('.rev-expand-btn').forEach(btn => {
    const body = btn.previousElementSibling;
    const fade = body.querySelector('.rev-fade');
    btn.addEventListener('click', () => {
        const expanded = body.classList.toggle('expanded');
        if (fade) fade.style.display = expanded ? 'none' : '';
        btn.textContent = expanded ? 'Show less' : 'Show more';
    });
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="js/search.js"></script>
</body>
</html>
