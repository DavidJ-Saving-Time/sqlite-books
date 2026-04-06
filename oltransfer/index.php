<?php
require_once '../db.php';
require_once '../cache.php';
requireLogin();

$bookId = isset($_GET['book_id']) ? (int)$_GET['book_id'] : 0;
if (!$bookId) { http_response_code(400); die('Missing book_id'); }

$pdo = getDatabaseConnection();

// ── Fetch book from Calibre ────────────────────────────────────────────────
$stmt = $pdo->prepare(
    "SELECT b.id, b.title, b.path, b.has_cover, b.pubdate, b.series_index,
            GROUP_CONCAT(a.name, '|') AS authors,
            c.text AS description,
            s.name AS series_name
     FROM books b
     LEFT JOIN books_authors_link bal ON bal.book = b.id
     LEFT JOIN authors a ON a.id = bal.author
     LEFT JOIN comments c ON c.book = b.id
     LEFT JOIN books_series_link bsl ON bsl.book = b.id
     LEFT JOIN series s ON s.id = bsl.series
     WHERE b.id = ?
     GROUP BY b.id"
);
$stmt->execute([$bookId]);
$book = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$book) { http_response_code(404); die('Book not found'); }

// Identifiers
$idStmt = $pdo->prepare("SELECT type, val FROM identifiers WHERE book = ?");
$idStmt->execute([$bookId]);
$identifiers = [];
foreach ($idStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $identifiers[$row['type']] = $row['val'];
}

$olid = $identifiers['olid'] ?? '';
if (!$olid) {
    die('No OLID found for this book. Use the OL Meta button on the book page to set one first.');
}

// Genres
$genreColId     = getCustomColumnId($pdo, 'genre');
$genreLinkTable = "books_custom_column_{$genreColId}_link";
$gStmt = $pdo->prepare(
    "SELECT gv.value FROM custom_column_{$genreColId} gv
     JOIN {$genreLinkTable} gl ON gl.value = gv.id
     WHERE gl.book = ?"
);
$gStmt->execute([$bookId]);
$localGenres = $gStmt->fetchAll(PDO::FETCH_COLUMN);

$localAuthors    = array_filter(explode('|', $book['authors'] ?? ''));
$localSeriesName = $book['series_name'] ?? '';
$localSeriesIdx  = $book['series_index'] !== null ? (float)$book['series_index'] : null;
// Format index: drop .0 for whole numbers
$localSeriesPos  = ($localSeriesIdx !== null)
    ? (($localSeriesIdx == floor($localSeriesIdx)) ? (string)(int)$localSeriesIdx : (string)$localSeriesIdx)
    : '';

$localCoverUrl = $book['has_cover']
    ? (getLibraryWebPath() . '/' . $book['path'] . '/cover.jpg')
    : '';

$localDescription = trim(strip_tags($book['description'] ?? ''));

// ── Helpers ────────────────────────────────────────────────────────────────
function olFetch(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT      => 'calibre-nilla/1.0 (personal library tool)',
        CURLOPT_TIMEOUT        => 8,
    ]);
    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($status === 200 && $body) ? json_decode($body, true) : null;
}

// ── Fetch OL Work ──────────────────────────────────────────────────────────
// OLID might be an edition key (OL...M) — if so, follow it to the work
$workOlid = $olid;
if (preg_match('/^OL\d+M$/', $olid)) {
    $edition = olFetch("https://openlibrary.org/books/{$olid}.json");
    $workKey = $edition['works'][0]['key'] ?? '';
    if ($workKey) {
        $workOlid = ltrim(str_replace('/works/', '', $workKey), '/');
    }
}

$olWork = olFetch("https://openlibrary.org/works/{$workOlid}.json");
if (!$olWork) { die("Failed to fetch OL data for {$workOlid}"); }

$d = $olWork['description'] ?? '';
$olDescription = is_array($d) ? ($d['value'] ?? '') : (string)$d;
$olSubjects    = $olWork['subjects'] ?? [];
$olCoverId     = $olWork['covers'][0] ?? null;
$olCoverUrl    = $olCoverId ? "https://covers.openlibrary.org/b/id/{$olCoverId}-L.jpg" : '';

// ── OL Series ─────────────────────────────────────────────────────────────
// OL stores series in multiple places — collect all and display them all.
$olSeriesKey  = '';   // set only for new-format works with a /series/OLXXXL entity
$olSeriesName = '';   // from new-format work series field
$olSeriesPos  = '';
$olEditionSeries = []; // series strings from editions (e.g. "Shadows of the Apt -- bk. 1")

// 1. New work-level series field (2026+)
if (!empty($olWork['series']) && is_array($olWork['series'])) {
    foreach ($olWork['series'] as $s) {
        $rawKey = $s['series']['key'] ?? '';
        if (!$rawKey) continue;
        $olSeriesKey = ltrim(str_replace('/series/', '', $rawKey), '/');
        $olSeriesPos = $s['position'] ?? '';
        $seriesEntity = olFetch("https://openlibrary.org/series/{$olSeriesKey}.json");
        $olSeriesName = $seriesEntity['name'] ?? $olSeriesKey;
        break;
    }
}

// 2. Edition-level series strings (older format — most common)
$editionsData = olFetch("https://openlibrary.org/works/{$workOlid}/editions.json?limit=10");
foreach (($editionsData['entries'] ?? []) as $ed) {
    foreach (($ed['series'] ?? []) as $s) {
        $s = trim($s);
        if ($s && !in_array($s, $olEditionSeries, true)) {
            $olEditionSeries[] = $s;
        }
    }
}

// Which key to use for the transfer (only new-format works have a key)
$transferSeriesKey = $olSeriesKey;

// ── Diff flags ─────────────────────────────────────────────────────────────
$descDiffers    = $localDescription !== '' && $localDescription !== $olDescription;
$subjectsDiffer = !empty($localGenres) && array_map('strtolower', $localGenres) !== array_map('strtolower', $olSubjects);
$coverDiffers   = $localCoverUrl !== '' && $olCoverId === null;

$olHasSeries = $olSeriesName !== '' || !empty($olEditionSeries);
$seriesDiffers = $localSeriesName !== '' && !$olHasSeries;
$canTransferSeries = $localSeriesName !== '' && $transferSeriesKey !== '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>OL Transfer — <?= htmlspecialchars($book['title']) ?></title>
    <link rel="stylesheet" href="/theme.css.php">
    <link rel="stylesheet" href="/css/all.min.css" crossorigin="anonymous">
    <style>
        .compare-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        .compare-col {
            background: var(--bs-body-bg);
            border: 1px solid var(--bs-border-color);
            border-radius: 0.5rem;
            padding: 1rem;
        }
        .compare-col h6 {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--bs-secondary-color);
            margin-bottom: 0.5rem;
        }
        .field-block { margin-bottom: 1.5rem; }
        .field-label {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--bs-secondary-color);
            margin-bottom: 0.25rem;
        }
        .diff-badge {
            font-size: 0.65rem;
            background: #ffc107;
            color: #000;
            padding: 0.15rem 0.4rem;
            border-radius: 999px;
            margin-left: 0.4rem;
            vertical-align: middle;
        }
        .cover-img { max-width: 140px; border-radius: 0.25rem; }
        .desc-text {
            font-size: 0.85rem;
            white-space: pre-wrap;
            max-height: 200px;
            overflow-y: auto;
            color: var(--bs-body-color);
        }
        .empty-value { color: var(--bs-secondary-color); font-style: italic; font-size: 0.85rem; }
        .transfer-check { margin-right: 0.4rem; }
        .series-key { font-size: 0.75rem; color: var(--bs-secondary-color); font-family: monospace; }
    </style>
</head>
<body class="pt-5 bg-light">
<?php include '../navbar.php'; ?>
<div class="container my-4">

    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="/list_books.php">Library</a></li>
            <li class="breadcrumb-item"><a href="/book.php?id=<?= $bookId ?>"><?= htmlspecialchars($book['title']) ?></a></li>
            <li class="breadcrumb-item active">OL Transfer</li>
        </ol>
    </nav>

    <div class="d-flex align-items-center gap-2 mb-2">
        <h2 class="mb-0"><?= htmlspecialchars($book['title']) ?></h2>
    </div>

    <?php
    // Map identifier types to labels and URL templates
    $idMeta = [
        'olid'      => ['label' => 'OpenLibrary', 'url' => 'https://openlibrary.org/works/%s'],
        'isbn'      => ['label' => 'ISBN',        'url' => null],
        'isbn13'    => ['label' => 'ISBN-13',     'url' => null],
        'goodreads' => ['label' => 'Goodreads',   'url' => 'https://www.goodreads.com/book/show/%s'],
        'amazon'    => ['label' => 'Amazon',      'url' => 'https://www.amazon.com/dp/%s'],
        'google'    => ['label' => 'Google Books', 'url' => 'https://books.google.com/books?id=%s'],
        'asin'      => ['label' => 'ASIN',        'url' => 'https://www.amazon.com/dp/%s'],
    ];
    if ($identifiers):
    ?>
    <div class="mb-4 d-flex flex-wrap gap-2 align-items-center">
        <?php foreach ($identifiers as $type => $val):
            $meta  = $idMeta[$type] ?? ['label' => strtoupper($type), 'url' => null];
            $label = $meta['label'];
            $url   = $meta['url'] ? sprintf($meta['url'], rawurlencode($val)) : null;
        ?>
            <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle" style="font-size:0.75rem">
                <span class="text-muted me-1"><?= htmlspecialchars($label) ?>:</span>
                <?php if ($url): ?>
                    <a href="<?= htmlspecialchars($url) ?>" target="_blank" class="text-reset text-decoration-none"><?= htmlspecialchars($val) ?> <i class="fa-solid fa-arrow-up-right-from-square fa-xs"></i></a>
                <?php else: ?>
                    <?= htmlspecialchars($val) ?>
                <?php endif; ?>
            </span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!$descDiffers && !$subjectsDiffer && !$coverDiffers && !$seriesDiffers): ?>
    <div class="alert alert-success">
        <i class="fa-solid fa-circle-check me-2"></i>Your library data matches what's on OpenLibrary — nothing to transfer.
    </div>
    <?php endif; ?>

    <form action="review.php" method="post">
        <input type="hidden" name="book_id"          value="<?= $bookId ?>">
        <input type="hidden" name="olid"             value="<?= htmlspecialchars($olid) ?>">
        <input type="hidden" name="transfer_series_key" value="<?= htmlspecialchars($transferSeriesKey) ?>">

        <!-- Description ──────────────────────────────────────────────────── -->
        <div class="field-block">
            <div class="field-label">
                <input type="checkbox" name="transfer_description" value="1" class="transfer-check"
                    <?= $descDiffers ? 'checked' : '' ?> <?= (!$descDiffers || !$localDescription) ? 'disabled' : '' ?>>
                Description
                <?php if ($descDiffers): ?><span class="diff-badge">differs</span><?php endif; ?>
            </div>
            <div class="compare-grid">
                <div class="compare-col">
                    <h6>Your Library</h6>
                    <?php if ($localDescription): ?>
                        <div class="desc-text"><?= htmlspecialchars($localDescription) ?></div>
                    <?php else: ?>
                        <span class="empty-value">No description</span>
                    <?php endif; ?>
                </div>
                <div class="compare-col">
                    <h6>OpenLibrary</h6>
                    <?php if ($olDescription): ?>
                        <div class="desc-text"><?= htmlspecialchars($olDescription) ?></div>
                    <?php else: ?>
                        <span class="empty-value">No description</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Subjects ─────────────────────────────────────────────────────── -->
        <div class="field-block">
            <div class="field-label">
                <input type="checkbox" name="transfer_subjects" value="1" class="transfer-check"
                    <?= $subjectsDiffer ? 'checked' : '' ?> <?= (empty($localGenres)) ? 'disabled' : '' ?>>
                Subjects / Genres
                <?php if ($subjectsDiffer): ?><span class="diff-badge">differs</span><?php endif; ?>
            </div>
            <div class="compare-grid">
                <div class="compare-col">
                    <h6>Your Library</h6>
                    <?php if ($localGenres): ?>
                        <span class="small"><?= htmlspecialchars(implode(', ', $localGenres)) ?></span>
                    <?php else: ?>
                        <span class="empty-value">No genres</span>
                    <?php endif; ?>
                </div>
                <div class="compare-col">
                    <h6>OpenLibrary</h6>
                    <?php if ($olSubjects): ?>
                        <span class="small"><?= htmlspecialchars(implode(', ', $olSubjects)) ?></span>
                    <?php else: ?>
                        <span class="empty-value">No subjects</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Series ───────────────────────────────────────────────────────── -->
        <div class="field-block">
            <div class="field-label">
                <input type="checkbox" name="transfer_series" value="1" class="transfer-check"
                    <?= ($seriesDiffers && $canTransferSeries) ? 'checked' : '' ?>
                    <?= (!$canTransferSeries) ? 'disabled' : '' ?>>
                Series
                <?php if ($seriesDiffers && $canTransferSeries): ?><span class="diff-badge">differs</span><?php endif; ?>
            </div>
            <div class="compare-grid">
                <div class="compare-col">
                    <h6>Your Library</h6>
                    <?php if ($localSeriesName): ?>
                        <?= htmlspecialchars($localSeriesName) ?>
                        <?php if ($localSeriesPos !== ''): ?>
                            <span class="text-muted">#<?= htmlspecialchars($localSeriesPos) ?></span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="empty-value">No series</span>
                    <?php endif; ?>
                </div>
                <div class="compare-col">
                    <h6>OpenLibrary</h6>
                    <?php if ($olSeriesName): ?>
                        <?= htmlspecialchars($olSeriesName) ?>
                        <?php if ($olSeriesPos !== ''): ?>
                            <span class="text-muted">#<?= htmlspecialchars($olSeriesPos) ?></span>
                        <?php endif; ?>
                        <?php if ($olSeriesKey): ?>
                            <span class="series-key">(<?= htmlspecialchars($olSeriesKey) ?>)</span>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($olEditionSeries): ?>
                        <?php if ($olSeriesName): ?><br><?php endif; ?>
                        <span class="text-muted small">Editions: <?= htmlspecialchars(implode('; ', $olEditionSeries)) ?></span>
                    <?php endif; ?>
                    <?php if (!$olSeriesName && !$olEditionSeries): ?>
                        <span class="empty-value">No series on OL</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Cover ────────────────────────────────────────────────────────── -->
        <div class="field-block">
            <div class="field-label">
                <input type="checkbox" name="transfer_cover" value="1" class="transfer-check"
                    <?= $coverDiffers ? 'checked' : '' ?> <?= (!$localCoverUrl) ? 'disabled' : '' ?>>
                Cover
                <?php if ($coverDiffers): ?><span class="diff-badge">OL has no cover</span><?php endif; ?>
            </div>
            <div class="compare-grid">
                <div class="compare-col">
                    <h6>Your Library</h6>
                    <?php if ($localCoverUrl): ?>
                        <img src="<?= htmlspecialchars($localCoverUrl) ?>" class="cover-img" alt="Local cover">
                    <?php else: ?>
                        <span class="empty-value">No cover</span>
                    <?php endif; ?>
                </div>
                <div class="compare-col">
                    <h6>OpenLibrary</h6>
                    <?php if ($olCoverUrl): ?>
                        <img src="<?= htmlspecialchars($olCoverUrl) ?>" class="cover-img" alt="OL cover">
                    <?php else: ?>
                        <span class="empty-value">No cover on OL</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-arrow-right me-1"></i>Review Transfer
            </button>
            <a href="/book.php?id=<?= $bookId ?>" class="btn btn-outline-secondary">Cancel</a>
        </div>

    </form>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
</body>
</html>
