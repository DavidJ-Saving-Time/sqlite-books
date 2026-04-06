<?php
require_once '../db.php';
require_once '../cache.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /list_books.php');
    exit;
}

$bookId      = (int)($_POST['book_id'] ?? 0);
$olid        = preg_replace('/[^A-Za-z0-9]/', '', $_POST['olid'] ?? '');
$doDesc      = !empty($_POST['transfer_description']);
$doSubj      = !empty($_POST['transfer_subjects']);
$doCover     = !empty($_POST['transfer_cover']);
$doSeries    = !empty($_POST['transfer_series']);
$seriesKey   = preg_replace('/[^A-Za-z0-9]/', '', $_POST['transfer_series_key'] ?? '');

if (!$bookId || !$olid) { http_response_code(400); die('Invalid request'); }
if (!$doDesc && !$doSubj && !$doCover && !$doSeries) {
    die('Nothing selected to transfer. <a href="javascript:history.back()">Go back</a>');
}

$pdo = getDatabaseConnection();

// ── Fetch local data ───────────────────────────────────────────────────────
$stmt = $pdo->prepare(
    "SELECT b.id, b.title, b.path, b.has_cover, b.series_index,
            c.text AS description,
            s.name AS series_name
     FROM books b
     LEFT JOIN comments c ON c.book = b.id
     LEFT JOIN books_series_link bsl ON bsl.book = b.id
     LEFT JOIN series s ON s.id = bsl.series
     WHERE b.id = ?"
);
$stmt->execute([$bookId]);
$book = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$book) { http_response_code(404); die('Book not found'); }

$localDescription = trim(strip_tags($book['description'] ?? ''));
$localSeriesName  = $book['series_name'] ?? '';
$localSeriesIdx   = $book['series_index'] !== null ? (float)$book['series_index'] : null;
$localSeriesPos   = ($localSeriesIdx !== null)
    ? (($localSeriesIdx == floor($localSeriesIdx)) ? (string)(int)$localSeriesIdx : (string)$localSeriesIdx)
    : '';
$localCoverUrl    = $book['has_cover']
    ? (getLibraryWebPath() . '/' . $book['path'] . '/cover.jpg')
    : '';
$localCoverPath   = $book['has_cover']
    ? (getLibraryPath() . '/' . $book['path'] . '/cover.jpg')
    : '';

$genreColId     = getCustomColumnId($pdo, 'genre');
$genreLinkTable = "books_custom_column_{$genreColId}_link";
$gStmt = $pdo->prepare(
    "SELECT gv.value FROM custom_column_{$genreColId} gv
     JOIN {$genreLinkTable} gl ON gl.value = gv.id
     WHERE gl.book = ?"
);
$gStmt->execute([$bookId]);
$localGenres = $gStmt->fetchAll(PDO::FETCH_COLUMN);

// ── Fetch current OL Work ──────────────────────────────────────────────────
$ch = curl_init("https://openlibrary.org/works/{$olid}.json");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERAGENT      => 'calibre-nilla/1.0',
    CURLOPT_TIMEOUT        => 10,
]);
$olJson   = curl_exec($ch);
$olStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($olStatus !== 200 || !$olJson) {
    die("Failed to re-fetch OL data (HTTP {$olStatus})");
}
$olWork = json_decode($olJson, true);

$d = $olWork['description'] ?? '';
$olDescription = is_array($d) ? ($d['value'] ?? '') : (string)$d;
$olSubjects    = $olWork['subjects'] ?? [];
$olCoverId     = $olWork['covers'][0] ?? null;
$olCoverUrl    = $olCoverId ? "https://covers.openlibrary.org/b/id/{$olCoverId}-L.jpg" : '';

// Resolve series key to a name for display
$seriesDisplayName = $seriesKey;
if ($seriesKey) {
    $ch = curl_init("https://openlibrary.org/series/{$seriesKey}.json");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_USERAGENT => 'calibre-nilla/1.0', CURLOPT_TIMEOUT => 6]);
    $sBody = curl_exec($ch); $sStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($sStatus === 200 && $sBody) {
        $sData = json_decode($sBody, true);
        $seriesDisplayName = $sData['name'] ?? $seriesKey;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Review Transfer — <?= htmlspecialchars($book['title']) ?></title>
    <link rel="stylesheet" href="/theme.css.php">
    <link rel="stylesheet" href="/css/all.min.css" crossorigin="anonymous">
    <style>
        .change-block {
            border: 1px solid var(--bs-border-color);
            border-radius: 0.5rem;
            margin-bottom: 1.25rem;
            overflow: hidden;
        }
        .change-header {
            background: var(--bs-secondary-bg);
            padding: 0.5rem 1rem;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            color: var(--bs-secondary-color);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .change-body { padding: 1rem; }
        .change-row {
            display: grid;
            grid-template-columns: 90px 1fr;
            gap: 0.5rem 1rem;
            font-size: 0.85rem;
            margin-bottom: 0.5rem;
        }
        .change-label { color: var(--bs-secondary-color); font-size: 0.75rem; padding-top: 0.1rem; }
        .change-value { white-space: pre-wrap; }
        .arrow-col { text-align: center; color: var(--bs-secondary-color); padding: 0.5rem 0; }
        .subject-list { display: flex; flex-wrap: wrap; gap: 0.3rem; }
        .subject-pill { font-size: 0.75rem; padding: 0.2rem 0.5rem; border-radius: 999px; background: var(--bs-secondary-bg); }
        .subject-pill.new { background: #d1e7dd; color: #0a3622; }
        .cover-row { display: flex; align-items: flex-start; gap: 1.5rem; }
        .cover-img { max-width: 120px; border-radius: 0.25rem; }
        .was-label { font-size: 0.7rem; color: var(--bs-secondary-color); margin-bottom: 0.2rem; }
    </style>
</head>
<body class="pt-5 bg-light">
<?php include '../navbar.php'; ?>
<div class="container my-4">

    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="/list_books.php">Library</a></li>
            <li class="breadcrumb-item"><a href="/book.php?id=<?= $bookId ?>"><?= htmlspecialchars($book['title']) ?></a></li>
            <li class="breadcrumb-item"><a href="index.php?book_id=<?= $bookId ?>">OL Transfer</a></li>
            <li class="breadcrumb-item active">Review</li>
        </ol>
    </nav>

    <h2 class="mb-1"><?= htmlspecialchars($book['title']) ?></h2>
    <p class="text-muted mb-4">
        Review the changes below before pushing to
        <a href="https://openlibrary.org/works/<?= htmlspecialchars($olid) ?>" target="_blank"><?= htmlspecialchars($olid) ?></a>.
    </p>

    <?php if ($doDesc): ?>
    <div class="change-block">
        <div class="change-header"><i class="fa-solid fa-align-left"></i> Description</div>
        <div class="change-body">
            <?php if ($olDescription): ?>
            <div class="change-row">
                <span class="change-label">Currently on OL</span>
                <span class="change-value text-muted"><?= htmlspecialchars($olDescription) ?></span>
            </div>
            <div class="arrow-col"><i class="fa-solid fa-arrow-down"></i></div>
            <?php endif; ?>
            <div class="change-row">
                <span class="change-label">Will become</span>
                <span class="change-value"><?= htmlspecialchars($localDescription) ?></span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($doSubj): ?>
    <div class="change-block">
        <div class="change-header"><i class="fa-solid fa-tags"></i> Subjects</div>
        <div class="change-body">
            <?php if ($olSubjects): ?>
            <div class="change-row">
                <span class="change-label">Currently on OL</span>
                <span>
                    <div class="subject-list">
                        <?php foreach ($olSubjects as $s): ?>
                            <span class="subject-pill"><?= htmlspecialchars($s) ?></span>
                        <?php endforeach; ?>
                    </div>
                </span>
            </div>
            <div class="arrow-col"><i class="fa-solid fa-arrow-down"></i></div>
            <?php endif; ?>
            <div class="change-row">
                <span class="change-label">Will become</span>
                <span>
                    <div class="subject-list">
                        <?php foreach ($localGenres as $g): ?>
                            <?php $isNew = !in_array($g, $olSubjects, true); ?>
                            <span class="subject-pill <?= $isNew ? 'new' : '' ?>"><?= htmlspecialchars($g) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php if (array_diff($olSubjects, $localGenres)): ?>
                    <div class="mt-2 small text-muted">
                        <i class="fa-solid fa-triangle-exclamation me-1"></i>
                        These existing OL subjects will be replaced (not merged):
                        <?= htmlspecialchars(implode(', ', array_diff($olSubjects, $localGenres))) ?>
                    </div>
                    <?php endif; ?>
                </span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($doSeries && $localSeriesName && $seriesKey): ?>
    <div class="change-block">
        <div class="change-header"><i class="fa-solid fa-list-ol"></i> Series</div>
        <div class="change-body">
            <div class="change-row">
                <span class="change-label">Will become</span>
                <span>
                    <strong><?= htmlspecialchars($seriesDisplayName) ?></strong>
                    <?php if ($localSeriesPos !== ''): ?>
                        #<?= htmlspecialchars($localSeriesPos) ?>
                    <?php endif; ?>
                    <span class="text-muted small ms-2">(<?= htmlspecialchars($seriesKey) ?>)</span>
                </span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($doCover && $localCoverUrl): ?>
    <div class="change-block">
        <div class="change-header"><i class="fa-solid fa-image"></i> Cover</div>
        <div class="change-body">
            <div class="cover-row">
                <?php if ($olCoverUrl): ?>
                <div>
                    <div class="was-label">Currently on OL</div>
                    <img src="<?= htmlspecialchars($olCoverUrl) ?>" class="cover-img" alt="OL cover">
                </div>
                <div class="arrow-col align-self-center"><i class="fa-solid fa-arrow-right fa-lg"></i></div>
                <?php endif; ?>
                <div>
                    <div class="was-label">Will upload</div>
                    <img src="<?= htmlspecialchars($localCoverUrl) ?>" class="cover-img" alt="Local cover">
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <form action="do_transfer.php" method="post">
        <input type="hidden" name="book_id"              value="<?= $bookId ?>">
        <input type="hidden" name="olid"                 value="<?= htmlspecialchars($olid) ?>">
        <input type="hidden" name="transfer_description" value="<?= $doDesc   ? '1' : '0' ?>">
        <input type="hidden" name="transfer_subjects"    value="<?= $doSubj   ? '1' : '0' ?>">
        <input type="hidden" name="transfer_cover"       value="<?= $doCover  ? '1' : '0' ?>">
        <input type="hidden" name="transfer_series"      value="<?= $doSeries ? '1' : '0' ?>">
        <input type="hidden" name="local_description"    value="<?= htmlspecialchars($localDescription) ?>">
        <input type="hidden" name="local_subjects"       value="<?= htmlspecialchars(implode('|', $localGenres)) ?>">
        <input type="hidden" name="local_cover_path"     value="<?= htmlspecialchars($localCoverPath) ?>">
        <input type="hidden" name="local_series_key"     value="<?= htmlspecialchars($seriesKey) ?>">
        <input type="hidden" name="local_series_pos"     value="<?= htmlspecialchars($localSeriesPos) ?>">

        <div class="d-flex gap-2 mt-3">
            <button type="submit" class="btn btn-success">
                <i class="fa-solid fa-upload me-1"></i>Confirm &amp; Transfer to OpenLibrary
            </button>
            <a href="index.php?book_id=<?= $bookId ?>" class="btn btn-outline-secondary">Back</a>
        </div>
    </form>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
</body>
</html>
