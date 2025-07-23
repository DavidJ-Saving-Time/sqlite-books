<?php
require_once 'db.php';

$pdo = getDatabaseConnection();

try {
    $stmt = $pdo->query('SELECT id, title FROM books ORDER BY title');
    $books = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('Query failed: ' . $e->getMessage());
}
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
                <th>Title</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($books as $book): ?>
            <tr>
                <td><?= htmlspecialchars($book['id']) ?></td>
                <td><?= htmlspecialchars($book['title']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" integrity="sha384-q2by60N2ZYo/wURp6YkAJvJZopFNL+7kkC5jQmDR96dzWWFXOkR9702gGcmBtd4k" crossorigin="anonymous"></script>
</body>
</html>
