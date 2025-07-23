<?php
require_once 'db.php';

$pdo = getDatabaseConnection();

$perPage = 20;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

try {
    $totalStmt = $pdo->query('SELECT COUNT(*) FROM books');
    $totalBooks = (int)$totalStmt->fetchColumn();

    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare(
        'SELECT b.id, b.title, b.path, b.has_cover,
                COALESCE((SELECT GROUP_CONCAT(a.name, ", ")
                          FROM books_authors_link bal
                          JOIN authors a ON bal.author = a.id
                          WHERE bal.book = b.id), "") AS authors,
                (SELECT s.name FROM books_series_link bsl
                        JOIN series s ON bsl.series = s.id
                        WHERE bsl.book = b.id) AS series
         FROM books b
         ORDER BY b.title
         LIMIT :limit OFFSET :offset'
    );
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $books = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('Query failed: ' . $e->getMessage());
}

$totalPages = max(1, ceil($totalBooks / $perPage));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Book List</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-9ndCyUa6Yp8a0hEzj1FdknLE4YuKil60QBkk+2m1u+pFmmF57/kf5sH8mu+QK4w5" crossorigin="anonymous">
</head>
<body>
<div class="container my-4">
    <h1 class="mb-4">Books</h1>
    <table class="table table-striped">
        <thead>
            <tr>
                <th>ID</th>
                <th>Cover</th>
                <th>Title</th>
                <th>Author(s)</th>
                <th>Series</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($books as $book): ?>
            <tr>
                <td><?= htmlspecialchars($book['id']) ?></td>
                <td>
                    <?php if (!empty($book['has_cover'])): ?>
                        <img src="ebooks/<?= htmlspecialchars($book['path']) ?>/cover.jpg" alt="Cover" class="img-thumbnail" style="width: 50px; height: auto;">
                    <?php else: ?>
                        &mdash;
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($book['title']) ?></td>
                <td><?= htmlspecialchars($book['authors']) ?></td>
                <td><?= htmlspecialchars($book['series']) ?></td>
                <td>
                    <a class="btn btn-sm btn-primary" href="edit_book.php?id=<?= urlencode($book['id']) ?>">View / Edit</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <nav>
        <ul class="pagination justify-content-center">
            <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>">
                <a class="page-link" href="?page=1">First</a>
            </li>
            <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>">
                <a class="page-link" href="?page=<?= $page - 1 ?>">Previous</a>
            </li>

            <?php if ($page > 2): ?>
                <li class="page-item"><a class="page-link" href="?page=1">1</a></li>
                <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
            <?php endif; ?>

            <?php if ($page > 1): ?>
                <li class="page-item"><a class="page-link" href="?page=<?= $page - 1 ?>"><?= $page - 1 ?></a></li>
            <?php endif; ?>

            <li class="page-item active" aria-current="page"><span class="page-link"><?= $page ?></span></li>

            <?php if ($page < $totalPages): ?>
                <li class="page-item"><a class="page-link" href="?page=<?= $page + 1 ?>"><?= $page + 1 ?></a></li>
            <?php endif; ?>

            <?php if ($page < $totalPages - 1): ?>
                <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                <li class="page-item"><a class="page-link" href="?page=<?= $totalPages ?>"><?= $totalPages ?></a></li>
            <?php endif; ?>

            <li class="page-item<?= $page >= $totalPages ? ' disabled' : '' ?>">
                <a class="page-link" href="?page=<?= $page + 1 ?>">Next</a>
            </li>
            <li class="page-item<?= $page >= $totalPages ? ' disabled' : '' ?>">
                <a class="page-link" href="?page=<?= $totalPages ?>">Last</a>
            </li>
        </ul>
    </nav>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" integrity="sha384-q2by60N2ZYo/wURp6YkAJvJZopFNL+7kkC5jQmDR96dzWWFXOkR9702gGcmBtd4k" crossorigin="anonymous"></script>
</body>
</html>
