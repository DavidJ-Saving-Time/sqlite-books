<?php
require_once '../db.php';
requireLogin();
header('Content-Type: application/json');

$pdo = getDatabaseConnection();

// Locate custom columns
$genreColumnId = (int)$pdo->query("SELECT id FROM custom_columns WHERE label = 'genre'")->fetchColumn();
$genreLinkTable = "books_custom_column_{$genreColumnId}_link";

$shelfList = $pdo->query('SELECT name FROM shelves ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);

$statusId = (int)$pdo->query("SELECT id FROM custom_columns WHERE label = 'status'")->fetchColumn();
$statusTable = $statusId ? 'books_custom_column_' . $statusId . '_link' : null;
$statusOptions = $statusId ? $pdo->query("SELECT value FROM custom_column_{$statusId} ORDER BY value COLLATE NOCASE")->fetchAll(PDO::FETCH_COLUMN) : [];
$statusIsLink = true;

$shelfId = (int)$pdo->query("SELECT id FROM custom_columns WHERE label = 'shelf'")->fetchColumn();
$shelfValueTable = "custom_column_{$shelfId}";
$shelfLinkTable  = "books_custom_column_{$shelfId}_link";

$recId = (int)$pdo->query("SELECT id FROM custom_columns WHERE label = 'recommendation'")->fetchColumn();
$recTable = "custom_column_{$recId}";
$recLinkTable = "books_custom_column_{$recId}_link";
$recColumnExists = true;

$perPage = 20;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$sort = $_GET['sort'] ?? 'author_series';
$authorId = isset($_GET['author_id']) ? (int)$_GET['author_id'] : null;
$seriesId = isset($_GET['series_id']) ? (int)$_GET['series_id'] : null;
$genreName = isset($_GET['genre']) ? trim((string)$_GET['genre']) : '';
$shelfName = isset($_GET['shelf']) ? trim((string)$_GET['shelf']) : '';
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

$allowedSorts = ['title', 'author', 'series', 'author_series', 'author_series_surname', 'recommended', 'last_modified'];
if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'author_series';
}
$recommendedOnly = ($sort === 'recommended');

$orderByMap = [
    'title' => 'b.title',
    'author' => 'authors, b.title',
    'series' => 'series, b.series_index, b.title',
    'author_series' => 'authors, series, b.series_index, b.title',
    'author_series_surname' => 'b.author_sort, series, b.series_index, b.title',
    'recommended' => 'authors, series, b.series_index, b.title',
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

// Fetch genre list
$genreList = [];
try {
    $stmt = $pdo->query("SELECT id, value FROM custom_column_{$genreColumnId} ORDER BY value COLLATE NOCASE");
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
                   au.authors, au.author_ids,
                   s.id AS series_id,
                   s.name AS series,
                   ge.genres,
                   bc11.value AS shelf,
                   com.text AS description,
                   r.rating AS rating";
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
            LEFT JOIN series s ON bsl.series = s.id
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
    foreach ($books as &$b) {
        if ($b['rating'] !== null) {
            $b['rating'] = (int)($b['rating'] / 2);
        }
        $b['missing'] = !bookHasFile($b['path']);
        $b['first_file'] = $b['missing'] ? null : firstBookFile($b['path']);
    }
    unset($b);
} catch (PDOException $e) {
    echo json_encode(['error' => 'Query failed']);
    exit;
}

$totalPages = max(1, ceil($totalBooks / $perPage));

echo json_encode([
    'page' => $page,
    'per_page' => $perPage,
    'total_pages' => $totalPages,
    'books' => $books,
    'shelf_list' => $shelfList,
    'status_options' => $statusOptions,
    'genre_list' => $genreList,
    'library_path' => getLibraryPath(),
]);
