<?php
require_once 'db.php';
requireLogin();
require_once 'google_books.php';

$search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
$sort = $_GET['sort'] ?? 'author_series';
$source = 'google';
$books = [];
if ($search !== '') {
    $books = search_google_books($search);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Google Books Results</title>
    <link id="themeStylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <script src="theme.js"></script>
</head>
<body class="pt-5">
<?php include "navbar.php"; ?>
<div class="container my-4">
    <h1 class="mb-4">Google Books Results</h1>
    <table class="table table-striped">
        <thead>
            <tr>
                <th>Cover</th>
                <th class="title-col">Title</th>
                <th>Author(s)</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($books as $book): ?>
            <tr>
                <td>
                    <?php if (!empty($book['imgUrl'])): ?>
                        <img src="<?= htmlspecialchars($book['imgUrl']) ?>" alt="Cover" class="img-thumbnail" style="width: 150px; height: auto;">
                    <?php else: ?>
                        &mdash;
                    <?php endif; ?>
                </td>
                <td class="title-col"><?= htmlspecialchars($book['title']) ?></td>
                <td><?= $book['author'] !== '' ? htmlspecialchars($book['author']) : '&mdash;' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
</body>
</html>
