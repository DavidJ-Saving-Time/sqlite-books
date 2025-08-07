<?php
require_once 'db.php';
require_once 'cache.php';
requireLogin();

$pdo = getDatabaseConnection();
$authors = $pdo->query(
    "SELECT a.id, a.name, COUNT(DISTINCT bal.book) AS book_count,\n" .
    "       GROUP_CONCAT(DISTINCT s.id || ':' || s.name, '|') AS series_list\n" .
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous">
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
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <div class="flex-grow-1">
                        <a href="list_books.php?author_id=<?= (int)$a['id'] ?>">
                            <?= htmlspecialchars($a['name']) ?>
                        </a>
                        <div class="small text-muted">
                            <?= (int)$a['book_count'] ?> book<?= ((int)$a['book_count'] === 1) ? '' : 's' ?>
                            <?php if (!empty($seriesList)): ?>
                                — Series:
                                <?php foreach ($seriesList as $i => $s): ?>
                                    <?php list($sid, $sname) = explode(':', $s, 2); ?>
                                    <?php if ($i > 0) echo ', '; ?>
                                    <a href="list_books.php?series_id=<?= (int)$sid ?>" class="text-reset text-decoration-underline"><?= htmlspecialchars($sname) ?></a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <select class="form-select form-select-sm ms-2 author-genre" data-author-id="<?= (int)$a['id'] ?>" style="width: auto;">
                        <option value="">Set genre...</option>
                        <?php foreach ($genres as $g): ?>
                            <option value="<?= htmlspecialchars($g['value']) ?>"><?= htmlspecialchars($g['value']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select class="form-select form-select-sm ms-2 author-status" data-author-id="<?= (int)$a['id'] ?>" style="width: auto;">
                        <option value="">Set status...</option>
                        <?php foreach ($statuses as $s): ?>
                            <option value="<?= htmlspecialchars($s['value']) ?>"><?= htmlspecialchars($s['value']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-sm btn-danger ms-2 delete-author" data-author-id="<?= (int)$a['id'] ?>">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="js/authors.js"></script>
</body>
</html>
