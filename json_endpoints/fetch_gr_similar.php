<?php
/**
 * Fetch and store Goodreads "similar books" for a given library book.
 *
 * GET/POST ?book_id=N
 *
 * Looks up the book's gr_work_id, fetches goodreads.com/book/similar/{work_id},
 * parses the ReactComponents.SimilarBooksList data-react-props JSON, upserts
 * results into gr_similar_books, downloads covers to gr_covers/{gr_book_id}.jpg,
 * and returns the stored list cross-referenced against the local library.
 *
 * Returns JSON:
 *   { books: [{gr_book_id, title, author, series, series_position, gr_rating,
 *              gr_rating_count, cover_url, description, in_library, library_book_id}],
 *     source_work_id, fetched: true }
 * or
 *   { books: [...], source_work_id, fetched: false }  — served from DB cache
 * or
 *   { error: "..." }
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

define('COVERS_DIR', __DIR__ . '/../gr_covers');
define('COVERS_URL', '/gr_covers');

$bookId  = isset($_REQUEST['book_id'])  ? (int)$_REQUEST['book_id']  : 0;
$refresh = !empty($_REQUEST['refresh']); // ?refresh=1 forces re-fetch

if ($bookId <= 0) {
    echo json_encode(['error' => 'Missing book_id']);
    exit;
}

$pdo = getDatabaseConnection();

// ── Look up gr_work_id ────────────────────────────────────────────────────────
$workId = $pdo->prepare("SELECT val FROM identifiers WHERE book = ? AND type = 'gr_work_id'");
$workId->execute([$bookId]);
$workId = $workId->fetchColumn();

if (!$workId) {
    echo json_encode(['error' => 'This book has no gr_work_id — run the Goodreads metadata scraper first']);
    exit;
}

// ── Ensure gr_work_id column exists (migration for older DBs) ────────────────
try { $pdo->exec("ALTER TABLE gr_similar_books ADD COLUMN gr_work_id TEXT"); } catch (Exception $e) {}

// ── Check DB cache (skip fetch unless forced) ─────────────────────────────────
$cachedCount = $pdo->prepare("SELECT COUNT(*) FROM gr_similar_books WHERE source_work_id = ?");
$cachedCount->execute([$workId]);
$hasCached = (int)$cachedCount->fetchColumn() > 0;

if ($hasCached && !$refresh) {
    echo json_encode(loadFromDb($pdo, $workId, $bookId, false));
    exit;
}

// ── Fetch from Goodreads ──────────────────────────────────────────────────────
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
    $msg = $err ?: "HTTP $status";
    echo json_encode(['error' => "Goodreads fetch failed: $msg"]);
    exit;
}

// ── Parse data-react-props from ALL ReactComponents.SimilarBooksList elements ──
// The page contains 3 separate SimilarBooksList components:
//   #1 = source book header (same workId — skipped below)
//   #2 and #3 = the actual similar books
preg_match_all(
    '/data-react-class="ReactComponents\.SimilarBooksList"\s+data-react-props="([^"]+)"/s',
    $html, $matches
);

if (empty($matches[1])) {
    echo json_encode(['error' => 'Could not find SimilarBooksList component on page — Goodreads may have changed their markup']);
    exit;
}

// Collect all book entries across all components
$allEntries = [];
foreach ($matches[1] as $raw) {
    $props = json_decode(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
    if (isset($props['similarBooks']) && is_array($props['similarBooks'])) {
        $allEntries = array_merge($allEntries, $props['similarBooks']);
    }
}

// ── Upsert into gr_similar_books + download covers ────────────────────────────
$upsert = $pdo->prepare("
    INSERT OR REPLACE INTO gr_similar_books
        (source_work_id, gr_book_id, gr_work_id, title, author, series, series_position, gr_rating, gr_rating_count, cover_url, description, fetched_at)
    VALUES
        (:source_work_id, :gr_book_id, :gr_work_id, :title, :author, :series, :series_position, :gr_rating, :gr_rating_count, :cover_url, :description, datetime('now'))
");

$inserted = 0;
foreach ($allEntries as $entry) {
    $b = $entry['book'] ?? null;
    if (!$b) continue;

    $grBookId = (string)($b['bookId'] ?? '');
    $bWorkId  = (string)($b['workId'] ?? '');
    if ($grBookId === '' || $bWorkId === $workId) continue; // skip source book

    // Parse series from full title e.g. "City of Last Chances (The Tyrant Philosophers, #1)"
    $bareTitle      = $b['bookTitleBare'] ?? ($b['title'] ?? '');
    $series         = null;
    $seriesPosition = null;
    $fullTitle      = $b['title'] ?? '';
    if (preg_match('/^(.*?)\s*\(([^()]+),\s*#([\d.]+)\)\s*$/', $fullTitle, $sm)) {
        $series         = trim($sm[2]);
        $seriesPosition = $sm[3];
    }

    // Cover: strip GR's size suffix (._SY180_.jpg → .jpg) for the highest-res version
    $grCoverUrl = $b['imageUrl'] ?? null;
    if ($grCoverUrl) {
        $grCoverUrl = preg_replace('/\._[A-Z0-9_]+_(\.[a-z]+)$/i', '$1', $grCoverUrl);
    }

    // Download cover locally if not already saved
    $localCoverUrl = downloadCover($grBookId, $grCoverUrl);

    // Description: prefer full HTML, fall back to truncated
    $description = $b['description']['html'] ?? $b['description']['truncatedHtml'] ?? null;

    $upsert->execute([
        ':source_work_id'  => $workId,
        ':gr_book_id'      => $grBookId,
        ':gr_work_id'      => $bWorkId ?: null,
        ':title'           => $bareTitle ?: null,
        ':author'          => $b['author']['name'] ?? null,
        ':series'          => $series,
        ':series_position' => $seriesPosition,
        ':gr_rating'       => isset($b['avgRating'])    ? (float)$b['avgRating']    : null,
        ':gr_rating_count' => isset($b['ratingsCount']) ? (int)$b['ratingsCount']   : null,
        ':cover_url'       => $localCoverUrl ?? $grCoverUrl, // local path preferred, GR URL as fallback
        ':description'     => $description,
    ]);
    $inserted++;
}

echo json_encode(loadFromDb($pdo, $workId, $bookId, true, $inserted));

// ── Download a cover and return its local web path ────────────────────────────
function downloadCover(string $grBookId, ?string $sourceUrl): ?string {
    if (!$sourceUrl) return null;

    // Only allow safe filenames — GR book IDs are numeric but be defensive
    $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $grBookId);
    if ($safeId === '') return null;

    $localPath = COVERS_DIR . '/' . $safeId . '.jpg';

    // Already downloaded — return immediately
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
        return null; // don't save corrupt/empty responses
    }

    file_put_contents($localPath, $data);
    return COVERS_URL . '/' . $safeId . '.jpg';
}

// ── Helper: load from DB and cross-reference against local library ─────────────
function loadFromDb(PDO $pdo, string $workId, int $sourceBookId, bool $fetched, int $insertedCount = 0): array {
    $rows = $pdo->prepare("
        SELECT s.gr_book_id,
               s.title,
               s.author,
               s.series,
               s.series_position,
               s.gr_rating,
               s.gr_rating_count,
               s.cover_url,
               s.description,
               s.fetched_at,
               COALESCE(i.book, i2.book) AS library_book_id
        FROM gr_similar_books s
        LEFT JOIN identifiers i  ON i.type  = 'goodreads'  AND i.val  = s.gr_book_id
        LEFT JOIN identifiers i2 ON i2.type = 'gr_work_id' AND i2.val = s.gr_work_id
        WHERE s.source_work_id = ?
        ORDER BY s.gr_rating_count DESC NULLS LAST
    ");
    $rows->execute([$workId]);
    $books = [];
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        // If the stored cover_url is a GR URL (from a previous fetch before local caching),
        // try to download it now and update the DB record
        $coverUrl = $r['cover_url'];
        if ($coverUrl && str_starts_with($coverUrl, 'http')) {
            $local = downloadCover($r['gr_book_id'], $coverUrl);
            if ($local) {
                $pdo->prepare("UPDATE gr_similar_books SET cover_url = ? WHERE gr_book_id = ? AND source_work_id = ?")
                    ->execute([$local, $r['gr_book_id'], $workId]);
                $coverUrl = $local;
            }
        }

        $books[] = [
            'gr_book_id'      => $r['gr_book_id'],
            'title'           => $r['title'],
            'author'          => $r['author'],
            'series'          => $r['series'],
            'series_position' => $r['series_position'],
            'gr_rating'       => $r['gr_rating'] !== null ? (float)$r['gr_rating'] : null,
            'gr_rating_count' => $r['gr_rating_count'] !== null ? (int)$r['gr_rating_count'] : null,
            'cover_url'       => $coverUrl,
            'description'     => $r['description'],
            'in_library'      => $r['library_book_id'] !== null,
            'library_book_id' => $r['library_book_id'] ? (int)$r['library_book_id'] : null,
            'fetched_at'      => $r['fetched_at'],
        ];
    }
    return [
        'books'          => $books,
        'source_work_id' => $workId,
        'fetched'        => $fetched,
        'inserted'       => $insertedCount,
    ];
}
