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

// Fetch saved recommendations from custom column 10, if present
try {
    $recStmt = $pdo->prepare('SELECT value FROM books_custom_column_10 WHERE book = ?');
    $recStmt->execute([$id]);
    $savedRecommendations = $recStmt->fetchColumn();
} catch (PDOException $e) {
    // Table may not exist in some databases
    $savedRecommendations = null;
}
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
    <h1 class="mb-0"><?= htmlspecialchars($book['title']) ?></h1>
    <?php
        $formattedPubdate = '';
        if (!empty($book['pubdate'])) {
            try {
                $dt = new DateTime($book['pubdate']);
                $formattedPubdate = $dt->format('jS \of F Y');
            } catch (Exception $e) {
                $formattedPubdate = htmlspecialchars($book['pubdate']);
            }
        }
    ?>
    <p class="mb-4">
        <?php if (!empty($book['isbn'])): ?>
            <strong>ISBN:</strong> <?= htmlspecialchars($book['isbn']) ?><br>
        <?php endif; ?>
        <?php if ($formattedPubdate): ?>
            <strong>Published:</strong> <?= htmlspecialchars($formattedPubdate) ?>
        <?php endif; ?>
    </p>
    <button type="button" id="recommendBtn" data-book-id="<?= htmlspecialchars($book['id']) ?>" data-authors="<?= htmlspecialchars($book['authors']) ?>" data-title="<?= htmlspecialchars($book['title']) ?>" class="btn btn-primary mb-4">Get Book Recommendations</button>
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
            <div id="recommendSection">
                <?php if (!empty($savedRecommendations)): ?>
                    <p><strong>Recommendations:</strong> <?= nl2br(htmlspecialchars($savedRecommendations)) ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php if (!empty($comment)): ?>
        <div class="mb-4">
            <h2>Description</h2>
            <p><?= nl2br(htmlspecialchars($comment)) ?></p>
        </div>

<?php endif; ?>

</div>
<script>
const recommendBtn = document.getElementById('recommendBtn');
const recommendSection = document.getElementById('recommendSection');

recommendBtn.addEventListener('click', function () {
    const bookId = this.dataset.bookId;
    const authors = this.dataset.authors;
    const title = this.dataset.title;
    recommendSection.textContent = 'Loading...';

    fetch('recommend.php?book_id=' + encodeURIComponent(bookId) +
        '&authors=' + encodeURIComponent(authors) + '&title=' + encodeURIComponent(title))
        .then(resp => resp.json())
        .then(data => {
            if (data.output) {
                recommendSection.innerHTML = '<p><strong>Recommendations:</strong> ' +
                    data.output.replace(/\n/g, '<br>') + '</p>';
            } else {
                recommendSection.textContent = data.error || '';
            }
        })
        .catch(() => {
            recommendSection.textContent = 'Error fetching recommendations';
        });
});
</script>
</body>
</html>
