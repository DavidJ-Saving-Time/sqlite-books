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
    <link id="themeStylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
<link rel="stylesheet" href="/css/all.min.css" crossorigin="anonymous">
    <script src="js/theme.js"></script>
</head>
<body class="pt-5 bg-light">
<?php include 'navbar_other.php'; ?>
<div class="container my-4">
    <h1>Authors</h1>
    <?php if (empty($authors)): ?>
        <p class="text-muted">No authors found.</p>
    <?php else: ?>
       <ul class="list-group">
    <?php foreach ($authors as $a): ?>
        <?php $seriesList = $a['series_list'] !== null && $a['series_list'] !== '' ? explode('|', $a['series_list']) : []; ?>
        <li class="list-group-item d-flex justify-content-between align-items-start">
            <div class="flex-grow-1">
                <a href="list_books.php?author_id=<?= (int)$a['id'] ?>" class="fs-4 fw-semibold text-primary text-decoration-none">
                    <i class="fa-duotone fa-regular fa-user"></i> <?= htmlspecialchars($a['name']) ?>
                </a>
                <div class="">
                    <i class="fa-duotone fa-regular fa-books"></i> <?= (int)$a['book_count'] ?> book<?= ((int)$a['book_count'] === 1) ? '' : 's' ?>
                </div>
<?php if (!empty($seriesList)): ?>
    <div class="mt-2">
        Series:
        <ul class="list-unstyled mb-0 ms-3">
            <?php foreach ($seriesList as $s): ?>
                <?php list($sid, $sname) = explode(':', $s, 2); ?>
                <li class="d-flex align-items-start">
                    <i class="fa-duotone fa-solid fa-arrow-turn-down-right me-2 mt-1 text-secondary"></i>
                    <a href="list_books.php?series_id=<?= (int)$sid ?>" class="">
                        <?= htmlspecialchars($sname) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
            </div>
<div class="d-flex flex-column align-items-stretch ms-3" style="min-width: 11rem;">
    <div class="bg-light border rounded p-2 d-flex flex-column gap-2">
        <select class="form-select form-select-sm author-genre" data-author-id="<?= (int)$a['id'] ?>">
            <option value="">Set genre...</option>
            <?php foreach ($genres as $g): ?>
                <option value="<?= htmlspecialchars($g['value']) ?>"><?= htmlspecialchars($g['value']) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-select form-select-sm author-status" data-author-id="<?= (int)$a['id'] ?>">
            <option value="">Set status...</option>
            <?php foreach ($statuses as $s): ?>
                <option value="<?= htmlspecialchars($s['value']) ?>"><?= htmlspecialchars($s['value']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="button" class="btn btn-sm btn-outline-danger delete-author" data-author-id="<?= (int)$a['id'] ?>" title="Delete author">
            <i class="fa-solid fa-trash"></i> Delete
        </button>
    </div>
</div>
        </li>
    <?php endforeach; ?>
</ul>

<style>
.list-group-item:hover {
    background-color: #f8f9fa;
}
</style>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="js/authors.js"></script>
</body>
</html>
