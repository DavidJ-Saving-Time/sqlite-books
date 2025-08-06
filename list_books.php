<?php
require_once 'db.php';
require_once 'cache.php';
requireLogin();

$pdo = getDatabaseConnection();
$genreColumnId = getCustomColumnId($pdo, 'genre');
$genreLinkTable = "books_custom_column_{$genreColumnId}_link";
$totalLibraryBooks = getTotalLibraryBooks($pdo);

// Shelf column and list with counts
$shelfId = getCustomColumnId($pdo, 'shelf');
$shelfValueTable = "custom_column_{$shelfId}";
$shelfLinkTable  = "books_custom_column_{$shelfId}_link";
$shelves = getCachedShelves($pdo);
$shelfList = array_column($shelves, 'value');
$shelfName = isset($_GET['shelf']) ? trim((string)$_GET['shelf']) : '';
if ($shelfName !== '' && !in_array($shelfName, $shelfList, true)) {
    $shelfName = '';
}

// Locate custom column for reading status and fetch counts
$statusId = getCustomColumnId($pdo, 'status');
$statusTable = 'books_custom_column_' . $statusId . '_link';
$statusIsLink = true;
$statusList = getCachedStatuses($pdo);
$statusOptions = array_column($statusList, 'value');

$recId = getCustomColumnId($pdo, 'recommendation');
$recTable = "custom_column_{$recId}";
$recLinkTable = "books_custom_column_{$recId}_link";
$recColumnExists = true;

$hasSubseries = false;
$subseriesIsCustom = false;
$subseriesLinkTable = '';
$subseriesValueTable = '';
$subseriesIndexColumn = null;

try {
    $subseriesColumnId = getCustomColumnId($pdo, 'subseries');
    if ($subseriesColumnId) {
        $hasSubseries = true;
        $subseriesIsCustom = true;
        $subseriesValueTable = "custom_column_{$subseriesColumnId}";
        $subseriesLinkTable  = "books_custom_column_{$subseriesColumnId}_link";
        $cols = $pdo->query("PRAGMA table_info($subseriesLinkTable)")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $col) {
            if (in_array($col['name'], ['book_index', 'sort', 'extra'], true)) {
                $subseriesIndexColumn = $col['name'];
                break;
            }
        }
    } else {
        $subTable = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='subseries'")->fetchColumn();
        $subLinkTable = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='books_subseries_link'")->fetchColumn();
        if ($subTable && $subLinkTable) {
            $cols = $pdo->query('PRAGMA table_info(books)')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cols as $col) {
                if ($col['name'] === 'subseries_index') {
                    $hasSubseries = true;
                    break;
                }
            }
        }
    }
} catch (PDOException $e) {
    $hasSubseries = false;
}

$perPage = 20;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$isAjax = isset($_GET['ajax']) && $_GET['ajax'] === '1';
$sort = $_GET['sort'] ?? 'author_series';
$authorId = isset($_GET['author_id']) ? (int)$_GET['author_id'] : null;
$seriesId = isset($_GET['series_id']) ? (int)$_GET['series_id'] : null;
$genreName = isset($_GET['genre']) ? trim((string)$_GET['genre']) : '';
$statusName = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
if ($statusName !== '' && !in_array($statusName, $statusOptions, true)) {
    $statusName = '';
}
$fileType = isset($_GET['filetype']) ? strtolower(trim((string)$_GET['filetype'])) : '';
$allowedFileTypes = ['epub','mobi','azw3','txt','pdf','docx','none'];
if ($fileType !== '' && !in_array($fileType, $allowedFileTypes, true)) {
    $fileType = '';
}
$authorInitial = isset($_GET['author_initial'])
    ? strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $_GET['author_initial']), 0, 1))
    : '';
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
$allowedSorts = ['title', 'author', 'series', 'author_series', 'author_series_surname', 'recommended', 'last_modified'];
if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'author_series';
}
$recommendedOnly = ($sort === 'recommended');

$subseriesOrder = $hasSubseries ? ', subseries, subseries_index' : '';

$orderByMap = [
    'title' => 'b.title',
    'author' => 'authors, b.title',
    'series' => 'series, b.series_index' . $subseriesOrder . ', b.title',
    'author_series' => 'authors, series, b.series_index' . $subseriesOrder . ', b.title',
    'author_series_surname' => 'b.author_sort, series, b.series_index' . $subseriesOrder . ', b.title',
    'recommended' => 'authors, series, b.series_index' . $subseriesOrder . ', b.title',
    'last_modified' => 'b.last_modified DESC, b.title'
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
if ($genreName !== '') {
    $whereClauses[] = 'EXISTS (SELECT 1 FROM ' . $genreLinkTable . ' gl JOIN custom_column_' . (int)$genreColumnId . ' gv ON gl.value = gv.id WHERE gl.book = b.id AND gv.value = :genre_val)';
    $params[':genre_val'] = $genreName;
}
if ($shelfName !== '') {
    $whereClauses[] = 'EXISTS (SELECT 1 FROM ' . $shelfLinkTable . ' sl JOIN ' . $shelfValueTable . ' sv ON sl.value = sv.id WHERE sl.book = b.id AND sv.value = :shelf_name)';
    $params[':shelf_name'] = $shelfName;
}
if ($fileType !== '') {
    if ($fileType === 'none') {
        $whereClauses[] = "NOT EXISTS (SELECT 1 FROM data d WHERE d.book = b.id AND lower(d.format) IN ('epub','mobi','azw3','txt','pdf','docx'))";
    } else {
        $whereClauses[] = 'EXISTS (SELECT 1 FROM data d WHERE d.book = b.id AND lower(d.format) = :file_type)';
        $params[':file_type'] = $fileType;
    }
}
// Apply A-Z filtering based on the current sort field
if ($authorInitial !== '') {
    switch ($sort) {
        case 'title':
            $whereClauses[] = 'UPPER(b.title) LIKE :author_initial';
            break;
        case 'series':
            $whereClauses[] = 'EXISTS (SELECT 1 FROM books_series_link bsl JOIN series s ON bsl.series = s.id WHERE bsl.book = b.id AND UPPER(s.name) LIKE :author_initial)';
            break;
        default:
            $whereClauses[] = 'EXISTS (SELECT 1 FROM books_authors_link bal JOIN authors a ON bal.author = a.id WHERE bal.book = b.id AND UPPER(a.sort) LIKE :author_initial)';
    }
    $params[':author_initial'] = $authorInitial . '%';
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
    $whereClauses[] = "EXISTS (SELECT 1 FROM $recLinkTable rl JOIN $recTable rt ON rl.value = rt.id WHERE rl.book = b.id AND TRIM(COALESCE(rt.value, '')) <> '')";
}
if ($search !== '') {
    $tokens = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);
    $ftsQuery = implode(' ', array_map(fn($t) => $t . '*', $tokens));
    $whereClauses[] = 'b.id IN (SELECT rowid FROM books_fts WHERE books_fts MATCH :fts_query)';
    $params[':fts_query'] = $ftsQuery;
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
$filterGenreName = $genreName !== '' ? $genreName : null;
$filterStatusName = $statusName !== '' ? $statusName : null;
$filterShelfName = $shelfName !== '' ? $shelfName : null;
$filterFileTypeName = $fileType !== '' ? $fileType : null;

// Fetch full genre list for sidebar (with counts)
$genreList = getCachedGenres($pdo);

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
                       au.authors, au.author_ids,
                       s.id AS series_id,
                       s.name AS series,
                       ge.genres,
                       bc11.value AS shelf,
                       com.text AS description,
                       r.rating AS rating";
        if ($hasSubseries) {
            if ($subseriesIsCustom) {
                $idxExpr = $subseriesIndexColumn ? "bssl.$subseriesIndexColumn" : 'NULL';
                $selectFields .= ", $idxExpr AS subseries_index, ss.id AS subseries_id, ss.value AS subseries";
            } else {
                $selectFields .= ", b.subseries_index AS subseries_index, ss.id AS subseries_id, ss.name AS subseries";
            }
        }
        if ($statusTable) {
            if ($statusIsLink) {
                $selectFields .= ", scv.value AS status";
            } else {
                $selectFields .= ", sc.value AS status";
            }
        }
        if ($recColumnExists) {
            $selectFields .= ", EXISTS(SELECT 1 FROM $recLinkTable rl JOIN $recTable rt ON rl.value = rt.id WHERE rl.book = b.id AND TRIM(COALESCE(rt.value, '')) <> '') AS has_recs";
        }

        $sql = "SELECT $selectFields
                FROM books b
                LEFT JOIN (
                    SELECT bal.book,
                           GROUP_CONCAT(a.name, '|') AS authors,
                           GROUP_CONCAT(a.id, '|') AS author_ids
                    FROM books_authors_link bal
                    JOIN authors a ON bal.author = a.id
                    GROUP BY bal.book
                ) au ON au.book = b.id
                LEFT JOIN books_series_link bsl ON bsl.book = b.id
                LEFT JOIN series s ON bsl.series = s.id";
        if ($hasSubseries) {
            if ($subseriesIsCustom) {
                $sql .= "
                LEFT JOIN $subseriesLinkTable bssl ON bssl.book = b.id
                LEFT JOIN $subseriesValueTable ss ON bssl.value = ss.id";
            } else {
                $sql .= "
                LEFT JOIN books_subseries_link bssl ON bssl.book = b.id
                LEFT JOIN subseries ss ON bssl.subseries = ss.id";
            }
        }
        $sql .= "
                LEFT JOIN (
                    SELECT bcc.book,
                           GROUP_CONCAT(gv.value, '|') AS genres
                    FROM $genreLinkTable bcc
                    JOIN custom_column_{$genreColumnId} gv ON bcc.value = gv.id
                    GROUP BY bcc.book
                ) ge ON ge.book = b.id
                LEFT JOIN $shelfLinkTable bc11l ON bc11l.book = b.id
                LEFT JOIN $shelfValueTable bc11 ON bc11l.value = bc11.id
                LEFT JOIN comments com ON com.book = b.id
                LEFT JOIN books_ratings_link brl ON brl.book = b.id
                LEFT JOIN ratings r ON r.id = brl.rating";
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
        foreach ($books as &$b) {
            if (!$recColumnExists) {
                $b['has_recs'] = 0;
            }
            if (!$statusTable) {
                $b['status'] = null;
            }
            if ($b['rating'] !== null) {
                $b['rating'] = (int)($b['rating'] / 2);
            }
        }
        unset($b);
    } catch (PDOException $e) {
        die('Query failed: ' . $e->getMessage());
    }

$totalPages = max(1, ceil($totalBooks / $perPage));
$baseUrl = buildBaseUrl([], []) . '&page=';

$prevUrl = $baseUrl . max(1, $page - 1);
$nextUrl = $baseUrl . min($totalPages, $page + 1);

function render_book_rows(array $books, array $templateData, int $offset = 0): void {
    foreach ($books as $i => $book) {
        $index = $offset + $i;
        $missing = !bookHasFile($book['path']);
        $firstFile = $missing ? null : firstBookFile($book['path']);

        extract($templateData, EXTR_SKIP);
        include __DIR__ . '/templates/book_row.php';
    }
}

$rowTemplateData = [
    'shelfList' => $shelfList,
    'statusOptions' => $statusOptions,
    'genreList' => $genreList,
    'sort' => $sort,
    'page' => $page,
];

if ($isAjax) {
    render_book_rows($books, $rowTemplateData, $offset);
    exit;
}

function buildBaseUrl(array $params, array $exclude = []): string {
    $defaults = [
        'sort'      => $GLOBALS['sort'] ?? '',
        'author_id' => $GLOBALS['authorId'] ?? '',
        'series_id' => $GLOBALS['seriesId'] ?? '',
        'genre'  => $GLOBALS['genreName'] ?? '',
        'shelf'     => $GLOBALS['shelfName'] ?? '',
        'search'    => $GLOBALS['search'] ?? '',
        'source'    => $GLOBALS['source'] ?? '',
        'status'    => $GLOBALS['statusName'] ?? '',
        'filetype'  => $GLOBALS['fileType'] ?? '',
        'author_initial' => $GLOBALS['authorInitial'] ?? '',
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

// Build breadcrumb items for navigation
$breadcrumbs = [
    ['label' => 'Books', 'url' => buildBaseUrl([], ['author_id','series_id','genre','shelf','status','filetype','search','sort'])]
];
if ($filterAuthorName !== null) {
    $breadcrumbs[] = ['label' => $filterAuthorName, 'url' => buildBaseUrl([], ['author_id'])];
}
if ($filterSeriesName !== null) {
    $breadcrumbs[] = ['label' => $filterSeriesName, 'url' => buildBaseUrl([], ['series_id'])];
}
if ($filterGenreName !== null) {
    $breadcrumbs[] = ['label' => $filterGenreName, 'url' => buildBaseUrl([], ['genre'])];
}
if ($filterShelfName !== null) {
    $breadcrumbs[] = ['label' => $filterShelfName, 'url' => buildBaseUrl([], ['shelf'])];
}
if ($filterStatusName !== null) {
    $breadcrumbs[] = ['label' => $filterStatusName, 'url' => buildBaseUrl([], ['status'])];
}
if ($filterFileTypeName !== null) {
    $breadcrumbs[] = ['label' => strtoupper($filterFileTypeName), 'url' => buildBaseUrl([], ['filetype'])];
}
if ($recommendedOnly) {
    $breadcrumbs[] = ['label' => 'Recommended', 'url' => buildBaseUrl([], ['sort'])];
}
if ($search !== '') {
    $breadcrumbs[] = ['label' => 'Search: ' . $search, 'url' => buildBaseUrl([], ['search'])];
}
if (count($breadcrumbs) === 1) {
    // No filters active, show Books as current page without link
    $breadcrumbs[0]['url'] = null;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Book List</title>
    <link id="themeStylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link rel="stylesheet" href="/css/all.min.css" crossorigin="anonymous">
    
    <link rel="stylesheet" href="/css/duotone.css">
    
    <script src="js/theme.js"></script>
    <script src="js/search.js"></script>
    <!-- Removed jQuery and jQuery UI -->
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

[data-book-block-id] .show-more {
    cursor: pointer;
}

/* Make action buttons wrap nicely */
[data-book-block-id] .ms-auto {
    flex-wrap: wrap;
    gap: 0.5rem;
}

/* Star rating */
.star-rating .fa-star,
.star-rating .fa-xmark {
    cursor: pointer;
}
body {
    padding-bottom: 3rem;
}
#alphabetBar {
    z-index: 1030;
}
#backToTop {
    bottom: 3.5rem;
}
    </style>
</head>
<body class="pt-5" data-page="<?php echo $page; ?>" data-total-pages="<?php echo $totalPages; ?>" data-base-url="<?php echo htmlspecialchars($baseUrl, ENT_QUOTES); ?>" data-per-page="<?php echo $perPage; ?>" data-total-items="<?php echo $totalLibraryBooks; ?>">
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
                <li class="list-group-item d-flex justify-content-between align-items-center<?= linkActive($shelfName, '') ?>">
                    <a href="<?= htmlspecialchars($shelfUrlBase) ?>" class="flex-grow-1 text-decoration-none<?= linkTextColor($shelfName, '') ?>">All Shelves</a>
                    <span class="badge bg-secondary rounded-pill"><?= $totalLibraryBooks ?></span>
                </li>
                <?php foreach ($shelves as $s): ?>
                    <?php $url = buildBaseUrl(['shelf' => $s['value']]); ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center<?= linkActive($shelfName, $s['value']) ?>">
                        <a href="<?= htmlspecialchars($url) ?>" class="flex-grow-1 text-truncate text-decoration-none<?= linkTextColor($shelfName, $s['value']) ?>"><?= htmlspecialchars($s['value']) ?></a>
                        <div class="d-flex align-items-center">
                            <span class="badge bg-secondary rounded-pill me-2"><?= (int)$s['book_count'] ?></span>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-secondary edit-shelf" data-shelf="<?= htmlspecialchars($s['value']) ?>"><i class="fa-solid fa-pen"></i></button>
                                <button type="button" class="btn btn-outline-danger delete-shelf" data-shelf="<?= htmlspecialchars($s['value']) ?>"><i class="fa-solid fa-trash"></i></button>
                            </div>
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
                <li class="list-group-item d-flex justify-content-between align-items-center<?= linkActive($statusName, '') ?>">
                    <a href="<?= htmlspecialchars($statusUrlBase) ?>" class="flex-grow-1 text-decoration-none<?= linkTextColor($statusName, '') ?>">All Status</a>
                    <span class="badge bg-secondary rounded-pill"><?= $totalLibraryBooks ?></span>
                </li>
                <?php foreach ($statusList as $s): ?>
                    <?php $url = buildBaseUrl(['status' => $s['value']]); ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center<?= linkActive($statusName, $s['value']) ?>">
                        <a href="<?= htmlspecialchars($url) ?>" class="flex-grow-1 text-truncate text-decoration-none<?= linkTextColor($statusName, $s['value']) ?>"><?= htmlspecialchars($s['value']) ?></a>
                        <div class="d-flex align-items-center">
                            <span class="badge bg-secondary rounded-pill me-2"><?= (int)$s['book_count'] ?></span>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-secondary edit-status" data-status="<?= htmlspecialchars($s['value']) ?>"><i class="fa-solid fa-pen"></i></button>
                                <button type="button" class="btn btn-outline-danger delete-status" data-status="<?= htmlspecialchars($s['value']) ?>"><i class="fa-solid fa-trash"></i></button>
                            </div>
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

        <!-- File Type -->
        <div class="mb-3">
            <h6 class="fw-semibold mb-2">File Type</h6>
            <?php $ftBase = buildBaseUrl([], ['filetype']); ?>
            <ul class="list-group" id="fileTypeList">
                <li class="list-group-item<?= linkActive($fileType, '') ?>">
                    <a href="<?= htmlspecialchars($ftBase) ?>" class="stretched-link text-decoration-none<?= linkTextColor($fileType, '') ?>">All Types</a>
                </li>
                <?php foreach (['epub','mobi','azw3','txt','pdf','docx','none'] as $ft): ?>
                    <?php $url = buildBaseUrl(['filetype' => $ft]); ?>
                    <li class="list-group-item<?= linkActive($fileType, $ft) ?>">
                        <a href="<?= htmlspecialchars($url) ?>" class="stretched-link text-decoration-none<?= linkTextColor($fileType, $ft) ?>"><?= htmlspecialchars(strtoupper($ft)) ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- Genres -->
        <div class="mb-3">
            <h6 class="fw-semibold mb-2">Genres</h6>
            <?php $genreBase = buildBaseUrl([], ['genre']); ?>
            <ul class="list-group" id="genreList">
                <li class="list-group-item d-flex justify-content-between align-items-center<?= $genreName !== '' ? '' : ' active' ?>">
                    <a href="<?= htmlspecialchars($genreBase) ?>" class="flex-grow-1 text-decoration-none<?= $genreName !== '' ? '' : ' text-white' ?>">All Genres</a>
                    <span class="badge bg-secondary rounded-pill"><?= $totalLibraryBooks ?></span>
                </li>
                <?php foreach ($genreList as $g): ?>
                    <?php $url = buildBaseUrl(['genre' => $g['value']]); ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center<?= $genreName === $g['value'] ? ' active' : '' ?>">
                        <a href="<?= htmlspecialchars($url) ?>" class="flex-grow-1 text-truncate text-decoration-none<?= $genreName === $g['value'] ? ' text-white' : '' ?>">
                            <?= htmlspecialchars($g['value']) ?>
                        </a>
                        <div class="d-flex align-items-center">
                            <span class="badge bg-secondary rounded-pill me-2"><?= (int)$g['book_count'] ?></span>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-secondary edit-genre" data-genre="<?= htmlspecialchars($g['value']) ?>"><i class="fa-solid fa-pen"></i></button>
                                <button type="button" class="btn btn-outline-danger delete-genre" data-genre="<?= htmlspecialchars($g['value']) ?>"><i class="fa-solid fa-trash"></i></button>
                            </div>
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
             <h1 class="mb-4">Books (<?= $totalLibraryBooks ?>)</h1>
            <nav aria-label="breadcrumb" class="mb-3">
                <ol class="breadcrumb mb-0">
                    <?php foreach ($breadcrumbs as $index => $bc): ?>
                        <?php $isLast = ($index === array_key_last($breadcrumbs)); ?>
                        <li class="breadcrumb-item<?= $isLast ? ' active' : '' ?>"<?= $isLast ? ' aria-current="page"' : '' ?>>
                            <?php if (!$isLast && !empty($bc['url'])): ?>
                                <a href="<?= htmlspecialchars($bc['url']) ?>">
                                    <?= htmlspecialchars($bc['label']) ?>
                                    <span class="ms-1" aria-hidden="true">&times;</span>
                                </a>
                            <?php else: ?>
                                <?= htmlspecialchars($bc['label']) ?>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </nav>
        <?php if ($filterAuthorName || $filterSeriesName || $filterGenreName || $filterShelfName || $filterStatusName || $filterFileTypeName || $search !== ''): ?>
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
            <?php if ($filterFileTypeName): ?>
                <?php if ($filterAuthorName || $filterSeriesName || $filterGenreName || $filterShelfName || $filterStatusName): ?>,
                <?php endif; ?>
                filetype: <?= htmlspecialchars(strtoupper($filterFileTypeName)) ?>
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
  <div id="contentArea">
      <div id="topSentinel"></div>
      <?php render_book_rows($books, $rowTemplateData, $offset); ?>
      <div id="bottomSentinel"></div>
      <nav id="pageNav" aria-label="Page navigation" class="mt-3">
        <ul class="pagination justify-content-center">
          <li class="page-item <?php if ($page <= 1) echo 'disabled'; ?>">
            <a class="page-link" href="<?php echo $page > 1 ? htmlspecialchars($prevUrl, ENT_QUOTES) : '#'; ?>">Previous</a>
          </li>
          <li class="page-item <?php if ($page >= $totalPages) echo 'disabled'; ?>">
            <a class="page-link" href="<?php echo $page < $totalPages ? htmlspecialchars($nextUrl, ENT_QUOTES) : '#'; ?>">Next</a>
          </li>
        </ul>
      </nav>
  </div>
</div>
        </div>

    <!-- Open Library Metadata Modal -->
    <div class="modal fade" id="openLibraryModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Open Library Results</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div id="openLibraryResults">Loading...</div>
          </div>
        </div>
      </div>
    </div>
        </div>
    </div>
    <div id="alphabetBar" class="position-fixed bottom-0 start-0 end-0 bg-light text-center py-2">
        <?php
        $baseLetterParams = $_GET;
        unset($baseLetterParams['author_initial'], $baseLetterParams['page']);
        foreach (range('A', 'Z') as $letter) {
            $letterParams = $baseLetterParams;
            $letterParams['author_initial'] = $letter;
            $url = 'list_books.php?' . http_build_query($letterParams);
            $active = ($authorInitial === $letter) ? 'fw-bold h4' : '';
            echo '<a href="' . htmlspecialchars($url) . '" class="mx-1 ' . $active . '">' . $letter . '</a>';
        }
        if ($authorInitial !== '') {
            $url = 'list_books.php?' . http_build_query($baseLetterParams);
            echo '<a href="' . htmlspecialchars($url) . '" class="mx-1">Clear</a>';
        }
        ?>
    </div>
    <a href="#" id="backToTop" class="btn btn-primary position-fixed end-0 m-3 d-none"><i class="fa-solid fa-arrow-up"></i></a>

    <div id="loadingSpinner" class="position-fixed top-0 start-0 w-100 h-100 d-flex justify-content-center align-items-center bg-white bg-opacity-75 d-none" style="z-index:1050;">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script src="js/list_books.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const btn = document.getElementById('backToTop');
        window.addEventListener('scroll', () => {
            if (window.scrollY > 200) {
                btn.classList.remove('d-none');
            } else {
                btn.classList.add('d-none');
            }
        });
        btn.addEventListener('click', e => {
            e.preventDefault();
            if (window.listBooksSkipSave) {
                window.listBooksSkipSave();
            }
            sessionStorage.removeItem('lastItem');
            const params = new URLSearchParams(window.location.search);
            params.delete('page');
            const url = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
            window.location.href = url;
        });
    });
    </script>
</body>
</html>

