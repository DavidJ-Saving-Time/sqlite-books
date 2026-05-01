<?php
require_once 'db.php';
requireLogin();

function safe_filename(string $name, int $max_length = 150): string {
    // Transliterate accented Latin characters to ASCII equivalents so that
    // e.g. García → Garcia rather than being silently stripped to Garca.
    if (function_exists('iconv')) {
        $t = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if ($t !== false) {
            $name = $t;
        }
    } else {
        // Fallback map covering common Latin-1 / Latin Extended characters.
        static $from = ['À','Á','Â','Ã','Ä','Å','Æ','Ç','È','É','Ê','Ë',
                        'Ì','Í','Î','Ï','Ð','Ñ','Ò','Ó','Ô','Õ','Ö','Ø',
                        'Ù','Ú','Û','Ü','Ý','Þ','ß','à','á','â','ã','ä',
                        'å','æ','ç','è','é','ê','ë','ì','í','î','ï','ð',
                        'ñ','ò','ó','ô','õ','ö','ø','ù','ú','û','ü','ý',
                        'þ','ÿ','Ł','ł','Ś','ś','Ź','ź','Ż','ż','Ć','ć',
                        'Ń','ń','Č','č','Š','š','Ž','ž','Ř','ř'];
        static $to   = ['A','A','A','A','A','A','AE','C','E','E','E','E',
                        'I','I','I','I','D','N','O','O','O','O','O','O',
                        'U','U','U','U','Y','TH','ss','a','a','a','a','a',
                        'a','ae','c','e','e','e','e','i','i','i','i','d',
                        'n','o','o','o','o','o','o','u','u','u','u','y',
                        'th','y','L','l','S','s','Z','z','Z','z','C','c',
                        'N','n','C','c','S','s','Z','z','R','r'];
        $name = str_replace($from, $to, $name);
    }
    $name = preg_replace('/[^A-Za-z0-9 _.\'-]/', '', $name);
    return substr(trim($name), 0, $max_length);
}

function findBookFileByExtension(string $relativePath, string $extension): ?string {
    $relativePath = ltrim($relativePath, '/');
    if ($relativePath === '') {
        return null;
    }

    $library = getLibraryPath();
    $dir = rtrim($library . '/' . $relativePath, '/');
    if (!is_dir($dir)) {
        return null;
    }

    $extension = strtolower($extension);
    foreach (glob($dir . '/*') as $file) {
        if (!is_file($file)) {
            continue;
        }
        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === $extension) {
            if (strpos($file, $library . '/') === 0) {
                return substr($file, strlen($library) + 1);
            }
            return ltrim($relativePath . '/' . basename($file), '/');
        }
    }

    return null;
}

$pdo = getDatabaseConnection();

$hasSubseries = false;
$subseriesIsCustom = false;
$subseriesLinkTable = '';
$subseriesValueTable = '';
$subseriesIndexColumn = null; // column name for custom subseries index
$subseriesIndexExists = false; // whether any subseries index column exists
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
                $subseriesIndexExists = true;
                break;
            }
        }
    } else {
        $subTable = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='subseries'")->fetchColumn();
        $subLinkTable = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='books_subseries_link'")->fetchColumn();
        if ($subTable && $subLinkTable) {
            $hasSubseries = true;
            $cols = $pdo->query('PRAGMA table_info(books)')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cols as $col) {
                if ($col['name'] === 'subseries_index') {
                    $subseriesIndexExists = true;
                    break;
                }
            }
        }
    }
} catch (PDOException $e) {
    $hasSubseries = false;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    die('Invalid book ID');
}

// Fetch basic book info for editing
$stmt = $pdo->prepare('SELECT * FROM books WHERE id = ?');
$stmt->execute([$id]);
$book = $stmt->fetch(PDO::FETCH_ASSOC);
// Prefer ISBN stored in identifiers table if present
if (!$book) {
    die('Book not found');
}

if ($hasSubseries) {
    if ($subseriesIsCustom) {
        $idxField = $subseriesIndexColumn ? ", bssl.$subseriesIndexColumn AS idx" : ", NULL AS idx";
        $subStmt = $pdo->prepare("SELECT ss.id, ss.value AS name$idxField FROM $subseriesLinkTable bssl JOIN $subseriesValueTable ss ON bssl.value = ss.id WHERE bssl.book = :book LIMIT 1");
        $subStmt->execute([':book' => $id]);
        $row = $subStmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $book['subseries_id'] = $row['id'];
            $book['subseries'] = $row['name'];
            $book['subseries_index'] = $row['idx'];
        } else {
            $book['subseries_id'] = null;
            $book['subseries'] = null;
            $book['subseries_index'] = null;
        }
    } else {
        $idxSelect = $subseriesIndexExists ? 'b.subseries_index' : 'NULL AS subseries_index';
        $subStmt = $pdo->prepare("SELECT ss.id, ss.name, $idxSelect FROM books b LEFT JOIN books_subseries_link bssl ON bssl.book = b.id LEFT JOIN subseries ss ON bssl.subseries = ss.id WHERE b.id = :id");
        $subStmt->execute([':id' => $id]);
        $row = $subStmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $book['subseries_id'] = $row['id'];
            $book['subseries'] = $row['name'];
            $book['subseries_index'] = $row['subseries_index'];
        } else {
            $book['subseries_id'] = null;
            $book['subseries'] = null;
            $book['subseries_index'] = null;
        }
    }
}
$commentStmt = $pdo->prepare('SELECT text FROM comments WHERE book = ?');
$commentStmt->execute([$id]);
$description = $commentStmt->fetchColumn() ?: '';

// Fetch awards for this book (table may not exist yet on uninitialized DBs)
$bookAwards = [];
try {
    $stmt = $pdo->prepare(
        'SELECT ba.id, ba.award_id, a.name AS award_name, ba.year, ba.category, ba.result
         FROM book_awards ba JOIN awards a ON ba.award_id = a.id
         WHERE ba.book_id = ? ORDER BY ba.year DESC, a.name COLLATE NOCASE'
    );
    $stmt->execute([$id]);
    $bookAwards = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // awards tables not yet created — run init_schema.php
}

$bookReviews = [];
try {
    $stmt = $pdo->prepare(
        'SELECT reviewer, reviewer_url, rating, review_date, text, like_count, spoiler
         FROM book_reviews WHERE book = ? ORDER BY like_count DESC LIMIT 5'
    );
    $stmt->execute([$id]);
    $bookReviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // table not yet created
}
$notes = '';

$returnPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : null;
$returnItem = isset($_GET['item']) ? preg_replace('/[^A-Za-z0-9_-]/', '', $_GET['item']) : '';
$backToListUrl = 'list_books.php';
$backParams = [];
if ($returnPage) {
    $backParams['page'] = $returnPage;
}
if ($backParams) {
    $backToListUrl .= '?' . http_build_query($backParams);
}
if ($returnItem !== '') {
    $backToListUrl .= '#' . $returnItem;
}

$updated = false;
$fsWarning = null;
$sendMessage = null;
$conversionMessage = null;
$epubFileRel = '';
$pdfFileRel  = '';
$convertRequested      = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['convert_to_pdf']));
$convertEpubRequested  = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['convert_to_epub']));
$sendRequested         = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['send_to_device'] ?? '') === '1');

if ($convertRequested || $convertEpubRequested) {
    require __DIR__ . '/book_actions/convert.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$convertRequested && !$convertEpubRequested && !$sendRequested) {
    require __DIR__ . '/book_actions/save_metadata.php';
}


$sort = $_GET['sort'] ?? 'title';

// ── Prev / Next navigation ────────────────────────────────────────────────────
// Use ROW_NUMBER() CTE mirroring the exact ORDER BY from list_books.php so
// that prev/next always matches the list view order.
$authorSubquery = "
    SELECT bal.book, GROUP_CONCAT(a.name, '|') AS authors
    FROM books_authors_link bal
    JOIN authors a ON bal.author = a.id
    GROUP BY bal.book";

$navOrderExpr = match($sort) {
    'title'                 => "b.sort ASC, b.id ASC",
    'author'                => "au.authors ASC, b.sort ASC, b.id ASC",
    'author_series'         => "au.authors ASC, COALESCE(s.name,'') ASC, b.series_index ASC, b.sort ASC, b.id ASC",
    'author_series_surname' => "b.author_sort ASC, COALESCE(s.name,'') ASC, b.series_index ASC, b.sort ASC, b.id ASC",
    'series'                => "COALESCE(s.name,'') ASC, b.series_index ASC, b.sort ASC, b.id ASC",
    'last_modified'         => "b.last_modified DESC, b.id DESC",
    default                 => "au.authors ASC, COALESCE(s.name,'') ASC, b.series_index ASC, b.sort ASC, b.id ASC",
};

$navFromClause = "FROM books b
    LEFT JOIN ($authorSubquery) au ON au.book = b.id
    LEFT JOIN books_series_link bsl ON bsl.book = b.id
    LEFT JOIN series s ON bsl.series = s.id";

$navSql = "
    WITH ordered AS (
        SELECT b.id,
               ROW_NUMBER() OVER (ORDER BY $navOrderExpr) AS rn
        $navFromClause
    ),
    cur AS (SELECT rn FROM ordered WHERE id = :cur_id)
    SELECT b.id, b.title,
           CASE WHEN o.rn = (SELECT rn FROM cur) - 1 THEN 'prev' ELSE 'next' END AS dir
    FROM ordered o
    JOIN books b ON b.id = o.id
    WHERE o.rn IN ((SELECT rn FROM cur) - 1, (SELECT rn FROM cur) + 1)
";

$navStmt = $pdo->prepare($navSql);
$navStmt->execute([':cur_id' => $id]);
$prevBook = null;
$nextBook = null;
foreach ($navStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if ($row['dir'] === 'prev') $prevBook = $row;
    else $nextBook = $row;
}

// Build a URL for a nav target, preserving sort/page params
function navBookUrl(int $bookId, string $sort, ?int $page): string {
    $p = ['id' => $bookId];
    if ($sort !== 'title') $p['sort'] = $sort;
    if ($page)             $p['page'] = $page;
    return 'book.php?' . http_build_query($p);
}

// Prepare subseries fields if available
$subseriesSelect = '';
$subseriesJoin = '';
if ($hasSubseries) {
    if ($subseriesIsCustom) {
        $idxExpr = $subseriesIndexColumn ? "bssl.$subseriesIndexColumn" : 'NULL';
        $subseriesSelect = ", $idxExpr AS subseries_index, ss.id AS subseries_id, ss.value AS subseries";
        $subseriesJoin = " LEFT JOIN $subseriesLinkTable bssl ON bssl.book = b.id LEFT JOIN $subseriesValueTable ss ON bssl.value = ss.id";
    } else {
        $idxExpr = $subseriesIndexExists ? 'b.subseries_index' : 'NULL';
        $subseriesSelect = ", $idxExpr AS subseries_index, ss.id AS subseries_id, ss.name AS subseries";
        $subseriesJoin = " LEFT JOIN books_subseries_link bssl ON bssl.book = b.id LEFT JOIN subseries ss ON bssl.subseries = ss.id";
    }
}

// Fetch full book details for display
$stmt = $pdo->prepare("SELECT b.*,
        (SELECT GROUP_CONCAT(name, ', ') FROM (
            SELECT a.name FROM books_authors_link bal JOIN authors a ON bal.author = a.id
            WHERE bal.book = b.id ORDER BY bal.id)) AS authors,
        (SELECT GROUP_CONCAT(aid || ':' || aname, '|') FROM (
            SELECT a.id AS aid, a.name AS aname FROM books_authors_link bal JOIN authors a ON bal.author = a.id
            WHERE bal.book = b.id ORDER BY bal.id)) AS author_data,
        s.id AS series_id,
        s.name AS series,
        (SELECT name FROM publishers WHERE publishers.id IN
            (SELECT publisher FROM books_publishers_link WHERE book = b.id)
            LIMIT 1) AS publisher,
        (SELECT val FROM identifiers WHERE book = b.id AND type = 'isbn' COLLATE NOCASE LIMIT 1) AS isbn_identifier,
        (SELECT val FROM identifiers WHERE book = b.id AND type = 'olid' LIMIT 1) AS olid,
        (SELECT GROUP_CONCAT(t.name, ', ') FROM books_tags_link btl JOIN tags t ON btl.tag = t.id WHERE btl.book = b.id) AS tags,
        (SELECT l.lang_code FROM languages l JOIN books_languages_link bl ON bl.lang_code = l.id WHERE bl.book = b.id ORDER BY bl.item_order LIMIT 1) AS lang_code" . $subseriesSelect . "
    FROM books b
    LEFT JOIN books_series_link bsl ON bsl.book = b.id
    LEFT JOIN series s ON bsl.series = s.id" . $subseriesJoin . "
    WHERE b.id = :id");
$stmt->bindValue(':id', $id, PDO::PARAM_INT);
$stmt->execute();
$book = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$book) {
    die('Book not found');
}
if ($book['isbn_identifier'] !== null) {
    $book['isbn'] = $book['isbn_identifier'];
}
$tags     = $book['tags'] ?? '';
$langCode = (string)($book['lang_code'] ?? '');

// All identifiers for display
$allIdentifiers = [];
$idRows = $pdo->prepare("SELECT type, val FROM identifiers WHERE book = ? ORDER BY type");
$idRows->execute([$id]);
foreach ($idRows->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $allIdentifiers[$row['type']] = $row['val'];
}
$isOlNotFound = ($allIdentifiers['olid'] ?? '') === 'NOT_FOUND';

// Extract publication year for display
$pubYear = '';
if (!empty($book['pubdate'])) {
    try {
        $dt = new DateTime($book['pubdate']);
        $pubYear = $dt->format('Y');
    } catch (Exception $e) {
        if (preg_match('/^\d{4}/', $book['pubdate'], $m)) {
            $pubYear = $m[0];
        }
    }
}

$annasUrl = 'annas_results.php?search=' . urlencode($book['title'] ?? '') . '&author=' . urlencode($book['authors'] ?? '');

// Similar books tab: check if cached data exists
$grWorkId = $allIdentifiers['gr_work_id'] ?? '';
$similarBooksCount = 0;
if ($grWorkId) {
    $sc = $pdo->prepare("SELECT COUNT(*) FROM gr_similar_books WHERE source_work_id = ?");
    $sc->execute([$grWorkId]);
    $similarBooksCount = (int)$sc->fetchColumn();
}

// Fetch author details for Author tab
$authorTabData = [];
if (!empty($book['author_data'])) {
    foreach (explode('|', $book['author_data']) as $pair) {
        if (strpos($pair, ':') === false) continue;
        [$aid, $aname] = explode(':', $pair, 2);
        $aid = (int)$aid;
        try {
            $identStmt = $pdo->prepare('SELECT type, val FROM author_identifiers WHERE author_id = ?');
            $identStmt->execute([$aid]);
            $idents = [];
            foreach ($identStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $idents[$row['type']] = $row['val'];
            }
        } catch (PDOException $e) {
            $idents = [];
        }
        $authorTabData[] = [
            'id'          => $aid,
            'name'        => $aname,
            'bio'         => $idents['bio']          ?? '',
            'photo'       => $idents['photo']        ?? '',
            'olaid'       => $idents['olaid']        ?? '',
            'goodreads'   => $idents['goodreads']    ?? '',
            'wikidata'    => $idents['wikidata']     ?? '',
            'isni'        => $idents['isni']         ?? '',
            'viaf'        => $idents['viaf']         ?? '',
            'librarything'=> $idents['librarything'] ?? '',
            'storygraph'  => $idents['storygraph']   ?? '',
            'imdb'        => $idents['imdb']         ?? '',
            'birth_date'  => $idents['birth_date']   ?? '',
            'death_date'  => $idents['death_date']   ?? '',
            'alt_names'   => isset($idents['alt_names'])  ? json_decode($idents['alt_names'],  true) : [],
            'links'       => isset($idents['links'])       ? json_decode($idents['links'],       true) : [],
            'works'       => isset($idents['works'])       ? json_decode($idents['works'],       true) : [],
            'wiki_bio'    => $idents['wiki_bio']     ?? '',
            'wiki_url'    => $idents['wiki_url']     ?? '',
        ];
    }
}

// Fetch saved recommendations if present
try {
    $recId = ensureSingleValueColumn($pdo, '#recommendation', 'Recommendation');
    $valTable  = "custom_column_{$recId}";
    $linkTable = "books_custom_column_{$recId}_link";
    $recStmt = $pdo->prepare("SELECT v.value FROM $linkTable l JOIN $valTable v ON l.value = v.id WHERE l.book = ?");
    $recStmt->execute([$id]);
    $savedRecommendations = $recStmt->fetchColumn();
} catch (PDOException $e) {
    $savedRecommendations = null;
}

// Fetch notes if present
try {
    $notesId = ensureSingleValueColumn($pdo, '#notes', 'Notes');
    $valTable  = "custom_column_{$notesId}";
    $linkTable = "books_custom_column_{$notesId}_link";
    $notesStmt = $pdo->prepare("SELECT v.value FROM $linkTable l JOIN $valTable v ON l.value = v.id WHERE l.book = ?");
    $notesStmt->execute([$id]);
    $notes = $notesStmt->fetchColumn() ?: '';
} catch (PDOException $e) {
    $notes = '';
}

$missingFile = !bookHasFile($book['path']);

$libraryDirPath = getLibraryPath();
if (!empty($book['path'])) {
    $libraryDirPath .= '/' . $book['path'];
} else {
    $authorList = array_map('trim', explode(',', $book['authors'] ?? ''));
    $firstAuthor = $authorList[0] ?? '';
    $authorFolderName = safe_filename($firstAuthor . (count($authorList) > 1 ? ' et al.' : ''));
    $bookFolderName = safe_filename($book['title']) . ' (' . $book['id'] . ')';
    $libraryDirPath .= '/' . $authorFolderName . '/' . $bookFolderName;
}
$ebookFileRel = $missingFile ? '' : firstBookFile($book['path']);
$epubFileRel = '';
$pdfFileRel = '';
if (!$missingFile && $book['path'] !== '') {
    $epubPath = findBookFileByExtension($book['path'], 'epub');
    if ($epubPath !== null) {
        $epubFileRel = $epubPath;
    }
    $existingPdf = findBookFileByExtension($book['path'], 'pdf');
    if ($existingPdf !== null) {
        $pdfFileRel = $existingPdf;
    }
}
$pdfExists = ($pdfFileRel !== '');

$researchPrefillUrl = '';
if ($pdfFileRel !== '') {
    $prefillParams = [
        'prefill' => '1',
        'title' => $book['title'] ?? '',
        'library_book_id' => (string)($book['id'] ?? ''),
        'pdf_path' => $pdfFileRel,
    ];
    if (!empty($book['authors'])) {
        $prefillParams['author'] = $book['authors'];
    }
    if ($pubYear !== '') {
        $prefillParams['year'] = $pubYear;
    }
    $libraryWebBase = rtrim(getLibraryWebPath(), '/');
    if ($libraryWebBase !== '') {
        $prefillParams['pdf_url'] = $libraryWebBase . '/' . ltrim($pdfFileRel, '/');
    }
    $researchPrefillUrl = 'research/research-ai.php?' . http_build_query($prefillParams, '', '&', PHP_QUERY_RFC3986);
}

// All formats registered in DB for this book
$bookFormats = [];
try {
    $fmtStmt = $pdo->prepare('SELECT format, name, uncompressed_size FROM data WHERE book = :id ORDER BY format');
    $fmtStmt->execute([':id' => $id]);
    $bookFormats = $fmtStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

if ($sendRequested) {
    require __DIR__ . '/book_actions/send_device.php';
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
    <title><?= htmlspecialchars($book['title']) ?></title>
    <link rel="stylesheet" href="/theme.css.php">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous">
    <style>
        #description { min-height: 200px; resize: vertical; }
    </style>
</head>
<body class="pt-5" data-book-id="<?= (int)$book['id'] ?>" data-search-query="<?= htmlspecialchars($book['title'] . ' ' . $book['authors'], ENT_QUOTES) ?>"<?php if($ebookFileRel): ?> data-ebook-file="<?= htmlspecialchars($ebookFileRel) ?>"<?php endif; ?><?php if(!empty($book['isbn'])): ?> data-isbn="<?= htmlspecialchars($book['isbn']) ?>"<?php endif; ?>>
<?php include "navbar.php"; ?>
<div class="container my-4 mt-3">


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
    <?php
    // Build list of other libraries this book can be copied to
    $allUsers = json_decode(file_get_contents(__DIR__ . '/users.json'), true) ?? [];
    $transferTargets = [];
    foreach ($allUsers as $uname => $udata) {
        if ($uname === currentUser()) continue;
        $dbp = $udata['prefs']['db_path'] ?? '';
        if ($dbp !== '' && file_exists($dbp)) {
            $transferTargets[$uname] = $uname;
        }
    }
    ?>
    <?php if ($missingFile): ?>
        <div id="uploadMessage" class="mt-2 mb-2 h2"></div>
    <?php endif; ?>

    <!-- Two-column layout -->
        <div class="row">
        <!-- Left Column: Book Metadata -->
        <div class="col-lg-4 mb-4">
            <?php if (!empty($book['has_cover'])): ?>
                <div class="card shadow-sm mb-4">
                    <div class="position-relative">
                        <img id="coverImagePreview" src="<?= htmlspecialchars(getLibraryWebPath() . '/' . $book['path'] . '/cover.jpg') ?>" alt="Cover" class="card-img-top img-thumbnail">
                        <div id="coverDimensions" class="position-absolute bottom-0 end-0 bg-dark text-white px-2 py-1 small rounded-top-start opacity-75" style="font-size: 1.2rem;">Loading...</div>
                    </div>
                </div>
            <?php else: ?>
                <div class="text-muted">No cover</div>
            <?php endif; ?>

            <div class="d-flex flex-wrap gap-2 mb-4">
                <?php if ($ebookFileRel): ?>
                <button type="button" id="extractCoverBtn" class="btn btn-secondary">Extract Cover</button>
                <?php endif; ?>
                <a href="<?= htmlspecialchars($annasUrl) ?>" class="btn btn-secondary">AA</a>
                <button type="button" id="metadataBtn" class="btn btn-secondary">Metadata</button>
                <?php if ($researchPrefillUrl !== ''): ?>
                <a href="<?= htmlspecialchars($researchPrefillUrl) ?>" class="btn btn-secondary">
                    <i class="fa-solid fa-flask me-1"></i>Research
                </a>
                <?php endif; ?>
            </div>

        </div>

        <!-- Right Column: Edit Form with Tabs -->
        <div class="col-lg-8">
            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <?php if ($updated): ?>
                        <div class="alert alert-success alert-dismissible fade show">
                            <i class="fa-solid fa-circle-check me-2"></i> Book updated successfully
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    <?php if ($fsWarning): ?>
                        <div class="alert alert-warning alert-dismissible fade show">
                            <i class="fa-solid fa-triangle-exclamation me-2"></i> <?= $fsWarning ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    <?php if ($conversionMessage): ?>
                        <div class="alert alert-<?= htmlspecialchars($conversionMessage['type']) ?> alert-dismissible fade show">
                            <i class="fa-solid <?= $conversionMessage['type'] === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation' ?> me-2"></i>
                            <?= htmlspecialchars($conversionMessage['text']) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    <?php if ($sendMessage): ?>
                        <div class="alert alert-<?= htmlspecialchars($sendMessage['type']) ?> alert-dismissible fade show">
                            <i class="fa-solid <?= $sendMessage['type'] === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation' ?> me-2"></i>
                            <?= htmlspecialchars($sendMessage['text']) ?>
                            <?php if (!empty($sendMessage['detail'])): ?>
                                <pre class="mt-2 mb-0 small"><?= htmlspecialchars($sendMessage['detail']) ?></pre>
                            <?php endif; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <!-- Tabbed Form -->
                    <ul class="nav nav-tabs mb-3" id="editBookTabs" role="tablist">
                        <li class="nav-item">
                            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabBasic">Basic Info</button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabDescription">Description & Cover</button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabRecommendations">Recommendations<?php if (!empty($savedRecommendations)): ?> <i class="fa-solid fa-circle-check text-success ms-1"></i><?php endif; ?></button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabAwards">
                                <i class="fa-solid fa-trophy me-1"></i>Awards<?php if (!empty($bookAwards)): ?> <span class="badge bg-warning text-dark ms-1"><?= count($bookAwards) ?></span><?php endif; ?>
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabInfo">Info</button>
                        </li>
                        <?php if (!empty($authorTabData)): ?>
                        <li class="nav-item">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabAuthor">
                                <i class="fa-solid fa-user me-1"></i>Author<?php if (count($authorTabData) > 1): ?>s<?php endif; ?>
                            </button>
                        </li>
                        <?php endif; ?>
                        <?php if (!empty($bookReviews)): ?>
                        <li class="nav-item">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabReviews">
                                <i class="fa-brands fa-goodreads me-1"></i>Reviews <span class="badge bg-secondary ms-1"><?= count($bookReviews) ?></span>
                            </button>
                        </li>
                        <?php endif; ?>
                        <?php if ($grWorkId): ?>
                        <li class="nav-item">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabSimilar" id="tabSimilarBtn">
                                <i class="fa-solid fa-list-ul me-1"></i>Similar<?php if ($similarBooksCount > 0): ?> <span class="badge bg-secondary ms-1"><?= $similarBooksCount ?></span><?php endif; ?>
                            </button>
                        </li>
                        <?php endif; ?>
                    </ul>
                    <form method="post" enctype="multipart/form-data">
                        <div class="tab-content">
                            <!-- Basic Info -->
                            <div class="tab-pane fade show active" id="tabBasic">
                                <?php
                                $inlineIdLinks = [
                                    'olid'         => ['fa-solid fa-book-open',       'Open Library',      'https://openlibrary.org/works/%s',         false],
                                    'goodreads'    => ['fa-brands fa-goodreads',      'Goodreads',         'https://www.goodreads.com/book/show/%s',    false],
                                    'amazon'       => ['fa-brands fa-amazon',         'Amazon',            'https://www.amazon.com/dp/%s',              false],
                                    'asin'         => ['fa-brands fa-amazon',         'Amazon',            'https://www.amazon.com/dp/%s',              false],
                                    'google'       => ['fa-brands fa-google',         'Google Books',      'https://books.google.com/books?id=%s',     false],
                                    'librarything' => ['fa-solid fa-building-columns','LibraryThing',      'https://www.librarything.com/work/%s',     false],
                                    'ff'           => ['fa-solid fa-hat-wizard',      'Fantastic Fiction', 'https://www.fantasticfiction.com/%s.htm',  true],
                                    'fictiondb'    => ['fa-solid fa-database',        'FictionDB',         'https://www.fictiondb.com/author/%s.htm',  true],
                                    'oclc'         => ['fa-solid fa-globe',           'WorldCat',          'https://www.worldcat.org/oclc/%s',         false],
                                ];
                                $idLinkItems = [];
                                foreach ($allIdentifiers as $iType => $iVal) {
                                    if (!isset($inlineIdLinks[$iType])) continue;
                                    if ($iVal === 'NOT_FOUND') continue;
                                    [$icon, $label2, $urlTpl, $raw] = $inlineIdLinks[$iType];
                                    $idLinkItems[] = [
                                        'href'  => sprintf($urlTpl, $raw ? $iVal : rawurlencode($iVal)),
                                        'icon'  => $icon,
                                        'label' => $label2,
                                    ];
                                }
                                if ($idLinkItems): ?>
                                <div class="alert alert-primary d-flex flex-wrap gap-3 py-2 mb-3">
                                    <?php foreach ($idLinkItems as $item): ?>
                                    <a href="<?= htmlspecialchars($item['href']) ?>" target="_blank" rel="noopener"
                                       class="text-decoration-none d-inline-flex align-items-center gap-1 small">
                                        <i class="<?= $item['icon'] ?>" style="color:var(--accent)"></i><?= htmlspecialchars($item['label']) ?>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                                <div class="mb-3 position-relative">
                                    <label for="title" class="form-label">
                                        <i class="fa-solid fa-book me-1 text-primary"></i> Title
                                    </label>
                                    <input type="text" id="title" name="title" value="<?= htmlspecialchars($book['title']) ?>" class="form-control" required autocomplete="off">
                                    <ul id="titleSuggestions" class="list-group position-absolute w-100" style="z-index:1050;display:none;max-height:200px;overflow-y:auto;"></ul>
                                </div>
                                <div class="row g-3 mb-3">
                                    <div class="col-md-6 position-relative">
                                        <label for="authors" class="form-label">
                                            <i class="fa-solid fa-user me-1 text-primary"></i> Author(s)
                                        </label>
                                        <div class="input-group">
                                            <input type="text" id="authors" name="authors" value="<?= htmlspecialchars($book['authors']) ?>" class="form-control" placeholder="Separate multiple authors with commas" autocomplete="off">
                                            <button type="button" id="applyAuthorSortBtn" class="btn btn-outline-secondary" title="Apply to Author Sort"><i class="fa-solid fa-arrow-right"></i></button>
                                        </div>
                                        <ul id="authorSuggestionsEdit" class="list-group position-absolute w-100" style="z-index:1050;display:none;max-height:200px;overflow-y:auto;"></ul>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="authorSort" class="form-label">
                                            <i class="fa-solid fa-user-pen me-1 text-primary"></i> Author Sort
                                        </label>
                                        <input type="text" id="authorSort" name="author_sort" value="<?= htmlspecialchars($book['author_sort']) ?>" class="form-control">
                                    </div>
                                </div>
                                <hr class="my-3">
                                <div class="row g-3 mb-3">
                                    <div class="col-md-8 position-relative">
                                        <label for="seriesInput" class="form-label">
                                            <i class="fa-solid fa-layer-group me-1 text-primary"></i> Series
                                        </label>
                                        <div class="input-group">
                                            <input type="text" id="seriesInput" name="series" value="<?= htmlspecialchars($book['series']) ?>" class="form-control" autocomplete="off" placeholder="None">
                                            <input type="number" step="0.1" id="seriesIndex" name="series_index" value="<?= htmlspecialchars($book['series_index']) ?>" class="form-control" style="max-width:90px" placeholder="#" title="Number in series">
                                        </div>
                                        <ul id="seriesSuggestions" class="list-group position-absolute w-100" style="z-index:1000; display:none;"></ul>
                                    </div>
                                </div>
                                <?php if ($hasSubseries): ?>
                                <div class="row g-3 mb-3">
                                    <div class="col-md-8 position-relative">
                                        <label for="subseriesInput" class="form-label">
                                            <i class="fa-solid fa-diagram-project me-1 text-primary"></i> Subseries
                                        </label>
                                        <div class="input-group">
                                            <input type="text" id="subseriesInput" name="subseries" value="<?= htmlspecialchars($book['subseries'] ?? '') ?>" class="form-control" autocomplete="off" placeholder="None">
                                            <?php if ($subseriesIndexExists): ?>
                                            <input type="number" step="0.1" id="subseriesIndex" name="subseries_index" value="<?= htmlspecialchars($book['subseries_index'] ?? '') ?>" class="form-control" style="max-width:90px" placeholder="#" title="Number in subseries">
                                            <?php endif; ?>
                                        </div>
                                        <ul id="subseriesSuggestions" class="list-group position-absolute w-100" style="z-index:1000; display:none;"></ul>
                                    </div>
                                    <div class="col-md-4 d-flex align-items-end">
                                        <button type="button" id="swapSeriesSubseriesBtn" class="btn btn-secondary">
                                            <i class="fa-solid fa-right-left me-1"></i> Swap Series
                                        </button>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <hr class="my-3">
                                <div class="row g-3 mb-3">
                                    <div class="col-md-8">
                                        <label for="publisher" class="form-label">
                                            <i class="fa-solid fa-building me-1 text-primary"></i> Publisher
                                        </label>
                                        <input type="text" id="publisher" name="publisher" value="<?= htmlspecialchars($book['publisher'] ?? '') ?>" class="form-control">
                                    </div>
                                    <div class="col-md-4">
                                        <label for="pubdate" class="form-label">
                                            <i class="fa-solid fa-calendar me-1 text-primary"></i> Publication Year
                                        </label>
                                        <input type="text" id="pubdate" name="pubdate" value="<?= htmlspecialchars($pubYear) ?>" class="form-control">
                                    </div>
                                </div>
                                <?php $olid = $book['olid'] ?? ''; ?>
                                <div class="row g-3 mb-3">
                                    <div class="col-md-4">
                                        <label for="isbn" class="form-label">
                                            <i class="fa-solid fa-barcode me-1 text-primary"></i> ISBN
                                        </label>
                                        <input type="text" id="isbn" name="isbn" value="<?= htmlspecialchars($book['isbn']) ?>" class="form-control">
                                    </div>
                                    <div class="col-md-5">
                                        <label class="form-label">
                                            <i class="fa-solid fa-book me-1 text-primary"></i> Open Library Work ID
                                        </label>
                                        <div class="input-group">
                                            <input type="text" id="olid" name="olid" value="<?= htmlspecialchars($olid) ?>" class="form-control" placeholder="e.g. OL12345W">
                                            <?php if ($olid !== ''): ?>
                                            <a href="https://openlibrary.org/works/<?= urlencode($olid) ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary" title="View on Open Library">
                                                <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <label for="language" class="form-label">
                                            <i class="fa-solid fa-language me-1 text-primary"></i> Language
                                        </label>
                                        <input type="text" id="language" name="language" value="<?= htmlspecialchars($langCode) ?>" class="form-control" placeholder="e.g. eng" maxlength="3">
                                        <div class="form-text">ISO 639-2</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Description & Cover -->
                            <div class="tab-pane fade" id="tabDescription">
                                <div class="mb-3">
                                    <div class="d-flex align-items-center justify-content-between mb-1">
                                        <label for="description" class="form-label mb-0">
                                            <i class="fa-solid fa-align-left me-1 text-primary"></i> Description
                                        </label>
                                        <div class="d-flex gap-2">
                                            <button type="button" id="synopsisBtn" data-book-id="<?= htmlspecialchars($book['id']) ?>" data-authors="<?= htmlspecialchars($book['authors']) ?>" data-title="<?= htmlspecialchars($book['title']) ?>" class="btn btn-primary btn-sm">
                                                <i class="fa-solid fa-wand-magic-sparkles me-1"></i>Generate Synopsis
                                            </button>
                                            <button type="button" id="goodreadsMetaBtn" class="btn btn-secondary btn-sm"
                                                <?php if (empty($allIdentifiers['goodreads'])): ?>disabled title="No Goodreads ID stored in identifiers"<?php endif; ?>>
                                                <i class="fa-brands fa-goodreads me-1"></i>Goodreads
                                            </button>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" id="stripHtmlBtn" title="Remove all HTML tags from the description">
                                                <i class="fa-solid fa-eraser me-1"></i> Strip HTML
                                            </button>
                                        </div>
                                    </div>
                                    <textarea id="description" name="description" class="form-control" rows="10"><?= htmlspecialchars($description) ?></textarea>
                                </div>
                                <div class="mb-3">
                                    <label for="cover" class="form-label">
                                        <i class="fa-solid fa-image me-1 text-primary"></i> Cover Image
                                    </label>
                                    <input type="file" id="cover" name="cover" class="form-control">
                                </div>
                            </div>
                            <!-- Recommendations -->
                            <div class="tab-pane fade" id="tabRecommendations">
                                <div class="mb-3">
                                    <button type="button" id="recommendBtn" data-book-id="<?= htmlspecialchars($book['id']) ?>" data-authors="<?= htmlspecialchars($book['authors']) ?>" data-title="<?= htmlspecialchars($book['title']) ?>" data-genres="<?= htmlspecialchars($tags) ?>" class="btn btn-primary btn-sm">
                                        <i class="fa-solid fa-wand-magic-sparkles me-1"></i>Get Recommendations
                                    </button>
                                </div>
                                <div id="recommendSection"<?php if (!empty($savedRecommendations)): ?> data-saved="<?= htmlspecialchars($savedRecommendations, ENT_QUOTES) ?>"<?php endif; ?>></div>
                            </div>

                            <!-- Info -->
                            <!-- Awards Tab -->
                            <div class="tab-pane fade" id="tabAwards">
                                <div id="bookAwardsList" class="mb-3">
                                    <?php if (empty($bookAwards)): ?>
                                        <p class="text-muted small" id="noAwardsMsg">No awards recorded yet.</p>
                                    <?php else: ?>
                                        <?php foreach ($bookAwards as $ba): ?>
                                        <div class="d-flex align-items-center gap-2 mb-2 award-entry" data-award-id="<?= (int)$ba['id'] ?>">
                                            <?php if ($ba['result'] === 'won'): ?>
                                                <i class="fa-solid fa-trophy text-warning"></i>
                                            <?php elseif ($ba['result'] === 'shortlisted'): ?>
                                                <i class="fa-solid fa-medal text-secondary"></i>
                                            <?php else: ?>
                                                <i class="fa-regular fa-star text-muted"></i>
                                            <?php endif; ?>
                                            <span class="fw-semibold"><?= htmlspecialchars($ba['award_name']) ?></span>
                                            <?php if ($ba['year']): ?><span class="text-muted"><?= (int)$ba['year'] ?></span><?php endif; ?>
                                            <?php if ($ba['category']): ?><span class="text-muted small fst-italic"><?= htmlspecialchars($ba['category']) ?></span><?php endif; ?>
                                            <span class="badge <?= $ba['result'] === 'won' ? 'bg-warning text-dark' : ($ba['result'] === 'shortlisted' ? 'bg-secondary' : 'bg-light text-dark border') ?>"><?= htmlspecialchars(ucfirst($ba['result'])) ?></span>
                                            <button type="button" class="btn btn-link btn-sm text-danger p-0 ms-auto remove-award-btn" data-id="<?= (int)$ba['id'] ?>" title="Remove"><i class="fa-solid fa-times"></i></button>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                <div class="card">
                                    <div class="card-body">
                                        <h6 class="card-title mb-3">Add Award</h6>
                                        <div class="row g-2 align-items-end">
                                            <div class="col-md-4 position-relative">
                                                <label class="form-label small mb-1">Award name</label>
                                                <input type="text" id="awardNameInput" class="form-control form-control-sm" placeholder="Hugo Award" autocomplete="off">
                                                <ul id="awardSuggestions" class="list-group position-absolute w-100" style="z-index:1060;display:none;max-height:200px;overflow-y:auto;top:100%;left:0"></ul>
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label small mb-1">Year</label>
                                                <input type="number" id="awardYearInput" class="form-control form-control-sm" placeholder="<?= date('Y') ?>" min="1900" max="2100">
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label small mb-1">Category</label>
                                                <input type="text" id="awardCategoryInput" class="form-control form-control-sm" placeholder="Best Novel">
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label small mb-1">Result</label>
                                                <select id="awardResultInput" class="form-select form-select-sm">
                                                    <option value="won">Won</option>
                                                    <option value="nominated" selected>Nominated</option>
                                                    <option value="shortlisted">Shortlisted</option>
                                                </select>
                                            </div>
                                            <div class="col-md-1">
                                                <button type="button" id="addAwardBtn" class="btn btn-primary btn-sm w-100">
                                                    <i class="fa-solid fa-plus"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <p id="awardMsg" class="text-danger small mt-2 mb-0" style="display:none"></p>
                                    </div>
                                </div>
                            </div>

                            <div class="tab-pane fade" id="tabInfo">
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
                                    <?php if (!empty($book['series']) || !empty($book['subseries'])): ?>
                                        <?php if (!empty($book['series'])): ?>
                                            <a href="list_books.php?sort=<?= urlencode($sort) ?>&series_id=<?= urlencode($book['series_id']) ?>">
                                                <?= htmlspecialchars($book['series']) ?>
                                            </a>
                                            <?php if ($book['series_index'] !== null && $book['series_index'] !== ''): ?>
                                                (<?= htmlspecialchars($book['series_index']) ?>)
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php if (!empty($book['subseries'])): ?>
                                            <?php if (!empty($book['series'])): ?>&gt; <?php endif; ?>
                                            <?= htmlspecialchars($book['subseries']) ?>
                                            <?php if ($book['subseries_index'] !== null && $book['subseries_index'] !== ''): ?>
                                                (<?= htmlspecialchars($book['subseries_index']) ?>)
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        &mdash;
                                    <?php endif; ?>
                                </p>
                                <?php if (!empty($tags)): ?>
                                    <p><strong>Tags:</strong> <?= htmlspecialchars($tags) ?></p>
                                <?php endif; ?>
                                <?php if ($formattedPubdate): ?>
                                    <p><strong>Published:</strong> <?= htmlspecialchars($formattedPubdate) ?></p>
                                <?php endif; ?>
                                <p class="mb-1"><strong>Path:</strong> <span class="text-muted small"><?= htmlspecialchars($libraryDirPath) ?></span></p>
                                <?php if ($bookFormats): ?>
                                    <p class="mb-1"><strong>Formats:</strong></p>
                                    <div class="d-flex flex-wrap gap-1 mb-1">
                                        <?php foreach ($bookFormats as $fmt): ?>
                                            <?php
                                                $libraryWebPath = getLibraryWebPath();
                                                $fmtExt  = strtolower($fmt['format']);
                                                $fmtUrl  = rtrim($libraryWebPath, '/') . '/' . $book['path'] . '/' . $fmt['name'] . '.' . $fmtExt;
                                                $fmtSize = $fmt['uncompressed_size'] > 0
                                                    ? ' (' . round($fmt['uncompressed_size'] / 1048576, 1) . ' MB)'
                                                    : '';
                                                $fmtIcon = match($fmt['format']) {
                                                    'EPUB'       => 'fa-book-open',
                                                    'PDF'        => 'fa-file-pdf',
                                                    'MOBI','AZW3'=> 'fa-tablet-screen-button',
                                                    default      => 'fa-file',
                                                };
                                            ?>
                                            <a href="<?= htmlspecialchars($fmtUrl) ?>"
                                               class="badge text-decoration-none bg-secondary"
                                               title="<?= htmlspecialchars($fmt['format'] . $fmtSize) ?>">
                                                <i class="fa-solid <?= $fmtIcon ?> me-1"></i><?= htmlspecialchars($fmt['format']) ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="form-check mt-2 mb-2">
                                    <input class="form-check-input" type="checkbox"
                                           name="ol_not_found" id="olNotFound" value="1"
                                           <?= $isOlNotFound ? 'checked' : '' ?>>
                                    <label class="form-check-label small text-muted" for="olNotFound">
                                        Marked as NOT_FOUND — skip OL Work ID lookup
                                    </label>
                                </div>
                                <div class="mt-3 mb-2">
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <button type="button" id="findGoodreadsBtn" class="btn btn-sm btn-secondary">
                                            <i class="fa-brands fa-goodreads me-1"></i><?= empty($allIdentifiers['goodreads']) ? 'Find Goodreads ID' : 'Update Goodreads ID' ?>
                                        </button>
                                        <div class="input-group input-group-sm" style="width:200px">
                                            <input type="text" id="manualGrIdInput" class="form-control form-control-sm"
                                                   placeholder="Manual GR ID…"
                                                   value="<?= htmlspecialchars($allIdentifiers['goodreads'] ?? '', ENT_QUOTES) ?>">
                                            <button type="button" id="manualGrIdSaveBtn" class="btn btn-outline-secondary btn-sm">
                                                <i class="fa-solid fa-floppy-disk"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div id="goodreadsSearchResults" class="mt-2" style="display:none"></div>
                                </div>
                                <?php if ($allIdentifiers): ?>
                                    <?php
                                    $idLinks = [
                                        'olid'         => ['Open Library',   'https://openlibrary.org/works/%s'],
                                        'isbn'         => ['ISBN',           null],
                                        'isbn13'       => ['ISBN-13',        null],
                                        'goodreads'    => ['Goodreads',      'https://www.goodreads.com/book/show/%s'],
                                        'amazon'       => ['Amazon',         'https://www.amazon.com/dp/%s'],
                                        'asin'         => ['ASIN',           'https://www.amazon.com/dp/%s'],
                                        'google'       => ['Google Books',   'https://books.google.com/books?id=%s'],
                                        'librarything' => ['LibraryThing',        'https://www.librarything.com/work/%s'],
                                        'oclc'         => ['WorldCat',            'https://www.worldcat.org/oclc/%s'],
                                        'ff'           => ['Fantastic Fiction',   'https://www.fantasticfiction.com/%s.htm'],
                                        'fictiondb'    => ['FictionDB',           'https://www.fictiondb.com/author/%s.htm'],
                                    ];
                                    ?>
                                    <p class="mb-1"><strong>Identifiers:</strong></p>
                                    <div class="d-flex flex-wrap gap-1 mb-2">
                                        <?php foreach ($allIdentifiers as $type => $val):
                                            if ($val === 'NOT_FOUND') continue;
                                            if ($type === 'ol_ids_fetched') continue;
                                            $meta  = $idLinks[$type] ?? [strtoupper($type), null];
                                            $label = $meta[0];
                                            $noEncode = in_array($type, ['ff', 'fictiondb'], true);
                                            $url   = $meta[1] ? sprintf($meta[1], $noEncode ? $val : rawurlencode($val)) : null;
                                        ?>
                                        <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle" style="font-size:0.75rem">
                                            <span class="text-muted me-1"><?= htmlspecialchars($label) ?>:</span>
                                            <?php if ($url): ?>
                                                <a href="<?= htmlspecialchars($url) ?>" target="_blank" rel="noopener" class="text-reset text-decoration-none"><?= htmlspecialchars($val) ?> <i class="fa-solid fa-arrow-up-right-from-square fa-xs"></i></a>
                                            <?php else: ?>
                                                <?= htmlspecialchars($val) ?>
                                            <?php endif; ?>
                                        </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Author Tab -->
                            <?php if (!empty($authorTabData)): ?>
                            <div class="tab-pane fade" id="tabAuthor">
                                <?php foreach ($authorTabData as $idx => $author): ?>
                                    <?php if ($idx > 0): ?><hr><?php endif; ?>
                                    <div class="mb-4" data-author-id="<?= (int)$author['id'] ?>">
                                        <div class="d-flex align-items-start gap-3 mb-3">
                                            <?php if (!empty($author['photo'])): ?>
                                                <img src="<?= htmlspecialchars($author['photo']) ?>"
                                                     alt="<?= htmlspecialchars($author['name']) ?>"
                                                     class="rounded"
                                                     style="width:80px;height:80px;object-fit:cover;flex-shrink:0;">
                                            <?php endif; ?>
                                            <div class="flex-grow-1">
                                                <h5 class="mb-1"><?= htmlspecialchars($author['name']) ?></h5>
                                                <div class="d-flex flex-wrap gap-2 mb-1">
                                                    <a href="list_books.php?author_id=<?= (int)$author['id'] ?>" class="btn btn-sm btn-primary">
                                                        <i class="fa-solid fa-filter me-1"></i> Books by this author
                                                    </a>
                                                    <?php if (!empty($author['olaid'])): ?>
                                                        <a href="https://openlibrary.org/authors/<?= urlencode($author['olaid']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-secondary">
                                                            <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> Open Library
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if (!empty($author['goodreads'])): ?>
                                                        <a href="https://www.goodreads.com/author/show/<?= urlencode($author['goodreads']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-secondary">
                                                            <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> Goodreads
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if (!empty($author['storygraph'])): ?>
                                                        <a href="https://app.thestorygraph.com/authors/<?= urlencode($author['storygraph']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-secondary">
                                                            <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> StoryGraph
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if (!empty($author['imdb'])): ?>
                                                        <a href="https://www.imdb.com/name/<?= urlencode($author['imdb']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-secondary">
                                                            <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> IMDb
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if (!empty($author['librarything'])): ?>
                                                        <a href="https://www.librarything.com/author/<?= urlencode($author['librarything']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-secondary">
                                                            <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> LibraryThing
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if (!empty($author['wikidata'])): ?>
                                                        <a href="https://www.wikidata.org/wiki/<?= urlencode($author['wikidata']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-secondary">
                                                            <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> Wikidata
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php foreach ($author['links'] as $link): ?>
                                                        <a href="<?= htmlspecialchars($link['url']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-secondary">
                                                            <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> <?= htmlspecialchars($link['title']) ?>
                                                        </a>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <?php if (!empty($author['birth_date']) || !empty($author['death_date']) || !empty($author['alt_names'])): ?>
                                        <dl class="row small mb-3">
                                            <?php if (!empty($author['birth_date'])): ?>
                                                <dt class="col-sm-3 text-muted">Born</dt>
                                                <dd class="col-sm-9"><?= htmlspecialchars($author['birth_date']) ?></dd>
                                            <?php endif; ?>
                                            <?php if (!empty($author['death_date'])): ?>
                                                <dt class="col-sm-3 text-muted">Died</dt>
                                                <dd class="col-sm-9"><?= htmlspecialchars($author['death_date']) ?></dd>
                                            <?php endif; ?>
                                            <?php if (!empty($author['alt_names'])): ?>
                                                <dt class="col-sm-3 text-muted">Also known as</dt>
                                                <dd class="col-sm-9"><?= htmlspecialchars(implode(', ', $author['alt_names'])) ?></dd>
                                            <?php endif; ?>
                                        </dl>
                                        <?php endif; ?>
                                        <div class="mb-2">
                                            <label class="form-label fw-semibold">
                                                <i class="fa-solid fa-align-left me-1 text-primary"></i> Biography
                                            </label>
                                            <textarea class="form-control author-bio-editor" rows="10"
                                                      data-author-id="<?= (int)$author['id'] ?>"><?= htmlspecialchars($author['bio']) ?></textarea>
                                        </div>
                                        <div class="d-flex align-items-center gap-2">
                                            <button type="button" class="btn btn-primary btn-sm save-author-bio-btn"
                                                    data-author-id="<?= (int)$author['id'] ?>">
                                                <i class="fa-solid fa-floppy-disk me-1"></i> Save Bio
                                            </button>
                                            <span class="author-bio-status text-muted small"
                                                  data-author-id="<?= (int)$author['id'] ?>"></span>
                                        </div>
                                        <?php if (!empty($author['wiki_bio'])): ?>
                                        <hr>
                                        <div class="mb-1 fw-semibold">
                                            <i class="fa-brands fa-wikipedia-w me-1 text-primary"></i> Wikipedia
                                            <?php if (!empty($author['wiki_url'])): ?>
                                                <a href="<?= htmlspecialchars($author['wiki_url']) ?>" target="_blank" rel="noopener" class="small ms-2">
                                                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Full article
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                        <div class="wiki-author-extract"><?= $author['wiki_bio'] ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($author['works'])): ?>
                                        <hr>
                                        <div class="mb-1 fw-semibold">
                                            <i class="fa-solid fa-book me-1 text-primary"></i> Works on Open Library
                                        </div>
                                        <ul class="list-unstyled small mb-0" style="columns:2;gap:1rem">
                                            <?php foreach ($author['works'] as $workTitle): ?>
                                                <li class="text-muted"><?= htmlspecialchars($workTitle) ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>

                            <!-- Reviews Tab -->
                            <?php if (!empty($bookReviews)): ?>
                            <div class="tab-pane fade" id="tabReviews">
                                <?php foreach ($bookReviews as $rev): ?>
                                <div class="mb-4 pb-3 border-bottom">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <?php if (!empty($rev['reviewer_url'])): ?>
                                            <a href="<?= htmlspecialchars($rev['reviewer_url']) ?>" target="_blank" rel="noopener" class="fw-semibold text-decoration-none small"><?= htmlspecialchars($rev['reviewer']) ?: 'Anonymous' ?></a>
                                        <?php else: ?>
                                            <span class="fw-semibold small"><?= htmlspecialchars($rev['reviewer']) ?: 'Anonymous' ?></span>
                                        <?php endif; ?>
                                        <?php if ($rev['rating']): ?>
                                        <span class="text-warning" style="font-size:0.8rem">
                                            <?= str_repeat('★', (int)$rev['rating']) ?><?= str_repeat('☆', 5 - (int)$rev['rating']) ?>
                                        </span>
                                        <?php endif; ?>
                                        <?php if ($rev['review_date']): ?>
                                        <span class="text-muted" style="font-size:0.75rem"><?= htmlspecialchars(substr($rev['review_date'], 0, 10)) ?></span>
                                        <?php endif; ?>
                                        <?php if ($rev['like_count']): ?>
                                        <span class="text-muted ms-auto" style="font-size:0.75rem"><i class="fa-regular fa-thumbs-up me-1"></i><?= (int)$rev['like_count'] ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="small" style="line-height:1.6; max-height:12rem; overflow:hidden; position:relative" data-review-body>
                                        <?= $rev['text'] ?>
                                        <div style="position:absolute;bottom:0;left:0;right:0;height:3rem;background:linear-gradient(transparent,var(--bs-body-bg))" data-review-fade></div>
                                    </div>
                                    <button type="button" class="btn btn-link btn-sm p-0 mt-1 text-muted review-expand-btn" style="font-size:0.75rem">Show more</button>
                                </div>
                                <?php endforeach; ?>
                                <p class="text-muted small mb-0">Top <?= count($bookReviews) ?> most-liked reviews from Goodreads.</p>
                            </div>
                            <?php endif; ?>

                            <!-- Similar Books Tab -->
                            <?php if ($grWorkId): ?>
                            <div class="tab-pane fade" id="tabSimilar">
                                <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
                                    <span class="text-muted small">Books Goodreads readers of this title also enjoyed.</span>
                                    <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" id="similarRefreshBtn">
                                        <i class="fa-solid fa-rotate me-1"></i><?= $similarBooksCount > 0 ? 'Refresh' : 'Fetch similar books' ?>
                                    </button>
                                </div>
                                <div id="similarBooksContainer">
                                    <?php if ($similarBooksCount === 0): ?>
                                    <p class="text-muted small">No similar books fetched yet. Click "Fetch similar books" above.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Form Actions -->
                        <?php if ($ebookFileRel): ?>
                            <button type="button" id="writeMetaBtn" style="display:none"
                                    data-book-id="<?= (int)$book['id'] ?>"></button>
                            <input type="hidden" name="send_to_device" id="sendToDeviceHidden" value="">
                        <?php endif; ?>
                        <div class="d-flex justify-content-between mt-4">
                            <div class="d-flex flex-wrap gap-2">
                                <?php if ($epubFileRel && !$pdfExists): ?>
                                    <button id="convertPdfBtn" type="submit" form="convertPdfForm" class="btn btn-secondary">
                                        <i class="fa-solid fa-file-pdf me-1"></i> Convert to PDF
                                    </button>
                                <?php endif; ?>
                                <?php if (!$epubFileRel && !$missingFile): ?>
                                    <button id="convertEpubBtn" type="submit" form="convertEpubForm" class="btn btn-secondary">
                                        <i class="fa-solid fa-book-open me-1"></i> Convert to EPUB
                                    </button>
                                <?php endif; ?>
                                <?php if (!empty($transferTargets)): ?>
                                    <button type="button" class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#transferModal">
                                        <i class="fa-solid fa-copy me-1"></i> Copy to Library
                                    </button>
                                <?php endif; ?>
                                <?php if (!empty($book['olid'])): ?>
                                    <a href="/oltransfer/index.php?book_id=<?= (int)$book['id'] ?>" class="btn btn-secondary">
                                        <i class="fa-solid fa-arrow-up-from-bracket me-1"></i>OL Transfer
                                    </a>
                                <?php endif; ?>
                                <?php if ($missingFile): ?>
                                    <button type="button" id="uploadFileButton" class="btn btn-secondary">
                                        <i class="fa-solid fa-upload me-1"></i>Upload File
                                    </button>
                                    <input type="file" id="bookFileInput" style="display:none">
                                <?php endif; ?>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <?php if ($ebookFileRel): ?>
                                    <button type="button" id="sendToDeviceBtn"
                                            data-book-id="<?= (int)$book['id'] ?>"
                                            class="btn btn-success">
                                        <i class="fa-solid fa-paper-plane me-1"></i> Send to device
                                    </button>
                                <?php endif; ?>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fa-solid fa-save me-1"></i> Save
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>



    <?php include 'metadata_modal.php'; ?>
    <?php include 'cover_modal.php'; ?>
    <?php if ($epubFileRel && !$pdfExists): ?>
        <form id="convertPdfForm" method="post"><input type="hidden" name="convert_to_pdf" value="1"></form>
    <?php endif; ?>
    <?php if (!$epubFileRel && !$missingFile): ?>
        <form id="convertEpubForm" method="post"><input type="hidden" name="convert_to_epub" value="1"></form>
    <?php endif; ?>

    <?php if (!empty($transferTargets)): ?>
    <div class="modal fade" id="transferModal" tabindex="-1" aria-labelledby="transferModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="transferModalLabel">
                        <i class="fa-solid fa-copy me-2"></i>Copy to Library
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3">Copies <strong><?= htmlspecialchars($book['title']) ?></strong> — including metadata, cover, and book files — into the selected library.</p>
                    <label for="transferTarget" class="form-label fw-semibold">Target library</label>
                    <select id="transferTarget" class="form-select">
                        <?php foreach ($transferTargets as $uname): ?>
                        <option value="<?= htmlspecialchars($uname) ?>"><?= htmlspecialchars($uname) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div id="transferStatus" class="mt-3"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="transferConfirmBtn">
                        <i class="fa-solid fa-copy me-1"></i> Copy
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Prev / Next nav -->
    <div class="d-flex justify-content-between align-items-center mt-4 mb-3">
        <div>
            <?php if ($prevBook): ?>
            <a href="<?= htmlspecialchars(navBookUrl((int)$prevBook['id'], $sort, $returnPage)) ?>"
               class="text-decoration-none text-muted small" title="<?= htmlspecialchars($prevBook['title']) ?>">
                <i class="fa-solid fa-chevron-left me-1"></i><?= htmlspecialchars(mb_strimwidth($prevBook['title'], 0, 40, '…')) ?>
            </a>
            <?php endif; ?>
        </div>
        <div class="d-flex align-items-center gap-3">
            <a href="<?= htmlspecialchars($backToListUrl) ?>" class="text-decoration-none text-muted small">
                <i class="fa-solid fa-list me-1"></i>Library
            </a>
            <a href="book-view.php?id=<?= $id ?>" class="text-decoration-none text-muted small">
                <i class="fa-solid fa-eye me-1"></i>View
            </a>
        </div>
        <div>
            <?php if ($nextBook): ?>
            <a href="<?= htmlspecialchars(navBookUrl((int)$nextBook['id'], $sort, $returnPage)) ?>"
               class="text-decoration-none text-muted small" title="<?= htmlspecialchars($nextBook['title']) ?>">
                <?= htmlspecialchars(mb_strimwidth($nextBook['title'], 0, 40, '…')) ?><i class="fa-solid fa-chevron-right ms-1"></i>
            </a>
            <?php endif; ?>
        </div>
    </div>

  </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js" referrerpolicy="origin"></script>
<script>
tinymce.init({
    selector: '#description',
    license_key: 'gpl',
    promotion: false,
    branding: false,
    height: 400
});
</script>
<script src="js/search.js"></script>
<script src="js/recommendations.js"></script>
<script src="js/book.js"></script>
<script>
(function () {
    const BOOK_ID     = <?= (int)$id ?>;
    const list        = document.getElementById('bookAwardsList');
    const msg         = document.getElementById('awardMsg');
    const nameInput   = document.getElementById('awardNameInput');
    const suggestions = document.getElementById('awardSuggestions');

    // ── Award name autocomplete ───────────────────────────────────────────────
    let acTimer;
    let acIndex = -1;

    function acClear() {
        suggestions.innerHTML = '';
        suggestions.style.display = 'none';
        acIndex = -1;
    }

    function acRender(names) {
        acClear();
        if (!names.length) return;
        names.forEach(name => {
            const li = document.createElement('li');
            li.className = 'list-group-item list-group-item-action py-1 px-2';
            li.textContent = name;
            li.addEventListener('mousedown', e => { e.preventDefault(); nameInput.value = name; acClear(); });
            suggestions.appendChild(li);
        });
        suggestions.style.display = 'block';
    }

    nameInput.addEventListener('input', () => {
        clearTimeout(acTimer);
        const term = nameInput.value.trim();
        if (term.length < 1) { acClear(); return; }
        acTimer = setTimeout(async () => {
            try {
                const res = await fetch('json_endpoints/book_awards.php?term=' + encodeURIComponent(term));
                acRender(await res.json());
            } catch { acClear(); }
        }, 250);
    });

    nameInput.addEventListener('keydown', e => {
        const items = suggestions.querySelectorAll('li');
        if (!items.length) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            acIndex = (acIndex + 1) % items.length;
            items.forEach((li, i) => li.classList.toggle('active', i === acIndex));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            acIndex = (acIndex - 1 + items.length) % items.length;
            items.forEach((li, i) => li.classList.toggle('active', i === acIndex));
        } else if (e.key === 'Enter' && acIndex >= 0) {
            e.preventDefault();
            nameInput.value = items[acIndex].textContent;
            acClear();
        } else if (e.key === 'Escape') {
            acClear();
        }
    });

    document.addEventListener('click', e => {
        if (!suggestions.contains(e.target) && e.target !== nameInput) acClear();
    });

    function resultIcon(result) {
        if (result === 'won')         return '<i class="fa-solid fa-trophy text-warning"></i>';
        if (result === 'shortlisted') return '<i class="fa-solid fa-medal text-secondary"></i>';
        return '<i class="fa-regular fa-star text-muted"></i>';
    }
    function resultBadge(result) {
        const cls = result === 'won' ? 'bg-warning text-dark' : result === 'shortlisted' ? 'bg-secondary' : 'bg-light text-dark border';
        return `<span class="badge ${cls}">${result.charAt(0).toUpperCase() + result.slice(1)}</span>`;
    }

    function addAwardRow(ba) {
        const noMsg = document.getElementById('noAwardsMsg');
        if (noMsg) noMsg.remove();

        const div = document.createElement('div');
        div.className = 'd-flex align-items-center gap-2 mb-2 award-entry';
        div.dataset.awardId = ba.id;
        div.innerHTML = `
            ${resultIcon(ba.result)}
            <span class="fw-semibold">${ba.award_name.replace(/</g,'&lt;')}</span>
            ${ba.year ? `<span class="text-muted">${ba.year}</span>` : ''}
            ${ba.category ? `<span class="text-muted small fst-italic">${ba.category.replace(/</g,'&lt;')}</span>` : ''}
            ${resultBadge(ba.result)}
            <button type="button" class="btn btn-link btn-sm text-danger p-0 ms-auto remove-award-btn" data-id="${ba.id}" title="Remove">
                <i class="fa-solid fa-times"></i>
            </button>`;
        list.appendChild(div);
    }

    list.addEventListener('click', async e => {
        const btn = e.target.closest('.remove-award-btn');
        if (!btn) return;
        const id  = parseInt(btn.dataset.id, 10);
        btn.disabled = true;
        const res  = await fetch('json_endpoints/book_awards.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'remove', id }),
        });
        const data = await res.json();
        if (data.ok) {
            btn.closest('.award-entry').remove();
            if (!list.querySelector('.award-entry')) {
                const p = document.createElement('p');
                p.className = 'text-muted small';
                p.id = 'noAwardsMsg';
                p.textContent = 'No awards recorded yet.';
                list.appendChild(p);
            }
        } else {
            btn.disabled = false;
        }
    });

    document.getElementById('addAwardBtn')?.addEventListener('click', async () => {
        const award    = nameInput.value.trim();
        const year     = document.getElementById('awardYearInput').value.trim();
        const category = document.getElementById('awardCategoryInput').value.trim();
        const result   = document.getElementById('awardResultInput').value;
        msg.style.display = 'none';

        if (!award) { msg.textContent = 'Award name is required.'; msg.style.display = ''; return; }

        const res  = await fetch('json_endpoints/book_awards.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'add', book_id: BOOK_ID, award, year, category, result }),
        });
        const data = await res.json();
        if (data.ok) {
            addAwardRow({ id: data.id, award_name: award, year: year || null, category: category || null, result });
            nameInput.value = '';
            document.getElementById('awardYearInput').value    = '';
            document.getElementById('awardCategoryInput').value = '';
            document.getElementById('awardResultInput').value  = 'nominated';
            acClear();
        } else {
            msg.textContent = data.error || 'Failed to add award.';
            msg.style.display = '';
        }
    });
})();
</script>
<div id="convertOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;flex-direction:column;align-items:center;justify-content:center;color:#fff;gap:1rem">
    <div class="spinner-border" style="width:3rem;height:3rem" role="status"></div>
    <div id="convertOverlayMsg" style="font-size:1.1rem;font-weight:500">Converting…</div>
</div>
<script>
['convertPdfBtn', 'convertEpubBtn'].forEach(id => {
    document.getElementById(id)?.addEventListener('click', function () {
        const msg = id === 'convertPdfBtn' ? 'Converting to PDF…' : 'Converting to EPUB…';
        const overlay = document.getElementById('convertOverlay');
        document.getElementById('convertOverlayMsg').textContent = msg;
        overlay.style.display = 'flex';
    });
});

async function saveGoodreadsId(grId) {
    const saveRes  = await fetch('json_endpoints/save_identifier.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ book_id: <?= (int)$id ?>, type: 'goodreads', val: grId }),
    });
    const saveData = await saveRes.json();
    if (!saveData.ok) {
        alert('Failed to save: ' + (saveData.error || 'unknown error'));
        return false;
    }
    // Unmark from metadata + shelves progress so it re-fetches on next import run
    const fd = new FormData();
    fd.append('book_id', <?= (int)$id ?>);
    fetch('json_endpoints/gr_unmark_done.php', { method: 'POST', body: fd });
    // Keep manual input in sync
    const inp = document.getElementById('manualGrIdInput');
    if (inp) inp.value = grId;
    return true;
}

document.getElementById('manualGrIdSaveBtn')?.addEventListener('click', async function () {
    const input = document.getElementById('manualGrIdInput');
    const grId  = input.value.trim();
    if (!grId) { input.focus(); return; }
    this.disabled = true;
    this.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    const ok = await saveGoodreadsId(grId);
    this.disabled = false;
    this.innerHTML = '<i class="fa-solid fa-floppy-disk"></i>';
    if (ok) {
        document.getElementById('findGoodreadsBtn').innerHTML =
            '<i class="fa-brands fa-goodreads me-1"></i>Update Goodreads ID';
        document.getElementById('goodreadsMetaBtn')?.removeAttribute('disabled');
        const resultsDiv = document.getElementById('goodreadsSearchResults');
        resultsDiv.innerHTML = `<span class="text-success small"><i class="fa-solid fa-check me-1"></i>Saved Goodreads ID: ${grId} — will re-fetch metadata on next import run.</span>`;
        resultsDiv.style.display = '';
    }
});

// Allow pressing Enter in the manual input
document.getElementById('manualGrIdInput')?.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') document.getElementById('manualGrIdSaveBtn').click();
});

document.getElementById('findGoodreadsBtn')?.addEventListener('click', async function () {
    const btn        = this;
    const resultsDiv = document.getElementById('goodreadsSearchResults');
    const origHtml   = btn.innerHTML;

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span>Searching…';
    resultsDiv.style.display = 'none';
    resultsDiv.innerHTML = '';

    try {
        const res  = await fetch('json_endpoints/goodreads_search.php?book_id=<?= (int)$id ?>');
        const data = await res.json();

        if (data.error) { alert('Goodreads search: ' + data.error); return; }

        if (!data.results || data.results.length === 0) {
            resultsDiv.innerHTML = '<p class="text-muted small mb-0">No results found.</p>';
            resultsDiv.style.display = '';
            return;
        }

        if (data.isbn_query) {
            const isbnMatches = data.results.filter(r => r.isbn_match).length;
            resultsDiv.dataset.isbnNote = isbnMatches
                ? `ISBN ${data.isbn_query} matched ${isbnMatches} result(s) — shown first`
                : `ISBN ${data.isbn_query} tried but no match found`;
        }

        let html = '';
        if (data.isbn_query) {
            const note = data.results.some(r => r.isbn_match)
                ? `<span class="text-success"><i class="fa-solid fa-barcode me-1"></i>ISBN ${data.isbn_query.replace(/</g,'&lt;')} matched — shown first</span>`
                : `<span class="text-muted"><i class="fa-solid fa-barcode me-1"></i>ISBN ${data.isbn_query.replace(/</g,'&lt;')} — no direct match, showing title search</span>`;
            html += `<div class="small mb-1 px-1">${note}</div>`;
        }
        html += '<div class="list-group" style="max-width:560px">';
        data.results.forEach(r => {
            const title  = r.title.replace(/</g, '&lt;');
            const author = r.author ? `<span class="text-muted"> — ${r.author.replace(/</g,'&lt;')}</span>` : '';
            const year   = r.pub_year  ? `<span class="text-muted ms-1">${r.pub_year}</span>` : '';
            const stars  = r.rating    ? `<span class="text-warning ms-1">★${r.rating}</span>` : '';
            const count  = r.rating_count ? `<span class="text-muted ms-1" style="font-size:0.7rem">${Number(r.rating_count).toLocaleString()} ratings</span>` : '';
            const isbnBadge = r.isbn_match ? `<span class="badge bg-success ms-1" style="font-size:0.65rem">ISBN</span>` : '';
            html += `<button type="button" class="list-group-item list-group-item-action py-1 px-2 small gr-pick${r.isbn_match ? ' list-group-item-success' : ''}"
                         data-gr-id="${r.id}">
                        <div><strong>${title}</strong>${isbnBadge}${author}</div>
                        <div style="font-size:0.7rem">${year}${stars}${count}<span class="text-muted ms-1">#${r.id}</span></div>
                    </button>`;
        });
        html += '</div>';
        resultsDiv.innerHTML = html;
        resultsDiv.style.display = '';

        resultsDiv.querySelectorAll('.gr-pick').forEach(pickBtn => {
            pickBtn.addEventListener('click', async function () {
                const grId = this.dataset.grId;
                this.disabled = true;
                const ok = await saveGoodreadsId(grId);
                if (!ok) { this.disabled = false; return; }
                btn.innerHTML = '<i class="fa-brands fa-goodreads me-1"></i>Update Goodreads ID';
                document.getElementById('goodreadsMetaBtn')?.removeAttribute('disabled');
                resultsDiv.innerHTML = `<span class="text-success small"><i class="fa-solid fa-check me-1"></i>Saved Goodreads ID: ${grId} — will re-fetch metadata on next import run.</span>`;
            });
        });

    } catch (e) {
        alert('Goodreads search failed: ' + e.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = origHtml;
    }
});

document.querySelectorAll('.review-expand-btn').forEach(btn => {
    const body = btn.previousElementSibling;
    const fade = body.querySelector('[data-review-fade]');
    if (body.scrollHeight <= body.offsetHeight + 10) {
        btn.style.display = 'none'; return;
    }
    btn.addEventListener('click', () => {
        body.style.maxHeight = 'none';
        if (fade) fade.style.display = 'none';
        btn.style.display = 'none';
    });
});

document.getElementById('goodreadsMetaBtn')?.addEventListener('click', async function () {
    const btn = this;
    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span>Fetching…';

    try {
        const res  = await fetch('json_endpoints/goodreads_metadata.php?book_id=<?= (int)$id ?>');
        const data = await res.json();

        if (data.error) {
            alert('Goodreads: ' + data.error);
            return;
        }

        if (data.description) {
            const ed = tinymce.get('description');
            if (ed) {
                ed.setContent(data.description);
            } else {
                document.getElementById('description').value = data.description;
            }
        }

        if (data.series) {
            document.getElementById('seriesInput').value = data.series;
        }
        if (data.series_index) {
            document.getElementById('seriesIndex').value = data.series_index;
        }
    } catch (e) {
        alert('Goodreads fetch failed: ' + e.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = origHtml;
    }
});

document.getElementById('stripHtmlBtn')?.addEventListener('click', function () {
    const ta = document.getElementById('description');
    const tmp = document.createElement('div');
    tmp.innerHTML = ta.value;
    // Collapse block elements to a single newline, inline elements to nothing
    tmp.querySelectorAll('p, br, div, li').forEach(el => {
        el.before(document.createTextNode('\n'));
    });
    ta.value = (tmp.textContent || '').replace(/\n{3,}/g, '\n\n').trim();
});
</script>

<?php if ($grWorkId): ?>
<script>
(function () {
    const bookId   = <?= (int)$id ?>;
    const container = document.getElementById('similarBooksContainer');
    const refreshBtn = document.getElementById('similarRefreshBtn');
    let loaded = <?= $similarBooksCount > 0 ? 'false' : 'false' ?>;

    function renderBooks(books) {
        if (!books.length) {
            container.innerHTML = '<p class="text-muted small">No similar books found for this title on Goodreads.</p>';
            return;
        }
        const html = books.map(b => {
            const series  = b.series ? `<div class="text-muted" style="font-size:0.78rem">${escHtml(b.series)}${b.series_position ? ' #' + escHtml(b.series_position) : ''}</div>` : '';
            const rating  = b.gr_rating ? `<span class="text-warning me-2" style="font-size:0.8rem">★${b.gr_rating.toFixed(2)}</span>` : '';
            const count   = b.gr_rating_count ? `<span class="text-muted" style="font-size:0.75rem">${b.gr_rating_count.toLocaleString()} ratings</span>` : '';
            const inLib   = b.in_library
                ? `<a href="book.php?id=${b.library_book_id}" class="badge bg-success text-decoration-none ms-1" title="In your library">In library</a>`
                : '';
            const grLink  = `<a href="https://www.goodreads.com/book/show/${escHtml(b.gr_book_id)}" target="_blank" rel="noopener" class="badge bg-secondary text-decoration-none">GR</a>`;
            const cover   = b.cover_url
                ? `<img src="${escHtml(b.cover_url)}" alt="" class="similar-cover" loading="lazy" onerror="this.style.display='none'">`
                : `<div class="similar-cover-placeholder"><i class="fa-solid fa-book"></i></div>`;
            const desc    = b.description
                ? `<div class="similar-desc small text-muted mt-2" style="max-height:5rem;overflow:hidden;font-size:0.78rem;line-height:1.5">${b.description}</div>`
                : '';
            return `<div class="similar-card">
                <div class="d-flex gap-3">
                    <div class="similar-cover-wrap flex-shrink-0">${cover}</div>
                    <div class="flex-grow-1 overflow-hidden">
                        <div class="fw-semibold text-truncate">${escHtml(b.title || '')}</div>
                        <div class="text-muted small text-truncate">${escHtml(b.author || '')}</div>
                        ${series}
                        <div class="mt-1">${rating}${count}</div>
                        <div class="mt-1 d-flex gap-1 flex-wrap">${grLink}${inLib}</div>
                        ${desc}
                    </div>
                </div>
            </div>`;
        }).join('');
        container.innerHTML = `<div class="similar-grid">${html}</div>`;
    }

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    async function loadSimilar(refresh) {
        refreshBtn.disabled = true;
        refreshBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Loading…';
        container.innerHTML = '<p class="text-muted small">Fetching similar books from Goodreads…</p>';
        try {
            const url = `json_endpoints/fetch_gr_similar.php?book_id=${bookId}${refresh ? '&refresh=1' : ''}`;
            const res = await fetch(url);
            const data = await res.json();
            if (data.error) {
                container.innerHTML = `<div class="alert alert-warning small">${escHtml(data.error)}</div>`;
            } else {
                renderBooks(data.books || []);
                const badge = document.querySelector('#tabSimilarBtn .badge');
                const count = (data.books || []).length;
                if (badge) badge.textContent = count;
                else if (count > 0) {
                    document.getElementById('tabSimilarBtn').insertAdjacentHTML('beforeend',
                        ` <span class="badge bg-secondary ms-1">${count}</span>`);
                }
            }
        } catch (e) {
            container.innerHTML = `<div class="alert alert-danger small">Request failed: ${escHtml(e.message)}</div>`;
        }
        refreshBtn.disabled = false;
        refreshBtn.innerHTML = '<i class="fa-solid fa-rotate me-1"></i>Refresh';
    }

    // Auto-load cached data when tab is first opened
    document.getElementById('tabSimilarBtn').addEventListener('shown.bs.tab', function () {
        if (!loaded) {
            loaded = true;
            <?php if ($similarBooksCount > 0): ?>
            loadSimilar(false);
            <?php else: ?>
            // Nothing cached — leave the "click Fetch" prompt visible
            <?php endif; ?>
        }
    });

    refreshBtn.addEventListener('click', () => {
        loaded = true;
        loadSimilar(true);
    });
})();
</script>
<style>
.similar-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1rem; }
.similar-card { border: 1px solid var(--bs-border-color); border-radius: 6px; padding: 0.75rem; }
.similar-cover-wrap { width: 56px; }
.similar-cover { width: 56px; height: 80px; object-fit: cover; border-radius: 3px; display: block; }
.similar-cover-placeholder { width: 56px; height: 80px; border-radius: 3px; background: var(--bs-secondary-bg); display: flex; align-items: center; justify-content: center; color: var(--bs-secondary-color); }
</style>
<?php endif; ?>
</body>
</html>
