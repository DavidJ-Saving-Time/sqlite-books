<?php
/**
 * Writes Calibre DB metadata back into the book's ebook file using ebook-meta.
 *
 * Syncs title, authors, publisher, publish date, description, tags (genres),
 * series, series index, language, and cover art to the best available file
 * format on disk (preference order: EPUB, AZW3, MOBI, PDF).
 *
 * POST Parameters:
 * - book_id: (int) ID of the book in the Calibre DB.
 *
 * Returns JSON:
 * - Success: { ok: true, format: string, file: string, command: string, detail: string }
 * - Failure: { error: string, command?: string, detail?: string }
 *
 * Requires the ebook-meta binary (part of Calibre) to be installed.
 * Logs all operations to logs/write_ebook_metadata.log.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

// ── Logging ────────────────────────────────────────────────────────────────
$logFile = __DIR__ . '/../logs/write_ebook_metadata.log';
if (!is_dir(dirname($logFile))) {
    mkdir(dirname($logFile), 0775, true);
}
function wm_log(string $msg): void {
    global $logFile;
    file_put_contents(
        $logFile,
        '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n",
        FILE_APPEND | LOCK_EX
    );
}

$bookId = (int)($_POST['book_id'] ?? 0);
wm_log("=== write_ebook_metadata called: book_id=$bookId user=" . (currentUser() ?? 'unknown'));

if ($bookId <= 0) {
    wm_log("ERROR: invalid book_id");
    http_response_code(400);
    echo json_encode(['error' => 'Invalid book ID']);
    exit;
}

// ── Locate ebook-meta binary ───────────────────────────────────────────────
// The web server PATH rarely includes /usr/bin; try known locations explicitly.
$ebookMetaBin = null;
foreach (['/usr/bin/ebook-meta', '/usr/local/bin/ebook-meta', trim((string)shell_exec('which ebook-meta 2>/dev/null'))] as $candidate) {
    if ($candidate !== '' && is_executable($candidate)) {
        $ebookMetaBin = $candidate;
        break;
    }
}
if ($ebookMetaBin === null) {
    wm_log("ERROR: ebook-meta binary not found");
    echo json_encode(['error' => 'ebook-meta not found. Checked /usr/bin, /usr/local/bin, and PATH.']);
    exit;
}
wm_log("ebook-meta binary: $ebookMetaBin");

// ── Check exec() is available ──────────────────────────────────────────────
if (!function_exists('exec') || in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))))) {
    wm_log("ERROR: exec() is disabled");
    echo json_encode(['error' => 'exec() is disabled in php.ini']);
    exit;
}

try {
    $pdo     = getDatabaseConnection();
    $library = rtrim(getLibraryPath(), '/');
    wm_log("library path: $library");

    // ── Core book row ──────────────────────────────────────────────
    $book = $pdo->prepare(
        "SELECT b.title, b.pubdate, b.path, b.has_cover, b.series_index
         FROM books b WHERE b.id = :id"
    );
    $book->execute([':id' => $bookId]);
    $row = $book->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        wm_log("ERROR: book not found id=$bookId");
        http_response_code(404);
        echo json_encode(['error' => 'Book not found']);
        exit;
    }
    wm_log("book row: title=\"{$row['title']}\" path=\"{$row['path']}\"");

    // ── Publisher (separate table in Calibre) ──────────────────────
    $pubStmt = $pdo->prepare(
        "SELECT p.name FROM publishers p
         JOIN books_publishers_link bpl ON p.id = bpl.publisher
         WHERE bpl.book = :id LIMIT 1"
    );
    $pubStmt->execute([':id' => $bookId]);
    $publisher = (string)($pubStmt->fetchColumn() ?: '');
    wm_log("publisher: \"$publisher\"");

    // ── Description (separate comments table in Calibre) ───────────
    $commentStmt = $pdo->prepare("SELECT text FROM comments WHERE book = :id LIMIT 1");
    $commentStmt->execute([':id' => $bookId]);
    $comment = (string)($commentStmt->fetchColumn() ?: '');

    // ── Authors ────────────────────────────────────────────────────
    $authStmt = $pdo->prepare(
        "SELECT a.name FROM authors a
         JOIN books_authors_link bal ON a.id = bal.author
         WHERE bal.book = :id ORDER BY bal.id"
    );
    $authStmt->execute([':id' => $bookId]);
    $authors = $authStmt->fetchAll(PDO::FETCH_COLUMN);
    wm_log("authors: " . implode(', ', $authors));

    // ── Series (standard Calibre tables, optional) ─────────────────
    $seriesName = '';
    try {
        $serStmt = $pdo->prepare(
            "SELECT s.name FROM series s
             JOIN books_series_link bsl ON s.id = bsl.series
             WHERE bsl.book = :id LIMIT 1"
        );
        $serStmt->execute([':id' => $bookId]);
        $seriesName = (string)($serStmt->fetchColumn() ?: '');
        wm_log("series: \"$seriesName\"");
    } catch (Exception $e) {
        wm_log("series query failed (table absent?): " . $e->getMessage());
    }

    // ── Genres / tags (custom column '#genre') ─────────────────────
    $tags = [];
    try {
        $genreCol = $pdo->query(
            "SELECT id FROM custom_columns WHERE label = 'genre' LIMIT 1"
        )->fetchColumn();
        if ($genreCol !== false) {
            $tagStmt = $pdo->prepare(
                "SELECT v.value FROM custom_column_{$genreCol} v
                 JOIN books_custom_column_{$genreCol}_link l ON v.id = l.value
                 WHERE l.book = :id ORDER BY v.value"
            );
            $tagStmt->execute([':id' => $bookId]);
            $tags = $tagStmt->fetchAll(PDO::FETCH_COLUMN);
        }
        wm_log("tags: " . implode(', ', $tags));
    } catch (Exception $e) {
        wm_log("genre query failed: " . $e->getMessage());
    }

    // ── Language ───────────────────────────────────────────────────
    $langStmt = $pdo->prepare(
        "SELECT l.lang_code FROM languages l
         JOIN books_languages_link bl ON l.id = bl.lang_code
         WHERE bl.book = :id ORDER BY bl.item_order LIMIT 1"
    );
    $langStmt->execute([':id' => $bookId]);
    $language = (string)($langStmt->fetchColumn() ?: '');
    wm_log("language: \"$language\"");

    // ── Find best ebook file ────────────────────────────────────────
    $preferredFormats = ['EPUB', 'AZW3', 'MOBI', 'PDF'];
    $fileStmt = $pdo->prepare("SELECT format, name FROM data WHERE book = :id");
    $fileStmt->execute([':id' => $bookId]);
    $files = [];
    foreach ($fileStmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $files[strtoupper($f['format'])] = $f['name'];
    }
    wm_log("formats in DB: " . implode(', ', array_keys($files)));

    $chosenFormat = null;
    $chosenName   = null;
    foreach ($preferredFormats as $fmt) {
        if (isset($files[$fmt])) {
            $chosenFormat = $fmt;
            $chosenName   = $files[$fmt];
            break;
        }
    }
    if ($chosenName === null) {
        wm_log("ERROR: no supported format found");
        echo json_encode(['error' => 'No supported ebook file found (need EPUB, AZW3, MOBI, or PDF)']);
        exit;
    }

    $filePath = $library . '/' . $row['path'] . '/' . $chosenName . '.' . strtolower($chosenFormat);
    wm_log("constructed file path: $filePath");
    $realFile = realpath($filePath);
    wm_log("realpath result: " . ($realFile ?: 'FALSE - file not found'));
    if ($realFile === false || !is_file($realFile)) {
        echo json_encode(['error' => "File not found on disk: $filePath"]);
        exit;
    }
    if (!str_starts_with($realFile . '/', $library . '/')) {
        wm_log("ERROR: path traversal detected: $realFile");
        http_response_code(400);
        echo json_encode(['error' => 'File path escapes library root']);
        exit;
    }

    // ── Build ebook-meta command ────────────────────────────────────
    $cmd = [$ebookMetaBin, $realFile];

    if ($row['title'] !== '') {
        $cmd[] = '--title';
        $cmd[] = $row['title'];
    }
    if ($authors) {
        $cmd[] = '--authors';
        $cmd[] = implode(' & ', $authors);
    }
    if ($publisher !== '') {
        $cmd[] = '--publisher';
        $cmd[] = $publisher;
    }
    // pubdate: Calibre stores as ISO datetime; ebook-meta wants YYYY-MM-DD
    if (!empty($row['pubdate']) && !str_starts_with($row['pubdate'], '0101')) {
        $dt = date('Y-m-d', strtotime($row['pubdate']));
        if ($dt && $dt !== '1970-01-01') {
            $cmd[] = '--date';
            $cmd[] = $dt;
        }
    }
    if ($comment !== '') {
        $cmd[] = '--comments';
        $cmd[] = strip_tags($comment);
    }
    if ($tags) {
        $cmd[] = '--tags';
        $cmd[] = implode(', ', $tags);
    }
    if ($seriesName !== '') {
        $cmd[] = '--series';
        $cmd[] = $seriesName;
        if ((float)($row['series_index'] ?? 0) > 0) {
            $cmd[] = '--index';
            $cmd[] = (string)$row['series_index'];
        }
    }
    if ($language !== '') {
        $cmd[] = '--language';
        $cmd[] = $language;
    }
    $coverPath = $library . '/' . $row['path'] . '/cover.jpg';
    if ($row['has_cover'] && is_file($coverPath)) {
        $cmd[] = '--cover';
        $cmd[] = $coverPath;
    }

    // Escape every argument individually
    $escaped = array_map('escapeshellarg', $cmd);
    $cmdStr  = implode(' ', $escaped);
    wm_log("COMMAND: $cmdStr");

    exec($cmdStr . ' 2>&1', $output, $exitCode);
    $outputStr = implode("\n", $output);
    wm_log("exit code: $exitCode");
    wm_log("output: " . ($outputStr ?: '(none)'));

    if ($exitCode !== 0) {
        echo json_encode([
            'error'   => 'ebook-meta exited with code ' . $exitCode,
            'command' => $cmdStr,
            'detail'  => $outputStr,
        ]);
        exit;
    }

    echo json_encode([
        'ok'      => true,
        'format'  => $chosenFormat,
        'file'    => basename($realFile),
        'command' => $cmdStr,
        'detail'  => $outputStr,
    ]);

} catch (Exception $e) {
    wm_log("EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
