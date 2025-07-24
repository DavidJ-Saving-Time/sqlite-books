<?php
require_once 'db.php';

$pdo = getDatabaseConnection();

// Ensure shelf table and custom column exist
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS shelves (name TEXT PRIMARY KEY)");
    foreach (['Physical','Ebook Calibre','PDFs'] as $def) {
        $pdo->prepare('INSERT OR IGNORE INTO shelves (name) VALUES (?)')->execute([$def]);
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS books_custom_column_11 (book INTEGER PRIMARY KEY REFERENCES books(id) ON DELETE CASCADE, value TEXT)");
    $pdo->exec("INSERT INTO books_custom_column_11 (book, value)
            SELECT id, 'Ebook Calibre' FROM books
            WHERE id NOT IN (SELECT book FROM books_custom_column_11)");
} catch (PDOException $e) {
    // Ignore errors if the table cannot be created
}

// Fetch shelves list
$shelfList = [];
try {
    $shelfList = $pdo->query('SELECT name FROM shelves ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $shelfList = ['Ebook Calibre','Physical','PDFs'];
}
$shelfName = isset($_GET['shelf']) ? trim((string)$_GET['shelf']) : '';
if ($shelfName !== '' && !in_array($shelfName, $shelfList, true)) {
    $shelfName = '';
}

// Locate custom column for reading status
$statusTable = null;
$statusOptions = [];
$statusIsLink = false;
$statusId = null;
try {
    $stmt = $pdo->prepare("SELECT id FROM custom_columns WHERE label = 'status'");
    $stmt->execute();
    $statusId = $stmt->fetchColumn();
    if ($statusId !== false) {
        $base = 'books_custom_column_' . (int)$statusId;
        $direct = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . $base . "'")->fetchColumn();
        $link = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . $base . "_link'")->fetchColumn();
        if ($link) {
            // Enumerated column stored via link table
            $statusTable = $base . '_link';
            $statusIsLink = true;
            $valueTable = 'custom_column_' . (int)$statusId;
            $pdo->prepare("INSERT OR IGNORE INTO $valueTable (value) VALUES ('Want to Read')")->execute();
            $defaultId = $pdo->query("SELECT id FROM $valueTable WHERE value = 'Want to Read'")->fetchColumn();
            $pdo->exec("INSERT INTO $statusTable (book, value)
                        SELECT id, $defaultId FROM books
                        WHERE id NOT IN (SELECT book FROM $statusTable)");
            $statusOptions = $pdo->query("SELECT value FROM $valueTable ORDER BY value COLLATE NOCASE")->fetchAll(PDO::FETCH_COLUMN);
        } else {
            // Text column stored directly
            $statusTable = $base;
            $pdo->exec("CREATE TABLE IF NOT EXISTS $statusTable (book INTEGER PRIMARY KEY REFERENCES books(id) ON DELETE CASCADE, value TEXT)");
            $pdo->exec("INSERT INTO $statusTable (book, value)
                        SELECT id, 'Want to Read' FROM books
                        WHERE id NOT IN (SELECT book FROM $statusTable)");
            $statusOptions = $pdo->query("SELECT DISTINCT value FROM $statusTable WHERE TRIM(COALESCE(value,'')) <> '' ORDER BY value")->fetchAll(PDO::FETCH_COLUMN);
        }
    }
} catch (PDOException $e) {
    $statusTable = null;
    $statusOptions = [];
    $statusIsLink = false;
}

// Check if the recommendations table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS books_custom_column_11 (book INTEGER PRIMARY KEY REFERENCES books(id) ON DELETE CASCADE, value TEXT)");
    $pdo->exec("INSERT INTO books_custom_column_11 (book, value)\n            SELECT id, 'Ebook Calibre' FROM books\n            WHERE id NOT IN (SELECT book FROM books_custom_column_11)");
} catch (PDOException $e) {
    // Ignore errors if the table cannot be created
}

// Check if the recommendations table exists
$recColumnExists = false;
try {
    $check = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='books_custom_column_10'");
    if ($check->fetch()) {
        $recColumnExists = true;
    }
} catch (PDOException $e) {
    $recColumnExists = false;
}

$perPage = 20;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$isAjax = isset($_GET['ajax']) && $_GET['ajax'] === '1';
$sort = $_GET['sort'] ?? 'author_series';
$authorId = isset($_GET['author_id']) ? (int)$_GET['author_id'] : null;
$seriesId = isset($_GET['series_id']) ? (int)$_GET['series_id'] : null;
$genreId = isset($_GET['genre_id']) ? (int)$_GET['genre_id'] : null;
$statusName = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
if ($statusName !== '' && !in_array($statusName, $statusOptions, true)) {
    $statusName = '';
}
$search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
$source = $_GET['source'] ?? 'local';
$allowedSorts = ['title', 'author', 'series', 'author_series', 'recommended'];
if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'author_series';
}
$recommendedOnly = ($sort === 'recommended');

$orderByMap = [
    'title' => 'b.title',
    'author' => 'authors, b.title',
    'series' => 'series, b.series_index, b.title',
    'author_series' => 'authors, series, b.series_index, b.title',
    'recommended' => 'authors, series, b.series_index, b.title'
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
    $whereClauses[] = 'b.id IN (SELECT book FROM books_custom_column_2_link WHERE value = :genre_id)';
    $params[':genre_id'] = $genreId;
}
if ($shelfName !== '') {
    $whereClauses[] = 'b.id IN (SELECT book FROM books_custom_column_11 WHERE value = :shelf_name)';
    $params[':shelf_name'] = $shelfName;
}
if ($statusName !== '' && $statusTable) {
    if ($statusIsLink) {
        $stmt = $pdo->prepare('SELECT id FROM custom_column_' . (int)$statusId . ' WHERE value = :v');
        $stmt->execute([':v' => $statusName]);
        $sid = $stmt->fetchColumn();
        if ($sid !== false) {
            $whereClauses[] = 'EXISTS (SELECT 1 FROM ' . $statusTable . ' WHERE book = b.id AND value = :status_val)';
            $params[':status_val'] = $sid;
        } else {
            $whereClauses[] = '0';
        }
    } else {
        $whereClauses[] = 'b.id IN (SELECT book FROM ' . $statusTable . ' WHERE value = :status_val)';
        $params[':status_val'] = $statusName;
    }
}
if ($recommendedOnly) {
    $whereClauses[] = "EXISTS (SELECT 1 FROM books_custom_column_10 br WHERE br.book = b.id AND TRIM(COALESCE(br.value, '')) <> '')";
}
if ($search !== '') {
    $whereClauses[] = '(b.title LIKE :search OR EXISTS (
            SELECT 1 FROM books_authors_link bal
            JOIN authors a ON bal.author = a.id
            WHERE bal.book = b.id AND a.name LIKE :search))';
    $params[':search'] = '%' . $search . '%';
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
    $stmt = $pdo->prepare('SELECT value FROM custom_column_2 WHERE id = ?');
    $stmt->execute([$genreId]);
    $filterGenreName = $stmt->fetchColumn();
}
$filterStatusName = $statusName !== '' ? $statusName : null;
$filterShelfName = $shelfName !== '' ? $shelfName : null;

// Fetch full genre list for sidebar
$genreList = [];
try {
    $stmt = $pdo->query('SELECT id, value FROM custom_column_2 ORDER BY value');
    $genreList = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $genreList = [];
}

$books = [];
if ($source === 'openlibrary' && $search !== '') {
    require_once 'openlibrary.php';
    $books = search_openlibrary($search);
    $totalBooks = count($books);
    $totalPages = 1;
} else {
    try {
        $totalSql = "SELECT COUNT(*) FROM books b $where";
        $totalStmt = $pdo->prepare($totalSql);
        foreach ($params as $key => $val) {
            $type = is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $totalStmt->bindValue($key, $val, $type);
        }
        $totalStmt->execute();
        $totalBooks = (int)$totalStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;

        $selectFields = "b.id, b.title, b.path, b.has_cover, b.series_index,
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
                       (SELECT GROUP_CONCAT(c.value, ', ')
                            FROM books_custom_column_2_link bcc
                            JOIN custom_column_2 c ON bcc.value = c.id
                            WHERE bcc.book = b.id) AS genres,
                       (SELECT GROUP_CONCAT(c.id || ':' || c.value, '|')
                            FROM books_custom_column_2_link bcc
                            JOIN custom_column_2 c ON bcc.value = c.id
                            WHERE bcc.book = b.id) AS genre_data,
                       bc11.value AS shelf";
        if ($statusTable) {
            if ($statusIsLink) {
                $selectFields .= ", scv.value AS status";
            } else {
                $selectFields .= ", sc.value AS status";
            }
        }
        if ($recColumnExists) {
            $selectFields .= ", EXISTS(SELECT 1 FROM books_custom_column_10 br WHERE br.book = b.id AND TRIM(COALESCE(br.value, '')) <> '') AS has_recs";
        }

        $sql = "SELECT $selectFields
                FROM books b
                LEFT JOIN books_series_link bsl ON bsl.book = b.id
                LEFT JOIN series s ON bsl.series = s.id
                LEFT JOIN books_custom_column_11 bc11 ON bc11.book = b.id";
        if ($statusTable) {
            if ($statusIsLink) {
                $sql .= " LEFT JOIN $statusTable sc ON sc.book = b.id LEFT JOIN custom_column_" . (int)$statusId . " scv ON sc.value = scv.id";
            } else {
                $sql .= " LEFT JOIN $statusTable sc ON sc.book = b.id";
            }
        }
        $sql .= " $where
                ORDER BY {$orderBy}
                LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $val) {
            $type = is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($key, $val, $type);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $books = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$recColumnExists) {
            foreach ($books as &$b) {
                $b['has_recs'] = 0;
            }
            unset($b);
        }
        if (!$statusTable) {
            foreach ($books as &$b) {
                $b['status'] = null;
            }
            unset($b);
        }
    } catch (PDOException $e) {
        die('Query failed: ' . $e->getMessage());
    }

    $totalPages = max(1, ceil($totalBooks / $perPage));
}
$baseUrl = '?sort=' . urlencode($sort);
if ($source !== '') {
    $baseUrl .= '&source=' . urlencode($source);
}
if ($authorId) {
    $baseUrl .= '&author_id=' . urlencode((string)$authorId);
}
if ($seriesId) {
    $baseUrl .= '&series_id=' . urlencode((string)$seriesId);
}
if ($genreId) {
    $baseUrl .= '&genre_id=' . urlencode((string)$genreId);
}
if ($shelfName !== '') {
    $baseUrl .= '&shelf=' . urlencode($shelfName);
}
if ($statusName !== '') {
    $baseUrl .= '&status=' . urlencode($statusName);
}
if ($search !== '') {
    $baseUrl .= '&search=' . urlencode($search);
}
$baseUrl .= '&page=';

function render_book_rows(array $books, array $shelfList, array $statusOptions, string $source, string $sort, ?int $authorId, ?int $seriesId): void {
    foreach ($books as $book) {
        if ($source === 'openlibrary') {
            ?>
            <tr>
                <td>
                    <?php if (!empty($book['cover_id'])): ?>
                        <img src="https://covers.openlibrary.org/b/id/<?= htmlspecialchars($book['cover_id']) ?>-S.jpg" alt="Cover" class="img-thumbnail" style="width: 50px; height: auto;">
                    <?php else: ?>
                        &mdash;
                    <?php endif; ?>
                </td>
                <td>
                    <a href="openlibrary_view.php?key=<?= urlencode($book['key']) ?>&title=<?= urlencode($book['title']) ?>&authors=<?= urlencode($book['authors']) ?>&cover_id=<?= urlencode((string)$book['cover_id']) ?>">
                        <?= htmlspecialchars($book['title']) ?>
                    </a>
                </td>
                <td>&mdash;</td>
                <td><?= $book['authors'] !== '' ? htmlspecialchars($book['authors']) : '&mdash;' ?></td>
                <td>&mdash;</td>
                <td>&mdash;</td>
                <td>&mdash;</td>
            </tr>
            <?php
        } else {
            ?>
            <tr>
                <td>
                    <?php if (!empty($book['has_cover'])): ?>
                        <a href="view_book.php?id=<?= urlencode($book['id']) ?>">
                            <img src="ebooks/<?= htmlspecialchars($book['path']) ?>/cover.jpg" alt="Cover" class="img-thumbnail" style="width: 50px; height: auto;">
                        </a>
                    <?php else: ?>
                        &mdash;
                    <?php endif; ?>
                </td>
                <td>
                    <?= htmlspecialchars($book['title']) ?>
                    <?php if (!empty($book['has_recs'])): ?>
                        <span class="text-success ms-1">&#10003;</span>
                    <?php endif; ?>
                    <?php if (!empty($book['series'])): ?>
                        <br>
                        <small>
                            <a href="list_books.php?sort=<?= urlencode($sort) ?>&series_id=<?= urlencode($book['series_id']) ?>">
                                <?= htmlspecialchars($book['series']) ?>
                            </a>
                            <?php if ($book['series_index'] !== null && $book['series_index'] !== ''): ?>
                                (<?= htmlspecialchars($book['series_index']) ?>)
                            <?php endif; ?>
                        </small>
                    <?php endif; ?>
                </td>
                <td>
                    <select class="form-select form-select-sm status-select" data-book-id="<?= htmlspecialchars($book['id']) ?>">
                        <option value="Want to Read"<?= ($book['status'] === null || $book['status'] === '') ? ' selected' : '' ?>>Want to Read</option>
                        <?php foreach ($statusOptions as $s): ?>
                            <?php if ($s === 'Want to Read') continue; ?>
                            <option value="<?= htmlspecialchars($s) ?>"<?= $book['status'] === $s ? ' selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                        <?php endforeach; ?>
                        <?php if ($book['status'] !== null && $book['status'] !== '' && !in_array($book['status'], $statusOptions, true)): ?>
                            <option value="<?= htmlspecialchars($book['status']) ?>" selected><?= htmlspecialchars($book['status']) ?></option>
                        <?php endif; ?>
                    </select>
                </td>
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
                    <select class="form-select form-select-sm shelf-select" data-book-id="<?= htmlspecialchars($book['id']) ?>">
                        <?php foreach ($shelfList as $s): ?>
                            <option value="<?= htmlspecialchars($s) ?>"<?= $book['shelf'] === $s ? ' selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td>
                    <a class="btn btn-sm btn-primary" href="edit_book.php?id=<?= urlencode($book['id']) ?>">View / Edit</a>
                </td>
            </tr>
            <?php
        }
    }
}

if ($isAjax) {
    render_book_rows($books, $shelfList, $statusOptions, $source, $sort, $authorId, $seriesId);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Book List</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css" crossorigin="anonymous">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js" crossorigin="anonymous"></script>
</head>
<body>
<div class="container-fluid my-4">
    <div class="row">
        <nav class="col-md-3 col-lg-2 mb-3">
            <button class="btn btn-outline-secondary d-md-none mb-3" type="button" data-bs-toggle="collapse" data-bs-target="#genreSidebar" aria-controls="genreSidebar" aria-expanded="false" aria-label="Toggle genres">
                Genres
            </button>
            <div id="genreSidebar" class="collapse d-md-block">
                <div class="mb-3">
                    <h6 class="mb-1">Shelves</h6>
                    <?php
                        $shelfUrlBase = 'list_books.php?sort=' . urlencode($sort);
                        if ($authorId) $shelfUrlBase .= '&author_id=' . urlencode((string)$authorId);
                        if ($seriesId) $shelfUrlBase .= '&series_id=' . urlencode((string)$seriesId);
                        if ($genreId) $shelfUrlBase .= '&genre_id=' . urlencode((string)$genreId);
                        if ($search !== '') $shelfUrlBase .= '&search=' . urlencode($search);
                        if ($source !== '') $shelfUrlBase .= '&source=' . urlencode($source);
                        if ($statusName !== '') $shelfUrlBase .= '&status=' . urlencode($statusName);
                    ?>
                    <ul class="list-group" id="shelfList">
                        <li class="list-group-item<?= $shelfName === '' ? ' active' : '' ?>">
                            <a href="<?= htmlspecialchars($shelfUrlBase) ?>" class="text-decoration-none<?= $shelfName === '' ? ' text-white' : '' ?>">All Shelves</a>
                        </li>
                        <?php foreach ($shelfList as $s): ?>
                            <?php $url = $shelfUrlBase . '&shelf=' . urlencode($s); ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center<?= $shelfName === $s ? ' active' : '' ?>">
                                <a href="<?= htmlspecialchars($url) ?>" class="flex-grow-1 me-2 text-decoration-none<?= $shelfName === $s ? ' text-white' : '' ?>"><?= htmlspecialchars($s) ?></a>
                                <div class="btn-group btn-group-sm" role="group">
                                    <button type="button" class="btn btn-outline-secondary edit-shelf" data-shelf="<?= htmlspecialchars($s) ?>">E</button>
                                    <button type="button" class="btn btn-outline-danger delete-shelf" data-shelf="<?= htmlspecialchars($s) ?>">&times;</button>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <form id="addShelfForm" class="mt-2">
                        <div class="input-group input-group-sm">
                            <input type="text" class="form-control" name="shelf" placeholder="New shelf">
                            <button class="btn btn-primary" type="submit">Add</button>
                        </div>
                    </form>
                </div>
                <div class="mb-3">
                    <h6 class="mb-1">Status</h6>
                    <?php
                        $statusUrlBase = 'list_books.php?sort=' . urlencode($sort);
                        if ($authorId) $statusUrlBase .= '&author_id=' . urlencode((string)$authorId);
                        if ($seriesId) $statusUrlBase .= '&series_id=' . urlencode((string)$seriesId);
                        if ($genreId) $statusUrlBase .= '&genre_id=' . urlencode((string)$genreId);
                        if ($shelfName !== '') $statusUrlBase .= '&shelf=' . urlencode($shelfName);
                        if ($search !== '') $statusUrlBase .= '&search=' . urlencode($search);
                        if ($source !== '') $statusUrlBase .= '&source=' . urlencode($source);
                    ?>
                    <ul class="list-group" id="statusList">
                        <li class="list-group-item<?= $statusName === '' ? ' active' : '' ?>">
                            <a href="<?= htmlspecialchars($statusUrlBase) ?>" class="text-decoration-none<?= $statusName === '' ? ' text-white' : '' ?>">All Status</a>
                        </li>
                        <?php foreach ($statusOptions as $s): ?>
                            <?php $url = $statusUrlBase . '&status=' . urlencode($s); ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center<?= $statusName === $s ? ' active' : '' ?>">
                                <a href="<?= htmlspecialchars($url) ?>" class="flex-grow-1 me-2 text-decoration-none<?= $statusName === $s ? ' text-white' : '' ?>"><?= htmlspecialchars($s) ?></a>
                                <div class="btn-group btn-group-sm" role="group">
                                    <button type="button" class="btn btn-outline-secondary edit-status" data-status="<?= htmlspecialchars($s) ?>">E</button>
                                    <button type="button" class="btn btn-outline-danger delete-status" data-status="<?= htmlspecialchars($s) ?>">&times;</button>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <form id="addStatusForm" class="mt-2">
                        <div class="input-group input-group-sm">
                            <input type="text" class="form-control" name="status" placeholder="New status">
                            <button class="btn btn-primary" type="submit">Add</button>
                        </div>
                    </form>
                </div>
                <div class="list-group">
                    <?php
                        $genreBase = 'list_books.php?sort=' . urlencode($sort);
                        if ($authorId) $genreBase .= '&author_id=' . urlencode((string)$authorId);
                        if ($seriesId) $genreBase .= '&series_id=' . urlencode((string)$seriesId);
                        if ($shelfName !== '') $genreBase .= '&shelf=' . urlencode($shelfName);
                        if ($search !== '') $genreBase .= '&search=' . urlencode($search);
                        if ($source !== '') $genreBase .= '&source=' . urlencode($source);
                        if ($statusName !== '') $genreBase .= '&status=' . urlencode($statusName);
                    ?>
                    <a href="<?= htmlspecialchars($genreBase) ?>" class="list-group-item list-group-item-action<?= $genreId ? '' : ' active' ?>">All Genres</a>
                    <?php foreach ($genreList as $g): ?>
                        <?php
                            $url = 'list_books.php?sort=' . urlencode($sort);
                            if ($authorId) $url .= '&author_id=' . urlencode((string)$authorId);
                            if ($seriesId) $url .= '&series_id=' . urlencode((string)$seriesId);
                            $url .= '&genre_id=' . urlencode((string)$g['id']);
                            if ($shelfName !== '') $url .= '&shelf=' . urlencode($shelfName);
                            if ($search !== '') $url .= '&search=' . urlencode($search);
                            if ($source !== '') $url .= '&source=' . urlencode($source);
                            if ($statusName !== '') $url .= '&status=' . urlencode($statusName);
                        ?>
                        <a href="<?= htmlspecialchars($url) ?>" class="list-group-item list-group-item-action<?= $genreId == $g['id'] ? ' active' : '' ?>">
                            <?= htmlspecialchars($g['value']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </nav>
        <div class="col-md-9 col-lg-10">
            <h1 class="mb-4">Books</h1>
    <form method="get" class="mb-3">
        <input type="hidden" name="page" value="1">
        <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
        <?php if ($authorId): ?>
            <input type="hidden" name="author_id" value="<?= htmlspecialchars($authorId) ?>">
        <?php endif; ?>
        <?php if ($seriesId): ?>
            <input type="hidden" name="series_id" value="<?= htmlspecialchars($seriesId) ?>">
        <?php endif; ?>
        <?php if ($genreId): ?>
            <input type="hidden" name="genre_id" value="<?= htmlspecialchars($genreId) ?>">
        <?php endif; ?>
        <?php if ($shelfName !== ''): ?>
            <input type="hidden" name="shelf" value="<?= htmlspecialchars($shelfName) ?>">
        <?php endif; ?>
        <div class="input-group">
            <input type="text" class="form-control" name="search" placeholder="Search by title or author" value="<?= htmlspecialchars($search) ?>">
            <select name="source" class="form-select" style="max-width: 12rem;">
                <option value="local"<?= $source === 'local' ? ' selected' : '' ?>>Local</option>
                <option value="openlibrary"<?= $source === 'openlibrary' ? ' selected' : '' ?>>Open Library</option>
            </select>
            <button class="btn btn-outline-secondary" type="submit">Search</button>
        </div>
    </form>
    <?php if ($filterAuthorName || $filterSeriesName || $filterGenreName || $filterShelfName || $filterStatusName || $search !== ''): ?>
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
            <?php if ($filterShelfName): ?>
                <?php if ($filterAuthorName || $filterSeriesName || $filterGenreName): ?>,
                <?php endif; ?>
                shelf: <?= htmlspecialchars($filterShelfName) ?>
            <?php endif; ?>
            <?php if ($filterStatusName): ?>
                <?php if ($filterAuthorName || $filterSeriesName || $filterGenreName || $filterShelfName): ?>,
                <?php endif; ?>
                status: <?= htmlspecialchars($filterStatusName) ?>
            <?php endif; ?>
            <?php if ($search !== ''): ?>
                <?php if ($filterAuthorName || $filterSeriesName || $filterGenreName): ?>,
                <?php endif; ?>
                search: "<?= htmlspecialchars($search) ?>"
            <?php endif; ?>
            <?php if ($recommendedOnly): ?>
                <?php if ($filterAuthorName || $filterSeriesName || $filterGenreName || $search !== ''): ?>,
                <?php endif; ?>
                recommended only
            <?php endif; ?>
            <a class="btn btn-sm btn-secondary ms-2" href="list_books.php?sort=<?= urlencode($sort) ?>">Clear</a>
        </div>
    <?php endif; ?>
    <form method="get" class="row g-2 mb-3 align-items-center">
        <input type="hidden" name="page" value="1">
        <input type="hidden" name="source" value="<?= htmlspecialchars($source) ?>">
        <?php if ($search !== ''): ?>
            <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
        <?php endif; ?>
        <?php if ($authorId): ?>
            <input type="hidden" name="author_id" value="<?= htmlspecialchars($authorId) ?>">
        <?php endif; ?>
        <?php if ($seriesId): ?>
            <input type="hidden" name="series_id" value="<?= htmlspecialchars($seriesId) ?>">
        <?php endif; ?>
        <?php if ($genreId): ?>
            <input type="hidden" name="genre_id" value="<?= htmlspecialchars($genreId) ?>">
        <?php endif; ?>
        <?php if ($shelfName !== ''): ?>
            <input type="hidden" name="shelf" value="<?= htmlspecialchars($shelfName) ?>">
        <?php endif; ?>
        <?php if ($statusName !== ''): ?>
            <input type="hidden" name="status" value="<?= htmlspecialchars($statusName) ?>">
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
                <option value="recommended"<?= $sort === 'recommended' ? ' selected' : '' ?>>Recommended Only</option>
            </select>
        </div>
    </form>
    <table class="table table-striped">
        <thead>
            <tr>
                <th>Cover</th>
                <th>Title</th>
                <th>Status</th>
                <th>Author(s)</th>
                <th>Genre</th>
                <th>Shelf</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php render_book_rows($books, $shelfList, $statusOptions, $source, $sort, $authorId, $seriesId); ?>
        </tbody>
    </table>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script>
$(function() {
    var $searchInput = $('input[name="search"]');
    $searchInput.autocomplete({
        source: function(request, response) {
            $.getJSON('author_autocomplete.php', { term: request.term }, function(data) {
                response(data);
            });
        },
        minLength: 2
    });

    var currentPage = <?= $page ?>;
    var totalPages = <?= $totalPages ?>;
    var loading = false;
    var fetchUrlBase = <?= json_encode($baseUrl) ?>;
    var $tbody = $('table tbody');

    function loadMore() {
        if (loading || currentPage >= totalPages) return;
        loading = true;
        $.get(fetchUrlBase + (currentPage + 1) + '&ajax=1', function(html) {
            $tbody.append(html);
            currentPage++;
            loading = false;
        });
    }

    $(document).on('change', '.shelf-select', function() {
        var bookId = $(this).data('book-id');
        var value = $(this).val();
        fetch('update_shelf.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ book_id: bookId, value: value })
        });
    });

    $(document).on('change', '.status-select', function() {
        var bookId = $(this).data('book-id');
        var value = $(this).val();
        fetch('update_status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ book_id: bookId, value: value })
        });
    });

    $('#addShelfForm').on('submit', function(e) {
        e.preventDefault();
        var shelf = $(this).find('input[name="shelf"]').val().trim();
        if (!shelf) return;
        fetch('add_shelf.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ shelf: shelf })
        }).then(function() { location.reload(); });
    });

    $('#addStatusForm').on('submit', function(e) {
        e.preventDefault();
        var status = $(this).find('input[name="status"]').val().trim();
        if (!status) return;
        fetch('add_status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ status: status })
        }).then(function() { location.reload(); });
    });

    $(document).on('click', '.delete-shelf', function() {
        if (!confirm('Are you sure you want to remove this shelf?')) return;
        var shelf = $(this).data('shelf');
        fetch('delete_shelf.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ shelf: shelf })
        }).then(function() { location.reload(); });
    });

    $(document).on('click', '.edit-shelf', function() {
        var shelf = $(this).data('shelf');
        var name = prompt('Rename shelf:', shelf);
        if (name === null) return;
        name = name.trim();
        if (!name || name === shelf) return;
        fetch('rename_shelf.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ shelf: shelf, new: name })
        }).then(function() { location.reload(); });
    });

    $(document).on('click', '.delete-status', function() {
        if (!confirm('Are you sure you want to remove this status?')) return;
        var status = $(this).data('status');
        fetch('delete_status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ status: status })
        }).then(function() { location.reload(); });
    });

    $(document).on('click', '.edit-status', function() {
        var status = $(this).data('status');
        var name = prompt('Rename status:', status);
        if (name === null) return;
        name = name.trim();
        if (!name || name === status) return;
        fetch('rename_status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ status: status, new: name })
        }).then(function() { location.reload(); });
    });

    $(window).on('scroll', function() {
        if ($(window).scrollTop() + $(window).height() >= $(document).height() - 100) {
            loadMore();
        }
    });
});
</script>
</body>
</html>

