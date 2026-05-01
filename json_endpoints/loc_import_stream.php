<?php
/**
 * SSE stream: bulk LOC catalog lookup for every library book that hasn't been
 * checked yet (no 'lccn' or 'loc_checked' identifier).
 *
 * Events:
 *   status         {message}
 *   scan_done      {total}
 *   skipped        {n, total, book_id, title}
 *   auto_applied   {n, total, book_id, title, author, score, loc_title, loc_author,
 *                   lccn, edition, lcc, subjects, genres, isbns}
 *   review_needed  {n, total, book_id, title, author, score, loc_title, loc_author,
 *                   lccn, edition, lcc, subjects, genres, isbns}
 *   not_found      {n, total, book_id, title}
 *   error          {n, total, book_id, title, message}
 *   done           {processed, auto_applied, review_needed, not_found, skipped, errors}
 */

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

require_once __DIR__ . '/../db.php';
requireLogin();

set_time_limit(0);
ignore_user_abort(true);

$stopFile = sys_get_temp_dir() . '/loc_import_stop_' . md5(currentUser());

function sse(string $event, array $data): void {
    echo "event: {$event}\ndata: " . json_encode($data) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

function stopped(string $f): bool { return file_exists($f); }

// ── Helpers ───────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../lib/loc_helper.php';

// ── Main ──────────────────────────────────────────────────────────────────────

@unlink($stopFile);

try {
    $pdo = getDatabaseConnection();
} catch (Exception $e) {
    sse('error', ['message' => 'DB error: ' . $e->getMessage()]);
    exit;
}

sse('status', ['message' => 'Building book list…']);

// Books not yet checked (no lccn and no loc_checked identifier)
$books = $pdo->query("
    SELECT b.id, b.title,
           (SELECT a.name FROM books_authors_link bal
            JOIN authors a ON a.id = bal.author
            WHERE bal.book = b.id ORDER BY bal.id ASC LIMIT 1) AS author
    FROM books b
    WHERE NOT EXISTS (
        SELECT 1 FROM identifiers i
        WHERE i.book = b.id AND i.type IN ('lccn', 'loc_checked')
    )
    ORDER BY b.title
")->fetchAll(PDO::FETCH_ASSOC);

// Pre-load all existing ISBNs so we can compare
$isbnIndex = [];
foreach ($pdo->query("SELECT book, val FROM identifiers WHERE type = 'isbn'")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $isbnIndex[(int)$r['book']][] = $r['val'];
}

$total       = count($books);
$nAutoApp    = 0;
$nReview     = 0;
$nNotFound   = 0;
$nSkipped    = 0;
$nErrors     = 0;
$today       = date('Y-m-d');

sse('scan_done', ['total' => $total]);

if ($total === 0) {
    sse('done', ['processed' => 0, 'auto_applied' => 0, 'review_needed' => 0,
                 'not_found' => 0, 'skipped' => 0, 'errors' => 0]);
    exit;
}

foreach ($books as $idx => $book) {
    if (stopped($stopFile)) break;

    $n      = $idx + 1;
    $bookId = (int)$book['id'];
    $title  = $book['title'] ?? '';
    $author = $book['author'] ?? '';

    if ($title === '' || $author === '') {
        $nSkipped++;
        sse('skipped', ['n' => $n, 'total' => $total, 'book_id' => $bookId, 'title' => $title]);
        continue;
    }

    $surname    = extractSurname($author);
    $bookIsbns  = $isbnIndex[$bookId] ?? [];

    try {
        $locResults = locQuerySRU($title, $surname, 5);
    } catch (Exception $e) {
        $nErrors++;
        sse('error', ['n' => $n, 'total' => $total, 'book_id' => $bookId,
                      'title' => $title, 'message' => $e->getMessage()]);
        sleep(5);
        continue;
    }

    // Rate-limited (429, 503, or CAPTCHA HTML) — LOC blocks for 1 hour so stop the run
    if ($locResults === false) {
        sse('error', ['n' => $n, 'total' => $total, 'book_id' => $bookId, 'title' => $title,
                      'message' => 'Rate limited by LOC (429/CAPTCHA) or service temporarily unavailable. Stopped — wait ~1 hour before restarting.']);
        break;
    }

    if (empty($locResults)) {
        // No results — mark checked so we don't retry
        try {
            $pdo->prepare("INSERT OR REPLACE INTO identifiers (book, type, val) VALUES (?, 'loc_checked', ?)")
                ->execute([$bookId, $today]);
        } catch (Exception $e) { /* non-fatal */ }
        $nNotFound++;
        sse('not_found', ['n' => $n, 'total' => $total, 'book_id' => $bookId, 'title' => $title]);
        usleep(1500000);
        continue;
    }

    // Score all results, keep highest (prefer first editions on ties)
    $bestScore  = -1;
    $bestResult = null;
    foreach ($locResults as $loc) {
        $s = scoreLocResult($loc, $title, $author, $bookIsbns);
        if ($s > $bestScore) { $bestScore = $s; $bestResult = $loc; }
    }

    $payload = [
        'n'          => $n,
        'total'      => $total,
        'book_id'    => $bookId,
        'title'      => $title,
        'author'     => $author,
        'score'      => $bestScore,
        'loc_title'  => $bestResult['title']   ?? '',
        'loc_author' => invertLocAuthor($bestResult['author'] ?? ''),
        'lccn'       => $bestResult['lccn']    ?? '',
        'edition'    => $bestResult['edition'] ?? '',
        'lcc'        => $bestResult['lcc']     ?? '',
        'publisher'  => $bestResult['publisher'] ?? '',
        'date'       => $bestResult['date']    ?? '',
        'subjects'   => $bestResult['subjects'] ?? [],
        'genres'     => $bestResult['genres']  ?? [],
        'isbns'      => $bestResult['isbn']    ?? [],
    ];

    if ($bestScore >= 70) {
        // Auto-apply
        try {
            applyLocData($pdo, $bookId, $bestResult, $today);
        } catch (Exception $e) {
            $nErrors++;
            sse('error', ['n' => $n, 'total' => $total, 'book_id' => $bookId,
                          'title' => $title, 'message' => 'Save failed: ' . $e->getMessage()]);
            usleep(250000);
            continue;
        }
        $nAutoApp++;
        sse('auto_applied', $payload);
    } elseif ($bestScore >= 40) {
        // Queue for review — don't save yet
        $nReview++;
        sse('review_needed', $payload);
    } else {
        // No confident match
        try {
            $pdo->prepare("INSERT OR REPLACE INTO identifiers (book, type, val) VALUES (?, 'loc_checked', ?)")
                ->execute([$bookId, $today]);
        } catch (Exception $e) { /* non-fatal */ }
        $nNotFound++;
        sse('not_found', ['n' => $n, 'total' => $total, 'book_id' => $bookId, 'title' => $title]);
    }

    usleep(1500000); // 1.5 s between requests ≈ 40 req/min — well under LOC rate limits
}

@unlink($stopFile);

sse('done', [
    'processed'     => $total,
    'auto_applied'  => $nAutoApp,
    'review_needed' => $nReview,
    'not_found'     => $nNotFound,
    'skipped'       => $nSkipped,
    'errors'        => $nErrors,
]);
