<?php
/**
 * Copies a book (metadata + files) from the current user's library to another
 * user's library.
 *
 * POST params:
 *   book_id      int    Source book ID
 *   target_user  string Username whose library receives the copy
 *
 * Returns JSON:
 *   { status: 'ok', new_book_id: int, message: string }
 *   { status: 'duplicate', message: string }
 *   { error: string }
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

// ── Input validation ──────────────────────────────────────────────────────────
$bookId     = (int)($_POST['book_id']     ?? 0);
$targetUser = trim($_POST['target_user'] ?? '');

if ($bookId <= 0 || $targetUser === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing book_id or target_user']);
    exit;
}

$currentUser = currentUser();
if ($targetUser === $currentUser) {
    echo json_encode(['error' => 'Source and target library are the same']);
    exit;
}

// ── Load target library config from users.json ────────────────────────────────
$usersFile = __DIR__ . '/../users.json';
$users     = json_decode(file_get_contents($usersFile), true);
if (!isset($users[$targetUser])) {
    echo json_encode(['error' => 'Unknown target user']);
    exit;
}
$targetDbPath  = $users[$targetUser]['prefs']['db_path']      ?? '';
$targetLibPath = $users[$targetUser]['prefs']['library_path'] ?? '';

if ($targetDbPath === '' || !file_exists($targetDbPath)) {
    echo json_encode(['error' => 'Target library database not found']);
    exit;
}
if ($targetLibPath === '') {
    echo json_encode(['error' => 'Target library path not configured']);
    exit;
}

// ── Fetch full book from source DB ────────────────────────────────────────────
$srcPdo = getDatabaseConnection();

$stmt = $srcPdo->prepare("
    SELECT b.*,
           (SELECT GROUP_CONCAT(a.name, '|')
              FROM books_authors_link bal JOIN authors a ON bal.author = a.id
              WHERE bal.book = b.id) AS author_names,
           (SELECT s.name
              FROM books_series_link bsl JOIN series s ON bsl.series = s.id
              WHERE bsl.book = b.id LIMIT 1) AS series_name,
           (SELECT p.name
              FROM books_publishers_link bpl JOIN publishers p ON bpl.publisher = p.id
              WHERE bpl.book = b.id LIMIT 1) AS publisher_name,
           (SELECT GROUP_CONCAT(t.name, '|')
              FROM books_tags_link btl JOIN tags t ON btl.tag = t.id
              WHERE btl.book = b.id) AS tag_names,
           (SELECT l.lang_code
              FROM languages l JOIN books_languages_link bll ON bll.lang_code = l.id
              WHERE bll.book = b.id LIMIT 1) AS lang_code,
           (SELECT text FROM comments WHERE book = b.id LIMIT 1) AS description
    FROM books b WHERE b.id = ?
");
$stmt->execute([$bookId]);
$book = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$book) {
    echo json_encode(['error' => 'Book not found']);
    exit;
}

// All identifiers (isbn, olid, etc.)
$identStmt = $srcPdo->prepare('SELECT type, val FROM identifiers WHERE book = ?');
$identStmt->execute([$bookId]);
$identifiers = $identStmt->fetchAll(PDO::FETCH_ASSOC);

$isbnVal = '';
foreach ($identifiers as $id) {
    if (strtolower($id['type']) === 'isbn') { $isbnVal = $id['val']; break; }
}

// Format records
$fmtStmt = $srcPdo->prepare('SELECT format, name, uncompressed_size FROM data WHERE book = ?');
$fmtStmt->execute([$bookId]);
$formats = $fmtStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Open destination DB (via getDatabaseConnection so custom functions are registered) ──
try {
    $dstPdo = getDatabaseConnection($targetDbPath);
} catch (Exception $e) {
    echo json_encode(['error' => 'Cannot open target database: ' . $e->getMessage()]);
    exit;
}

// ── Duplicate check ───────────────────────────────────────────────────────────
// 1. ISBN match
if ($isbnVal !== '') {
    $r = $dstPdo->prepare("
        SELECT b.title FROM identifiers i
        JOIN books b ON i.book = b.id
        WHERE i.type = 'isbn' COLLATE NOCASE AND i.val = ? LIMIT 1
    ");
    $r->execute([$isbnVal]);
    if ($dup = $r->fetchColumn()) {
        echo json_encode(['status' => 'duplicate', 'message' => "Already in library (ISBN match): $dup"]);
        exit;
    }
}

// 2. Normalised title + first author match
function norm_match(string $s): string {
    if (function_exists('iconv')) {
        $t = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($t !== false) $s = $t;
    }
    $s = mb_strtolower(trim($s));
    $s = preg_replace("/[']/", '', $s);
    $s = preg_replace('/[^a-z0-9 ]/', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

$normTitle      = norm_match($book['title']);
$firstAuthor    = explode('|', $book['author_names'] ?? '')[0] ?? '';
$normFirstAuthor = norm_match($firstAuthor);

$candidates = $dstPdo->query("
    SELECT b.title, GROUP_CONCAT(a.name, '|') AS authors
    FROM books b
    LEFT JOIN books_authors_link bal ON bal.book = b.id
    LEFT JOIN authors a ON a.id = bal.author
    GROUP BY b.id
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($candidates as $c) {
    if (norm_match($c['title']) !== $normTitle) continue;
    $candFirst = explode('|', $c['authors'] ?? '')[0] ?? '';
    if (norm_match($candFirst) === $normFirstAuthor) {
        echo json_encode(['status' => 'duplicate', 'message' => 'Already in library (title/author match): ' . $c['title']]);
        exit;
    }
}

// ── Helper: find-or-create a simple lookup row ────────────────────────────────
function findOrCreate(PDO $pdo, string $table, string $col, string $val): int {
    $s = $pdo->prepare("SELECT id FROM $table WHERE $col = ? LIMIT 1");
    $s->execute([$val]);
    $id = $s->fetchColumn();
    if ($id !== false) return (int)$id;
    $pdo->prepare("INSERT INTO $table ($col) VALUES (?)")->execute([$val]);
    return (int)$pdo->lastInsertId();
}

// ── safe_filename (same rules as book.php / send_to_device.php) ───────────────
function safe_fn(string $name, int $max = 150): string {
    if (function_exists('iconv')) {
        $t = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if ($t !== false) $name = $t;
    }
    $name = preg_replace('/[^A-Za-z0-9 _.\'-]/', '', $name);
    return substr(trim($name), 0, $max);
}

// ── Write metadata into destination DB ───────────────────────────────────────
$dstPdo->beginTransaction();
try {
    // Authors
    $authorIds = [];
    foreach (array_filter(explode('|', $book['author_names'] ?? ''), 'strlen') as $aname) {
        $authorIds[] = findOrCreate($dstPdo, 'authors', 'name', $aname);
    }

    // Insert book row with placeholder path; update once we have the new ID
    $dstPdo->prepare("
        INSERT INTO books
            (title, sort, author_sort, path, uuid, pubdate, last_modified, series_index, timestamp, has_cover)
        VALUES (?, ?, ?, '__placeholder__', ?, ?, ?, ?, ?, ?)
    ")->execute([
        $book['title'],
        $book['sort']         ?? $book['title'],
        $book['author_sort']  ?? '',
        ($book['uuid'] ?? '') !== '' ? $book['uuid'] : bin2hex(random_bytes(16)),
        $book['pubdate']      ?? null,
        date('Y-m-d H:i:s+00:00'),
        (float)($book['series_index'] ?? 1.0),
        $book['timestamp']    ?? date('Y-m-d H:i:s+00:00'),
        0, // has_cover updated after file copy
    ]);
    $newBookId = (int)$dstPdo->lastInsertId();

    // Build Calibre-style path: Author/Title (ID)
    $authorDir   = safe_fn($firstAuthor !== '' ? $firstAuthor : 'Unknown') ?: 'Unknown';
    $titleDir    = safe_fn($book['title']) ?: 'Book';
    $bookRelPath = $authorDir . '/' . $titleDir . ' (' . $newBookId . ')';
    $dstPdo->prepare("UPDATE books SET path = ? WHERE id = ?")->execute([$bookRelPath, $newBookId]);

    // Author links
    foreach ($authorIds as $aid) {
        $dstPdo->prepare("INSERT OR IGNORE INTO books_authors_link (book, author) VALUES (?, ?)")->execute([$newBookId, $aid]);
    }

    // Series
    if (!empty($book['series_name'])) {
        $seriesId = findOrCreate($dstPdo, 'series', 'name', $book['series_name']);
        $dstPdo->prepare("INSERT OR IGNORE INTO books_series_link (book, series) VALUES (?, ?)")->execute([$newBookId, $seriesId]);
    }

    // Publisher
    if (!empty($book['publisher_name'])) {
        $pubId = findOrCreate($dstPdo, 'publishers', 'name', $book['publisher_name']);
        $dstPdo->prepare("INSERT OR IGNORE INTO books_publishers_link (book, publisher) VALUES (?, ?)")->execute([$newBookId, $pubId]);
    }

    // Tags
    foreach (array_filter(explode('|', $book['tag_names'] ?? ''), 'strlen') as $tag) {
        $tagId = findOrCreate($dstPdo, 'tags', 'name', $tag);
        $dstPdo->prepare("INSERT OR IGNORE INTO books_tags_link (book, tag) VALUES (?, ?)")->execute([$newBookId, $tagId]);
    }

    // Language
    if (!empty($book['lang_code'])) {
        $langId = findOrCreate($dstPdo, 'languages', 'lang_code', $book['lang_code']);
        $dstPdo->prepare("INSERT OR IGNORE INTO books_languages_link (book, lang_code, item_order) VALUES (?, ?, 0)")
               ->execute([$newBookId, $langId]);
    }

    // Description
    if (!empty($book['description'])) {
        $dstPdo->prepare("INSERT INTO comments (book, text) VALUES (?, ?)")->execute([$newBookId, $book['description']]);
    }

    // Identifiers
    foreach ($identifiers as $ident) {
        $dstPdo->prepare("INSERT OR IGNORE INTO identifiers (book, type, val) VALUES (?, ?, ?)")
               ->execute([$newBookId, $ident['type'], $ident['val']]);
    }

    // ── Custom columns: matched by label name ─────────────────────────────────
    $srcCols = $srcPdo->query("SELECT id, label, datatype FROM custom_columns")->fetchAll(PDO::FETCH_ASSOC);
    $dstColMap = [];
    foreach ($dstPdo->query("SELECT id, label, datatype FROM custom_columns")->fetchAll(PDO::FETCH_ASSOC) as $dc) {
        $dstColMap[$dc['label']] = $dc;
    }

    foreach ($srcCols as $sc) {
        $label = $sc['label'];
        if (!isset($dstColMap[$label])) continue;
        $dc = $dstColMap[$label];

        $srcLink = "books_custom_column_{$sc['id']}_link";
        $srcVal  = "custom_column_{$sc['id']}";
        $dstLink = "books_custom_column_{$dc['id']}_link";
        $dstVal  = "custom_column_{$dc['id']}";

        // Verify tables exist in both DBs
        $srcOk = $srcPdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name=" . $srcPdo->quote($srcLink))->fetchColumn();
        $dstOk = $dstPdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name=" . $dstPdo->quote($dstLink))->fetchColumn();
        if (!$srcOk || !$dstOk) continue;

        // Fetch values for this book from source
        $rows = $srcPdo->prepare("SELECT v.value FROM $srcLink l JOIN $srcVal v ON l.value = v.id WHERE l.book = ?");
        $rows->execute([$bookId]);

        foreach ($rows->fetchAll(PDO::FETCH_COLUMN) as $val) {
            // Find or create value in destination
            $existing = $dstPdo->prepare("SELECT id FROM $dstVal WHERE value = ? LIMIT 1");
            $existing->execute([$val]);
            $dstValId = $existing->fetchColumn();
            if ($dstValId === false) {
                $dstPdo->prepare("INSERT INTO $dstVal (value) VALUES (?)")->execute([$val]);
                $dstValId = (int)$dstPdo->lastInsertId();
            }
            $dstPdo->prepare("INSERT OR IGNORE INTO $dstLink (book, value) VALUES (?, ?)")->execute([$newBookId, (int)$dstValId]);
        }
    }

    $dstPdo->commit();
} catch (Exception $e) {
    $dstPdo->rollBack();
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    exit;
}

// ── Copy physical files ───────────────────────────────────────────────────────
$srcLibPath = getLibraryPath();
$srcBookDir = rtrim($srcLibPath, '/') . '/' . ltrim($book['path'], '/');
$dstBookDir = rtrim($targetLibPath, '/') . '/' . $bookRelPath;

if (!is_dir($dstBookDir)) {
    mkdir($dstBookDir, 0755, true);
}

// Formats
$copiedFormats = [];
foreach ($formats as $fmt) {
    $ext     = strtolower($fmt['format']);
    $srcFile = $srcBookDir . '/' . $fmt['name'] . '.' . $ext;
    $dstName = safe_fn($book['title']) ?: 'book';
    $dstFile = $dstBookDir . '/' . $dstName . '.' . $ext;

    if (file_exists($srcFile) && copy($srcFile, $dstFile)) {
        $copiedFormats[] = ['format' => strtoupper($fmt['format']), 'name' => $dstName, 'size' => filesize($dstFile)];
    }
}

// Register formats in destination DB
foreach ($copiedFormats as $cf) {
    $dstPdo->prepare("INSERT OR IGNORE INTO data (book, format, uncompressed_size, name) VALUES (?, ?, ?, ?)")
           ->execute([$newBookId, $cf['format'], $cf['size'], $cf['name']]);
}

// Cover
$srcCover = $srcBookDir . '/cover.jpg';
if (file_exists($srcCover) && copy($srcCover, $dstBookDir . '/cover.jpg')) {
    $dstPdo->prepare("UPDATE books SET has_cover = 1 WHERE id = ?")->execute([$newBookId]);
}

// ── Invalidate destination user's cache ───────────────────────────────────────
$dstCacheDir = __DIR__ . '/../cache/' . $targetUser;
foreach (['genres', 'shelves', 'statuses', 'total_books'] as $key) {
    $f = $dstCacheDir . '/' . $key . '.json';
    if (file_exists($f)) @unlink($f);
}

echo json_encode([
    'status'      => 'ok',
    'new_book_id' => $newBookId,
    'formats'     => count($copiedFormats),
    'message'     => 'Copied to ' . $targetUser . '\'s library',
]);
