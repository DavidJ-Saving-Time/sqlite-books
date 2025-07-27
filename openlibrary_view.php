<?php
require_once 'db.php';
require_once 'openlibrary.php';

$key = isset($_GET['key']) ? trim((string)$_GET['key']) : '';
$title = isset($_GET['title']) ? (string)$_GET['title'] : '';
$authors = isset($_GET['authors']) ? (string)$_GET['authors'] : '';
$coverId = isset($_GET['cover_id']) ? (string)$_GET['cover_id'] : '';

$work = [];
if ($key !== '') {
    $work = get_openlibrary_work($key);
}

$workTitle = $work['title'] ?? $title;
$description = $work['description'] ?? '';
$subjects = $work['subjects'] ?? [];
$covers = $work['covers'] ?? [];
if (!$coverId && !empty($covers)) {
    $coverId = (string)($covers[0]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($workTitle) ?></title>
    <link id="themeStylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <script src="theme.js"></script>
</head>
<body>
<?php include "navbar.php"; ?>
<div class="container my-4">
    <a href="javascript:history.back()" class="btn btn-secondary mb-3">Back</a>
    <h1 class="mb-4"><?= htmlspecialchars($workTitle) ?></h1>
    <div class="row mb-4">
        <div class="col-md-3">
            <?php if ($coverId): ?>
                <img src="https://covers.openlibrary.org/b/id/<?= htmlspecialchars($coverId) ?>-L.jpg" class="img-fluid" alt="Cover">
            <?php endif; ?>
        </div>
        <div class="col-md-9">
            <?php if ($authors !== ''): ?>
                <p><strong>Author(s):</strong> <?= htmlspecialchars($authors) ?></p>
            <?php endif; ?>
            <?php if ($description !== ''): ?>
                <p><?= nl2br(htmlspecialchars($description)) ?></p>
            <?php endif; ?>
            <?php if (!empty($subjects)): ?>
                <p><strong>Subjects:</strong> <?= htmlspecialchars(implode(', ', $subjects)) ?></p>
            <?php endif; ?>
            <button id="addBtn" type="button" class="btn btn-primary mt-3"
                    data-title="<?= htmlspecialchars($workTitle, ENT_QUOTES) ?>"
                    data-authors="<?= htmlspecialchars($authors, ENT_QUOTES) ?>"
                    data-imgurl="<?= htmlspecialchars($coverId ? 'https://covers.openlibrary.org/b/id/' . $coverId . '-L.jpg' : '', ENT_QUOTES) ?>"
                    data-description="<?= htmlspecialchars($description, ENT_QUOTES) ?>">
                Add to Library
            </button>
            <div id="addResult" class="mt-2"></div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script>
document.getElementById('addBtn').addEventListener('click', function () {
    const title = this.dataset.title;
    const authors = this.dataset.authors;
    const imgurl = this.dataset.imgurl || '';
    const description = this.dataset.description || '';
    const params = new URLSearchParams({title: title, authors: authors, imgurl: imgurl, description: description});
    const resultEl = document.getElementById('addResult');
    resultEl.textContent = 'Adding...';
    fetch('add_metadata_book.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: params
    })
    .then(resp => resp.json())
    .then(data => {
        if (data.status === 'ok') {
            resultEl.textContent = 'Book added to library';
        } else {
            resultEl.textContent = data.error || 'Error adding book';
        }
    })
    .catch(() => {
        resultEl.textContent = 'Error adding book';
    });
});
</script>
</body>
</html>
