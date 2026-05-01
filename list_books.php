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

// Locate wiki_book column (may not exist yet — created on first fetch)
$wikiBookColId     = getCustomColumnId($pdo, 'wiki_book');
$wikiBookLinkTable = $wikiBookColId ? "books_custom_column_{$wikiBookColId}_link" : null;
$wikiBookValTable  = $wikiBookColId ? "custom_column_{$wikiBookColId}"            : null;

$subseriesInfo        = getCachedSubseriesInfo($pdo);
$hasSubseries         = $subseriesInfo['exists'];
$subseriesIsCustom    = $subseriesInfo['isCustom'];
$subseriesLinkTable   = $subseriesInfo['linkTable'];
$subseriesValueTable  = $subseriesInfo['valueTable'];
$subseriesIndexColumn = $subseriesInfo['indexColumn'];

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$isAjax = isset($_GET['ajax']) && $_GET['ajax'] === '1';
$validSorts = ['title','author','series','author_series','author_series_surname','last_modified','file_modified','recommended'];
if (isset($_GET['sort']) && in_array($_GET['sort'], $validSorts, true)) {
    $sort = $_GET['sort'];
    setcookie('book_sort', $sort, ['expires' => time() + 60 * 60 * 24 * 365, 'path' => '/']);
} else {
    $sort = in_array($_COOKIE['book_sort'] ?? '', $validSorts, true) ? $_COOKIE['book_sort'] : 'author_series';
}
if (isset($_GET['view']) && in_array($_GET['view'], ['list', 'simple', 'two'], true)) {
    $view = $_GET['view'];
    setcookie('book_view', $view, ['expires' => time() + 60 * 60 * 24 * 365, 'path' => '/']);
} else {
    $view = in_array($_COOKIE['book_view'] ?? '', ['list', 'simple', 'two'], true) ? $_COOKIE['book_view'] : 'list';
}
$perPage = $view === 'simple' ? 100 : 34;


$authorId = isset($_GET['author_id']) ? (int)$_GET['author_id'] : null;
$seriesId = isset($_GET['series_id']) ? (int)$_GET['series_id'] : null;
$subseriesId = isset($_GET['subseries_id']) ? (int)$_GET['subseries_id'] : null;
$awardId  = isset($_GET['award_id']) ? (int)$_GET['award_id'] : null;
$hasAward = isset($_GET['has_award']) && $_GET['has_award'] === '1';
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
$recommendedOnly = ($sort === 'recommended');

$subseriesOrder = $hasSubseries ? ', subseries, subseries_index' : '';

// Extract first author only for sort — authors is GROUP_CONCAT(name,'|'), author_sort uses ' & '
$firstAuthor     = "SUBSTR(au.authors, 1, INSTR(au.authors || '|', '|') - 1)";
$firstAuthorSort = "SUBSTR(b.author_sort, 1, INSTR(b.author_sort || ' & ', ' & ') - 1)";

$orderByMap = [
    'title' => 'b.title',
    'author' => "$firstAuthor, b.title",
    'series' => 'series, b.series_index' . $subseriesOrder . ', b.title',
    'author_series' => "$firstAuthor, series, b.series_index" . $subseriesOrder . ', b.title',
    'author_series_surname' => "$firstAuthorSort, series, b.series_index" . $subseriesOrder . ', b.title',
    'recommended' => "$firstAuthor, series, b.series_index" . $subseriesOrder . ', b.title',
    'last_modified'  => 'b.last_modified DESC, b.title',
    'file_modified'  => 'file_mtime(b.path) DESC, b.title'
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

$filterNotOnDevice  = isset($_GET['not_on_device']) && $_GET['not_on_device'] === '1';
$filterOnDevice     = isset($_GET['on_device'])     && $_GET['on_device']     === '1';
$filterNoGrRating      = isset($_GET['no_gr_rating'])       && $_GET['no_gr_rating']       === '1';
$filterGrZeroRating    = isset($_GET['gr_zero_rating'])    && $_GET['gr_zero_rating']    === '1';
$filterGrLowReviews    = isset($_GET['gr_low_reviews'])    && $_GET['gr_low_reviews']    === '1';

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
if ($awardId) {
    $whereClauses[] = 'EXISTS (SELECT 1 FROM book_awards ba WHERE ba.book_id = b.id AND ba.award_id = :award_id)';
    $params[':award_id'] = $awardId;
} elseif ($hasAward) {
    $whereClauses[] = 'EXISTS (SELECT 1 FROM book_awards ba WHERE ba.book_id = b.id)';
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
        $knownTypes = implode(',', array_map(fn($t) => "'$t'", array_filter($allowedFileTypes, fn($t) => $t !== 'none')));
        $whereClauses[] = "NOT EXISTS (SELECT 1 FROM data d WHERE d.book = b.id AND lower(d.format) IN ($knownTypes))";
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
if ($filterNoGrRating) {
    $whereClauses[] = "NOT EXISTS (SELECT 1 FROM identifiers i WHERE i.book = b.id AND i.type = 'gr_rating')";
}
if ($filterGrZeroRating) {
    $whereClauses[] = "EXISTS (SELECT 1 FROM identifiers i WHERE i.book = b.id AND i.type = 'gr_rating' AND CAST(i.val AS REAL) = 0)";
}
if ($filterGrLowReviews) {
    $whereClauses[] = "EXISTS (SELECT 1 FROM identifiers i WHERE i.book = b.id AND i.type = 'gr_rating_count' AND CAST(i.val AS INTEGER) < 50)";
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
$filterAwardName = null;
if ($awardId) {
    $stmt = $pdo->prepare('SELECT name FROM awards WHERE id = ?');
    $stmt->execute([$awardId]);
    $filterAwardName = $stmt->fetchColumn() ?: null;
}

// Fetch full genre list (cached; needed for sidebar and two-column view dropdowns)
$genreList = getCachedGenres($pdo);

// These are only needed for the full HTML render, not AJAX row fetches
$noGenreCount       = 0;
$noGrRatingCount    = 0;
$grZeroRatingCount  = 0;
$grLowReviewsCount  = 0;
$seriesList         = [];
$awardList          = [];
if (!$isAjax) {
    $noGenreCount = (int)$pdo->query(
        "SELECT COUNT(DISTINCT b.id) FROM books b WHERE NOT EXISTS (SELECT 1 FROM $genreLinkTable gl WHERE gl.book = b.id)"
    )->fetchColumn();
    $grRow = $pdo->query("
        SELECT
            SUM(CASE WHEN NOT EXISTS (SELECT 1 FROM identifiers i WHERE i.book = b.id AND i.type = 'gr_rating') THEN 1 ELSE 0 END) AS no_gr_rating,
            SUM(CASE WHEN EXISTS (SELECT 1 FROM identifiers i WHERE i.book = b.id AND i.type = 'gr_rating' AND CAST(i.val AS REAL) = 0) THEN 1 ELSE 0 END) AS gr_zero_rating,
            SUM(CASE WHEN EXISTS (SELECT 1 FROM identifiers i WHERE i.book = b.id AND i.type = 'gr_rating_count' AND CAST(i.val AS INTEGER) < 50) THEN 1 ELSE 0 END) AS gr_low_reviews
        FROM books b
    ")->fetch(PDO::FETCH_ASSOC);
    $noGrRatingCount   = (int)($grRow['no_gr_rating']   ?? 0);
    $grZeroRatingCount = (int)($grRow['gr_zero_rating'] ?? 0);
    $grLowReviewsCount = (int)($grRow['gr_low_reviews'] ?? 0);
    $seriesList = $pdo->query('SELECT id, name FROM series ORDER BY sort COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
    $awardList  = getCachedAwards($pdo);
}

$hasAwardsTable = (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='book_awards'")->fetchColumn();
$books = [];
    try {
        $totalSql = "SELECT COUNT(*) FROM books b $where";
        $totalStmt = $pdo->prepare($totalSql);
        bindParams($totalStmt, $params);
        $totalStmt->execute();
        $totalBooks = (int)$totalStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;

        $selectFields = "b.id, b.title, b.path, b.has_cover, b.series_index, b.last_modified,
                       au.authors, au.author_ids,
                       s.id AS series_id,
                       s.name AS series,
                       ge.genres,
                       bc11.value AS shelf,
                       com.text AS description,
                       r.rating AS rating,
                       (SELECT GROUP_CONCAT(type || ':' || val, '|') FROM identifiers WHERE book = b.id) AS all_identifiers,
                       (SELECT GROUP_CONCAT(t.name, '|') FROM books_tags_link btl JOIN tags t ON btl.tag = t.id WHERE btl.book = b.id) AS tags";
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
        if ($hasAwardsTable) {
            $selectFields .= ", EXISTS(SELECT 1 FROM book_awards ba WHERE ba.book_id = b.id AND ba.result IN ('won', 'special citation')) AS has_won_award";
            $selectFields .= ", EXISTS(SELECT 1 FROM book_awards ba JOIN awards a ON ba.award_id = a.id WHERE ba.book_id = b.id AND a.name = 'Hugo and Nebula' AND ba.result = 'won') AS has_hugo_nebula";
            $selectFields .= ", (SELECT GROUP_CONCAT(
                    a.name || COALESCE(' ' || ba.year, '') || COALESCE(' (' || ba.category || ')', ''),
                    ' · '
                ) FROM book_awards ba JOIN awards a ON ba.award_id = a.id
                WHERE ba.book_id = b.id AND ba.result = 'won') AS won_awards_detail";
            $selectFields .= ", (SELECT GROUP_CONCAT(
                    a.name || COALESCE(' ' || ba.year, '') || COALESCE(' (' || ba.category || ')', ''),
                    ' · '
                ) FROM book_awards ba JOIN awards a ON ba.award_id = a.id
                WHERE ba.book_id = b.id AND ba.result = 'special citation') AS citation_awards_detail";
            $selectFields .= ", (SELECT GROUP_CONCAT(
                    a.name || COALESCE(' ' || ba.year, '') || COALESCE(' (' || ba.category || ')', ''),
                    ' · '
                ) FROM book_awards ba JOIN awards a ON ba.award_id = a.id
                WHERE ba.book_id = b.id AND ba.result IN ('nominated', 'shortlisted')) AS nominated_awards_detail";
        }

        if ($wikiBookColId) {
            if ($view === 'simple') {
                // Simple view only needs a boolean — avoid fetching large JSON for 100 rows
                $selectFields .= ", EXISTS(SELECT 1 FROM $wikiBookLinkTable WHERE book = b.id) AS wiki_book";
            } else {
                $selectFields .= ", wbv.value AS wiki_book";
            }
        }

        $includeRecs = $recColumnExists && $view !== 'simple';
        if ($includeRecs) {
            $selectFields .= ", EXISTS(SELECT 1 FROM $recLinkTable rl JOIN $recTable rt ON rl.value = rt.id WHERE rl.book = b.id AND TRIM(COALESCE(rt.value, '')) <> '') AS has_recs";
            $selectFields .= ", (SELECT rt.value FROM $recLinkTable rl JOIN $recTable rt ON rl.value = rt.id WHERE rl.book = b.id LIMIT 1) AS rec_text";
        }

        $sql = "SELECT $selectFields
                FROM books b
                LEFT JOIN (
                    SELECT bal.book,
                           GROUP_CONCAT(a.name, '|') AS authors,
                           GROUP_CONCAT(a.id, '|') AS author_ids
                    FROM (SELECT * FROM books_authors_link ORDER BY id) bal
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
        if ($wikiBookColId && $view !== 'simple') {
            $sql .= " LEFT JOIN $wikiBookLinkTable wbl ON wbl.book = b.id LEFT JOIN $wikiBookValTable wbv ON wbl.value = wbv.id";
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
        if (!$includeRecs) {
            array_walk($books, fn(&$b) => $b['has_recs'] = 0);
        }
        if (!$hasAwardsTable) {
            array_walk($books, function (&$b) {
                $b['has_won_award']           = 0;
                $b['has_hugo_nebula']         = 0;
                $b['won_awards_detail']       = null;
                $b['citation_awards_detail']  = null;
                $b['nominated_awards_detail'] = null;
            });
        }
        if (!$statusTable) {
            array_walk($books, fn(&$b) => $b['status'] = null);
        }
        if (!$wikiBookColId) {
            array_walk($books, fn(&$b) => $b['wiki_book'] = null);
        }
        $idKeys = array_flip(['isbn', 'olid', 'goodreads', 'amazon', 'librarything', 'gr_rating', 'gr_rating_count', 'gr_pages', 'gr_work_id', 'gr_shelf_counts']);
        foreach ($books as &$b) {
            if ($b['rating'] !== null) {
                $b['rating'] = (int)($b['rating'] / 2);
            }
            // Parse individual identifier fields from the single all_identifiers subquery
            $b['isbn'] = $b['olid'] = $b['goodreads'] = $b['amazon'] = $b['librarything'] = $b['gr_rating'] = $b['gr_rating_count'] = $b['gr_pages'] = $b['gr_work_id'] = $b['gr_shelf_counts'] = null;
            if (!empty($b['all_identifiers'])) {
                foreach (explode('|', $b['all_identifiers']) as $pair) {
                    $c = strpos($pair, ':');
                    if ($c < 1) continue;
                    $t = substr($pair, 0, $c);
                    if (isset($idKeys[$t])) $b[$t] = substr($pair, $c + 1);
                }
            }
        }
        unset($b);

        // Mark which books already have cached similar data
        $workIds = array_filter(array_column($books, 'gr_work_id'));
        if ($workIds) {
            $placeholders = implode(',', array_fill(0, count($workIds), '?'));
            $simRows = $pdo->prepare(
                "SELECT source_work_id, COUNT(*) AS cnt FROM gr_similar_books WHERE source_work_id IN ($placeholders) GROUP BY source_work_id"
            );
            $simRows->execute(array_values($workIds));
            $simCounts = $simRows->fetchAll(PDO::FETCH_KEY_PAIR); // [work_id => count]
            foreach ($books as &$b) {
                $b['similar_count'] = isset($b['gr_work_id']) ? (int)($simCounts[$b['gr_work_id']] ?? 0) : 0;
            }
            unset($b);
        } else {
            foreach ($books as &$b) { $b['similar_count'] = 0; }
            unset($b);
        }
    } catch (PDOException $e) {
        die('Query failed: ' . $e->getMessage());
    }

// ── Series grouping for "two" view when sorted by series ──────────────────────
if ($view === 'two' && in_array($sort, ['series', 'author_series', 'author_series_surname'], true)) {
    $pageSeriesIds = array_values(array_unique(array_filter(array_column($books, 'series_id'))));
    if ($pageSeriesIds) {
        $ph = implode(',', array_fill(0, count($pageSeriesIds), '?'));

        // Build optional subseries join/select for the sibling query
        $sibSubJoin   = '';
        $sibSubSelect = "'' AS subseries, 0 AS subseries_index";
        if ($hasSubseries) {
            if ($subseriesIsCustom) {
                $idxExpr      = $subseriesIndexColumn ? "ssl.$subseriesIndexColumn" : '0';
                $sibSubJoin   = "LEFT JOIN $subseriesLinkTable ssl ON ssl.book = b.id LEFT JOIN $subseriesValueTable ssv ON ssl.value = ssv.id";
                $sibSubSelect = "COALESCE(ssv.value, '') AS subseries, COALESCE($idxExpr, 0) AS subseries_index";
            } else {
                $sibSubJoin   = "LEFT JOIN books_subseries_link ssl ON ssl.book = b.id LEFT JOIN subseries ss ON ssl.subseries = ss.id";
                $sibSubSelect = "COALESCE(ss.name, '') AS subseries, COALESCE(b.subseries_index, 0) AS subseries_index";
            }
        }

        $sibStmt = $pdo->prepare(
            "SELECT b.id, b.title, b.has_cover, b.path, b.series_index, s.id AS series_id, $sibSubSelect
             FROM books b
             JOIN books_series_link bsl ON bsl.book = b.id
             JOIN series s ON bsl.series = s.id
             $sibSubJoin
             WHERE s.id IN ($ph)
             ORDER BY subseries, subseries_index, b.series_index"
        );
        $sibStmt->execute($pageSeriesIds);
        $allSeriesBooks = $sibStmt->fetchAll(PDO::FETCH_ASSOC);

        // Group all fetched books by series_id
        $bySeriesId = [];
        foreach ($allSeriesBooks as $sb) {
            $bySeriesId[$sb['series_id']][] = $sb;
        }

        // For each series, find the page-rep: lowest series_index among books on this page
        $pageReps = []; // series_id => book id
        foreach ($pageSeriesIds as $sid) {
            $lowestIdx = PHP_FLOAT_MAX;
            $repId     = null;
            foreach ($books as $b) {
                if ((int)$b['series_id'] !== (int)$sid) continue;
                $idx = (float)($b['series_index'] ?? PHP_FLOAT_MAX);
                if ($idx < $lowestIdx) { $lowestIdx = $idx; $repId = $b['id']; }
            }
            $pageReps[$sid] = $repId;
        }

        // Mark non-reps as suppressed; attach siblings to reps
        foreach ($books as &$b) {
            $sid = $b['series_id'] ?? null;
            if (!$sid) continue;
            if ($b['id'] === $pageReps[$sid]) {
                $b['_series_siblings'] = array_values(array_filter(
                    $bySeriesId[$sid] ?? [],
                    fn($sb) => (int)$sb['id'] !== (int)$b['id']
                ));
            } else {
                $b['_suppressed'] = true;
            }
        }
        unset($b);
    }
}

$totalPages = max(1, ceil($totalBooks / $perPage));
$baseUrl = buildBaseUrl([], []) . '&page=';

$prevUrl = $baseUrl . max(1, $page - 1);
$nextUrl = $baseUrl . min($totalPages, $page + 1);

function render_book_rows(array $books, array $templateData, int $offset = 0): void {
    global $view;
    $visibleIndex = 0;
    foreach ($books as $i => $book) {
        if (!empty($book['_suppressed'])) continue;
        $index          = $offset + $i;
        $seriesSiblings = $book['_series_siblings'] ?? [];
        $missing        = !bookHasFile($book['path']);
        $firstFile      = $missing ? null : firstBookFile($book['path']);

        extract($templateData, EXTR_SKIP);
        $template = $view === 'simple' ? 'book_row_simple.php' : ($view === 'two' ? 'book_row_two.php' : 'book_row.php');
        include __DIR__ . "/templates/$template";
        $visibleIndex++;
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
        'award_id'    => $GLOBALS['awardId']  ?? '',
        'has_award'   => ($GLOBALS['hasAward'] ?? false) ? '1' : '',
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
        'no_gr_rating'     => ($GLOBALS['filterNoGrRating']     ?? false) ? '1' : '',
        'gr_zero_rating'   => ($GLOBALS['filterGrZeroRating']   ?? false) ? '1' : '',
        'gr_low_reviews'   => ($GLOBALS['filterGrLowReviews']   ?? false) ? '1' : '',
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
if ($filterAwardName !== null) {
    $breadcrumbs[] = ['label' => $filterAwardName, 'url' => buildBaseUrl([], ['award_id'])];
} elseif ($hasAward) {
    $breadcrumbs[] = ['label' => 'All Awards', 'url' => buildBaseUrl([], ['has_award'])];
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
  <link rel="manifest" href="/manifest.json">
  <meta name="theme-color" content="#212529">
  <link rel="apple-touch-icon" href="/app-icons/icon-192.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Book List</title>
    <link rel="stylesheet" href="/theme.css.php">
    <link rel="stylesheet" href="/css/all.min.css" crossorigin="anonymous">
    
    
    
    <script src="js/search.js"></script>
    <!-- Removed jQuery and jQuery UI -->
    <style>
        /* Simple view table-like grid */
        .simple-row {
            display: grid;
            grid-template-columns: var(--scol1,1.5rem) var(--scol2,16rem) var(--scol3,1fr) var(--scol4,7rem) var(--scol5,7rem) var(--scol6,14rem) var(--scol7,10rem);
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
            cursor: default;
        }
        .simple-row .cell-display { cursor: text; }
        .simple-row[data-book-block-id]:hover {
            box-shadow: none;
            transform: none;
            background-color: var(--row-hover, var(--bs-primary-bg-subtle)) !important;
        }
        .simple-row[data-book-block-id].row-selected {
            background-color: var(--row-select, color-mix(in srgb, var(--accent) 20%, transparent)) !important;
            border-bottom-color: var(--row-select, color-mix(in srgb, var(--accent) 40%, transparent));
        }

        /* Modal header and footer use Row A (odd) theme colour for contrast against the body */
        .modal-header,
        .modal-footer {
            background-color: var(--row-stripe-a, var(--bs-tertiary-bg));
        }

        /* Cover preview panel (simple view) */
        #coverPreviewPanel {
            width: 250px;
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
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 0.4rem;
        }
        #coverPreviewPanel .cover-title .cover-dims {
            font-size: 0.65rem;
            font-weight: 400;
            color: var(--bs-secondary-color);
            white-space: nowrap;
            flex-shrink: 0;
        }
        #coverPreviewPanel .cover-author {
            font-size: 0.72rem;
            color: var(--bs-secondary-color);
            word-break: break-word;
        }
        #coverPreviewPanel .cover-description {
            font-size: 0.72rem;
            line-height: 1.5;
            color: var(--bs-body-color);
            overflow-y: auto;
            max-height: 260px;
            border-top: 1px solid var(--bs-border-color);
            padding-top: 0.4rem;
            padding-bottom:8rem;
            margin-top: 0.2rem;
            scrollbar-width: thin;
            scrollbar-color: var(--bs-border-color) transparent;
        }
        #coverPreviewPanel .cover-description::-webkit-scrollbar {
            width: 4px;
        }
        #coverPreviewPanel .cover-description::-webkit-scrollbar-track {
            background: transparent;
        }
        #coverPreviewPanel .cover-description::-webkit-scrollbar-thumb {
            background-color: var(--bs-border-color);
            border-radius: 4px;
        }
        #coverPreviewPanel .cover-description::-webkit-scrollbar-thumb:hover {
            background-color: var(--bs-secondary-color);
        }
        #coverPreviewPanel .cover-description:empty { display: none; }
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
        #simpleHeaderOuter {
            position: sticky;
            top: 4.4rem;
            z-index: 20;
            background: var(--bs-body-bg);
            border-top: 2px solid var(--bs-border-color);
            border-bottom: 2px solid var(--bs-border-color);
            overflow: hidden;
        }
        .simple-row-header {
            font-size: 0.72rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--bs-secondary-color);
            padding-top: 0.3rem;
            padding-bottom: 0.3rem;
        }
        /* Horizontal scroll body for data rows */
        #simpleScrollBody {
            overflow-x: auto;
        }
        /* When columns are all explicit px, rows must expand to their true width
           so the scroll container sees the overflow */
        #contentArea.col-px-mode .simple-row {
            min-width: max-content;
        }
        .simple-row-header > span {
            position: relative;
            overflow: visible;
        }
        /* Column resize handle */
        .col-rz {
            position: absolute;
            top: 0;
            right: -5px;
            width: 10px;
            height: 100%;
            cursor: col-resize;
            z-index: 5;
        }
        .col-rz::after {
            content: '';
            position: absolute;
            top: 15%;
            left: 50%;
            transform: translateX(-50%);
            width: 2px;
            height: 70%;
            background: var(--bs-border-color);
            border-radius: 1px;
            transition: background 0.15s;
        }
        .col-rz:hover::after,
        .col-rz.col-rz-active::after {
            background: var(--bs-primary);
        }

        .title-col {
            max-width: 700px;
            word-break: break-word;
        }

        /* Checkbox label hit area */
        .bulk-select-label {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100%;
            min-height: 2rem;
            cursor: pointer;
            margin: 0;
        }

        /* Inline editing */
        .editable-cell {
            display: flex !important;
            align-items: center;
            gap: 0.2rem;
            min-width: 0;
        }
        .cell-display {
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            min-width: 0;
            cursor: pointer;
        }
        .cell-link-pill {
            flex-shrink: 0;
            border: 1px solid var(--accent, var(--bs-primary));
            border-radius: 999px;
            padding: 0 0.35rem;
            
            font-size: 0.6rem;
            line-height: 1.6;
            text-decoration: none;
            background: #000;
        }
        .inline-edit-wrap {
            display: flex;
            gap: 0.25rem;
            flex: 1;
            min-width: 0;
            position: relative;
        }
        .editable-cell.editing > .cell-display,
        .editable-cell.editing > .cell-link-pill { display: none !important; }
        .book-title.editable-cell.editing { flex: 1; min-width: 0; }
        .inline-suggest {
            position: absolute;
            top: calc(100% + 2px);
            left: 0;
            min-width: 180px;
            max-width: 360px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1060;
            display: none;
        }
        .inline-edit-input {
            flex: 1;
            min-width: 0;
            border: 1px solid var(--bs-primary);
            border-radius: 0.25rem;
            padding: 0.05rem 0.3rem;
            font-size: inherit;
            background: var(--bs-body-bg);
            color: var(--bs-body-color);
        }
        .inline-edit-index {
            width: 3.5rem !important;
            flex: none !important;
        }

        /* Floating bulk toolbar */
        #bulkToolbar {
            position: fixed;
            bottom: 2.5rem;
            left: 50%;
            transform: translateX(-50%);
            z-index: 1040;
            display: flex;
            align-items: center;
            gap: 0.4rem;
            flex-wrap: nowrap;
            padding: 0.45rem 0.9rem;
            background: var(--bs-body-bg);
            border: 1px solid var(--bs-border-color);
            border-radius: 0.6rem;
            box-shadow: 0 4px 18px rgba(0,0,0,0.2);
            white-space: nowrap;
        }
        .object-fit-cover { object-fit: cover; }
.line-clamp-2 {
  display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}


.book-title {
  font-size: 1rem;
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
.metadata-bar {
    background: var(--metabar-bg, #F5F5F5);
    border: 1px solid var(--metabar-border, #CFCFCF);
    border-top: 5px solid var(--metabar-border, #CFCFCF);
    border-radius: .35rem;
    padding: .6rem .9rem;
}
.metadata-bar label {
    font-size: .72rem;
    color: var(--metabar-label, #7A7A7A);
}
.metadata-bar .form-select-sm,
.metadata-bar .form-control-sm {
    min-height: 30px;
    padding-top: 2px;
    padding-bottom: 2px;
}
.progress-bar {
    font-size: 1rem !important;
    line-height: 1rem !important;
}
.title-edit-btn { opacity: 0; transition: opacity .15s; }
.flex-grow-1:hover .title-edit-btn { opacity: 1; }

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
        /* Context menu (simple view) */
        #simpleCtxMenu {
            position: fixed;
            z-index: 9999;
            background: var(--bs-body-bg);
            border: 1px solid var(--bs-border-color);
            border-radius: 0.4rem;
            padding: 0.25rem 0;
            box-shadow: 0 6px 20px rgba(0,0,0,0.2);
            min-width: 215px;
            display: none;
            font-size: 0.82rem;
        }
        .ctx-item {
            padding: 0.38rem 1rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.55rem;
            color: var(--bs-body-color);
            text-decoration: none;
            background: none;
            border: none;
            width: 100%;
            text-align: left;
            white-space: nowrap;
            line-height: 1.3;
        }
        .ctx-item:hover { background: var(--bs-border-color); color: var(--bs-body-color); }
        .ctx-item.ctx-danger { color: var(--bs-danger); }
        .ctx-item.ctx-danger:hover { background: var(--bs-danger-bg-subtle); }
        .ctx-sep { border-top: 1px solid var(--bs-border-color); margin: 0.2rem 0; }

        /* Similar books modal */
        .similar-modal-grid { display: flex; flex-direction: column; gap: 0.75rem; }
        .similar-modal-card { border: 1px solid var(--bs-border-color); border-radius: 6px; padding: 0.75rem; }
        .similar-modal-cover { width: 80px; height: 114px; object-fit: cover; border-radius: 3px; display: block; }
        .similar-modal-cover-ph { width: 80px; height: 114px; border-radius: 3px; background: var(--bs-secondary-bg); display: flex; align-items: center; justify-content: center; color: var(--bs-secondary-color); font-size: 1.4rem; }
        .similar-modal-desc { font-size: 0.8rem; line-height: 1.5; color: var(--bs-secondary-color); margin-top: 0.4rem; display: -webkit-box; -webkit-line-clamp: 4; -webkit-box-orient: vertical; overflow: hidden; }
        .similar-modal-desc.expanded { display: block; -webkit-line-clamp: unset; }
        .similar-modal-desc-toggle { font-size: 0.75rem; cursor: pointer; background: none; border: none; padding: 0; margin-top: 0.15rem; color: var(--bs-link-color); }
        .two-desc-clamped { display: -webkit-box; -webkit-line-clamp: 11; -webkit-box-orient: vertical; overflow: hidden; }
        .two-desc-clamped.expanded { display: block; }
        .two-desc-toggle { display: none; }

        /* Similar books panel */

.similar-thumb-row {
	display: flex;
	gap: 0.75rem;
	flex-wrap: wrap;
}

.similar-thumb-card {
	display: flex;
	flex-direction: column;
	width: 120px;
	color: inherit;
}

.similar-thumb-card:hover .similar-thumb-title {
	text-decoration: underline;
}

.similar-thumb-img {
	position: relative;
	width: 120px;
	height: 190px;
	flex-shrink: 0;
}

.similar-thumb {
	width: 120px;
	height: 190px;
	object-fit: cover;
	border-radius: 3px;
	display: block;
}

.similar-thumb-placeholder {
	width: 120px;
	height: 190px;
	border-radius: 3px;
	background: var(--bs-secondary-bg);
	display: flex;
	align-items: center;
	justify-content: center;
	color: var(--bs-secondary-color);
	font-size: 1.4rem;
}

.similar-thumb-title {
	font-size: 0.72rem;
	font-weight: 600;
	margin-top: 0.3rem;
	line-height: 1.3;
	display: -webkit-box;
	-webkit-line-clamp: 2;
	-webkit-box-orient: vertical;
	overflow: hidden;
	color: var(--bs-body-color);
}

.similar-thumb-author {
	font-size: 0.68rem;
	color: var(--bs-secondary-color);
	line-height: 1.2;
	margin-top: 0.15rem;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

        .similar-series { font-size: 0.65rem; color: var(--bs-secondary-color); margin-top: 0.1rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .similar-in-lib { position: absolute; top: 3px; right: 3px; background: var(--bs-success); color: #fff; font-size: 0.6rem; font-weight: 700; border-radius: 3px; padding: 1px 3px; line-height: 1.2; }

        .similar-in-lib-text-overlay { position: absolute; top: 3px; right: 3px; background: var(--bs-success); color: #fff; font-size: 0.6rem; font-weight: 700; border-radius: 3px; padding: 1px 3px; line-height: 1.2; }




        /* book_row_two sections */
        .two-section-hdr { cursor: pointer; user-select: none; border-radius: 4px; padding: 0.25rem 0.4rem; margin: 0 -0.4rem; }
        .two-section-hdr:hover { background: var(--bs-secondary-bg); }
        .two-section-chevron { font-size: 0.7rem; transition: transform 0.2s; color: var(--bs-secondary-color); }
        .two-section-hdr.open .two-section-chevron { transform: rotate(180deg); }
        .two-scroll-strip { display: flex; gap: 0.75rem; overflow-x: auto; padding: 0.6rem 0 0.4rem; scrollbar-width: thin; scrollbar-color: color-mix(in srgb, var(--bs-secondary-color) 25%, transparent) transparent; }
        .two-scroll-strip::-webkit-scrollbar { height: 3px; }
        .two-scroll-strip::-webkit-scrollbar-thumb { background: color-mix(in srgb, var(--bs-secondary-color) 25%, transparent); border-radius: 2px; }
        .two-strip-wrap { position: relative; }
        .two-strip-arrow { position: absolute; top: 50%; transform: translateY(-50%); z-index: 2; width: 56px; height: 56px; border-radius: 50%; border: 1px solid color-mix(in srgb, var(--bs-border-color) 40%, transparent); background: color-mix(in srgb, var(--bs-body-bg) 70%, transparent); color: var(--bs-secondary-color); cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; box-shadow: none; transition: color .15s, background .15s, border-color .15s; padding: 0; backdrop-filter: blur(4px); }
        .two-strip-arrow:hover { background: color-mix(in srgb, var(--bs-secondary-bg) 80%, transparent); border-color: color-mix(in srgb, var(--bs-border-color) 70%, transparent); color: var(--bs-body-color); }
        .two-strip-arrow-left  { left: 2px; }
        .two-strip-arrow-right { right: 2px; }
        .two-rec-wrap { display: flex; flex-wrap: wrap; gap: 0.75rem; padding: 0.5rem 0 0.25rem; }
        .two-ai-card { width: 170px; border: 1px solid var(--bs-border-color); border-radius: 6px; padding: 0.6rem 0.75rem; display: flex; flex-direction: column; background: var(--bs-body-bg); }
        .two-ai-title { font-size: 0.8rem; font-weight: 600; line-height: 1.3; color: var(--bs-body-color); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .two-ai-author { font-size: 0.72rem; color: var(--bs-secondary-color); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 0.2rem; }
        .two-ai-reason { font-size: 0.72rem; color: var(--bs-secondary-color); line-height: 1.45; display: -webkit-box; -webkit-line-clamp: 5; -webkit-box-orient: vertical; overflow: hidden; margin-top: 0.4rem; flex-grow: 1; }
        .two-rev-stack { display: flex; flex-direction: column; gap: 0.6rem; padding: 0.5rem 0 0.25rem; }
        .two-rev-item { border: 1px solid var(--bs-border-color); border-radius: 6px; padding: 0.6rem 0.75rem; }
        .two-rev-show-more { font-size: 0.8rem; color: var(--bs-link-color); background: none; border: none; padding: 0; cursor: pointer; }
    </style>
</head>
<body class="pt-5" data-page="<?php echo $page; ?>" data-total-pages="<?php echo $totalPages; ?>" data-base-url="<?php echo htmlspecialchars($baseUrl, ENT_QUOTES); ?>" data-per-page="<?php echo $perPage; ?>" data-total-items="<?php echo $totalLibraryBooks; ?>">
<?php
$showOffcanvas = true;
$viewOptions   = ['list' => 'Default', 'simple' => 'Transfer', 'two' => 'Browse'];
include "navbar.php";
?>
<div class="container-fluid my-4">
    <div class="row">
       
        


<!-- Sidebar -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="sidebarMenu">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title">Filters</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        
        
<?php
// Sections with an active filter are forced open regardless of localStorage
$sbActiveDevice  = $filterNotOnDevice || $filterOnDevice;
$sbActiveShelves = $shelfName !== '';
$sbActiveStatus  = $statusName !== '';
$sbActiveFile    = $fileType !== '';
$sbActiveAwards  = !empty($hasAward) || !empty($awardId);
$sbActiveGr      = !empty($filterNoGrRating) || !empty($filterGrZeroRating) || !empty($filterGrLowReviews);
$sbActiveGenres  = $genreName !== '' || !empty($genreNone);

// Helper: emit collapse toggle attrs for a sidebar section
// $id       = collapse target id
// $active   = PHP-side force-open flag
// $default  = default open (true) or closed (false) when no localStorage value
function sbToggle(string $id, bool $active, bool $default = true): string {
    $show = $active ? 'true' : ($default ? 'true' : 'false');
    $cls  = ($show === 'false') ? ' collapsed' : '';
    return 'data-bs-toggle="collapse" data-bs-target="#' . $id . '" aria-controls="' . $id . '"'
         . ' aria-expanded="' . $show . '" data-sb-default="' . ($default ? '1' : '0') . '"'
         . ' data-sb-force="' . ($active ? '1' : '0') . '" class="sb-toggle' . $cls . '"';
}
function sbCollapse(string $id, bool $active, bool $default = true): string {
    $show = $active || $default;
    return 'class="collapse' . ($show ? ' show' : '') . '" id="' . $id . '"';
}
?>
<style>
.sb-toggle { background: none; border: none; padding: 0.25rem 0; width: 100%; text-align: left;
             display: flex; align-items: center; justify-content: space-between;
             color: inherit; font-weight: 700; font-size: 1rem; letter-spacing: 0.01em; }
.sb-toggle:hover { opacity: 0.8; }
.sb-chevron { transition: transform 0.2s ease; flex-shrink: 0; font-size: 0.75rem; opacity: 0.55; }
.sb-toggle.collapsed .sb-chevron { transform: rotate(-90deg); }
</style>
<nav>
    <?php if ($deviceTotalCount !== null): ?>
    <div class="mb-3">
        <h6 class="mb-1">
            <button type="button" <?= sbToggle('sbDevice', $sbActiveDevice, false) ?>>
                <span><i class="fa-solid fa-tablet-screen-button me-1"></i>Device</span>
                <i class="fa-solid fa-chevron-down sb-chevron"></i>
            </button>
        </h6>
        <div <?= sbCollapse('sbDevice', $sbActiveDevice, false) ?>>
            <?php $deviceUrlBase = buildBaseUrl([], ['not_on_device']); ?>
            <ul class="list-group mt-1">
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
    </div>
    <?php endif; ?>

    <?php if (!empty($recentlyReadOnDevice)): ?>
    <div class="mb-3">
        <h6 class="mb-1">
            <button type="button" <?= sbToggle('sbRecent', false, false) ?>>
                <span><i class="fa-solid fa-clock-rotate-left me-1"></i>Recently on Device</span>
                <i class="fa-solid fa-chevron-down sb-chevron"></i>
            </button>
        </h6>
        <div <?= sbCollapse('sbRecent', false, false) ?>>
            <ul class="list-group mt-1">
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
        <h6 class="mb-1">
            <button type="button" <?= sbToggle('sbShelves', $sbActiveShelves, false) ?>>
                <span><i class="fa-solid fa-bookmark me-1"></i>Shelves</span>
                <i class="fa-solid fa-chevron-down sb-chevron"></i>
            </button>
        </h6>
        <div <?= sbCollapse('sbShelves', $sbActiveShelves, false) ?>>
            <?php $shelfUrlBase = buildBaseUrl([], ['shelf']); ?>
            <ul class="list-group mt-1" id="shelfList">
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
    </div>

    <!-- Status -->
    <div class="mb-3">
        <h6 class="mb-1">
            <button type="button" <?= sbToggle('sbStatus', $sbActiveStatus, false) ?>>
                <span><i class="fa-solid fa-tag me-1"></i>Status</span>
                <i class="fa-solid fa-chevron-down sb-chevron"></i>
            </button>
        </h6>
        <div <?= sbCollapse('sbStatus', $sbActiveStatus, false) ?>>
            <?php $statusUrlBase = buildBaseUrl([], ['status']); ?>
            <ul class="list-group mt-1" id="statusList">
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
    </div>

    <!-- Genres -->
    <div class="mb-3">
        <h6 class="mb-1">
            <button type="button" <?= sbToggle('sbGenres', $sbActiveGenres, false) ?>>
                <span><i class="fa-solid fa-tags me-1"></i>Genres</span>
                <i class="fa-solid fa-chevron-down sb-chevron"></i>
            </button>
        </h6>
        <div <?= sbCollapse('sbGenres', $sbActiveGenres, false) ?>>
            <?php $genreBase = buildBaseUrl([], ['genre']); ?>
            <ul class="list-group mt-1" id="genreList">
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

    <!-- File Type -->
    <div class="mb-3">
        <h6 class="mb-1">
            <button type="button" <?= sbToggle('sbFileType', $sbActiveFile, false) ?>>
                <span><i class="fa-solid fa-file-lines me-1"></i>File Type</span>
                <i class="fa-solid fa-chevron-down sb-chevron"></i>
            </button>
        </h6>
        <div <?= sbCollapse('sbFileType', $sbActiveFile, false) ?>>
            <?php $ftBase = buildBaseUrl([], ['filetype']); ?>
            <ul class="list-group mt-1" id="fileTypeList">
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
    </div>

    <!-- Awards -->
    <?php if (!empty($awardList)): ?>
    <div class="mb-3">
        <h6 class="mb-1">
            <button type="button" <?= sbToggle('sbAwards', $sbActiveAwards, false) ?>>
                <span><i class="fa-solid fa-trophy me-1"></i>Awards</span>
                <i class="fa-solid fa-chevron-down sb-chevron"></i>
            </button>
        </h6>
        <div <?= sbCollapse('sbAwards', $sbActiveAwards, false) ?>>
            <ul class="list-group mt-1">
                <li class="list-group-item d-flex justify-content-between align-items-center<?= ($hasAward && !$awardId) ? ' active' : '' ?>">
                    <a href="<?= htmlspecialchars(buildBaseUrl(['has_award' => '1'], ['award_id'])) ?>" class="flex-grow-1 text-decoration-none<?= ($hasAward && !$awardId) ? ' text-white' : '' ?>">All Awards</a>
                </li>
                <?php foreach ($awardList as $a): ?>
                    <?php $url = buildBaseUrl(['award_id' => $a['id']], ['has_award']); ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center<?= $awardId === (int)$a['id'] ? ' active' : '' ?>">
                        <a href="<?= htmlspecialchars($url) ?>" class="flex-grow-1 text-truncate text-decoration-none<?= $awardId === (int)$a['id'] ? ' text-white' : '' ?>">
                            <?= htmlspecialchars($a['name']) ?>
                        </a>
                        <span class="badge bg-secondary rounded-pill ms-1" title="<?= (int)$a['won_count'] ?> won"><?= (int)$a['book_count'] ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <!-- Goodreads -->
    <?php if ($noGrRatingCount > 0 || $grZeroRatingCount > 0 || $grLowReviewsCount > 0): ?>
    <div class="mb-3">
        <h6 class="mb-1">
            <button type="button" <?= sbToggle('sbGoodreads', $sbActiveGr, false) ?>>
                <span><i class="fa-brands fa-goodreads me-1"></i>Goodreads</span>
                <i class="fa-solid fa-chevron-down sb-chevron"></i>
            </button>
        </h6>
        <div <?= sbCollapse('sbGoodreads', $sbActiveGr, false) ?>>
            <ul class="list-group mt-1">
                <?php if ($noGrRatingCount > 0): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center<?= $filterNoGrRating ? ' active' : '' ?>">
                    <a href="<?= htmlspecialchars($filterNoGrRating ? buildBaseUrl([], ['no_gr_rating']) : buildBaseUrl(['no_gr_rating' => '1'])) ?>"
                       class="flex-grow-1 text-decoration-none<?= $filterNoGrRating ? ' text-white' : ' text-muted fst-italic' ?>">No GR Rating</a>
                    <span class="badge bg-secondary rounded-pill"><?= $noGrRatingCount ?></span>
                </li>
                <?php endif; ?>
                <?php if ($grZeroRatingCount > 0): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center<?= $filterGrZeroRating ? ' active' : '' ?>">
                    <a href="<?= htmlspecialchars($filterGrZeroRating ? buildBaseUrl([], ['gr_zero_rating']) : buildBaseUrl(['gr_zero_rating' => '1'])) ?>"
                       class="flex-grow-1 text-decoration-none<?= $filterGrZeroRating ? ' text-white' : ' text-muted fst-italic' ?>">Zero GR Rating</a>
                    <span class="badge bg-secondary rounded-pill"><?= $grZeroRatingCount ?></span>
                </li>
                <?php endif; ?>
                <?php if ($grLowReviewsCount > 0): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center<?= $filterGrLowReviews ? ' active' : '' ?>">
                    <a href="<?= htmlspecialchars($filterGrLowReviews ? buildBaseUrl([], ['gr_low_reviews']) : buildBaseUrl(['gr_low_reviews' => '1'])) ?>"
                       class="flex-grow-1 text-decoration-none<?= $filterGrLowReviews ? ' text-white' : ' text-muted fst-italic' ?>">&lt;50 Reviews</a>
                    <span class="badge bg-secondary rounded-pill"><?= $grLowReviewsCount ?></span>
                </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

</nav>
<script>
(function () {
    const LS_KEY = 'sidebarCollapse';
    const saved  = JSON.parse(localStorage.getItem(LS_KEY) || '{}');

    // Apply saved state (skip sections with an active filter — they stay open)
    document.querySelectorAll('.sb-toggle').forEach(btn => {
        const id     = btn.dataset.bsTarget?.replace('#', '');
        const forced = btn.dataset.sbForce === '1';
        if (!id || forced) return;
        if (id in saved) {
            const open = saved[id];
            const el   = document.getElementById(id);
            if (!el) return;
            if (open) {
                el.classList.add('show');
                btn.classList.remove('collapsed');
                btn.setAttribute('aria-expanded', 'true');
            } else {
                el.classList.remove('show');
                btn.classList.add('collapsed');
                btn.setAttribute('aria-expanded', 'false');
            }
        }
    });

    // Persist state on toggle
    document.getElementById('sidebarMenu')?.addEventListener('show.bs.collapse', e => {
        saved[e.target.id] = true;
        localStorage.setItem(LS_KEY, JSON.stringify(saved));
    });
    document.getElementById('sidebarMenu')?.addEventListener('hide.bs.collapse', e => {
        saved[e.target.id] = false;
        localStorage.setItem(LS_KEY, JSON.stringify(saved));
    });
})();
</script>
        
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
            $filterAwardName    ? 'award: '   . htmlspecialchars($filterAwardName)                 : null,
            (!$filterAwardName && $hasAward) ? 'has award'                                        : null,
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
      <div id="bulkToolbar">
          <input type="text" id="rowTitleFilter" class="form-control form-control-sm"
                 placeholder="Filter titles…" autocomplete="off"
                 style="width:11rem" title="Filter visible rows by title">
          <input type="checkbox" class="form-check-input" id="bulkSelectAll" title="Select all">
          <button type="button" class="btn btn-sm btn-secondary" id="bulkSelectNotOnDevice" title="Select all not on device">
              <i class="fa-solid fa-paper-plane me-1"></i>Not on device
          </button>
          <button type="button" class="btn btn-sm btn-success" id="bulkSendBtn" disabled>
              <i class="fa-solid fa-paper-plane me-1"></i>Send
          </button>
          <button type="button" class="btn btn-sm btn-warning" id="bulkRemoveDevBtn" disabled>
              <i class="fa-solid fa-tablet-screen-button me-1"></i>Remove
          </button>
          <button type="button" class="btn btn-sm btn-danger" id="bulkDeleteBtn" disabled>
              <i class="fa-solid fa-trash me-1"></i>Delete
          </button>
          <?php if (!empty($transferTargets)): ?>
          <select id="bulkTransferTarget" class="form-select form-select-sm" style="width:auto">
              <?php foreach ($transferTargets as $uname): ?>
              <option value="<?= htmlspecialchars($uname) ?>"><?= htmlspecialchars($uname) ?></option>
              <?php endforeach; ?>
          </select>
          <button type="button" class="btn btn-sm btn-secondary" id="bulkTransferBtn" disabled>
              <i class="fa-solid fa-copy me-1"></i>Copy
          </button>
          <?php endif; ?>
          <button type="button" class="btn btn-sm btn-outline-secondary" id="bulkResetScraperBtn" disabled title="Reset OL &amp; GR scraper status so selected books are re-processed">
              <i class="fa-solid fa-rotate-left me-1"></i>Reset scrapers
          </button>
          <span id="bulkStatus" class="small text-muted ms-1"></span>
      </div>
      <?php endif; ?>
      <?php if ($view === 'simple'): ?>
      <div id="simpleHeaderOuter">
          <div class="simple-row simple-row-header" id="simpleColHeader">
              <span></span>
              <span>Author<div class="col-rz" data-col="2" title="Drag to resize · Double-click to reset"></div></span>
              <span>Title<div class="col-rz" data-col="3" title="Drag to resize · Double-click to reset"></div></span>
              <span>GR<div class="col-rz" data-col="4" title="Drag to resize · Double-click to reset"></div></span>
              <span>GR ID<div class="col-rz" data-col="5" title="Drag to resize · Double-click to reset"></div></span>
              <span>Series<div class="col-rz" data-col="6" title="Drag to resize · Double-click to reset"></div></span>
              <span>Genre<div class="col-rz" data-col="7" title="Drag to resize · Double-click to reset"></div></span>
          </div>
      </div>
      <?php endif; ?>
      <?php if ($view === 'simple'): ?><div id="simpleScrollBody"><?php endif; ?>
      <?php if ($view === 'two'): ?><div class="row g-3"><?php endif; ?>
      <div id="topSpacer" style="height:0"></div>
      <div id="topSentinel"></div>
      <?php render_book_rows($books, $rowTemplateData, $offset); ?>
      <div id="bottomSentinel"></div>
      <div id="bottomSpacer" style="height:0"></div>
      <?php if ($view === 'simple'): ?></div><?php endif; ?>
      <?php if ($view === 'two'): ?></div><?php endif; ?>
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
    <div style="position:relative;cursor:pointer;display:none" id="coverPreviewImgWrap" title="Click to change cover">
        <img id="coverPreviewImg" src="" alt="" style="display:none;width:100%;border-radius:0.25rem;">
        <div id="coverPreviewOverlay"
             style="display:none;position:absolute;inset:0;background:rgba(0,0,0,0.45);border-radius:0.25rem;align-items:center;justify-content:center;color:#fff;font-size:0.75rem;gap:0.3rem;flex-direction:column;">
            <i class="fa-solid fa-camera fa-lg"></i>Change cover
        </div>
    </div>
    <input type="file" id="coverPreviewInput" accept="image/*" style="display:none">
    <div class="cover-title" id="coverPreviewTitle"><span id="coverPreviewTitleText"></span><span class="cover-dims" id="coverPreviewDims"></span></div>
    <div class="cover-author" id="coverPreviewAuthor"></div>
    <div class="cover-description" id="coverPreviewDesc"></div>
</div>
</div><!-- flex container -->
<div id="simpleCtxMenu"></div>
<?php else: ?>
</div><!-- col-md-12 -->
<?php endif; ?>
        </div>

        </div>
    </div>
    <div id="alphabetBar" class="position-fixed bottom-0 start-0 end-0 bg-dark d-flex align-items-center px-3 py-1">
        <?php
        $footerFilterParts = array_column(array_slice($breadcrumbs, 1), 'label');
        $footerFilter      = $footerFilterParts ? ' (' . implode(' › ', $footerFilterParts) . ')' : '';
        ?>
        <?php $clearFiltersUrl = 'list_books.php?view=' . urlencode($view) . '&sort=' . urlencode($sort); ?>
        <div class="small me-2 d-flex align-items-center gap-1" style="flex-shrink:0;max-width:260px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;color:#adb5bd">
            <span style="color:var(--accent);font-weight:600"><?= htmlspecialchars($viewOptions[$view]) ?></span><?php if ($footerFilter): ?><span style="opacity:0.65;overflow:hidden;text-overflow:ellipsis"> <?= htmlspecialchars($footerFilter) ?></span><a href="<?= htmlspecialchars($clearFiltersUrl) ?>" class="text-decoration-none ms-1" style="color:var(--bs-danger);opacity:0.8;flex-shrink:0" title="Clear filters"><i class="fa-solid fa-xmark fa-xs"></i></a><?php endif; ?>
        </div>
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
            $viewIcons = ['list' => 'fa-sharp-duotone fa-solid fa-building-memo', 'simple' => 'fa-duotone fa-solid fa-list', 'two' => 'fa-duotone fa-solid fa-table-cells-large'];
            foreach ($viewIcons as $v => $icon):
                $isActive = $view === $v;
            ?>
            <a href="<?= htmlspecialchars(buildBaseUrl(['view' => $v, 'page' => $page])) ?>"
               class="text-decoration-none view-switch-link" title="<?= htmlspecialchars($viewOptions[$v]) ?>"
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
    <script>
window.seriesList    = <?= json_encode($seriesList, JSON_HEX_TAG) ?>;
window.genreOptions  = <?= json_encode(array_column($genreList, 'value'), JSON_HEX_TAG) ?>;
window.shelfOptions  = <?= json_encode($shelfList, JSON_HEX_TAG) ?>;
window.statusOptions = <?= json_encode($statusOptions, JSON_HEX_TAG) ?>;
    </script>
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
                if (icon) icon.classList.add('fa-spin');
                refreshCacheBtn.style.pointerEvents = 'none';
                try {
                    await fetch('json_endpoints/clear_cache.php', { method: 'POST' });
                    window.location.reload();
                } catch {
                    if (icon) icon.classList.remove('fa-spin');
                    refreshCacheBtn.style.pointerEvents = '';
                }
            });
        }
    });
    </script>
<?php if ($view === 'simple'): ?>
<script>
(function () {
    const filterInput = document.getElementById('rowTitleFilter');
    filterInput.addEventListener('input', () => {
        const q = filterInput.value.trim().toLowerCase();
        document.querySelectorAll('.simple-row[data-book-block-id]').forEach(row => {
            const title  = (row.dataset.title  || '').toLowerCase();
            const author = (row.dataset.author || '').toLowerCase();
            row.style.display = (!q || title.includes(q) || author.includes(q)) ? '' : 'none';
        });
    });
})();
</script>
<script>
(function () {
    const img        = document.getElementById('coverPreviewImg');
    const imgWrap    = document.getElementById('coverPreviewImgWrap');
    const overlay    = document.getElementById('coverPreviewOverlay');
    const coverInput = document.getElementById('coverPreviewInput');
    const empty      = document.getElementById('coverPreviewEmpty');
    const titleEl    = document.getElementById('coverPreviewTitleText');
    const dimsEl     = document.getElementById('coverPreviewDims');
    const authorEl   = document.getElementById('coverPreviewAuthor');
    const descEl     = document.getElementById('coverPreviewDesc');
    let activeBookId = null;

    // Hover overlay on the cover
    imgWrap.addEventListener('mouseenter', () => { if (img.src) overlay.style.display = 'flex'; });
    imgWrap.addEventListener('mouseleave', () => { overlay.style.display = 'none'; });
    imgWrap.addEventListener('click', () => coverInput.click());

    coverInput.addEventListener('change', () => {
        if (!coverInput.files.length || !activeBookId) return;
        const file = coverInput.files[0];
        // Show local preview immediately
        img.src = URL.createObjectURL(file);
        const reader = new FileReader();
        reader.onload = () => {
            const base64 = reader.result.split(',')[1];
            const fd = new FormData();
            fd.append('book_id', activeBookId);
            fd.append('coverdata', base64);
            fetch('json_endpoints/update_metadata.php', {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                body: fd,
            })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'ok' && data.cover_url) {
                    const freshUrl = data.cover_url + '?t=' + Date.now();
                    // Update panel image with cache-busted URL
                    img.src = freshUrl;
                    // Update row's data-cover so re-clicking also shows new cover
                    const row = document.querySelector(`.simple-row[data-book-block-id="${activeBookId}"]`);
                    if (row) row.dataset.cover = freshUrl;
                } else if (data.error) {
                    alert(data.error);
                }
                coverInput.value = '';
            })
            .catch(() => { alert('Cover upload failed'); coverInput.value = ''; });
        };
        reader.readAsDataURL(file);
    });

    function showRow(row) {
        const cover  = row.dataset.cover       || '';
        const title  = row.dataset.title       || '';
        const author = row.dataset.author      || '';
        const desc   = row.dataset.description || '';

        activeBookId = row.dataset.bookBlockId;
        titleEl.textContent  = title;
        authorEl.textContent = author;
        descEl.innerHTML     = desc;
        dimsEl.textContent   = '';
        empty.style.display  = 'none';

        if (cover) {
            img.onload = () => {
                if (img.naturalWidth && img.naturalHeight) {
                    dimsEl.textContent = `${img.naturalWidth}×${img.naturalHeight}`;
                }
            };
            img.src = cover;
            img.style.display = '';
            imgWrap.style.display = '';
        } else {
            img.onload = null;
            img.style.display = 'none';
            imgWrap.style.display = 'none';
            img.src = '';
        }
    }

    let hoverTimer = null;
    const DEBOUNCE_MS = 120;

    // Use event delegation on the content area to avoid attaching to every row
    const contentArea = document.getElementById('contentArea');
    contentArea.addEventListener('mouseover', e => {
        const row = e.target.closest('.simple-row[data-book-block-id]');
        if (!row) return;
        if (row.dataset.bookBlockId === activeBookId) return; // already showing
        clearTimeout(hoverTimer);
        hoverTimer = setTimeout(() => showRow(row), DEBOUNCE_MS);
    });
    contentArea.addEventListener('mouseout', e => {
        const row = e.target.closest('.simple-row[data-book-block-id]');
        if (!row) return;
        // Cancel pending show if mouse leaves before debounce fires
        // but only if not moving into the panel itself
        const to = e.relatedTarget;
        if (to && (to.closest('#coverPreviewPanel') || to.closest('.simple-row[data-book-block-id]'))) return;
        clearTimeout(hoverTimer);
    });
    // Keep panel active while hovering over it
    const panel = document.getElementById('coverPreviewPanel');
    panel.addEventListener('mouseout', e => {
        const to = e.relatedTarget;
        if (to && to.closest('#coverPreviewPanel')) return;
        clearTimeout(hoverTimer);
    });

    // Prevent page scroll when mouse is over the panel; forward wheel delta to description if needed
    panel.addEventListener('wheel', e => {
        e.preventDefault();
        const desc = e.target.closest('.cover-description');
        if (desc) desc.scrollTop += e.deltaY;
    }, { passive: false });

    // ── Context menu ─────────────────────────────────────────────────────────────
    const ctxMenu  = document.getElementById('simpleCtxMenu');
    const descViewModal = document.getElementById('descViewModal')
        ? bootstrap.Modal.getOrCreateInstance(document.getElementById('descViewModal')) : null;
    const reviewsModal = document.getElementById('reviewsModal')
        ? bootstrap.Modal.getOrCreateInstance(document.getElementById('reviewsModal')) : null;
    const coverDlModal = document.getElementById('coverDlModal')
        ? bootstrap.Modal.getOrCreateInstance(document.getElementById('coverDlModal')) : null;
    const grCoverModal = document.getElementById('grCoverModal')
        ? bootstrap.Modal.getOrCreateInstance(document.getElementById('grCoverModal')) : null;
    const ctxOlModal = document.getElementById('openLibraryModal')
        ? bootstrap.Modal.getOrCreateInstance(document.getElementById('openLibraryModal')) : null;
    const ctxLocModal = document.getElementById('locModal')
        ? bootstrap.Modal.getOrCreateInstance(document.getElementById('locModal')) : null;
    let ctxRow = null;

    function hideCtx() { ctxMenu.style.display = 'none'; ctxRow = null; }
    document.addEventListener('click', hideCtx);
    document.addEventListener('keydown', e => { if (e.key === 'Escape') hideCtx(); });

    contentArea.addEventListener('contextmenu', e => {
        const row = e.target.closest('.simple-row[data-book-block-id]');
        if (!row) return;
        e.preventDefault();
        ctxRow = row;

        const bookId     = row.dataset.bookBlockId;
        const title      = row.dataset.title      || '';
        const authorName = row.dataset.author     || '';
        const desc       = row.dataset.description || '';
        const readUrl    = row.dataset.readUrl    || '';

        const authorBtn   = row.querySelector('.author-info-btn');
        const authorId    = authorBtn?.dataset.authorId || '';

        const seriesCell  = row.querySelector('.editable-cell[data-field="series"]');
        const seriesName  = seriesCell?.dataset.seriesName || '';
        const seriesId    = seriesCell?.dataset.seriesId   || '';
        const seriesHref  = seriesId ? `list_books.php?series_id=${encodeURIComponent(seriesId)}` : '';

        const hasOnDevice = !!row.querySelector('.remove-from-device-row');
        const canSendDev  = !!row.querySelector('.send-to-device-row');

        const ids = {};
        (row.dataset.identifiers || '').split('|').forEach(pair => {
            const colon = pair.indexOf(':');
            if (colon > 0) ids[pair.slice(0, colon)] = pair.slice(colon + 1);
        });
        const grUrl = ids.goodreads
            ? `https://www.goodreads.com/book/show/${encodeURIComponent(ids.goodreads)}`
            : `https://www.goodreads.com/search?q=${encodeURIComponent(title)}`;

        const shortAuthor = authorName.split(',')[0].trim();

        const wikiCached = row.querySelector('.wiki-book-btn')?.dataset.wikiCached === '1';
        const amzId = ids.amazon || ids.asin;

        let html = '';

        // View / Edit / Read
        html += `<a class="ctx-item" href="book-view.php?id=${encodeURIComponent(bookId)}"><i class="fa-solid fa-eye fa-fw"></i>View book</a>`;
        html += `<a class="ctx-item" href="book.php?id=${encodeURIComponent(bookId)}"><i class="fa-solid fa-pen-to-square fa-fw"></i>Edit book</a>`;
        if (readUrl) {
            html += `<a class="ctx-item" href="${readUrl}" target="_blank"><i class="fa-regular fa-book-open fa-fw"></i>Read</a>`;
        }

        html += '<div class="ctx-sep"></div>';

        // Author / series navigation
        if (authorId && shortAuthor) {
            html += `<a class="ctx-item" href="list_books.php?author_id=${encodeURIComponent(authorId)}"><i class="fa-solid fa-user fa-fw"></i>All by ${shortAuthor}</a>`;
        }
        if (seriesName && seriesHref) {
            html += `<a class="ctx-item" href="${seriesHref}"><i class="fa-solid fa-books fa-fw"></i>All in &ldquo;${seriesName}&rdquo;</a>`;
        }

        html += '<div class="ctx-sep"></div>';

        // Book info
        if (desc) {
            html += `<button class="ctx-item" data-ctx="desc"><i class="fa-solid fa-align-left fa-fw"></i>Show description</button>`;
        }
        html += `<button class="ctx-item" data-ctx="author"><i class="fa-solid fa-circle-info fa-fw"></i>Author info</button>`;
        html += `<button class="ctx-item" data-ctx="reviews"><i class="fa-solid fa-comments fa-fw"></i>View reviews</button>`;
        if (ids.gr_work_id) {
            const hasSimilar = parseInt(row.dataset.similarCount || '0', 10) > 0;
            html += `<button class="ctx-item" data-ctx="similar"><i class="fa-solid fa-list-ul fa-fw"></i>${hasSimilar ? 'View similar books' : 'Get similar books'}</button>`;
        }

        html += '<div class="ctx-sep"></div>';

        // External links
        html += `<a class="ctx-item" href="${grUrl}" target="_blank" rel="noopener"><i class="fa-brands fa-goodreads fa-fw"></i>Goodreads${ids.goodreads ? '' : ' (search)'}</a>`;
        if (amzId) {
            html += `<a class="ctx-item" href="https://www.amazon.com/dp/${encodeURIComponent(amzId)}" target="_blank" rel="noopener"><i class="fa-brands fa-amazon fa-fw"></i>Amazon</a>`;
        }
        html += `<button class="ctx-item" data-ctx="loc-lookup"><i class="fa-solid fa-landmark fa-fw"></i>Lib. of Congress</button>`;

        html += '<div class="ctx-sep"></div>';

        // Cover downloads
        if (amzId) {
            html += `<button class="ctx-item" data-ctx="dl-cover" data-cover-source="amazon"><i class="fa-brands fa-amazon fa-fw"></i>Download cover (Amazon)</button>`;
        }
        if (ids.kindle_asin) {
            html += `<button class="ctx-item" data-ctx="dl-cover" data-cover-source="kindle"><i class="fa-brands fa-amazon fa-fw"></i>Download cover (Kindle)</button>`;
        }
        if (ids.goodreads) {
            html += `<button class="ctx-item" data-ctx="dl-cover" data-cover-source="goodreads"><i class="fa-brands fa-goodreads fa-fw"></i>Download cover (Goodreads)</button>`;
        }
        if (ids.goodreads || ids.gr_image_url) {
            html += `<button class="ctx-item" data-ctx="dl-cover-gr"><i class="fa-brands fa-goodreads fa-fw"></i>GR Image URL</button>`;
        }

        html += '<div class="ctx-sep"></div>';

        // Research / metadata
        if (authorId && shortAuthor) {
            html += `<a class="ctx-item" href="missing_by_author.php?author=${encodeURIComponent(authorName)}" target="_blank"><i class="fa-solid fa-magnifying-glass-plus fa-fw"></i>Find missing by ${shortAuthor}</a>`;
        }
        if (wikiCached) {
            html += `<button class="ctx-item" data-ctx="wiki"><i class="fa-solid fa-globe fa-fw"></i>Wikipedia</button>`;
        }
        html += `<button class="ctx-item" data-ctx="ol-meta"><i class="fa-solid fa-book fa-fw"></i>OL Metadata</button>`;

        html += '<div class="ctx-sep"></div>';

        // Device / destructive
        if (hasOnDevice) {
            html += `<button class="ctx-item" data-ctx="remove-dev"><i class="fa-solid fa-tablet-screen-button fa-fw"></i>Remove from device</button>`;
        } else if (canSendDev) {
            html += `<button class="ctx-item" data-ctx="send-dev"><i class="fa-solid fa-paper-plane fa-fw"></i>Send to device</button>`;
        }
        html += `<button class="ctx-item ctx-danger" data-ctx="delete"><i class="fa-solid fa-trash fa-fw"></i>Delete</button>`;

        ctxMenu.innerHTML = html;
        ctxMenu.style.display = 'block';

        const vw = window.innerWidth, vh = window.innerHeight;
        const mw = ctxMenu.offsetWidth,  mh = ctxMenu.offsetHeight;
        ctxMenu.style.left = Math.min(e.clientX, vw - mw - 6) + 'px';
        ctxMenu.style.top  = Math.min(e.clientY, vh - mh - 6) + 'px';
    });

    ctxMenu.addEventListener('click', e => {
        const btn = e.target.closest('[data-ctx]');
        if (!btn || !ctxRow) return;
        e.stopPropagation(); // don't bubble to the document click-to-hide handler
        switch (btn.dataset.ctx) {
            case 'desc': {
                const html  = ctxRow.dataset.description || '';
                const title = ctxRow.dataset.title || 'Description';
                document.getElementById('descViewModalLabel').textContent = title;
                document.getElementById('descViewModalBody').innerHTML = html;
                descViewModal?.show();
                break;
            }
            case 'reviews': {
                const bookId2 = ctxRow.dataset.bookBlockId || '';
                const title2  = ctxRow.dataset.title || 'Reviews';
                document.getElementById('reviewsModalLabel').textContent = title2;
                const body = document.getElementById('reviewsModalBody');
                body.innerHTML = '<p class="text-muted">Loading…</p>';
                reviewsModal?.show();
                fetch(`json_endpoints/book_reviews.php?book_id=${encodeURIComponent(bookId2)}`)
                    .then(r => r.json())
                    .then(data => {
                        if (!data.reviews || data.reviews.length === 0) {
                            body.innerHTML = '<p class="text-muted fst-italic">No reviews found.</p>';
                            return;
                        }
                        body.innerHTML = data.reviews.map(rv => {
                            const stars = rv.rating ? '★'.repeat(rv.rating) + '☆'.repeat(5 - rv.rating) : '';
                            const likeStr = rv.like_count ? rv.like_count + ' likes' : '';
                            const metaParts = [stars, rv.review_date, likeStr].filter(Boolean).join(' · ');
                            return `<div class="mb-4">
                                <div class="d-flex align-items-baseline gap-2 mb-1 p-2 bg-primary">
                                    <span style="font-size:1.05rem;font-weight:600;color:var(--bs-emphasis-color)">${rv.reviewer || 'Anonymous'}</span>
                                    ${metaParts ? `<span class="small text-muted">${metaParts}</span>` : ''}
                                </div>
                                <div class="mb-2">${rv.text || ''}</div>
                                <hr style="border-color:var(--bs-border-color);opacity:0.6;margin:0">
                            </div>`;
                        }).join('');
                    })
                    .catch(() => { body.innerHTML = '<p class="text-danger">Failed to load reviews.</p>'; });
                break;
            }
            case 'similar':
                window.openSimilarModal(ctxRow.dataset.bookBlockId, ctxRow.dataset.title || 'Similar Books');
                break;
            case 'ol-meta': {
                const olMeta = ctxRow.querySelector('.openlibrary-meta');
                const olid   = olMeta?.dataset.olid || '';
                const bookId = ctxRow.dataset.bookBlockId || '';
                const resultsEl = document.getElementById('openLibraryResults');
                if (resultsEl) resultsEl.innerHTML = '<div class="d-flex justify-content-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading…</span></div></div>';
                ctxOlModal?.show();
                if (!olid) {
                    if (resultsEl) resultsEl.textContent = 'No Open Library ID on this book — use the OL Metadata button from the book detail page to search.';
                    break;
                }
                fetch(`json_endpoints/ol_local_lookup.php?olid=${encodeURIComponent(olid)}`)
                    .then(r => r.json())
                    .then(data => {
                        if (!data.books || data.books.length === 0) {
                            if (resultsEl) resultsEl.textContent = 'Not found in local Open Library mirror.';
                            return;
                        }
                        const html = data.books.map(b => {
                            const title     = escapeHTML(b.title     || '');
                            const author    = escapeHTML(b.authors   || '');
                            const year      = escapeHTML(b.year      || '');
                            const publisher = escapeHTML(b.publisher || '');
                            const imgUrl    = escapeHTML(b.cover     || '');
                            const desc      = escapeHTML(b.description || '');
                            const isbn      = escapeHTML(b.isbn      || '');
                            const link      = escapeHTML(b.source_link || '');
                            const rawKey    = b.key || '';
                            const bookOlid  = escapeHTML(rawKey.startsWith('/works/') ? rawKey.slice(7) : '');
                            const subjects  = (b.subjects || []).slice(0, 8).map(s => `<span class="badge bg-secondary me-1">${escapeHTML(s)}</span>`).join('');
                            return `<div class="mb-3 p-3 border rounded">
                              <div class="d-flex gap-3">
                                ${imgUrl ? `<img src="${imgUrl}" style="height:120px;width:auto;object-fit:cover" class="flex-shrink-0">` : ''}
                                <div class="flex-grow-1">
                                  <div class="fw-semibold">${title}${link ? ` <a href="${link}" target="_blank" rel="noopener" class="ms-1 small text-muted"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>` : ''}</div>
                                  ${author    ? `<div class="text-muted small">${author}</div>` : ''}
                                  ${year || publisher ? `<div class="text-muted small">${[publisher, year].filter(Boolean).join(' · ')}</div>` : ''}
                                  ${isbn      ? `<div class="text-muted small">ISBN: ${isbn}</div>` : ''}
                                  ${bookOlid  ? `<div class="text-muted small">OLID: ${bookOlid}</div>` : ''}
                                  ${desc      ? `<div class="mt-2 small">${desc}</div>` : ''}
                                  ${subjects  ? `<div class="mt-2">${subjects}</div>` : ''}
                                  <div class="mt-2 d-flex gap-2 flex-wrap">
                                    ${imgUrl  ? `<button type="button" class="btn btn-sm btn-outline-primary openlibrary-use" data-book-id="${bookId}" data-title="" data-authors="" data-year="${year}" data-imgurl="${imgUrl}" data-description="" data-publisher="${publisher}" data-isbn="${isbn}" data-olid="${bookOlid}"><i class="fa-solid fa-image me-1"></i>Use Cover</button>` : ''}
                                    ${desc    ? `<button type="button" class="btn btn-sm btn-outline-secondary openlibrary-use" data-book-id="${bookId}" data-title="" data-authors="" data-year="${year}" data-imgurl="" data-description="${escapeHTML(b.description||'')}" data-publisher="${publisher}" data-isbn="${isbn}" data-olid="${bookOlid}"><i class="fa-solid fa-align-left me-1"></i>Use Description</button>` : ''}
                                    ${imgUrl && desc ? `<button type="button" class="btn btn-sm btn-outline-success openlibrary-use" data-book-id="${bookId}" data-title="" data-authors="" data-year="${year}" data-imgurl="${imgUrl}" data-description="${escapeHTML(b.description||'')}" data-publisher="${publisher}" data-isbn="${isbn}" data-olid="${bookOlid}"><i class="fa-solid fa-circle-check me-1"></i>Use Both</button>` : ''}
                                    ${isbn    ? `<button type="button" class="btn btn-sm btn-outline-secondary openlibrary-use" data-book-id="${bookId}" data-title="" data-authors="" data-year="${year}" data-imgurl="" data-description="" data-publisher="${publisher}" data-isbn="${isbn}" data-olid="${bookOlid}"><i class="fa-solid fa-barcode me-1"></i>Use ISBN</button>` : ''}
                                  </div>
                                </div>
                              </div>
                            </div>`;
                        }).join('');
                        if (resultsEl) resultsEl.innerHTML = html;
                    })
                    .catch(() => {
                        if (resultsEl) resultsEl.textContent = 'Error fetching from local Open Library mirror.';
                    });
                break;
            }
            case 'loc-lookup': {
                const locTitle  = ctxRow.dataset.title  || '';
                const locAuthor = ctxRow.dataset.author || '';
                hideCtx();
                const locBody = document.getElementById('locModalBody');
                if (locBody) locBody.innerHTML = '<div class="d-flex justify-content-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading…</span></div></div>';
                ctxLocModal?.show();
                fetch(`json_endpoints/loc_lookup.php?title=${encodeURIComponent(locTitle)}&author=${encodeURIComponent(locAuthor)}`)
                    .then(r => r.json())
                    .then(data => {
                        if (!locBody) return;
                        if (data.error) { locBody.innerHTML = `<div class="alert alert-danger">${escapeHTML(data.error)}</div>`; return; }
                        if (!data.results || !data.results.length) { locBody.innerHTML = '<p class="text-muted">No matching records found in the LOC catalog.</p>'; return; }
                        const moreNote = data.total > data.results.length ? `<p class="text-muted small mb-3">${data.results.length} of ${data.total} total matches shown.</p>` : '';
                        locBody.innerHTML = moreNote + data.results.map(r => {
                            const lccnLink = r.loc_url ? `<a href="${escapeHTML(r.loc_url)}" target="_blank" rel="noopener" class="text-muted small ms-1"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>` : '';
                            const isbns   = (r.isbn || []).join(', ');
                            const subjs   = (r.subjects || []).map(s => `<span class="badge bg-secondary me-1 mb-1">${escapeHTML(s)}</span>`).join('');
                            const genres  = (r.genres  || []).map(g => `<span class="badge bg-info text-dark me-1 mb-1">${escapeHTML(g)}</span>`).join('');
                            return `<div class="mb-3 p-3 border rounded">
                              <div class="fw-semibold">${escapeHTML(r.title)}${lccnLink}</div>
                              ${r.author    ? `<div class="text-muted small">${escapeHTML(r.author)}</div>` : ''}
                              ${(r.publisher || r.place || r.date) ? `<div class="text-muted small">${escapeHTML([r.place, r.publisher, r.date].filter(Boolean).join(' · '))}</div>` : ''}
                              ${r.edition   ? `<div class="text-muted small"><em>${escapeHTML(r.edition)}</em></div>` : ''}
                              ${r.lccn      ? `<div class="text-muted small">LCCN: ${escapeHTML(r.lccn)}</div>` : ''}
                              ${isbns       ? `<div class="text-muted small">ISBN: ${escapeHTML(isbns)}</div>` : ''}
                              ${r.lcc       ? `<div class="text-muted small">LCC: ${escapeHTML(r.lcc)}</div>` : ''}
                              ${subjs || genres ? `<div class="mt-2">${genres}${subjs}</div>` : ''}
                            </div>`;
                        }).join('');
                    })
                    .catch(() => { if (locBody) locBody.innerHTML = '<div class="alert alert-danger">Error contacting LOC catalog.</div>'; });
                break;
            }
            case 'wiki':       ctxRow.querySelector('.wiki-book-btn')?.click();          break;
            case 'author':     ctxRow.querySelector('.author-info-btn')?.click();        break;
            case 'send-dev':   ctxRow.querySelector('.send-to-device-row')?.click();     break;
            case 'remove-dev': ctxRow.querySelector('.remove-from-device-row')?.click(); break;
            case 'delete':     ctxRow.querySelector('.delete-book')?.click();            break;
            case 'dl-cover': {
                const dlBookId     = ctxRow.dataset.bookBlockId;
                const dlRow        = ctxRow;
                const dlSource     = btn.dataset.coverSource || '';

                // Reset modal state fully (handles re-open after interrupted save)
                document.getElementById('coverDlSpinner').style.display  = '';
                document.getElementById('coverDlPreview').style.display  = 'none';
                document.getElementById('coverDlError').style.display    = 'none';
                const saveBtn = document.getElementById('coverDlSaveBtn');
                saveBtn.style.display = 'none';
                saveBtn.disabled      = false;
                saveBtn.innerHTML     = '<i class="fa-solid fa-floppy-disk me-1"></i>Use this cover';
                saveBtn.onclick       = null;
                coverDlModal?.show();

                const fd = new FormData();
                fd.append('book_id', dlBookId);
                if (dlSource) fd.append('source', dlSource);
                fetch('json_endpoints/fetch_cover_preview.php', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(data => {
                        document.getElementById('coverDlSpinner').style.display = 'none';
                        if (data.error) {
                            const errEl = document.getElementById('coverDlError');
                            errEl.textContent = data.error;
                            errEl.style.display = '';
                            return;
                        }
                        const srcLabel = data.source === 'amazon' ? 'Amazon' : data.source === 'kindle' ? 'Kindle' : 'Goodreads';
                        const srcClass = data.source === 'amazon' ? 'bg-warning text-dark' : data.source === 'kindle' ? 'bg-warning text-dark' : 'bg-success';
                        document.getElementById('coverDlSource').className = `badge fs-6 ${srcClass}`;
                        document.getElementById('coverDlSource').textContent = srcLabel;
                        document.getElementById('coverDlDims').textContent =
                            data.width && data.height ? `${data.width} × ${data.height}px` : '';
                        document.getElementById('coverDlSize').textContent =
                            data.size_kb ? `${data.size_kb} KB` : '';
                        document.getElementById('coverDlImg').src = data.preview_url + '?t=' + Date.now();
                        document.getElementById('coverDlPreview').style.display = '';
                        saveBtn.style.display = '';
                        saveBtn.onclick = () => {
                            saveBtn.disabled = true;
                            saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving…';
                            const fd2 = new FormData();
                            fd2.append('book_id', dlBookId);
                            const ctrl    = new AbortController();
                            const timeout = setTimeout(() => ctrl.abort(), 15000);
                            fetch('json_endpoints/save_cover_download.php', { method: 'POST', body: fd2, signal: ctrl.signal })
                                .then(r => { clearTimeout(timeout); return r.json(); })
                                .then(d2 => {
                                    if (d2.ok) {
                                        dlRow.dataset.cover = d2.cover_url;
                                        if (activeBookId == dlBookId) {
                                            const previewImg = document.getElementById('coverPreviewImg');
                                            if (previewImg) previewImg.src = d2.cover_url;
                                        }
                                        coverDlModal?.hide();
                                    } else {
                                        saveBtn.disabled = false;
                                        saveBtn.innerHTML = '<i class="fa-solid fa-floppy-disk me-1"></i>Use this cover';
                                        document.getElementById('coverDlError').textContent = d2.error || 'Save failed';
                                        document.getElementById('coverDlError').style.display = '';
                                    }
                                })
                                .catch(err => {
                                    clearTimeout(timeout);
                                    saveBtn.disabled = false;
                                    saveBtn.innerHTML = '<i class="fa-solid fa-floppy-disk me-1"></i>Use this cover';
                                    document.getElementById('coverDlError').textContent =
                                        err.name === 'AbortError' ? 'Save timed out — try again' : 'Request failed';
                                    document.getElementById('coverDlError').style.display = '';
                                });
                        };
                    })
                    .catch(() => {
                        document.getElementById('coverDlSpinner').style.display = 'none';
                        const errEl = document.getElementById('coverDlError');
                        errEl.textContent = 'Request failed';
                        errEl.style.display = '';
                    });
                break;
            }
            case 'dl-cover-gr': {
                const grBookId = ctxRow.dataset.bookBlockId;
                const grRow    = ctxRow;

                // Reset modal
                document.getElementById('grCoverError').style.display    = 'none';
                document.getElementById('grCoverLocalCol').innerHTML     = '<div class="text-muted small mb-2 fw-semibold">Local (gr_covers)</div>';
                document.getElementById('grCoverCdnCol').innerHTML       = '<div class="text-muted small mb-2 fw-semibold">CDN (gr_image_url)</div>';
                grCoverModal?.show();

                const fd = new FormData();
                fd.append('book_id', grBookId);
                fetch('json_endpoints/fetch_gr_dual_cover.php', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(data => {
                        if (data.error) {
                            document.getElementById('grCoverError').textContent  = data.error;
                            document.getElementById('grCoverError').style.display = '';
                            return;
                        }

                        function buildCoverCol(el, info, source) {
                            if (!info) {
                                el.innerHTML += '<p class="text-muted fst-italic small">Not available</p>';
                                return;
                            }
                            const dims = info.width && info.height ? `${info.width} × ${info.height}px` : '';
                            const size = info.size_kb ? `${info.size_kb} KB` : '';
                            const btnId = 'grCoverSave_' + source;
                            el.innerHTML += `
                                <img src="${info.url}?t=${Date.now()}" alt="Cover"
                                     style="max-width:100%;max-height:360px;border-radius:0.35rem;box-shadow:0 2px 12px rgba(0,0,0,0.35)">
                                <div class="mt-2 d-flex justify-content-center gap-2 flex-wrap">
                                    ${dims ? `<span class="text-muted small">${dims}</span>` : ''}
                                    ${size ? `<span class="text-muted small">${size}</span>` : ''}
                                </div>
                                <button class="btn btn-primary btn-sm mt-2" id="${btnId}">
                                    <i class="fa-solid fa-floppy-disk me-1"></i>Use this cover
                                </button>
                                <div class="text-danger small mt-1" id="${btnId}_err" style="display:none"></div>`;
                            document.getElementById(btnId).onclick = function() {
                                const btn = this;
                                btn.disabled = true;
                                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving…';
                                const fd2 = new FormData();
                                fd2.append('book_id', grBookId);
                                fd2.append('source', source);
                                fetch('json_endpoints/save_gr_cover.php', { method: 'POST', body: fd2 })
                                    .then(r => r.json())
                                    .then(d2 => {
                                        if (d2.ok) {
                                            grRow.dataset.cover = d2.cover_url;
                                            if (activeBookId == grBookId) {
                                                const previewImg = document.getElementById('coverPreviewImg');
                                                if (previewImg) previewImg.src = d2.cover_url;
                                            }
                                            grCoverModal?.hide();
                                        } else {
                                            btn.disabled = false;
                                            btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-1"></i>Use this cover';
                                            const errEl2 = document.getElementById(btnId + '_err');
                                            errEl2.textContent   = d2.error || 'Save failed';
                                            errEl2.style.display = '';
                                        }
                                    })
                                    .catch(() => {
                                        btn.disabled = false;
                                        btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-1"></i>Use this cover';
                                        const errEl2 = document.getElementById(btnId + '_err');
                                        errEl2.textContent   = 'Request failed';
                                        errEl2.style.display = '';
                                    });
                            };
                        }

                        buildCoverCol(document.getElementById('grCoverLocalCol'), data.local, 'local');
                        buildCoverCol(document.getElementById('grCoverCdnCol'), data.cdn, 'cdn');
                        if (!data.cdn && data.cdn_error) {
                            document.getElementById('grCoverCdnCol').innerHTML +=
                                `<p class="text-danger small">${data.cdn_error}</p>`;
                        }
                    })
                    .catch(() => {
                        document.getElementById('grCoverError').textContent  = 'Request failed';
                        document.getElementById('grCoverError').style.display = '';
                    });
                break;
            }
        }
        hideCtx();
    });
})();
</script>
<script>
/* Column resize for simple view */
(function () {
    const header     = document.getElementById('simpleColHeader');
    if (!header) return;
    const ca         = document.getElementById('contentArea');
    const scrollBody = document.getElementById('simpleScrollBody');
    const STORAGE_KEY = 'simpleViewColWidths_v2';
    const COL_COUNT = 7;

    function applyWidths(widths) {
        widths.forEach((w, i) => ca.style.setProperty('--scol' + (i + 1), w));
    }

    // Restore saved widths (and activate px-mode so rows expand to true width)
    try {
        const saved = JSON.parse(localStorage.getItem(STORAGE_KEY));
        if (Array.isArray(saved) && saved.length === COL_COUNT) {
            applyWidths(saved);
            ca.classList.add('col-px-mode');
        }
    } catch (e) {}

    // Sync header position with horizontal scroll of the data body
    if (scrollBody) {
        scrollBody.addEventListener('scroll', () => {
            header.style.transform = 'translateX(-' + scrollBody.scrollLeft + 'px)';
        });
    }

    let state = null;

    header.addEventListener('mousedown', e => {
        const handle = e.target.closest('.col-rz');
        if (!handle) return;
        e.preventDefault();

        const col   = parseInt(handle.dataset.col); // 1-based
        const spans = [...header.querySelectorAll(':scope > span')];

        // Snapshot all current widths as px so 1fr becomes a fixed value,
        // then activate px-mode so rows expand to their true width
        spans.forEach((s, i) => {
            ca.style.setProperty('--scol' + (i + 1), s.getBoundingClientRect().width + 'px');
        });
        ca.classList.add('col-px-mode');

        handle.classList.add('col-rz-active');
        document.body.style.cursor     = 'col-resize';
        document.body.style.userSelect = 'none';

        const nextCol = col + 1;
        state = {
            col,
            nextCol,
            handle,
            startX:     e.clientX,
            startW:     spans[col - 1].getBoundingClientRect().width,
            startWNext: nextCol <= spans.length ? spans[nextCol - 1].getBoundingClientRect().width : 0,
        };
    });

    document.addEventListener('mousemove', e => {
        if (!state) return;
        const MIN = 48;
        const dx    = e.clientX - state.startX;
        // Clamp new width and figure out the real delta
        const newW  = Math.max(MIN, state.startW + dx);
        const delta = newW - state.startW;
        ca.style.setProperty('--scol' + state.col, newW + 'px');
        // Right neighbour absorbs the inverse delta so it stays in place
        if (state.startWNext > 0) {
            ca.style.setProperty('--scol' + state.nextCol, Math.max(MIN, state.startWNext - delta) + 'px');
        }
    });

    document.addEventListener('mouseup', () => {
        if (!state) return;
        state.handle.classList.remove('col-rz-active');
        document.body.style.cursor     = '';
        document.body.style.userSelect = '';

        // Persist all current widths
        const spans  = [...header.querySelectorAll(':scope > span')];
        const widths = spans.map((s, i) => ca.style.getPropertyValue('--scol' + (i + 1)) || (s.getBoundingClientRect().width + 'px'));
        localStorage.setItem(STORAGE_KEY, JSON.stringify(widths));
        state = null;
    });

    // Double-click any handle to reset all columns to CSS defaults
    header.addEventListener('dblclick', e => {
        if (!e.target.closest('.col-rz')) return;
        for (let i = 1; i <= COL_COUNT; i++) ca.style.removeProperty('--scol' + i);
        ca.classList.remove('col-px-mode');
        if (scrollBody) scrollBody.scrollLeft = 0;
        header.style.transform = '';
        localStorage.removeItem(STORAGE_KEY);
    });
})();
</script>
<?php endif; ?>
</body>
</html>

