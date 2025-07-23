<?php
require_once 'db.php';

$pdo = getDatabaseConnection();

$perPage = 20;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$sort = $_GET['sort'] ?? 'title';
$authorId = isset($_GET['author_id']) ? (int)$_GET['author_id'] : null;
$seriesId = isset($_GET['series_id']) ? (int)$_GET['series_id'] : null;
$genreId = isset($_GET['genre_id']) ? (int)$_GET['genre_id'] : null;
$allowedSorts = ['title', 'author', 'series', 'author_series'];
if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'title';
}

$orderByMap = [
    'title' => 'b.title',
    'author' => 'authors, b.title',
    'series' => 'series, b.series_index, b.title',
    'author_series' => 'authors, series, b.series_index, b.title'
];
$orderBy = $orderByMap[$sort];

$whereClauses = [];
$params = [];
if ($authorId) {
    $whereClauses[] = 'b.id IN (SELECT book FROM books_authors_link WHERE author = :author_id)';
    $params[':author_id'] = $authorId;
}
if ($seriesId) {
    $whereClauses[] = 'EXISTS (SELECT 1 FROM books_series_link WHERE book = b.id AND series = :series_id)';
    $params[':series_id'] = $seriesId;
}
if ($genreId) {
    $whereClauses[] = 'b.id IN (SELECT book FROM books_tags_link WHERE tag = :genre_id)';
    $params[':genre_id'] = $genreId;
}
$where = $whereClauses ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

// Names for filter display
$filterAuthorName = null;
if ($authorId) {
    $stmt = $pdo->prepare('SELECT name FROM authors WHERE id = ?');
    $stmt->execute([$authorId]);
    $filterAuthorName = $stmt->fetchColumn();
}
$filterSeriesName = null;
if ($seriesId) {
    $stmt = $pdo->prepare('SELECT name FROM series WHERE id = ?');
    $stmt->execute([$seriesId]);
    $filterSeriesName = $stmt->fetchColumn();
}
$filterGenreName = null;
if ($genreId) {
    $stmt = $pdo->prepare('SELECT name FROM tags WHERE id = ?');
    $stmt->execute([$genreId]);
    $filterGenreName = $stmt->fetchColumn();
}

try {
    $totalSql = "SELECT COUNT(*) FROM books b $where";
    $totalStmt = $pdo->prepare($totalSql);
    foreach ($params as $key => $val) {
        $totalStmt->bindValue($key, $val, PDO::PARAM_INT);
    }
    $totalStmt->execute();
    $totalBooks = (int)$totalStmt->fetchColumn();

    $offset = ($page - 1) * $perPage;

    $sql = "SELECT b.id, b.title, b.path, b.has_cover, b.series_index,
                   (SELECT GROUP_CONCAT(a.name, ', ')
                        FROM books_authors_link bal
                        JOIN authors a ON bal.author = a.id
                        WHERE bal.book = b.id) AS authors,
                   (SELECT GROUP_CONCAT(a.id || ':' || a.name, '|')
                        FROM books_authors_link bal
                        JOIN authors a ON bal.author = a.id
                        WHERE bal.book = b.id) AS author_data,
                   s.id AS series_id,
                   s.name AS series,
                   (SELECT GROUP_CONCAT(t.name, ', ')
                        FROM books_tags_link btl
                        JOIN tags t ON btl.tag = t.id
                        WHERE btl.book = b.id) AS genres,
                   (SELECT GROUP_CONCAT(t.id || ':' || t.name, '|')
                        FROM books_tags_link btl
                        JOIN tags t ON btl.tag = t.id
                        WHERE btl.book = b.id) AS genre_data
            FROM books b
            LEFT JOIN books_series_link bsl ON bsl.book = b.id
            LEFT JOIN series s ON bsl.series = s.id
            $where
            ORDER BY {$orderBy}
            LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val, PDO::PARAM_INT);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $books = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('Query failed: ' . $e->getMessage());
}

$totalPages = max(1, ceil($totalBooks / $perPage));
$baseUrl = '?sort=' . urlencode($sort);
if ($authorId) {
    $baseUrl .= '&author_id=' . urlencode((string)$authorId);
}
if ($seriesId) {
    $baseUrl .= '&series_id=' . urlencode((string)$seriesId);
}
if ($genreId) {
    $baseUrl .= '&genre_id=' . urlencode((string)$genreId);
}
$baseUrl .= '&page=';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Book List</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
</head>
<body>
<div class="container my-4">
    <h1 class="mb-4">Books</h1>
    <?php if ($filterAuthorName || $filterSeriesName || $filterGenreName): ?>
        <div class="alert alert-info mb-3">
            Showing
            <?php if ($filterAuthorName): ?>
                author: <?= htmlspecialchars($filterAuthorName) ?>
            <?php endif; ?>
            <?php if ($filterAuthorName && $filterSeriesName): ?>
                ,
            <?php endif; ?>
            <?php if ($filterSeriesName): ?>
                series: <?= htmlspecialchars($filterSeriesName) ?>
            <?php endif; ?>
            <?php if (($filterAuthorName || $filterSeriesName) && $filterGenreName): ?>
                ,
            <?php endif; ?>
            <?php if ($filterGenreName): ?>
                genre: <?= htmlspecialchars($filterGenreName) ?>
            <?php endif; ?>
            <a class="btn btn-sm btn-secondary ms-2" href="list_books.php?sort=<?= urlencode($sort) ?>">Clear</a>
        </div>
    <?php endif; ?>
    <form method="get" class="row g-2 mb-3 align-items-center">
        <input type="hidden" name="page" value="1">
        <?php if ($authorId): ?>
            <input type="hidden" name="author_id" value="<?= htmlspecialchars($authorId) ?>">
        <?php endif; ?>
        <?php if ($seriesId): ?>
            <input type="hidden" name="series_id" value="<?= htmlspecialchars($seriesId) ?>">
        <?php endif; ?>
        <?php if ($genreId): ?>
            <input type="hidden" name="genre_id" value="<?= htmlspecialchars($genreId) ?>">
        <?php endif; ?>
        <div class="col-auto">
            <label for="sort" class="col-form-label">Sort by:</label>
        </div>
        <div class="col-auto">
            <select id="sort" name="sort" class="form-select" onchange="this.form.submit()">
                <option value="title"<?= $sort === 'title' ? ' selected' : '' ?>>Title</option>
                <option value="author"<?= $sort === 'author' ? ' selected' : '' ?>>Author</option>
                <option value="series"<?= $sort === 'series' ? ' selected' : '' ?>>Series</option>
                <option value="author_series"<?= $sort === 'author_series' ? ' selected' : '' ?>>Author &amp; Series</option>
            </select>
        </div>
    </form>
    <table class="table table-striped">
        <thead>
            <tr>
                <th>ID</th>
                <th>Cover</th>
                <th>Title</th>
                <th>Author(s)</th>
                <th>Series (No.)</th>
                <th>Genre</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($books as $book): ?>
            <tr>
                <td><?= htmlspecialchars($book['id']) ?></td>
                <td>
                    <?php if (!empty($book['has_cover'])): ?>
                        <a href="view_book.php?id=<?= urlencode($book['id']) ?>">
                            <img src="ebooks/<?= htmlspecialchars($book['path']) ?>/cover.jpg" alt="Cover" class="img-thumbnail" style="width: 50px; height: auto;">
                        </a>
                    <?php else: ?>
                        &mdash;
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($book['title']) ?></td>
                <td>
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
                </td>
                <td>
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
                </td>
                <td>
                    <?php if (!empty($book['genre_data'])): ?>
                        <?php
                            $links = [];
                            foreach (explode('|', $book['genre_data']) as $pair) {
                                if ($pair === '') continue;
                                list($gid, $gname) = explode(':', $pair, 2);
                                $url = 'list_books.php?sort=' . urlencode($sort);
                                if ($authorId) $url .= '&author_id=' . urlencode((string)$authorId);
                                if ($seriesId) $url .= '&series_id=' . urlencode((string)$seriesId);
                                $url .= '&genre_id=' . urlencode($gid);
                                $links[] = '<a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($gname) . '</a>';
                            }
                            echo implode(', ', $links);
                        ?>
                    <?php else: ?>
                        &mdash;
                    <?php endif; ?>
                </td>
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
                <a class="page-link" href="<?= $baseUrl ?>1">First</a>
            </li>
            <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>">
                <a class="page-link" href="<?= $baseUrl . ($page - 1) ?>">Previous</a>
            </li>

            <?php if ($page > 2): ?>
                <li class="page-item"><a class="page-link" href="<?= $baseUrl ?>1">1</a></li>
                <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
            <?php endif; ?>

            <?php if ($page > 1): ?>
                <li class="page-item"><a class="page-link" href="<?= $baseUrl . ($page - 1) ?>"><?= $page - 1 ?></a></li>
            <?php endif; ?>

            <li class="page-item active" aria-current="page"><span class="page-link"><?= $page ?></span></li>

            <?php if ($page < $totalPages): ?>
                <li class="page-item"><a class="page-link" href="<?= $baseUrl . ($page + 1) ?>"><?= $page + 1 ?></a></li>
            <?php endif; ?>

            <?php if ($page < $totalPages - 1): ?>
                <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                <li class="page-item"><a class="page-link" href="<?= $baseUrl . $totalPages ?>"><?= $totalPages ?></a></li>
            <?php endif; ?>

            <li class="page-item<?= $page >= $totalPages ? ' disabled' : '' ?>">
                <a class="page-link" href="<?= $baseUrl . ($page + 1) ?>">Next</a>
            </li>
            <li class="page-item<?= $page >= $totalPages ? ' disabled' : '' ?>">
                <a class="page-link" href="<?= $baseUrl . $totalPages ?>">Last</a>
            </li>
        </ul>
    </nav>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
</body>
</html>
