<?php
require_once 'db.php';
requireLogin();

$pdo = getDatabaseConnection();
$shelfTable = null;

// Ensure shelf table and custom column exist
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS shelves (name TEXT PRIMARY KEY)");
    foreach (['Physical','Ebook Calibre','PDFs'] as $def) {
        $pdo->prepare('INSERT OR IGNORE INTO shelves (name) VALUES (?)')->execute([$def]);
    }

    $stmt = $pdo->prepare("SELECT id FROM custom_columns WHERE label = '#shelf'");
    $stmt->execute();
    $shelfId = $stmt->fetchColumn();
    if ($shelfId !== false) {
        $shelfTable = 'custom_column_' . (int)$shelfId;
        $pdo->exec("CREATE TABLE IF NOT EXISTS $shelfTable (book INTEGER PRIMARY KEY REFERENCES books(id) ON DELETE CASCADE, value TEXT)");
        $pdo->exec("INSERT INTO $shelfTable (book, value)
            SELECT id, 'Ebook Calibre' FROM books
            WHERE id NOT IN (SELECT book FROM $shelfTable)");
    }
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
    $stmt = $pdo->prepare("SELECT id FROM custom_columns WHERE label = '#status'");
    $stmt->execute();
    $statusId = $stmt->fetchColumn();
    if ($statusId !== false) {
        $base = 'custom_column_' . (int)$statusId;
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
$recColumnExists = false;
try {
    $stmt = $pdo->prepare("SELECT id FROM custom_columns WHERE label = '#recommendations'");
    $stmt->execute();
    $recId = $stmt->fetchColumn();
    if ($recId !== false) {
        $recTable = 'custom_column_' . (int)$recId;
        $check = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . $recTable . "'");
        if ($check->fetch()) {
            $recColumnExists = true;
        }
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
$redirectParams = $_GET;
unset($redirectParams['source']);
if ($source === 'openlibrary') {
    header('Location: openlibrary_results.php?' . http_build_query($redirectParams));
    exit;
} elseif ($source === 'google') {
    header('Location: google_results.php?' . http_build_query($redirectParams));
    exit;
} elseif ($source === 'annas') {
    header('Location: annas_results.php?' . http_build_query($redirectParams));
    exit;
}
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
if ($shelfName !== '' && isset($shelfTable)) {
    $whereClauses[] = 'b.id IN (SELECT book FROM ' . $shelfTable . ' WHERE value = :shelf_name)';
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
if ($recommendedOnly && $recColumnExists && isset($recTable)) {
    $whereClauses[] = "EXISTS (SELECT 1 FROM $recTable br WHERE br.book = b.id AND TRIM(COALESCE(br.value, '')) <> '')";
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
                            WHERE bcc.book = b.id) AS genre_data";
        if ($shelfTable) {
            $selectFields .= ", bc11.value AS shelf";
        }
        $selectFields .= ", com.text AS description";
        if ($statusTable) {
            if ($statusIsLink) {
                $selectFields .= ", scv.value AS status";
            } else {
                $selectFields .= ", sc.value AS status";
            }
        }
        if ($recColumnExists && isset($recTable)) {
            $selectFields .= ", EXISTS(SELECT 1 FROM $recTable br WHERE br.book = b.id AND TRIM(COALESCE(br.value, '')) <> '') AS has_recs";
        }

        $sql = "SELECT $selectFields
                FROM books b
                LEFT JOIN books_series_link bsl ON bsl.book = b.id
                LEFT JOIN series s ON bsl.series = s.id";
        if ($shelfTable) {
            $sql .= " LEFT JOIN $shelfTable bc11 ON bc11.book = b.id";
        }
        $sql .= " LEFT JOIN comments com ON com.book = b.id";
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

function render_book_rows(array $books, array $shelfList, array $statusOptions, array $genreList, string $sort, ?int $authorId, ?int $seriesId): void {
    foreach ($books as $book) {
        $missing = !bookHasFile($book['path']);
        ?>
        <div class="row g-3 py-3 border-bottom" data-book-block-id="<?= htmlspecialchars($book['id']) ?>">
            <!-- Left: Thumbnail -->
            <div class="col-md-2 col-12 text-center cover-wrapper">
                <?php if (!empty($book['has_cover'])): ?>
                    <a href="view_book.php?id=<?= urlencode($book['id']) ?>">
                        <img src="ebooks/<?= htmlspecialchars($book['path']) ?>/cover.jpg"
                             alt="Cover"
                             class="img-thumbnail img-fluid book-cover"
                             style="width: 100%; max-width:150px; height:auto;">
                    </a>
                <?php else: ?>
                    &mdash;
                <?php endif; ?>
            </div>

            <!-- Right: Title, Dropdowns, Description -->
            <div class="col-md-10 col-12">
                <!-- Title and Authors -->
                <div class="mb-2">
                    <?php if ($missing): ?>
                        <i class="fa-solid fa-circle-exclamation text-danger me-1" title="File missing"></i>
                    <?php endif; ?>
                    <a href="view_book.php?id=<?= urlencode($book['id']) ?>" class="fw-bold book-title me-1"
                       data-book-id="<?= htmlspecialchars($book['id']) ?>">
                        <?= htmlspecialchars($book['title']) ?>
                    </a>
                    <button type="button" class="btn btn-link btn-sm p-0 ms-1 edit-title"
                            data-book-id="<?= htmlspecialchars($book['id']) ?>"
                            data-title="<?= htmlspecialchars($book['title'], ENT_QUOTES) ?>">
                        <i class="fa-solid fa-pen"></i>
                    </button>
                    <?php if (!empty($book['has_recs'])): ?>
                        <span class="text-success ms-1">&#10003;</span>
                    <?php endif; ?>
                    <?php if (!empty($book['series'])): ?>
                        <div class="small mt-1">
                            <a href="list_books.php?sort=<?= urlencode($sort) ?>&series_id=<?= urlencode($book['series_id']) ?>">
                                <?= htmlspecialchars($book['series']) ?>
                            </a>
                            <?php if ($book['series_index'] !== null && $book['series_index'] !== ''): ?>
                                (<?= htmlspecialchars($book['series_index']) ?>)
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div class="text-muted small book-authors">
                        <?php if (!empty($book['author_data'])): ?>
                            <?php
                                $pairs = array_filter(explode('|', $book['author_data']), 'strlen');
                                $links = [];
                                foreach (array_slice($pairs, 0, 3) as $pair) {
                                    list($aid, $aname) = explode(':', $pair, 2);
                                    $url = 'list_books.php?sort=' . urlencode($sort) . '&author_id=' . urlencode($aid);
                                    $links[] = '<a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($aname) . '</a>';
                                }
                                echo implode(', ', $links);
                                if (count($pairs) > 3) echo '...';
                            ?>
                        <?php else: ?>
                            &mdash;
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Dropdowns -->
                <div class="d-flex flex-wrap gap-2 mb-2">
                    <?php
                        $firstGenreId = '';
                        if (!empty($book['genre_data'])) {
                            $first = explode('|', $book['genre_data'])[0];
                            if ($first !== '') {
                                list($gid,) = explode(':', $first, 2);
                                $firstGenreId = (string)$gid;
                            }
                        }
                    ?>
                    <div>
                        <label class="small text-muted mb-1 d-block">Genre</label>
                        <select class="form-select form-select-sm genre-select" data-book-id="<?= htmlspecialchars($book['id']) ?>">
                            <option value=""<?= $firstGenreId === '' ? ' selected' : '' ?>>None</option>
                            <?php foreach ($genreList as $g): ?>
                                <option value="<?= htmlspecialchars($g['id']) ?>"<?= (string)$g['id'] === $firstGenreId ? ' selected' : '' ?>>
                                    <?= htmlspecialchars($g['value']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="small text-muted mb-1 d-block">Shelf</label>
                        <select class="form-select form-select-sm shelf-select" data-book-id="<?= htmlspecialchars($book['id']) ?>">
                            <?php foreach ($shelfList as $s): ?>
                                <option value="<?= htmlspecialchars($s) ?>"<?= $book['shelf'] === $s ? ' selected' : '' ?>>
                                    <?= htmlspecialchars($s) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="small text-muted mb-1 d-block">Status</label>
                        <select class="form-select form-select-sm status-select" data-book-id="<?= htmlspecialchars($book['id']) ?>">
                            <option value="Want to Read"<?= ($book['status'] === null || $book['status'] === '') ? ' selected' : '' ?>>Want to Read</option>
                            <?php foreach ($statusOptions as $s): ?>
                                <?php if ($s === 'Want to Read') continue; ?>
                                <option value="<?= htmlspecialchars($s) ?>"<?= $book['status'] === $s ? ' selected' : '' ?>>
                                    <?= htmlspecialchars($s) ?>
                                </option>
                            <?php endforeach; ?>
                            <?php if ($book['status'] !== null && $book['status'] !== '' && !in_array($book['status'], $statusOptions, true)): ?>
                                <option value="<?= htmlspecialchars($book['status']) ?>" selected><?= htmlspecialchars($book['status']) ?></option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <!-- Actions -->
                    <div class="ms-auto d-flex align-items-end">
                        <a class="btn btn-sm btn-primary me-1" href="edit_book.php?id=<?= urlencode($book['id']) ?>">View / Edit</a>
                        <button type="button" class="btn btn-sm btn-secondary google-meta me-1"
                                data-book-id="<?= htmlspecialchars($book['id']) ?>"
                                data-search="<?= htmlspecialchars($book['title'] . ' ' . $book['authors'], ENT_QUOTES) ?>">
                            Metadata Google
                        </button>
                        <button type="button" class="btn btn-sm btn-danger delete-book"
                                data-book-id="<?= htmlspecialchars($book['id']) ?>">
                            Delete
                        </button>
                    </div>
                </div>

                <!-- Description -->
                <div class="small text-muted book-description">
                    <?php
                        $desc = trim($book['description'] ?? '');
                        if ($desc !== '') {
                            $lines = preg_split('/\r?\n/', $desc);
                            $preview = implode("\n", array_slice($lines, 0, 2));
                            echo nl2br(htmlspecialchars($preview));
                        } else {
                            echo '&mdash;';
                        }
                    ?>
                </div>
            </div>
        </div>
        <?php
    }
}




if ($isAjax) {
    render_book_rows($books, $shelfList, $statusOptions, $genreList, $sort, $authorId, $seriesId);
    exit;
}

function buildBaseUrl(array $params, array $exclude = []): string {
    $defaults = [
        'sort'      => $GLOBALS['sort'] ?? '',
        'author_id' => $GLOBALS['authorId'] ?? '',
        'series_id' => $GLOBALS['seriesId'] ?? '',
        'genre_id'  => $GLOBALS['genreId'] ?? '',
        'shelf'     => $GLOBALS['shelfName'] ?? '',
        'search'    => $GLOBALS['search'] ?? '',
        'source'    => $GLOBALS['source'] ?? '',
        'status'    => $GLOBALS['statusName'] ?? '',
    ];

    // Remove excluded keys
    foreach ($exclude as $key) {
        unset($defaults[$key]);
    }

    // Merge/override with custom params
    $params = array_merge($defaults, $params);

    // Filter empty values
    $query = array_filter($params, fn($v) => $v !== '' && $v !== null);

    return 'list_books.php?' . http_build_query($query);
}

function linkActive(string $current, string $compare): string {
    return $current === $compare ? ' active' : '';
}

function linkTextColor(string $current, string $compare): string {
    return $current === $compare ? ' text-white' : '';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Book List</title>
    <link id="themeStylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous">
    <script src="theme.js"></script>
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css" crossorigin="anonymous">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js" crossorigin="anonymous"></script>
    <style>
        .title-col {
            max-width: 700px;
            word-break: break-word;
        }
        
/* Modern full-width book blocks */
[data-book-block-id] {
    background: var(--bs-card-bg);
    border-radius: 6px;
    border: 1px solid var(--bs-border-color);
    transition: box-shadow 0.2s ease-in-out, transform 0.1s ease-in-out;
    padding: 1rem;
    margin-bottom: 1rem;
}

/* Striped effect (theme aware) */
[data-book-block-id]:nth-of-type(even) {
    background-color: var(--bs-gray-200);
}

/* Hover effect */
[data-book-block-id]:hover {
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    transform: translateY(-1px);
}

/* Cover image tweaks */
[data-book-block-id] .book-cover {
    border-radius: 4px;
}

/* Labels above dropdowns */
[data-book-block-id] label {
    font-size: 0.8rem;
    font-weight: 600;
}

/* Description text */
[data-book-block-id] .book-description {
    margin-top: 0.5rem;
    line-height: 1.4;
}

/* Make action buttons wrap nicely */
[data-book-block-id] .ms-auto {
    flex-wrap: wrap;
    gap: 0.5rem;
}
    </style>
</head>
<body class="pt-5">
<?php include "navbar.php"; ?>
<div class="container-fluid my-4">
    <div class="row">
       
        


<!-- Sidebar -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="sidebarMenu">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title">Sidebar</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        
        
     <nav>
    <!-- Mobile Toggle Button -->
    <button class="btn btn-outline-secondary d-md-none mb-3" 
        type="button" data-bs-toggle="collapse" data-bs-target="#genreSidebar" 
        aria-controls="genreSidebar" aria-expanded="false" aria-label="Toggle genres">
        Genres
    </button>

    <div id="genreSidebar" class="collapse d-md-block">

        <!-- Shelves -->
        <div class="mb-3">
            <h6 class="fw-semibold mb-2">Shelves</h6>
            <?php $shelfUrlBase = buildBaseUrl([], ['shelf']); ?>
            <ul class="list-group" id="shelfList">
                <li class="list-group-item<?= linkActive($shelfName, '') ?>">
                    <a href="<?= htmlspecialchars($shelfUrlBase) ?>" class="stretched-link text-decoration-none<?= linkTextColor($shelfName, '') ?>">All Shelves</a>
                </li>
                <?php foreach ($shelfList as $s): ?>
                    <?php $url = buildBaseUrl(['shelf' => $s]); ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center<?= linkActive($shelfName, $s) ?>">
                        <a href="<?= htmlspecialchars($url) ?>" class="flex-grow-1 text-truncate text-decoration-none<?= linkTextColor($shelfName, $s) ?>"><?= htmlspecialchars($s) ?></a>
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-secondary edit-shelf" data-shelf="<?= htmlspecialchars($s) ?>"><i class="fa-solid fa-pen"></i></button>
                            <button type="button" class="btn btn-outline-danger delete-shelf" data-shelf="<?= htmlspecialchars($s) ?>"><i class="fa-solid fa-trash"></i></button>
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

        <!-- Status -->
        <div class="mb-3">
            <h6 class="fw-semibold mb-2">Status</h6>
            <?php $statusUrlBase = buildBaseUrl([], ['status']); ?>
            <ul class="list-group" id="statusList">
                <li class="list-group-item<?= linkActive($statusName, '') ?>">
                    <a href="<?= htmlspecialchars($statusUrlBase) ?>" class="stretched-link text-decoration-none<?= linkTextColor($statusName, '') ?>">All Status</a>
                </li>
                <?php foreach ($statusOptions as $s): ?>
                    <?php $url = buildBaseUrl(['status' => $s]); ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center<?= linkActive($statusName, $s) ?>">
                        <a href="<?= htmlspecialchars($url) ?>" class="flex-grow-1 text-truncate text-decoration-none<?= linkTextColor($statusName, $s) ?>"><?= htmlspecialchars($s) ?></a>
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-secondary edit-status" data-status="<?= htmlspecialchars($s) ?>"><i class="fa-solid fa-pen"></i></button>
                            <button type="button" class="btn btn-outline-danger delete-status" data-status="<?= htmlspecialchars($s) ?>"><i class="fa-solid fa-trash"></i></button>
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

        <!-- Genres -->
        <div class="mb-3">
            <h6 class="fw-semibold mb-2">Genres</h6>
            <?php $genreBase = buildBaseUrl([], ['genre_id']); ?>
            <ul class="list-group" id="genreList">
                <li class="list-group-item<?= $genreId ? '' : ' active' ?>">
                    <a href="<?= htmlspecialchars($genreBase) ?>" class="stretched-link text-decoration-none<?= $genreId ? '' : ' text-white' ?>">All Genres</a>
                </li>
                <?php foreach ($genreList as $g): ?>
                    <?php $url = buildBaseUrl(['genre_id' => $g['id']]); ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center<?= $genreId == $g['id'] ? ' active' : '' ?>">
                        <a href="<?= htmlspecialchars($url) ?>" class="flex-grow-1 text-truncate text-decoration-none<?= $genreId == $g['id'] ? ' text-white' : '' ?>">
                            <?= htmlspecialchars($g['value']) ?>
                        </a>
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-secondary edit-genre" data-genre-id="<?= htmlspecialchars($g['id']) ?>" data-genre="<?= htmlspecialchars($g['value']) ?>"><i class="fa-solid fa-pen"></i></button>
                            <button type="button" class="btn btn-outline-danger delete-genre" data-genre-id="<?= htmlspecialchars($g['id']) ?>"><i class="fa-solid fa-trash"></i></button>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <form id="addGenreForm" class="mt-2">
                <div class="input-group input-group-sm">
                    <input type="text" class="form-control" name="genre" placeholder="New genre">
                    <button class="btn btn-primary" type="submit">Add</button>
                </div>
            </form>
        </div>
    </div>
</nav>
        
         </div>
</div>   


<div class="container-fluid">      
        <div class="col-md-12">
            <h1 class="mb-4">Books</h1>
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

            
            <!-- Main Content -->
<div class="col-md-12">
     <div id="book-list">
    <?php render_book_rows($books, $shelfList, $statusOptions, $genreList, $sort, $authorId, $seriesId); ?>
     </div>
</div>
        </div>

    <!-- Google Books Metadata Modal -->
    <div class="modal fade" id="googleModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Google Books Results</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div id="googleResults">Loading...</div>
          </div>
        </div>
      </div>
    </div>
        </div>
    </div>
</div>
    
    
    
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script>
function escapeHTML(str) {
    return str.replace(/&/g, '&amp;')
              .replace(/</g, '&lt;')
              .replace(/>/g, '&gt;')
              .replace(/"/g, '&quot;')
              .replace(/'/g, '&#39;');
}

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
    var googleModalEl = document.getElementById('googleModal');
    var googleModal = new bootstrap.Modal(googleModalEl);

    var restorePage = parseInt(sessionStorage.getItem('listBooksPage') || '0', 10);
    var restoreScroll = parseInt(sessionStorage.getItem('listBooksScroll') || '0', 10);
    if (!isNaN(restorePage) && restorePage > currentPage) {
        var restoreInterval = setInterval(function() {
            if (currentPage < restorePage) {
                loadMore();
            } else {
                clearInterval(restoreInterval);
                if (!isNaN(restoreScroll)) {
                    $(window).scrollTop(restoreScroll);
                }
                sessionStorage.removeItem('listBooksPage');
                sessionStorage.removeItem('listBooksScroll');
            }
        }, 300);
    } else if (!isNaN(restoreScroll) && restoreScroll > 0) {
        $(window).scrollTop(restoreScroll);
        sessionStorage.removeItem('listBooksPage');
        sessionStorage.removeItem('listBooksScroll');
    }
function loadMore() {
    if (loading || currentPage >= totalPages) return;
    loading = true;

    $.get(fetchUrlBase + (currentPage + 1) + '&ajax=1', function(html) {
        $('#book-list').append(html);
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

    $(document).on('change', '.genre-select', function() {
        var bookId = $(this).data('book-id');
        var value = $(this).val();
        fetch('update_genre.php', {
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

    $('#addGenreForm').on('submit', function(e) {
        e.preventDefault();
        var genre = $(this).find('input[name="genre"]').val().trim();
        if (!genre) return;
        fetch('add_genre.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ genre: genre })
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

    $(document).on('click', '.delete-genre', function() {
        if (!confirm('Are you sure you want to remove this genre?')) return;
        var id = $(this).data('genre-id');
        fetch('delete_genre.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ id: id })
        }).then(function() { location.reload(); });
    });

    $(document).on('click', '.delete-book', function() {
        if (!confirm('Are you sure you want to permanently delete this book?')) return;
        var bookId = $(this).data('book-id');
        fetch('delete_book.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ book_id: bookId })
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

    $(document).on('click', '.edit-genre', function() {
        var id = $(this).data('genre-id');
        var current = $(this).data('genre');
        var name = prompt('Rename genre:', current);
        if (name === null) return;
        name = name.trim();
        if (!name || name === current) return;
        fetch('rename_genre.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ id: id, new: name })
        }).then(function() { location.reload(); });
    });

    $(document).on('click', '.edit-title', function() {
        var bookId = $(this).data('book-id');
        var current = $(this).data('title');
        var name = prompt('Rename title:', current);
        if (name === null) return;
        name = name.trim();
        if (!name || name === current) return;
        var $link = $(this).siblings('a.book-title');
        fetch('update_title.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ book_id: bookId, title: name })
        }).then(function() {
            $link.text(name);
            $(this).data('title', name);
        }.bind(this));
    });





   // Helper to escape HTML
function escapeHTML(str) {
    return str
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

// Fetch Google metadata
$(document).on('click', '.google-meta', async function () {
    const bookId = $(this).data('book-id');
    const query = $(this).data('search');

    $('#googleResults').text('Loading...');
    googleModal.show();

    try {
        const response = await fetch(`google_search.php?q=${encodeURIComponent(query)}`);
        const data = await response.json();

        if (!data.books || data.books.length === 0) {
            $('#googleResults').text('No results');
            return;
        }

        const resultsHTML = data.books.map(b => {
            const title = escapeHTML(b.title || '');
            const author = escapeHTML(b.author || '');
            const year = escapeHTML(b.year || '');
            const imgUrl = escapeHTML(b.imgUrl || '');
            const description = escapeHTML(b.description || '');

            return `
                <div class="mb-3 p-2 border rounded bg-light">
                    ${imgUrl ? `<img src="${imgUrl}" style="height:100px" class="me-2 mb-2">` : ''}
                    <strong>${title}</strong>
                    ${author ? ` by ${author}` : ''}
                    ${year ? ` (${year})` : ''}
                    ${description ? `<br><em>${description}</em>` : ''}
                    <div>
                        <button type="button" class="btn btn-sm btn-primary mt-2 google-use"
                            data-book-id="${bookId}"
                            data-title="${title.replace(/"/g, '&quot;')}"
                            data-authors="${author.replace(/"/g, '&quot;')}"
                            data-year="${year.replace(/"/g, '&quot;')}"
                            data-imgurl="${imgUrl.replace(/"/g, '&quot;')}"
                            data-description="${description.replace(/"/g, '&quot;')}">
                            Use This
                        </button>
                    </div>
                </div>
            `;
        }).join('');

        $('#googleResults').html(resultsHTML);
    } catch (error) {
        console.error(error);
        $('#googleResults').text('Error fetching results');
    }
});

// Apply selected metadata
$(document).on('click', '.google-use', async function () {
    const bookId = $(this).data('book-id');
    const t = $(this).data('title');
    const a = $(this).data('authors');
    const y = $(this).data('year');
    const img = $(this).data('imgurl');
    const desc = $(this).data('description');

    try {
        const response = await fetch('update_metadata.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ book_id: bookId, title: t, authors: a, year: y, imgurl: img, description: desc })
        });

        const data = await response.json();

        if (data.status === 'ok') {
            googleModal.hide();

            // Update DOM directly
            const bookBlock = $(`[data-book-block-id="${bookId}"]`);
            if (bookBlock.length) {
                // Title
                bookBlock.find('.book-title').text(t);

                // Authors (can handle multiple authors if needed)
                if (a) {
                    bookBlock.find('.book-authors').text(a);
                } else {
                    bookBlock.find('.book-authors').html('&mdash;');
                }

                // Description
                if (desc) {
                    bookBlock.find('.book-description').text(desc);
                } else {
                    bookBlock.find('.book-description').html('&mdash;');
                }

                // Thumbnail
                if (img) {
                    const imgElem = bookBlock.find('.book-cover');
                    if (imgElem.length) {
                        imgElem.attr('src', img);
                    } else {
                        bookBlock.find('.cover-wrapper').html(`<img src="${img}" class="img-thumbnail img-fluid book-cover" alt="Cover">`);
                    }
                }
            }
        } else {
            alert(data.error || 'Error updating metadata');
        }
    } catch (error) {
        console.error(error);
        alert('Error updating metadata');
    }
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

