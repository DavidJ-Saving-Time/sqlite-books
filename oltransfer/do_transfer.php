<?php
require_once '../db.php';
require_once 'config.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /list_books.php');
    exit;
}

$bookId      = (int)($_POST['book_id'] ?? 0);
$olid        = preg_replace('/[^A-Za-z0-9]/', '', $_POST['olid'] ?? '');
$doDesc      = ($_POST['transfer_description'] ?? '0') === '1';
$doSubj      = ($_POST['transfer_subjects']    ?? '0') === '1';
$doCover     = ($_POST['transfer_cover']       ?? '0') === '1';
$doSeries    = ($_POST['transfer_series']      ?? '0') === '1';
$description = $_POST['local_description'] ?? '';
$subjects    = $_POST['local_subjects']    ?? '';
$coverPath   = $_POST['local_cover_path']  ?? '';
$seriesKey   = preg_replace('/[^A-Za-z0-9]/', '', $_POST['local_series_key'] ?? '');
$seriesPos   = $_POST['local_series_pos'] ?? '';

if (!$bookId || !$olid) { http_response_code(400); die('Invalid request'); }

// Validate cover path is inside the library (prevent path traversal)
$libraryPath = getLibraryPath();
$realCover   = $coverPath ? realpath($coverPath) : false;
if ($doCover && $realCover && str_starts_with($realCover, $libraryPath)) {
    $safeCoverPath = $realCover;
} else {
    $safeCoverPath = '';
    $doCover = false;
}

if (!OL_SESSION_COOKIE && (!OL_ACCESS || !OL_SECRET)) {
    die('No OpenLibrary credentials configured. Edit oltransfer/config.php — add your browser session cookie or S3 keys.');
}

// ── Build command ──────────────────────────────────────────────────────────
$python = __DIR__ . '/venv/bin/python3';
$script = __DIR__ . '/transfer.py';

$cmd = [escapeshellarg($python), escapeshellarg($script), '--olid', escapeshellarg($olid)];

if (OL_SESSION_COOKIE) {
    $cmd[] = '--session-cookie';
    $cmd[] = escapeshellarg(OL_SESSION_COOKIE);
} elseif (OL_ACCESS && OL_SECRET) {
    $cmd[] = '--access';
    $cmd[] = escapeshellarg(OL_ACCESS);
    $cmd[] = '--secret';
    $cmd[] = escapeshellarg(OL_SECRET);
}

if ($doDesc && $description !== '') {
    $cmd[] = '--description';
    $cmd[] = escapeshellarg($description);
}
if ($doSubj && $subjects !== '') {
    $cmd[] = '--subjects';
    $cmd[] = escapeshellarg($subjects);
}
if ($doSeries && $seriesKey !== '') {
    $cmd[] = '--series-key';
    $cmd[] = escapeshellarg($seriesKey);
    if ($seriesPos !== '') {
        $cmd[] = '--series-position';
        $cmd[] = escapeshellarg($seriesPos);
    }
}
if ($doCover && $safeCoverPath) {
    $cmd[] = '--cover';
    $cmd[] = escapeshellarg($safeCoverPath);
}

$cmdStr = implode(' ', $cmd) . ' 2>&1';
$output = shell_exec($cmdStr);
$result = json_decode($output, true);

$success = $result['ok'] ?? false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Transfer Result</title>
    <link rel="stylesheet" href="/theme.css.php">
    <link rel="stylesheet" href="/css/all.min.css" crossorigin="anonymous">
</head>
<body class="pt-5 bg-light">
<?php include '../navbar.php'; ?>
<div class="container my-4" style="max-width:640px">

    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="/list_books.php">Library</a></li>
            <li class="breadcrumb-item"><a href="/book.php?id=<?= $bookId ?>">Book</a></li>
            <li class="breadcrumb-item active">Transfer Result</li>
        </ol>
    </nav>

    <?php if ($success): ?>
    <div class="alert alert-success">
        <i class="fa-solid fa-circle-check fa-lg me-2"></i>
        <strong>Transfer successful.</strong>
    </div>
    <ul class="list-group mb-4">
        <?php foreach (($result['changes'] ?? []) as $c): ?>
        <li class="list-group-item">
            <i class="fa-solid fa-check text-success me-2"></i><?= htmlspecialchars(ucfirst($c)) ?> updated
        </li>
        <?php endforeach; ?>
        <?php if ($result['cover_result'] ?? null): ?>
        <li class="list-group-item">
            <i class="fa-solid fa-image me-2"></i>Cover: <?= htmlspecialchars($result['cover_result']) ?>
        </li>
        <?php endif; ?>
    </ul>
    <a href="https://openlibrary.org/works/<?= htmlspecialchars($olid) ?>" target="_blank" class="btn btn-outline-primary me-2">
        <i class="fa-solid fa-arrow-up-right-from-square me-1"></i>View on OpenLibrary
    </a>
    <a href="/book.php?id=<?= $bookId ?>" class="btn btn-outline-secondary">Back to book</a>

    <?php else: ?>
    <div class="alert alert-danger">
        <i class="fa-solid fa-circle-xmark fa-lg me-2"></i>
        <strong>Transfer failed.</strong>
    </div>
    <pre class="bg-dark text-light p-3 rounded" style="font-size:.8rem"><?= htmlspecialchars($result['error'] ?? $output ?? 'Unknown error') ?></pre>
    <a href="index.php?book_id=<?= $bookId ?>" class="btn btn-outline-secondary mt-2">
        <i class="fa-solid fa-arrow-left me-1"></i>Back to compare
    </a>
    <?php endif; ?>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
</body>
</html>
