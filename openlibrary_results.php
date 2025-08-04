<?php
require_once 'db.php';
requireLogin();
require_once 'metadata/metadata_sources.php';

$search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
$sort = $_GET['sort'] ?? 'author_series';
$source = 'openlibrary';
$books = [];
if ($search !== '') {
    $books = search_openlibrary($search);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Open Library Results</title>
    <link id="themeStylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <script src="js/theme.js"></script>
    <script src="js/search.js"></script>
</head>
<body class="pt-5">
<?php include "navbar_other.php"; ?>
<div class="container my-4">
    <h1 class="mb-4">Open Library Results</h1>
    <table class="table table-striped">
        <thead>
            <tr>
                <th>Cover</th>
                <th class="title-col">Title</th>
                <th>Author(s)</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($books as $book): ?>
            <tr>
                <td>
                    <?php if (!empty($book['cover_id'])): ?>
                        <img src="https://covers.openlibrary.org/b/id/<?= htmlspecialchars($book['cover_id']) ?>-S.jpg" alt="Cover" class="img-thumbnail" style="width: 150px; height: auto;">
                    <?php else: ?>
                        &mdash;
                    <?php endif; ?>
                </td>
                <td class="title-col">
                    <a href="openlibrary_view.php?key=<?= urlencode($book['key']) ?>&title=<?= urlencode($book['title']) ?>&authors=<?= urlencode($book['authors']) ?>&cover_id=<?= urlencode((string)$book['cover_id']) ?>">
                        <?= htmlspecialchars($book['title']) ?>
                    </a>
                    <?php if (!empty($book['description'])): ?>
                        <?php
                            $desc = $book['description'];
                            if (mb_strlen($desc) > 200) {
                                $desc = mb_substr($desc, 0, 200) . '...';
                            }
                        ?>
                        <div class="text-muted small mt-1"><?= nl2br(htmlspecialchars($desc)) ?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($book['authors'] !== ''): ?>
                        <?php
                            $parts = array_map('trim', explode(',', $book['authors']));
                            $display = implode(', ', array_slice($parts, 0, 3));
                            if (count($parts) > 3) {
                                $display .= '...';
                            }
                            echo htmlspecialchars($display);
                        ?>
                    <?php else: ?>
                        &mdash;
                    <?php endif; ?>
                </td>
                <td>
                    <?php $coverUrl = !empty($book['cover_id']) ? 'https://covers.openlibrary.org/b/id/' . urlencode($book['cover_id']) . '-L.jpg' : ''; ?>
                    <button type="button" class="btn btn-sm btn-primary ol-add"
                            data-title="<?= htmlspecialchars($book['title'], ENT_QUOTES) ?>"
                            data-authors="<?= htmlspecialchars($book['authors'], ENT_QUOTES) ?>"
                            data-thumbnail="<?= htmlspecialchars($coverUrl, ENT_QUOTES) ?>"
                            data-description="<?= htmlspecialchars($book['description'], ENT_QUOTES) ?>">
                        Add to Library
                    </button>
                    <span class="ol-add-result ms-1"></span>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="js/openlibrary_results.js"></script>
</body>
</html>
