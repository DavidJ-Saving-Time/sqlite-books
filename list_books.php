<?php
require_once 'db.php';
requireLogin();

$pdo = getDatabaseConnection();
$genreColumnId = (int)$pdo->query("SELECT id FROM custom_columns WHERE label = 'genre'")->fetchColumn();
$genreLinkTable = "books_custom_column_{$genreColumnId}_link";
$totalLibraryBooks = (int)$pdo->query('SELECT COUNT(*) FROM books')->fetchColumn();

// Fetch shelves list
$shelfList = $pdo->query('SELECT name FROM shelves ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
$shelfName = isset($_GET['shelf']) ? trim((string)$_GET['shelf']) : '';
if ($shelfName !== '' && !in_array($shelfName, $shelfList, true)) {
    $shelfName = '';
}

// Locate custom column for reading status
$statusId = (int)$pdo->query("SELECT id FROM custom_columns WHERE label = 'status'")->fetchColumn();
$statusTable = 'books_custom_column_' . $statusId . '_link';
$statusOptions = $pdo->query("SELECT value FROM custom_column_{$statusId} ORDER BY value COLLATE NOCASE")->fetchAll(PDO::FETCH_COLUMN);
$statusIsLink = true;

// Shelf column for recommendations block
$shelfId = (int)$pdo->query("SELECT id FROM custom_columns WHERE label = 'shelf'")->fetchColumn();
$shelfValueTable = "custom_column_{$shelfId}";
$shelfLinkTable  = "books_custom_column_{$shelfId}_link";

$recId = (int)$pdo->query("SELECT id FROM custom_columns WHERE label = 'recommendation'")->fetchColumn();
$recTable = "custom_column_{$recId}";
$recLinkTable = "books_custom_column_{$recId}_link";
$recColumnExists = true;

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
    $searchLower = function_exists('mb_strtolower') ? mb_strtolower($search, 'UTF-8') : strtolower($search);
    $maxDist = max(1, (int)floor(strlen($searchLower) / 3));
    $whereClauses[] = '('
        . 'LOWER(b.title) LIKE :search_like'
        . ' OR levenshtein(LOWER(b.title), :search_lower) <= :search_dist'
        . ' OR EXISTS (SELECT 1 FROM books_authors_link bal'
        . ' JOIN authors a ON bal.author = a.id'
        . ' WHERE bal.book = b.id'
        . ' AND (LOWER(a.name) LIKE :search_like OR levenshtein(LOWER(a.name), :search_lower) <= :search_dist))'
        . ')';
    $params[':search_like'] = '%' . $searchLower . '%';
    $params[':search_lower'] = $searchLower;
    $params[':search_dist'] = $maxDist;
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

// Fetch full genre list for sidebar
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
                       (SELECT GROUP_CONCAT(gv.value, ', ')
                            FROM $genreLinkTable bcc
                            JOIN custom_column_{$genreColumnId} gv ON bcc.value = gv.id
                            WHERE bcc.book = b.id) AS genres,
                       (SELECT GROUP_CONCAT(gv.id || ':' || gv.value, '|')
                            FROM $genreLinkTable bcc
                            JOIN custom_column_{$genreColumnId} gv ON bcc.value = gv.id
                            WHERE bcc.book = b.id) AS genre_data,
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
                LEFT JOIN books_series_link bsl ON bsl.book = b.id
                LEFT JOIN series s ON bsl.series = s.id
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
        }
        unset($b);
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
if ($genreName !== '') {
    $baseUrl .= '&genre=' . urlencode($genreName);
}
if ($shelfName !== '') {
    $baseUrl .= '&shelf=' . urlencode($shelfName);
}
if ($statusName !== '') {
    $baseUrl .= '&status=' . urlencode($statusName);
}
if ($fileType !== '') {
    $baseUrl .= '&filetype=' . urlencode($fileType);
}
if ($search !== '') {
    $baseUrl .= '&search=' . urlencode($search);
}
if ($authorInitial !== '') {
    $baseUrl .= '&author_initial=' . urlencode($authorInitial);
}
$baseUrl .= '&page=';

function render_book_rows(array $books, array $shelfList, array $statusOptions, array $genreList, string $sort, ?int $authorId, ?int $seriesId, int $offset = 0): void {
    foreach ($books as $i => $book) {
        $index = $offset + $i;
        $missing = !bookHasFile($book['path']);
        $firstFile = $missing ? null : firstBookFile($book['path']);
        ?>
       <div id="item-<?= $index ?>" class="row g-3 py-3 border-bottom list-item" data-book-block-id="<?= htmlspecialchars($book['id']) ?>" data-book-index="<?= $index ?>">
            <!-- Left: Thumbnail -->
            <div class="col-md-2 col-12 text-center cover-wrapper">
                <?php if (!empty($book['has_cover'])): ?>
                    <a href="book.php?id=<?= urlencode($book['id']) ?>">
                        <div class="position-relative d-inline-block">
                            <img id="coverImage<?= (int)$book['id'] ?>" src="<?= htmlspecialchars(getLibraryPath() . '/' . $book['path'] . '/cover.jpg') ?>"
                                 alt="Cover"
                                 class="img-thumbnail img-fluid book-cover"
                                 style="width: 100%; max-width:150px; height:auto;">
                            <div id="coverDimensions<?= (int)$book['id'] ?>" class="cover-dimensions position-absolute bottom-0 end-0 bg-dark text-white px-2 py-1 small rounded-top-start opacity-75" style="font-size: 0.8rem;">Loading...</div>
                        </div>
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
                     
                    <a href="book.php?id=<?= urlencode($book['id']) ?>" class="fw-bold book-title me-1"
                       data-book-id="<?= htmlspecialchars($book['id']) ?>">
                         <?= htmlspecialchars($book['title']) ?>
                    </a>
                    <?php if (!empty($book['has_recs'])): ?>
                        <span class="text-success ms-1">&#10003;</span>
                    <?php endif; ?>
                    <?php if (!empty($book['series'])): ?>
                        <div class=" mt-1">
                            <i class="fa-duotone fa-solid fa-arrow-turn-down-right"></i>


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
                        $firstGenreVal = '';
                        if (!empty($book['genre_data'])) {
                            $first = explode('|', $book['genre_data'])[0];
                            if ($first !== '') {
                                [, $gval] = explode(':', $first, 2);
                                $firstGenreVal = $gval;
                            }
                        }
                    ?>
                    <div>
                        <label class="small text-muted mb-1 d-block">Genre</label>
                        <select class="form-select form-select-sm genre-select" data-book-id="<?= htmlspecialchars($book['id']) ?>">
                            <option value=""<?= $firstGenreVal === '' ? ' selected' : '' ?>>None</option>
                            <?php foreach ($genreList as $g): ?>
                                <option value="<?= htmlspecialchars($g['value']) ?>"<?= $g['value'] === $firstGenreVal ? ' selected' : '' ?>>
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
                    <div>
                        <label class="small text-muted mb-1 d-block">Rating</label>
                        <div class="star-rating" data-book-id="<?= htmlspecialchars($book['id']) ?>">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="rating-star me-1 <?= ((int)$book['rating'] >= $i) ? 'fa-solid fa-star text-warning' : 'fa-regular fa-star text-muted' ?>" data-value="<?= $i ?>"></i>
                            <?php endfor; ?>
                            <i class="fa-solid fa-xmark rating-clear ms-1<?= ($book['rating'] > 0) ? '' : ' d-none' ?>" data-value="0" title="Clear rating"></i>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="ms-auto d-flex align-items-end">
                        <?php if ($firstFile):
                            $ftype = strtoupper(pathinfo($firstFile, PATHINFO_EXTENSION));
                            if ($ftype === 'PDF') {
                                $fileUrl = getLibraryPath() . '/' . $firstFile;
                                ?>
                                <a class="btn btn-sm btn-success me-1" target="_blank" href="<?= htmlspecialchars($fileUrl) ?>">Read <?= htmlspecialchars($ftype) ?></a>
                                <?php
                            } else {
                                ?>
                                <a class="btn btn-sm btn-success me-1" href="reader.php?file=<?= urlencode($firstFile) ?>"> <i class="fa-thumbprint fa-light fa-book-open"></i> Read <?= htmlspecialchars($ftype) ?></a>
                                <?php
                            }
                        endif; ?>
                        <button type="button" class="btn btn-sm btn-secondary google-meta me-1"
                                data-book-id="<?= htmlspecialchars($book['id']) ?>"
                                data-search="<?= htmlspecialchars($book['title'] . ' ' . $book['authors'], ENT_QUOTES) ?>">
                            Metadata Google
                        </button>
                        <a class="btn btn-sm btn-primary me-1" href="notes.php?id=<?= urlencode($book['id']) ?>">
                            Notes
                        </a>
                        <button type="button" class="btn btn-sm btn-danger delete-book"
                                data-book-id="<?= htmlspecialchars($book['id']) ?>">
                            Delete
                        </button>
                    </div>
                </div>

                <!-- Description -->
                <div class="small text-muted book-description" data-full="<?php
                        $desc = strip_tags(trim($book['description'] ?? ''));
                        echo htmlspecialchars($desc, ENT_QUOTES);
                    ?>">
                    <?php
                        if ($desc !== '') {
                            $lines = preg_split('/\r?\n/', $desc);
                            $preview = implode("\n", array_slice($lines, 0, 2));
                            $truncated = count($lines) > 2;
                            $maxChars = 300;
                            if (mb_strlen($preview) > $maxChars) {
                                $preview = mb_substr($preview, 0, $maxChars);
                                $preview = preg_replace('/\s+\S*$/u', '', $preview);
                                $truncated = true;
                            }
                            echo nl2br(htmlspecialchars($preview));
                            if ($truncated) {
                                echo '... <a href="#" class="show-more">Show more</a>';
                            }
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
    render_book_rows($books, $shelfList, $statusOptions, $genreList, $sort, $authorId, $seriesId, $offset);
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
    ['label' => 'Books', 'url' => buildBaseUrl([], ['author_id','series_id','genre','shelf','status','filetype','search'])]
];
if ($filterAuthorName !== null) {
    $breadcrumbs[] = ['label' => $filterAuthorName];
}
if ($filterSeriesName !== null) {
    $breadcrumbs[] = ['label' => $filterSeriesName];
}
if ($filterGenreName !== null) {
    $breadcrumbs[] = ['label' => $filterGenreName];
}
if ($filterShelfName !== null) {
    $breadcrumbs[] = ['label' => $filterShelfName];
}
if ($filterStatusName !== null) {
    $breadcrumbs[] = ['label' => $filterStatusName];
}
if ($filterFileTypeName !== null) {
    $breadcrumbs[] = ['label' => strtoupper($filterFileTypeName)];
}
if ($recommendedOnly) {
    $breadcrumbs[] = ['label' => 'Recommended'];
}
if ($search !== '') {
    $breadcrumbs[] = ['label' => 'Search: ' . $search];
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
                <li class="list-group-item<?= $genreName !== '' ? '' : ' active' ?>">
                    <a href="<?= htmlspecialchars($genreBase) ?>" class="stretched-link text-decoration-none<?= $genreName !== '' ? '' : ' text-white' ?>">All Genres</a>
                </li>
                <?php foreach ($genreList as $g): ?>
                    <?php $url = buildBaseUrl(['genre' => $g['value']]); ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center<?= $genreName === $g['value'] ? ' active' : '' ?>">
                        <a href="<?= htmlspecialchars($url) ?>" class="flex-grow-1 text-truncate text-decoration-none<?= $genreName === $g['value'] ? ' text-white' : '' ?>">
                            <?= htmlspecialchars($g['value']) ?>
                        </a>
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-secondary edit-genre" data-genre="<?= htmlspecialchars($g['value']) ?>"><i class="fa-solid fa-pen"></i></button>
                            <button type="button" class="btn btn-outline-danger delete-genre" data-genre="<?= htmlspecialchars($g['value']) ?>"><i class="fa-solid fa-trash"></i></button>
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
      <?php render_book_rows($books, $shelfList, $statusOptions, $genreList, $sort, $authorId, $seriesId, $offset); ?>
      <div id="bottomSentinel"></div>
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
    <div id="alphabetBar" class="position-fixed bottom-0 start-0 end-0 bg-light text-center py-2">
        <?php
        $baseLetterParams = $_GET;
        unset($baseLetterParams['author_initial'], $baseLetterParams['page']);
        foreach (range('A', 'Z') as $letter) {
            $letterParams = $baseLetterParams;
            $letterParams['author_initial'] = $letter;
            $url = 'list_books.php?' . http_build_query($letterParams);
            $active = ($authorInitial === $letter) ? 'fw-bold' : '';
            echo '<a href="' . htmlspecialchars($url) . '" class="mx-1 ' . $active . '">' . $letter . '</a>';
        }
        if ($authorInitial !== '') {
            $url = 'list_books.php?' . http_build_query($baseLetterParams);
            echo '<a href="' . htmlspecialchars($url) . '" class="mx-1">Clear</a>';
        }
        ?>
    </div>
    <a href="#" id="backToTop" class="btn btn-primary position-fixed end-0 m-3 d-none"><i class="fa-solid fa-arrow-up"></i></a>

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

