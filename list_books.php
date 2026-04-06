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

$recColumnExists = false;
$recTable = '';
$recLinkTable = '';
try {
    $recId = getCustomColumnId($pdo, 'recommendation');
    if ($recId) {
        $recTable = "custom_column_{$recId}";
        $recLinkTable = "books_custom_column_{$recId}_link";
        $recColumnExists = true;
    }
} catch (Exception $e) {
    // recommendation column absent — has_recs will default to 0
}

$subseriesInfo        = getCachedSubseriesInfo($pdo);
$hasSubseries         = $subseriesInfo['exists'];
$subseriesIsCustom    = $subseriesInfo['isCustom'];
$subseriesLinkTable   = $subseriesInfo['linkTable'];
$subseriesValueTable  = $subseriesInfo['valueTable'];
$subseriesIndexColumn = $subseriesInfo['indexColumn'];

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$isAjax = isset($_GET['ajax']) && $_GET['ajax'] === '1';
$sort = $_GET['sort'] ?? 'author_series';
if (isset($_GET['view']) && in_array($_GET['view'], ['list', 'grid', 'simple', 'two'], true)) {
    $view = $_GET['view'];
    setcookie('book_view', $view, ['expires' => time() + 60 * 60 * 24 * 365, 'path' => '/']);
} else {
    $cookieView = $_COOKIE['book_view'] ?? '';
    $view = in_array($cookieView, ['list', 'grid', 'simple', 'two'], true) ? $cookieView : 'list';
}
$perPage = $view === 'simple' ? 100 : 20;
$authorId = isset($_GET['author_id']) ? (int)$_GET['author_id'] : null;
$seriesId = isset($_GET['series_id']) ? (int)$_GET['series_id'] : null;
$subseriesId = isset($_GET['subseries_id']) ? (int)$_GET['subseries_id'] : null;
$genreName = isset($_GET['genre']) ? trim((string)$_GET['genre']) : '';
$genreNone = ($genreName === '__none__');
if ($genreNone) $genreName = '';
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

// If searching locally, redirect to series page when query matches a series name
if ($search !== '' && $source === 'local') {
    $stmt = $pdo->prepare('SELECT id FROM series WHERE name = :name COLLATE NOCASE');
    $stmt->execute([':name' => $search]);
    $seriesMatch = $stmt->fetchColumn();
    if ($seriesMatch) {
        header('Location: list_books.php?series_id=' . (int)$seriesMatch);
        exit;
    }
}
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

// Build device book map early so it can be used as a WHERE filter
$onDevice        = [];
$deviceProgress  = []; // library_id => ['percent' => float|null, 'pages' => int|null]
$deviceTotalCount = null;

// Other libraries this user can transfer books to
$allUsers = json_decode(file_get_contents(__DIR__ . '/users.json'), true) ?? [];
$transferTargets = [];
foreach ($allUsers as $uname => $udata) {
    if ($uname === currentUser()) continue;
    $dbp = $udata['prefs']['db_path'] ?? '';
    if ($dbp !== '' && file_exists($dbp)) {
        $transferTargets[] = $uname;
    }
}
$recentlyReadOnDevice = []; // top 10 by lua_last_accessed
$deviceCacheFile = __DIR__ . '/cache/' . currentUser() . '/device_books.json';
if (file_exists($deviceCacheFile)) {
    $deviceCache = json_decode(file_get_contents($deviceCacheFile), true);
    $deviceTotalCount = $deviceCache['count'] ?? count($deviceCache['books'] ?? []);
    $recentCandidates = [];
    foreach ($deviceCache['books'] ?? [] as $db) {
        if (!empty($db['library_id'])) {
            $onDevice[$db['library_id']] = $db['path'];
            if (!empty($db['lua_exists'])) {
                $deviceProgress[$db['library_id']] = [
                    'percent'       => $db['lua_percent']       ?? null,
                    'pages'         => $db['lua_pages']         ?? null,
                    'last_accessed' => $db['lua_last_accessed'] ?? null,
                ];
                if (!empty($db['lua_last_accessed'])) {
                    $recentCandidates[] = $db;
                }
            }
        }
    }
    usort($recentCandidates, fn($a, $b) => strcmp($b['lua_last_accessed'], $a['lua_last_accessed']));
    $recentlyReadOnDevice = array_slice($recentCandidates, 0, 10);
}

$filterNotOnDevice = isset($_GET['not_on_device']) && $_GET['not_on_device'] === '1';
$filterOnDevice    = isset($_GET['on_device'])     && $_GET['on_device']     === '1';

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
if ($subseriesId) {
    if ($subseriesIsCustom) {
        $whereClauses[] = 'EXISTS (SELECT 1 FROM ' . $subseriesLinkTable . ' WHERE book = b.id AND value = :subseries_id)';
    } else {
        $whereClauses[] = 'EXISTS (SELECT 1 FROM books_subseries_link WHERE book = b.id AND subseries = :subseries_id)';
    }
    $params[':subseries_id'] = $subseriesId;
}
if ($genreNone) {
    $whereClauses[] = 'NOT EXISTS (SELECT 1 FROM ' . $genreLinkTable . ' gl WHERE gl.book = b.id)';
} elseif ($genreName !== '') {
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
    // Sanitize search input to avoid FTS5 syntax errors
    $cleanSearch = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $search);
    $tokens = preg_split('/\s+/', $cleanSearch, -1, PREG_SPLIT_NO_EMPTY);
    if ($tokens) {
        $ftsQuery = implode(' ', array_map(fn($t) => $t . '*', $tokens));
        $whereClauses[] = 'b.id IN (SELECT rowid FROM books_fts WHERE books_fts MATCH :fts_query)';
        $params[':fts_query'] = $ftsQuery;
    }
}
if ($filterNotOnDevice && !empty($onDevice)) {
    $ids = implode(',', array_map('intval', array_keys($onDevice)));
    $whereClauses[] = "b.id NOT IN ($ids)";
}
if ($filterOnDevice && !empty($onDevice)) {
    $ids = implode(',', array_map('intval', array_keys($onDevice)));
    $whereClauses[] = "b.id IN ($ids)";
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
$filterSubseriesName = null;
if ($subseriesId) {
    if ($subseriesIsCustom) {
        $stmt = $pdo->prepare('SELECT value FROM ' . $subseriesValueTable . ' WHERE id = ?');
    } else {
        $stmt = $pdo->prepare('SELECT name FROM subseries WHERE id = ?');
    }
    $stmt->execute([$subseriesId]);
    $filterSubseriesName = $stmt->fetchColumn();
}
$filterGenreName = $genreNone ? 'No Genre' : ($genreName !== '' ? $genreName : null);
$filterStatusName = $statusName !== '' ? $statusName : null;
$filterShelfName = $shelfName !== '' ? $shelfName : null;
$filterFileTypeName = $fileType !== '' ? $fileType : null;

// Fetch full genre list for sidebar (with counts)
$genreList = getCachedGenres($pdo);

// Count books with no genre assigned
$noGenreCount = (int)$pdo->query(
    "SELECT COUNT(DISTINCT b.id) FROM books b WHERE NOT EXISTS (SELECT 1 FROM $genreLinkTable gl WHERE gl.book = b.id)"
)->fetchColumn();

// Fetch series list for inline editing
$seriesList = $pdo->query('SELECT id, name FROM series ORDER BY sort COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);

$books = [];
    try {
        $totalSql = "SELECT COUNT(*) FROM books b $where";
        $totalStmt = $pdo->prepare($totalSql);
        bindParams($totalStmt, $params);
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
                       r.rating AS rating,
                       (SELECT val FROM identifiers WHERE book = b.id AND type = 'isbn' LIMIT 1) AS isbn,
                       (SELECT val FROM identifiers WHERE book = b.id AND type = 'olid' LIMIT 1) AS olid,
                       (SELECT val FROM identifiers WHERE book = b.id AND type = 'goodreads' LIMIT 1) AS goodreads,
                       (SELECT val FROM identifiers WHERE book = b.id AND type = 'amazon' LIMIT 1) AS amazon,
                       (SELECT val FROM identifiers WHERE book = b.id AND type = 'librarything' LIMIT 1) AS librarything";
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
            $selectFields .= ", (SELECT rt.value FROM $recLinkTable rl JOIN $recTable rt ON rl.value = rt.id WHERE rl.book = b.id LIMIT 1) AS rec_text";
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
        bindParams($stmt, $params);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $books = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$recColumnExists) {
            array_walk($books, fn(&$b) => $b['has_recs'] = 0);
        }
        if (!$statusTable) {
            array_walk($books, fn(&$b) => $b['status'] = null);
        }
        foreach ($books as &$b) {
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
    global $view;
    foreach ($books as $i => $book) {
        $index = $offset + $i;
        $missing = !bookHasFile($book['path']);
        $firstFile = $missing ? null : firstBookFile($book['path']);

        extract($templateData, EXTR_SKIP);
        $template = $view === 'grid' ? 'book_tile.php' : ($view === 'simple' ? 'book_row_simple.php' : ($view === 'two' ? 'book_row_two.php' : 'book_row.php'));
        include __DIR__ . "/templates/$template";
    }
}

$rowTemplateData = [
    'shelfList' => $shelfList,
    'statusOptions' => $statusOptions,
    'genreList' => $genreList,
    'seriesList' => $seriesList,
    'sort' => $sort,
    'page' => $page,
    'onDevice'       => $onDevice,
    'deviceProgress' => $deviceProgress,
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
        'subseries_id' => $GLOBALS['subseriesId'] ?? '',
        'genre'  => $GLOBALS['genreName'] ?? '',
        'shelf'     => $GLOBALS['shelfName'] ?? '',
        'search'    => $GLOBALS['search'] ?? '',
        'source'    => $GLOBALS['source'] ?? '',
        'status'    => $GLOBALS['statusName'] ?? '',
        'filetype'  => $GLOBALS['fileType'] ?? '',
        'author_initial' => $GLOBALS['authorInitial'] ?? '',
        'view'          => $GLOBALS['view'] ?? '',
        'not_on_device' => ($GLOBALS['filterNotOnDevice'] ?? false) ? '1' : '',
        'on_device'     => ($GLOBALS['filterOnDevice']    ?? false) ? '1' : '',
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

function bindParams(PDOStatement $stmt, array $params): void {
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
}

function linkActive(string $current, string $compare): string {
    return $current === $compare ? ' active' : '';
}

function linkTextColor(string $current, string $compare): string {
    return $current === $compare ? ' text-white' : '';
}

// Build breadcrumb items for navigation
$breadcrumbs = [
    ['label' => 'Books', 'url' => buildBaseUrl([], ['author_id','series_id','subseries_id','genre','shelf','status','filetype','search','sort'])]
];
if ($filterAuthorName !== null) {
    $breadcrumbs[] = ['label' => $filterAuthorName, 'url' => buildBaseUrl([], ['author_id'])];
}
if ($filterSeriesName !== null) {
    $breadcrumbs[] = ['label' => $filterSeriesName, 'url' => buildBaseUrl([], ['series_id','subseries_id'])];
}
if ($filterSubseriesName !== null) {
    $breadcrumbs[] = ['label' => $filterSubseriesName, 'url' => buildBaseUrl([], ['subseries_id'])];
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
if ($filterNotOnDevice) {
    $breadcrumbs[] = ['label' => 'Not on device', 'url' => buildBaseUrl([], ['not_on_device'])];
}
if ($filterOnDevice) {
    $breadcrumbs[] = ['label' => 'On device', 'url' => buildBaseUrl([], ['on_device'])];
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
    <link rel="stylesheet" href="/theme.css.php">
    <link rel="stylesheet" href="/css/all.min.css" crossorigin="anonymous">
    
    <link rel="stylesheet" href="/css/duotone.css">
    
    <script src="js/search.js"></script>
    <!-- Removed jQuery and jQuery UI -->
    <style>
        /* Simple view table-like grid */
        .simple-row {
            display: grid;
            grid-template-columns: 1.5rem 16rem 1fr 14rem 10rem 5rem;
            align-items: center;
            gap: 0 0.75rem;
            padding: 0.2rem 0.5rem;
            font-size: 0.85rem;
            min-height: 2rem;
        }
        .simple-row .form-select-sm {
            padding-top: 0.1rem;
            padding-bottom: 0.1rem;
            font-size: 0.8rem;
        }

        /* Cancel card styles for simple-row so rows sit flush like a table */
        .simple-row[data-book-block-id] {
            border-radius: 0;
            margin-bottom: 0;
            padding: 0.2rem 0.5rem;
            border: none;
            border-bottom: 1px solid var(--bs-border-color);
        }

        .simple-row[data-book-block-id] {
            cursor: pointer;
        }
        .simple-row[data-book-block-id]:hover {
            box-shadow: none;
            transform: none;
            background-color: var(--bs-primary-bg-subtle) !important;
        }
        .simple-row[data-book-block-id].row-selected {
            background-color: color-mix(in srgb, var(--accent) 20%, transparent) !important;
            border-bottom-color: color-mix(in srgb, var(--accent) 40%, transparent);
        }

        /* Cover preview panel (simple view) */
        #coverPreviewPanel {
            width: 280px;
            flex-shrink: 0;
            position: sticky;
            top: 5rem;
            align-self: flex-start;
            background: var(--bs-body-bg);
            border: 1px solid var(--bs-border-color);
            border-radius: 0.5rem;
            padding: 0.6rem;
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
            min-height: 12rem;
        }
        #coverPreviewPanel img {
            width: 100%;
            border-radius: 0.25rem;
        }
        #coverPreviewPanel .cover-title {
            font-size: 0.78rem;
            font-weight: 600;
            line-height: 1.3;
            word-break: break-word;
        }
        #coverPreviewPanel .cover-author {
            font-size: 0.72rem;
            color: var(--bs-secondary-color);
            word-break: break-word;
        }
        .cover-empty-state {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: var(--bs-secondary-color);
            font-size: 0.75rem;
            text-align: center;
        }

        /* Column header row */
        .simple-row-header {
            font-size: 0.72rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--bs-secondary-color);
            border-top: 2px solid var(--bs-border-color) !important;
            border-bottom: 2px solid var(--bs-border-color) !important;
            padding-top: 0.3rem;
            padding-bottom: 0.3rem;
        }

        .title-col {
            max-width: 700px;
            word-break: break-word;
        }
        .object-fit-cover { object-fit: cover; }
.line-clamp-2 {
  display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}


.book-title {
  font-size: 1.2 rem;
  font-weight: 400;
  font-style: italic;
  text-decoration: none;
  letter-spacing: 0.02em;
}


.ratio-2x3 { --bs-aspect-ratio: 150%; } /* height/width = 3/2 */
/* Modern full-width book blocks */
[data-book-block-id] {
    background: var(--bs-card-bg);
    border-radius: 6px;
    border: 1px solid var(--bs-border-color);
    transition: box-shadow 0.2s ease-in-out, transform 0.1s ease-in-out;
    padding: 1rem;
    margin-bottom: 1rem;
}

/* Striped effect — row A (odd) and row B (even), falls back to legacy --row-stripe */
[data-book-block-id]:nth-of-type(odd) {
    background-color: var(--row-stripe-a, transparent);
}
[data-book-block-id]:nth-of-type(even) {
    background-color: var(--row-stripe-b, var(--row-stripe, transparent));
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
html {
    overflow-anchor: none;
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
<?php
$showOffcanvas = true;
$viewOptions   = ['list' => 'List', 'simple' => 'Simple', 'two' => 'Cards', 'grid' => 'Grid'];
include "navbar.php";
?>
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

        <!-- Device -->
        <?php if ($deviceTotalCount !== null): ?>
        <div class="mb-3">
            <h6 class="fw-semibold mb-2">Device</h6>
            <?php $deviceUrlBase = buildBaseUrl([], ['not_on_device']); ?>
            <ul class="list-group">
                <li class="list-group-item d-flex justify-content-between align-items-center<?= !$filterNotOnDevice ? ' active' : '' ?>">
                    <a href="<?= htmlspecialchars($deviceUrlBase) ?>" class="flex-grow-1 text-decoration-none<?= !$filterNotOnDevice ? ' text-white' : '' ?>">All books</a>
                    <span class="badge bg-secondary rounded-pill"><?= $totalLibraryBooks ?></span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center<?= $filterNotOnDevice ? ' active' : '' ?>">
                    <a href="<?= htmlspecialchars(buildBaseUrl(['not_on_device' => '1'], ['on_device'])) ?>" class="flex-grow-1 text-decoration-none<?= $filterNotOnDevice ? ' text-white' : '' ?>">
                        <i class="fa-solid fa-tablet-screen-button me-1"></i>Not on device
                    </a>
                    <span class="badge bg-secondary rounded-pill"><?= $totalLibraryBooks - count($onDevice) ?></span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center<?= $filterOnDevice ? ' active' : '' ?>">
                    <a href="<?= htmlspecialchars(buildBaseUrl(['on_device' => '1'], ['not_on_device'])) ?>" class="flex-grow-1 text-decoration-none<?= $filterOnDevice ? ' text-white' : '' ?>">
                        <i class="fa-solid fa-tablet-screen-button text-success me-1"></i>On device
                    </a>
                    <span class="badge bg-secondary rounded-pill"><?= count($onDevice) ?></span>
                </li>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Recently Read on Device -->
        <?php if (!empty($recentlyReadOnDevice)): ?>
        <div class="mb-3">
            <h6 class="fw-semibold mb-2">
                <a class="text-decoration-none text-body d-flex align-items-center justify-content-between"
                   data-bs-toggle="collapse" href="#recentDeviceList" role="button" aria-expanded="false">
                    <span><i class="fa-solid fa-clock-rotate-left me-1"></i>Recently on Device</span>
                    <i class="fa-solid fa-chevron-down small"></i>
                </a>
            </h6>
            <div class="collapse" id="recentDeviceList">
                <ul class="list-group">
                    <?php foreach ($recentlyReadOnDevice as $r): ?>
                    <li class="list-group-item px-2 py-1">
                        <a href="book.php?id=<?= (int)$r['library_id'] ?>" class="text-decoration-none d-block text-truncate" title="<?= htmlspecialchars($r['title']) ?>">
                            <?= htmlspecialchars($r['title']) ?>
                        </a>
                        <small class="text-muted"><?= htmlspecialchars($r['lua_last_accessed']) ?></small>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

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
                <li class="list-group-item d-flex justify-content-between align-items-center<?= (!$genreNone && $genreName === '') ? ' active' : '' ?>">
                    <a href="<?= htmlspecialchars($genreBase) ?>" class="flex-grow-1 text-decoration-none<?= (!$genreNone && $genreName === '') ? ' text-white' : '' ?>">All Genres</a>
                    <span class="badge bg-secondary rounded-pill"><?= $totalLibraryBooks ?></span>
                </li>
                <?php if ($noGenreCount > 0): ?>
                <?php $noGenreUrl = buildBaseUrl(['genre' => '__none__']); ?>
                <li class="list-group-item d-flex justify-content-between align-items-center<?= $genreNone ? ' active' : '' ?>">
                    <a href="<?= htmlspecialchars($noGenreUrl) ?>" class="flex-grow-1 text-decoration-none<?= $genreNone ? ' text-white' : ' text-muted fst-italic' ?>">No Genre</a>
                    <span class="badge bg-secondary rounded-pill"><?= $noGenreCount ?></span>
                </li>
                <?php endif; ?>
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
        <?php
        $filterParts = array_filter([
            $filterAuthorName   ? 'author: '   . htmlspecialchars($filterAuthorName)              : null,
            $filterSeriesName   ? 'series: '   . htmlspecialchars($filterSeriesName)              : null,
            $filterGenreName    ? 'genre: '    . htmlspecialchars($filterGenreName)               : null,
            $filterShelfName    ? 'shelf: '    . htmlspecialchars($filterShelfName)               : null,
            $filterStatusName   ? 'status: '   . htmlspecialchars($filterStatusName)              : null,
            $filterFileTypeName ? 'filetype: ' . htmlspecialchars(strtoupper($filterFileTypeName)) : null,
            $search !== ''      ? 'search: "'  . htmlspecialchars($search) . '"'                  : null,
            $filterNotOnDevice  ? 'not on device'                                                  : null,
            $recommendedOnly    ? 'recommended only'                                               : null,
        ]);
        ?>
        <?php if ($filterParts): ?>
        <div class="alert alert-info mb-3">
            Showing <?= implode(', ', $filterParts) ?>
            <a class="btn btn-sm btn-secondary ms-2" href="list_books.php?sort=<?= urlencode($sort) ?>">Clear</a>
        </div>
        <?php endif; ?>

            
            <!-- Main Content -->
<?php if ($view === 'simple'): ?>
<div class="d-flex align-items-flex-start gap-3">
<div style="flex:1;min-width:0">
<?php else: ?>
<div class="col-md-12">
<?php endif; ?>
  <div id="contentArea">
      <?php if ($view === 'simple'): ?>
      <div class="d-flex align-items-center gap-2 mb-3">
          <div id="bulkToolbar" class="d-flex align-items-center gap-2">
              <input type="checkbox" class="form-check-input" id="bulkSelectAll" title="Select all">
              <button type="button" class="btn btn-sm btn-outline-secondary" id="bulkSelectNotOnDevice" title="Select all not on device">
                  <i class="fa-solid fa-paper-plane me-1"></i>Select not on device
              </button>
              <button type="button" class="btn btn-sm btn-outline-success" id="bulkSendBtn" disabled>
                  <i class="fa-solid fa-paper-plane me-1"></i> Send selected
              </button>
              <button type="button" class="btn btn-sm btn-outline-warning" id="bulkRemoveDevBtn" disabled>
                  <i class="fa-solid fa-tablet-screen-button me-1"></i> Remove from device
              </button>
              <button type="button" class="btn btn-sm btn-outline-danger" id="bulkDeleteBtn" disabled>
                  <i class="fa-solid fa-trash me-1"></i> Delete selected
              </button>
              <?php if (!empty($transferTargets)): ?>
              <div class="d-flex align-items-center gap-1 ms-2">
                  <select id="bulkTransferTarget" class="form-select form-select-sm" style="width:auto">
                      <?php foreach ($transferTargets as $uname): ?>
                      <option value="<?= htmlspecialchars($uname) ?>"><?= htmlspecialchars($uname) ?></option>
                      <?php endforeach; ?>
                  </select>
                  <button type="button" class="btn btn-sm btn-outline-secondary" id="bulkTransferBtn" disabled>
                      <i class="fa-solid fa-copy me-1"></i> Copy to library
                  </button>
              </div>
              <?php endif; ?>
              <span id="bulkStatus" class="small text-muted"></span>
          </div>
      </div>
      <?php endif; ?>
      <?php if ($view === 'grid'): ?>
      <div class="row row-cols-2 row-cols-md-6 g-4">
      <?php endif; ?>
      <?php if ($view === 'two'): ?>
      <div class="row g-3">
      <?php endif; ?>
      <?php if ($view === 'simple'): ?>
      <div class="simple-row simple-row-header">
          <span></span>
          <span>Author</span>
          <span>Title</span>
          <span>Series</span>
          <span>Genre</span>
          <span></span>
      </div>
      <?php endif; ?>
      <div id="topSpacer" style="height:0"></div>
      <div id="topSentinel"></div>
      <?php render_book_rows($books, $rowTemplateData, $offset); ?>
      <div id="bottomSentinel"></div>
      <div id="bottomSpacer" style="height:0"></div>
      <?php if ($view === 'grid' || $view === 'two'): ?>
      </div>
      <?php endif; ?>
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
  </div><!-- #contentArea -->
<?php if ($view === 'simple'): ?>
</div><!-- book-list flex child -->
<div id="coverPreviewPanel">
    <div id="coverPreviewEmpty" class="cover-empty-state">
        <i class="fa-regular fa-image fa-2x mb-2"></i>
        <span>Select a book</span>
    </div>
    <img id="coverPreviewImg" src="" alt="" style="display:none">
    <div class="cover-title" id="coverPreviewTitle"></div>
    <div class="cover-author" id="coverPreviewAuthor"></div>
</div>
</div><!-- flex container -->
<?php else: ?>
</div><!-- col-md-12 -->
<?php endif; ?>
        </div>

        </div>
    </div>
    <div id="alphabetBar" class="position-fixed bottom-0 start-0 end-0 bg-dark d-flex align-items-center px-3 py-1">
        <div class="flex-grow-1 text-center">
        <?php
        $baseLetterParams = $_GET;
        unset($baseLetterParams['author_initial'], $baseLetterParams['page']);
        foreach (range('A', 'Z') as $letter) {
            $letterParams = $baseLetterParams;
            $letterParams['author_initial'] = $letter;
            $url = 'list_books.php?' . http_build_query($letterParams);
            $active = ($authorInitial === $letter) ? 'fw-bold h4 text-white' : '';
            echo '<a href="' . htmlspecialchars($url) . '" class="mx-1 text-decoration-none ' . $active . '" style="color:var(--accent);">' . $letter . '</a>';
        }
        if ($authorInitial !== '') {
            $url = 'list_books.php?' . http_build_query($baseLetterParams);
            echo '<a href="' . htmlspecialchars($url) . '" class="mx-1 text-decoration-none" style="color:var(--accent);">Clear</a>';
        }
        ?>
        </div>
        <div class="small text-nowrap ms-3 d-flex gap-3 align-items-center" style="color:#adb5bd;">
            <?php
            $viewIcons = ['list' => 'fa-list', 'simple' => 'fa-table-list', 'two' => 'fa-grip', 'grid' => 'fa-border-all'];
            foreach ($viewIcons as $v => $icon):
                $isActive = $view === $v;
            ?>
            <a href="<?= htmlspecialchars(buildBaseUrl(['view' => $v, 'page' => 1], ['page'])) ?>"
               class="text-decoration-none" title="<?= htmlspecialchars($viewOptions[$v]) ?>"
               style="color:<?= $isActive ? 'var(--accent)' : '#adb5bd' ?>; font-size:<?= $isActive ? '1rem' : '0.85rem' ?>">
                <i class="fa-solid <?= $icon ?>"></i>
            </a>
            <?php endforeach; ?>
            <span><i class="fa-solid fa-book me-1"></i><?= number_format($totalLibraryBooks) ?> books</span>
            <?php if ($deviceTotalCount !== null): ?>
            <span><i class="fa-solid fa-tablet-screen-button me-1"></i><?= number_format(count($onDevice)) ?> synced</span>
            <?php endif; ?>
            <a href="#" id="refreshCacheBtn" class="text-decoration-none fw-medium" style="color:var(--accent);" title="Refresh caches">
                <i class="fa-solid fa-arrows-rotate"></i>
            </a>
        </div>
    </div>
    <a href="#" id="backToTop" class="btn btn-primary position-fixed end-0 m-3 d-none"><i class="fa-solid fa-arrow-up"></i></a>

    <div id="loadingSpinner" class="position-fixed top-0 start-0 w-100 h-100 d-flex justify-content-center align-items-center bg-body bg-opacity-75 d-none" style="z-index:1050;">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
    </div>

    <?php require __DIR__ . '/templates/modals.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js" referrerpolicy="origin"></script>
    <script>window.seriesList = <?= json_encode($seriesList, JSON_HEX_TAG) ?>;</script>
    <script src="js/recommendations.js"></script>
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

        const refreshCacheBtn = document.getElementById('refreshCacheBtn');
        if (refreshCacheBtn) {
            refreshCacheBtn.addEventListener('click', async e => {
                e.preventDefault();
                const icon = refreshCacheBtn.querySelector('i');
                icon.classList.add('fa-spin');
                refreshCacheBtn.style.pointerEvents = 'none';
                try {
                    await fetch('json_endpoints/clear_cache.php', { method: 'POST' });
                    window.location.reload();
                } catch {
                    icon.classList.remove('fa-spin');
                    refreshCacheBtn.style.pointerEvents = '';
                }
            });
        }
    });
    </script>
<?php if ($view === 'simple'): ?>
<script>
(function () {
    const img      = document.getElementById('coverPreviewImg');
    const empty    = document.getElementById('coverPreviewEmpty');
    const titleEl  = document.getElementById('coverPreviewTitle');
    const authorEl = document.getElementById('coverPreviewAuthor');

    document.addEventListener('click', e => {
        const row = e.target.closest('.simple-row[data-book-block-id]');
        if (!row) return;
        if (e.target.closest('a, button, select, input, label')) return;

        const cover  = row.dataset.cover  || '';
        const title  = row.dataset.title  || '';
        const author = row.dataset.author || '';

        titleEl.textContent  = title;
        authorEl.textContent = author;
        empty.style.display  = 'none';

        if (cover) {
            img.src = cover;
            img.style.display = '';
        } else {
            img.style.display = 'none';
            img.src = '';
        }
    });
})();
</script>
<?php endif; ?>
</body>
</html>

