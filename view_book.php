<?php
require_once 'db.php';

$pdo = getDatabaseConnection();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    die('Invalid book ID');
}

$sort = $_GET['sort'] ?? 'title';

$stmt = $pdo->prepare("SELECT b.*, 
        (SELECT GROUP_CONCAT(a.name, ', ')
            FROM books_authors_link bal
            JOIN authors a ON bal.author = a.id
            WHERE bal.book = b.id) AS authors,
        (SELECT GROUP_CONCAT(a.id || ':' || a.name, '|')
            FROM books_authors_link bal
            JOIN authors a ON bal.author = a.id
            WHERE bal.book = b.id) AS author_data,
        s.id AS series_id,
        s.name AS series
    FROM books b
    LEFT JOIN books_series_link bsl ON bsl.book = b.id
    LEFT JOIN series s ON bsl.series = s.id
    WHERE b.id = :id");
$stmt->bindValue(':id', $id, PDO::PARAM_INT);
$stmt->execute();
$book = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$book) {
    die('Book not found');
}

$commentStmt = $pdo->prepare('SELECT text FROM comments WHERE book = ?');
$commentStmt->execute([$id]);
$comment = $commentStmt->fetchColumn();

$tagsStmt = $pdo->prepare("SELECT GROUP_CONCAT(t.name, ', ')
    FROM books_tags_link btl
    JOIN tags t ON btl.tag = t.id
    WHERE btl.book = ?");
$tagsStmt->execute([$id]);
$tags = $tagsStmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($book['title']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
</head>
<body>
<div class="container my-4">
    <a href="list_books.php" class="btn btn-secondary mb-3">Back to list</a>
    <h1 class="mb-4"><?= htmlspecialchars($book['title']) ?></h1>
    <button type="button" id="recommendBtn" data-authors="<?= htmlspecialchars($book['authors']) ?>" data-title="<?= htmlspecialchars($book['title']) ?>" class="btn btn-primary mb-4">Get Book Recommendations</button>
    <div class="row mb-4">
        <div class="col-md-3">
            <?php if (!empty($book['has_cover'])): ?>
                <img src="ebooks/<?= htmlspecialchars($book['path']) ?>/cover.jpg" alt="Cover" class="img-fluid">
            <?php else: ?>
                <div class="text-muted">No cover</div>
            <?php endif; ?>
        </div>
        <div class="col-md-9">
            <p><strong>Author(s):</strong>
                <?php if (!empty($book['author_data'])): ?>
                    <?php
                        $links = [];
                        foreach (explode('|', $book['author_data']) as $pair) {
                            list($aid, $aname) = explode(':', $pair, 2);
                            $url = 'list_books.php?sort=' . urlencode($sort) . '&author_id=' . urlencode($aid);
                            $links[] = '<a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($aname) . '</a>';
                        }
                        echo implode(', ', $links);
                    ?>
                <?php else: ?>
                    &mdash;
                <?php endif; ?>
            </p>
            <p><strong>Series:</strong>
                <?php if (!empty($book['series'])): ?>
                    <a href="list_books.php?sort=<?= urlencode($sort) ?>&series_id=<?= urlencode($book['series_id']) ?>">
                        <?= htmlspecialchars($book['series']) ?>
                    </a>
                    <?php if ($book['series_index'] !== null && $book['series_index'] !== ''): ?>
                        (<?= htmlspecialchars($book['series_index']) ?>)
                    <?php endif; ?>
                <?php else: ?>
                    &mdash;
                <?php endif; ?>
            </p>
            <?php if (!empty($tags)): ?>
                <p><strong>Tags:</strong> <?= htmlspecialchars($tags) ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php if (!empty($comment)): ?>
        <div class="mb-4">
            <h2>Description</h2>
            <p><?= nl2br(htmlspecialchars($comment)) ?></p>
        </div>
<?php endif; ?>
    <h2>Metadata</h2>
    <table class="table table-bordered">
        <tr><th>ID</th><td><?= htmlspecialchars($book['id']) ?></td></tr>
        <tr><th>Title</th><td><?= htmlspecialchars($book['title']) ?></td></tr>
        <tr><th>Sort</th><td><?= htmlspecialchars($book['sort']) ?></td></tr>
        <tr><th>Timestamp</th><td><?= htmlspecialchars($book['timestamp']) ?></td></tr>
        <tr><th>Pubdate</th><td><?= htmlspecialchars($book['pubdate']) ?></td></tr>
        <tr><th>Author Sort</th><td><?= htmlspecialchars($book['author_sort']) ?></td></tr>
        <tr><th>ISBN</th><td><?= htmlspecialchars($book['isbn']) ?></td></tr>
        <tr><th>LCCN</th><td><?= htmlspecialchars($book['lccn']) ?></td></tr>
        <tr><th>Path</th><td><?= htmlspecialchars($book['path']) ?></td></tr>
        <tr><th>Flags</th><td><?= htmlspecialchars($book['flags']) ?></td></tr>
        <tr><th>UUID</th><td><?= htmlspecialchars($book['uuid']) ?></td></tr>
        <tr><th>Has Cover</th><td><?= htmlspecialchars($book['has_cover']) ?></td></tr>
        <tr><th>Last Modified</th><td><?= htmlspecialchars($book['last_modified']) ?></td></tr>
    </table>

    <!-- Recommendations Modal -->
    <div class="modal fade" id="recommendModal" tabindex="-1" aria-labelledby="recommendModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="recommendModalLabel">Book Recommendations</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="recommendLoading" class="my-4 text-center" style="display: none;">
                        <div class="spinner-border" role="status" aria-hidden="true"></div>
                    </div>
                    <div id="recommendContent" style="white-space: pre-wrap;"></div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script>
const recommendBtn = document.getElementById('recommendBtn');
const recommendContent = document.getElementById('recommendContent');
const recommendLoading = document.getElementById('recommendLoading');
const recommendModalEl = document.getElementById('recommendModal');
const recommendModal = new bootstrap.Modal(recommendModalEl);

recommendBtn.addEventListener('click', function () {
    const authors = this.dataset.authors;
    const title = this.dataset.title;
    recommendContent.textContent = '';
    recommendLoading.style.display = 'block';
    recommendModal.show();

    fetch('recommend.php?authors=' + encodeURIComponent(authors) + '&title=' + encodeURIComponent(title))
        .then(resp => resp.json())
        .then(data => {
            recommendLoading.style.display = 'none';
            recommendContent.textContent = data.output || data.error || '';
        })
        .catch(() => {
            recommendLoading.style.display = 'none';
            recommendContent.textContent = 'Error fetching recommendations';
        });
});
</script>
</body>
</html>
