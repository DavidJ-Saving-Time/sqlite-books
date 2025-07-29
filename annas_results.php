<?php
require_once 'db.php';
requireLogin();
require_once 'annas_archive.php';

$search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
$sort = $_GET['sort'] ?? 'author_series';
$source = 'annas';
$books = [];
if ($search !== '') {
    $books = search_annas_archive($search);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Anna's Archive Results</title>
    <link id="themeStylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <script src="js/theme.js"></script>
    <script src="js/search.js"></script>
</head>
<body class="pt-5">
<?php include "navbar.php"; ?>
<div class="container my-4">
    <h1 class="mb-4">Anna's Archive Results</h1>
    <table class="table table-striped">
        <thead>
            <tr>
                <th>Cover</th>
                <th class="title-col">Title</th>
                <th>Author(s)</th>
                <th>Genre</th>
                <th>Year</th>
                <th>Size</th>
                <th>Actions</th>
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
                <td class="title-col">
                    <?php if (!empty($book['md5'])): ?>
                        <a href="https://annas-archive.org/md5/<?= urlencode($book['md5']) ?>" target="_blank">
                            <?= htmlspecialchars($book['title']) ?>
                        </a>
                    <?php else: ?>
                        <?= htmlspecialchars($book['title']) ?>
                    <?php endif; ?>
                </td>
                <td><?= $book['author'] !== '' ? htmlspecialchars($book['author']) : '&mdash;' ?></td>
                <td><?= $book['genre'] !== '' ? htmlspecialchars($book['genre']) : '&mdash;' ?></td>
                <td><?= $book['year'] !== '' ? htmlspecialchars($book['year']) : '&mdash;' ?></td>
                <td><?= $book['size'] !== '' ? htmlspecialchars($book['size']) : '&mdash;' ?></td>
                <td>
                    <?php if (!empty($book['md5'])): ?>
                        <button type="button" class="btn btn-sm btn-success annas-download" data-md5="<?= htmlspecialchars($book['md5']) ?>">
                            Download<?php if (!empty($book['format'])): ?> <?= htmlspecialchars(strtoupper($book['format'])) ?><?php endif; ?>
                        </button>
                    <?php else: ?>
                        &mdash;
                    <?php endif; ?>
                    <button type="button" class="btn btn-sm btn-primary ms-1 annas-add"
                            data-title="<?= htmlspecialchars($book['title'], ENT_QUOTES) ?>"
                            data-authors="<?= htmlspecialchars($book['author'], ENT_QUOTES) ?>"
                            data-thumbnail="<?= htmlspecialchars($book['imgUrl'], ENT_QUOTES) ?>"
                            data-description="">
                        Add to Library
                    </button>
                    <span class="annas-add-result ms-1"></span>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="js/annas_results.js"></script>
</body>
</html>
