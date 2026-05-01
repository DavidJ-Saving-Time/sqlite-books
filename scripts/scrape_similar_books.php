<?php
/**
 * Batch-scrape Goodreads "similar books" for eligible library books.
 *
 * Eligible criteria:
 *   1. Book has a gr_work_id identifier
 *   2. Book has gr_rating_count > 1000
 *   3. If the book is in a series, only the lowest-series_index qualifying
 *      book in that series is scraped (i.e. "book 1 of the series")
 *
 * Results are stored in gr_similar_books; covers saved to gr_covers/.
 * Progress is saved — safe to stop and resume.
 *
 * Usage:
 *   php scripts/scrape_similar_books.php <username> [--force] [--delay=N] [--dry-run]
 *
 * Options:
 *   --force       Re-fetch books that already have cached similar books
 *   --delay=N     Seconds to sleep between GR requests (default: 5)
 *   --dry-run     Print eligible books without fetching anything
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from CLI only\n");
}

// ── Args ──────────────────────────────────────────────────────────────────────
$args   = array_slice($argv, 1);
$user   = null;
$force  = false;
$dryRun = false;
$delay  = 5;

foreach ($args as $arg) {
    if ($arg === '--force')                    { $force  = true; }
    elseif ($arg === '--dry-run')              { $dryRun = true; }
    elseif (str_starts_with($arg, '--delay=')) { $delay  = max(0, (int)substr($arg, 8)); }
    elseif (!str_starts_with($arg, '-'))       { $user   = $arg; }
}

if ($user === null) {
    fwrite(STDERR, "Usage: php scrape_similar_books.php <username> [--force] [--delay=N] [--dry-run]\n");
    exit(1);
}

// ── Load users.json ───────────────────────────────────────────────────────────
$scriptDir = dirname(__DIR__);
$dataDir   = $scriptDir . '/data';
$usersFile = $scriptDir . '/users.json';
if (!file_exists($usersFile)) {
    fwrite(STDERR, "users.json not found: $usersFile\n");
    exit(1);
}
$users = json_decode(file_get_contents($usersFile), true);
if (!isset($users[$user])) {
    fwrite(STDERR, "Unknown user: $user\n");
    exit(1);
}

$dbPath = $users[$user]['prefs']['db_path'] ?? '';
if ($dbPath === '' || !file_exists($dbPath)) {
    fwrite(STDERR, "DB not found for user $user: $dbPath\n");
    exit(1);
}

// ── Constants ─────────────────────────────────────────────────────────────────
define('COVERS_DIR',       $scriptDir . '/gr_covers');
define('COVERS_URL',       '/gr_covers');
define('MIN_RATING_COUNT', 1000);
define('PROGRESS_FILE',    $dataDir . '/similar_books_progress.json');

// ── Open DB ───────────────────────────────────────────────────────────────────
try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 30000'); // wait up to 30s if DB is locked
} catch (Exception $e) {
    fwrite(STDERR, "DB error: " . $e->getMessage() . "\n");
    exit(1);
}

// ── Ensure covers dir exists ──────────────────────────────────────────────────
if (!is_dir(COVERS_DIR)) {
    mkdir(COVERS_DIR, 0755, true);
}

// ── Load progress ─────────────────────────────────────────────────────────────
$progress    = ['done_work_ids' => []];
$doneWorkIds = [];

if (!$force && file_exists(PROGRESS_FILE)) {
    $p = json_decode(file_get_contents(PROGRESS_FILE), true);
    if (is_array($p)) {
        $progress    = $p;
        $doneWorkIds = array_flip($progress['done_work_ids'] ?? []);
    }
}

// ── Fetch all qualifying books from DB ────────────────────────────────────────
// Qualifying = has gr_work_id + gr_rating_count > MIN_RATING_COUNT
$rows = $pdo->query("
    SELECT b.id,
           b.title,
           COALESCE(b.series_index, 1.0) AS series_index,
           i_work.val                    AS gr_work_id,
           CAST(i_rating.val AS INTEGER) AS gr_rating_count,
           s.id                          AS series_id,
           s.name                        AS series_name
    FROM   books b
    JOIN   identifiers i_work   ON i_work.book   = b.id AND i_work.type   = 'gr_work_id'
    JOIN   identifiers i_rating ON i_rating.book  = b.id AND i_rating.type  = 'gr_rating_count'
                                AND CAST(i_rating.val AS INTEGER) > " . MIN_RATING_COUNT . "
    LEFT JOIN books_series_link bsl ON bsl.book = b.id
    LEFT JOIN series            s   ON bsl.series = s.id
    ORDER BY s.name NULLS LAST, b.series_index, b.title COLLATE NOCASE
")->fetchAll(PDO::FETCH_ASSOC);

// ── Apply series filter ───────────────────────────────────────────────────────
// For each series, keep only the qualifying book with the lowest series_index.
$seriesFirst = []; // series_id => book row
foreach ($rows as $book) {
    $sid = $book['series_id'];
    if ($sid === null) continue;
    $idx = (float)$book['series_index'];
    if (!isset($seriesFirst[$sid]) || $idx < (float)$seriesFirst[$sid]['series_index']) {
        $seriesFirst[$sid] = $book;
    }
}

$eligible = [];
foreach ($rows as $book) {
    $sid = $book['series_id'];
    if ($sid === null) {
        // Not in any series — always eligible
        $eligible[] = $book;
    } elseif ((int)$seriesFirst[$sid]['id'] === (int)$book['id']) {
        // First book in this series
        $eligible[] = $book;
    }
    // else: filtered out — later book in the same series
}

$total    = count($eligible);
$filtered = count($rows) - $total;

echo "User: $user | DB: $dbPath\n";
echo "Qualifying books (rating_count > " . MIN_RATING_COUNT . "): " . count($rows) . "\n";
echo "After series dedup: $total eligible  ($filtered filtered as later-in-series)" . ($dryRun ? " [DRY RUN]" : "") . ($force ? " [--force]" : "") . "\n";
echo str_repeat('─', 70) . "\n";

if ($total === 0) {
    echo "Nothing to do.\n";
    exit(0);
}

// ── Prepare upsert statement ──────────────────────────────────────────────────
$upsert = $pdo->prepare("
    INSERT OR REPLACE INTO gr_similar_books
        (source_work_id, gr_book_id, title, author, series, series_position,
         gr_rating, gr_rating_count, cover_url, description, fetched_at)
    VALUES
        (:source_work_id, :gr_book_id, :title, :author, :series, :series_position,
         :gr_rating, :gr_rating_count, :cover_url, :description, datetime('now'))
");

// ── Main loop ─────────────────────────────────────────────────────────────────
$fetchedBooks = 0;
$skippedBooks = 0;
$errorBooks   = 0;

foreach ($eligible as $i => $book) {
    $num    = $i + 1;
    $bookId = (int)$book['id'];
    $workId = $book['gr_work_id'];
    $title  = $book['title'];
    $label  = $book['series_name']
        ? " [{$book['series_name']} #{$book['series_index']}]"
        : '';

    echo sprintf("[%d/%d] %s%s (work:%s)\n", $num, $total, mb_substr($title, 0, 50), $label, $workId);

    // Skip already-processed work IDs (progress resume)
    if (!$force && isset($doneWorkIds[$workId])) {
        $cachedStmt = $pdo->prepare("SELECT COUNT(*) FROM gr_similar_books WHERE source_work_id = ?");
        $cachedStmt->execute([$workId]);
        $cnt = (int)$cachedStmt->fetchColumn();
        echo "  · already fetched ($cnt similar books) — skipping\n";
        $skippedBooks++;
        continue;
    }

    if ($dryRun) {
        echo "  → [dry-run] would fetch\n";
        continue;
    }

    // Fetch from Goodreads
    $result = fetchSimilarBooks($workId);

    if ($result['error'] !== null) {
        echo "  ERROR: {$result['error']}\n";
        $errorBooks++;
        // Do NOT mark as done — it will be retried on next run
        continue;
    }

    // Upsert each similar book entry
    $inserted = 0;
    foreach ($result['entries'] as $entry) {
        $b = $entry['book'] ?? null;
        if (!$b) continue;

        $grBookId = (string)($b['bookId'] ?? '');
        $bWorkId  = (string)($b['workId'] ?? '');
        if ($grBookId === '' || $bWorkId === $workId) continue; // skip self

        $bareTitle      = $b['bookTitleBare'] ?? ($b['title'] ?? '');
        $simSeries      = null;
        $simSeriesPos   = null;
        $fullTitle      = $b['title'] ?? '';
        if (preg_match('/^(.*?)\s*\(([^()]+),\s*#([\d.]+)\)\s*$/', $fullTitle, $sm)) {
            $simSeries    = trim($sm[2]);
            $simSeriesPos = $sm[3];
        }

        $grCoverUrl = $b['imageUrl'] ?? null;
        if ($grCoverUrl) {
            $grCoverUrl = preg_replace('/\._[A-Z0-9_]+_(\.[a-z]+)$/i', '$1', $grCoverUrl);
        }

        $localCoverUrl = downloadCover($grBookId, $grCoverUrl);
        $description   = $b['description']['html'] ?? $b['description']['truncatedHtml'] ?? null;

        executeWithRetry($upsert, [
            ':source_work_id'  => $workId,
            ':gr_book_id'      => $grBookId,
            ':title'           => $bareTitle ?: null,
            ':author'          => $b['author']['name'] ?? null,
            ':series'          => $simSeries,
            ':series_position' => $simSeriesPos,
            ':gr_rating'       => isset($b['avgRating'])    ? (float)$b['avgRating']    : null,
            ':gr_rating_count' => isset($b['ratingsCount']) ? (int)$b['ratingsCount']   : null,
            ':cover_url'       => $localCoverUrl ?? $grCoverUrl,
            ':description'     => $description,
        ]);
        $inserted++;
    }

    echo "  → saved $inserted similar books\n";
    $fetchedBooks++;

    // Mark work ID as done and save progress every 10 books
    $doneWorkIds[$workId] = true;
    $progress['done_work_ids'] = array_keys($doneWorkIds);
    if ($num % 10 === 0) {
        saveProgress($progress);
    }

    if ($num < $total) {
        sleep($delay);
    }
}

saveProgress($progress);

echo str_repeat('─', 70) . "\n";
$suffix = $dryRun ? " [DRY RUN — nothing written]" : "";
echo "Done: $fetchedBooks fetched, $skippedBooks skipped (cached), $errorBooks errors{$suffix}\n";

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Fetch the GR similar-books page for a work ID.
 * Returns ['error' => string|null, 'entries' => array].
 */
function fetchSimilarBooks(string $workId): array {
    $url = 'https://www.goodreads.com/book/similar/' . urlencode($workId);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.5',
        ],
        CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64; rv:125.0) Gecko/20100101 Firefox/125.0',
    ]);
    $html   = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($html === false || $status !== 200) {
        return ['error' => $err ?: "HTTP $status", 'entries' => []];
    }

    preg_match_all(
        '/data-react-class="ReactComponents\.SimilarBooksList"\s+data-react-props="([^"]+)"/s',
        $html, $matches
    );

    if (empty($matches[1])) {
        return ['error' => 'SimilarBooksList component not found on page', 'entries' => []];
    }

    $allEntries = [];
    foreach ($matches[1] as $raw) {
        $props = json_decode(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
        if (isset($props['similarBooks']) && is_array($props['similarBooks'])) {
            $allEntries = array_merge($allEntries, $props['similarBooks']);
        }
    }

    return ['error' => null, 'entries' => $allEntries];
}

/**
 * Download a cover image and return its local web path, or null on failure.
 * Skips if already on disk.
 */
function downloadCover(string $grBookId, ?string $sourceUrl): ?string {
    if (!$sourceUrl) return null;

    $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $grBookId);
    if ($safeId === '') return null;

    $localPath = COVERS_DIR . '/' . $safeId . '.jpg';
    if (file_exists($localPath)) {
        return COVERS_URL . '/' . $safeId . '.jpg';
    }

    $ch = curl_init($sourceUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (X11; Linux x86_64; rv:125.0) Gecko/20100101 Firefox/125.0',
    ]);
    $data   = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($data === false || $status !== 200 || strlen($data) < 500) {
        return null;
    }

    file_put_contents($localPath, $data);
    return COVERS_URL . '/' . $safeId . '.jpg';
}

/**
 * Execute a PDO statement with automatic retry on SQLITE_BUSY (error code 5).
 * Retries up to $maxRetries times with a 2s sleep between attempts.
 */
function executeWithRetry(PDOStatement $stmt, array $params, int $maxRetries = 5): void {
    $attempt = 0;
    while (true) {
        try {
            $stmt->execute($params);
            return;
        } catch (PDOException $e) {
            // SQLSTATE HY000 / error code 5 = SQLITE_BUSY
            if ($e->getCode() === 'HY000' && str_contains($e->getMessage(), 'database is locked') && $attempt < $maxRetries) {
                $attempt++;
                echo "  [retry $attempt/$maxRetries] DB locked — waiting 2s...\n";
                sleep(2);
            } else {
                throw $e;
            }
        }
    }
}

function saveProgress(array $progress): void {
    $progress['updated'] = date('c');
    file_put_contents(PROGRESS_FILE, json_encode($progress, JSON_PRETTY_PRINT));
}
