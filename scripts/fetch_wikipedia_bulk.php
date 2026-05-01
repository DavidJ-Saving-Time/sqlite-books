<?php
/**
 * Bulk-fetches Wikipedia summaries for all books that don't yet have wiki_book data.
 *
 * CLI:  php scripts/fetch_wikipedia_bulk.php --db /path/to/metadata.db [options]
 *
 * Options:
 *   --db PATH      Path to Calibre metadata.db (required)
 *   --limit N      Max books to process this run (default: all)
 *   --delay N      Seconds to wait between requests (default: 2)
 *   --refetch      Re-fetch even if wiki_book data already exists (also clears not-found cache)
 *   --dry-run      Show what would be fetched without saving
 *
 * Not-found cache: books that returned no usable Wikipedia result are recorded in
 * wiki_book_cache.json (next to metadata.db) and skipped on future runs.
 * Use --refetch to clear the cache and retry everything.
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

require_once __DIR__ . '/../db.php';

// ── Parse arguments ───────────────────────────────────────────────────────────
$limit   = PHP_INT_MAX;
$delay   = 2;
$refetch = false;
$dryRun  = false;
$dbPath  = null;

for ($i = 1; $i < count($argv); $i++) {
    switch ($argv[$i]) {
        case '--db':      $dbPath  = $argv[++$i] ?? null;               break;
        case '--limit':   $limit   = (int)($argv[++$i] ?? PHP_INT_MAX); break;
        case '--delay':   $delay   = (int)($argv[++$i] ?? 2);           break;
        case '--refetch': $refetch = true;                               break;
        case '--dry-run': $dryRun  = true;                               break;
    }
}

if ($dbPath === null) {
    fwrite(STDERR, "Usage: php scripts/fetch_wikipedia_bulk.php --db /path/to/metadata.db [--limit N] [--delay N] [--refetch] [--dry-run]\n");
    exit(1);
}
if (!file_exists($dbPath)) {
    fwrite(STDERR, "Error: database not found: $dbPath\n");
    exit(1);
}

$pdo = getDatabaseConnection($dbPath);

// ── Not-found cache ───────────────────────────────────────────────────────────
// Stored as JSON next to the DB: { "123": "not found", "456": "no title match", ... }
$cacheFile = dirname(realpath($dbPath)) . '/wiki_book_cache.json';

if ($refetch) {
    $notFoundCache = [];
    if (file_exists($cacheFile)) {
        unlink($cacheFile);
        echo "Cache cleared.\n";
    }
} else {
    $notFoundCache = file_exists($cacheFile)
        ? (json_decode(file_get_contents($cacheFile), true) ?? [])
        : [];
    if ($notFoundCache) {
        echo "Not-found cache loaded: " . count($notFoundCache) . " books will be skipped.\n";
    }
}

function saveCache(string $cacheFile, array $cache): void
{
    file_put_contents($cacheFile, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Wrapper used inside the loop — writes immediately so interruptions don't lose progress
function cacheNotFound(string $cacheFile, array &$cache, string $bookId, string $reason): void
{
    $cache[$bookId] = $reason;
    saveCache($cacheFile, $cache);
}

// ── Ensure the column exists ──────────────────────────────────────────────────
$colId      = ensureSingleValueColumn($pdo, 'wiki_book', 'Wikipedia (Book)');
$valueTable = "custom_column_{$colId}";
$linkTable  = "books_custom_column_{$colId}_link";

// ── Build query for books to process ─────────────────────────────────────────
if ($refetch) {
    $sql = "
        SELECT b.id, b.title, GROUP_CONCAT(a.name, ' ') AS authors
        FROM books b
        LEFT JOIN books_authors_link bal ON bal.book = b.id
        LEFT JOIN authors a ON a.id = bal.author
        GROUP BY b.id
        ORDER BY b.title
    ";
} else {
    $sql = "
        SELECT b.id, b.title, GROUP_CONCAT(a.name, ' ') AS authors
        FROM books b
        LEFT JOIN books_authors_link bal ON bal.book = b.id
        LEFT JOIN authors a ON a.id = bal.author
        WHERE b.id NOT IN (SELECT book FROM $linkTable)
        GROUP BY b.id
        ORDER BY b.title
    ";
}

$books = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// Filter out books already in the not-found cache
if (!$refetch && $notFoundCache) {
    $books = array_values(array_filter($books, fn($b) => !isset($notFoundCache[(string)$b['id']])));
}

$total = min(count($books), $limit);

echo "Books to process: $total" . ($dryRun ? " (DRY RUN)\n" : "\n") . "\n";

if ($total === 0) {
    echo "Nothing to do.\n";
    exit(0);
}

$ctx = stream_context_create(['http' => [
    'timeout' => 10,
    'header'  => "User-Agent: calibre-nilla/1.0 (personal book library); principle3@gmail.com\r\n",
]]);

function normaliseBulk(string $s): string
{
    return trim(preg_replace('/\s+/', ' ', strtolower(preg_replace('/[^a-z0-9\s]/i', '', $s))));
}

function titleMatches(string $pageTitle, string $bookTitle): bool
{
    $pt = normaliseBulk($pageTitle);
    $bt = normaliseBulk($bookTitle);
    if ($pt === $bt) return true;
    if (preg_match('/^' . preg_quote($bt, '/') . '(\s|\(|$)/u', $pt)) return true;
    $words = array_filter(preg_split('/\s+/', $bt), fn($w) => strlen($w) > 2);
    if (empty($words)) return false;
    foreach ($words as $w) {
        if (!preg_match('/\b' . preg_quote($w, '/') . '\b/u', $pt)) return false;
    }
    return true;
}

function authorInSummary(string $author, array $summary): bool
{
    $parts   = preg_split('/\s+/', trim($author));
    $surname = strtolower(end($parts));
    if (strlen($surname) < 3) return true;
    $text = strtolower(strip_tags(
        ($summary['description'] ?? '') . ' ' . ($summary['extract'] ?? '')
    ));
    return str_contains($text, $surname);
}

function looksLikePerson(array $summary): bool
{
    if (($summary['type'] ?? '') === 'disambiguation') return true;
    $desc = strtolower($summary['description'] ?? '');
    foreach (['author', 'novelist', 'writer', 'poet', 'playwright', 'screenwriter'] as $kw) {
        if (str_contains($desc, $kw)) return true;
    }
    return false;
}

/**
 * Fetch a URL and return ['body' => string] on success,
 * ['rate_limited' => true] on HTTP 429, or ['error' => true] on other failure.
 */
function wikiGet(string $url, $ctx): array
{
    global $http_response_header;
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) return ['error' => true];
    $status = 200;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
            $status = (int)$m[1];
        }
    }
    if ($status === 429) return ['rate_limited' => true];
    if ($status >= 400) return ['error' => true];
    return ['body' => $body];
}

function cleanWikiHtml(string $raw): string
{
    // Strip heading wrappers and edit-section links added by action=parse
    $raw = preg_replace('/<div[^>]*class="[^"]*mw-heading[^"]*"[^>]*>.*?<\/div>/s', '', $raw);
    $raw = preg_replace('/<span[^>]*class="[^"]*mw-editsection[^"]*"[^>]*>.*?<\/span>/s', '', $raw);
    // Strip inline citation superscripts [1], [2] …
    $raw = preg_replace('/<sup[^>]*class="[^"]*reference[^"]*"[^>]*>.*?<\/sup>/s', '', $raw);
    // Strip the references / sources list at the bottom
    $raw = preg_replace('/<div[^>]*class="[^"]*mw-references-wrap[^"]*"[^>]*>.*?<\/div>/s', '', $raw);
    $raw = preg_replace('/<div[^>]*class="[^"]*reflist[^"]*"[^>]*>.*?<\/div>/s', '', $raw);
    $raw = preg_replace('/<ol[^>]*class="[^"]*references[^"]*"[^>]*>.*?<\/ol>/s', '', $raw);
    // Strip Wikipedia maintenance banners (ambox: BLP sources, notability, etc.)
    $raw = preg_replace('/<table[^>]*class="[^"]*ambox[^"]*"[^>]*>.*?<\/table>/s', '', $raw);
    // Make wiki-relative links absolute
    $raw = str_replace('href="/wiki/', 'href="https://en.wikipedia.org/wiki/', $raw);
    return trim($raw);
}

function wikiSearchBulk(string $query, $ctx): array|string
{
    $url = 'https://en.wikipedia.org/w/api.php?' . http_build_query([
        'action'   => 'query',
        'list'     => 'search',
        'srsearch' => $query,
        'format'   => 'json',
        'srlimit'  => 5,
    ]);
    $res = wikiGet($url, $ctx);
    if (isset($res['rate_limited'])) return 'rate_limited';
    if (!isset($res['body']))        return [];
    return json_decode($res['body'], true)['query']['search'] ?? [];
}

$done        = 0;
$failed      = 0;
$notFound    = 0;
$rateLimited = false;

foreach (array_slice($books, 0, $limit) as $idx => $book) {
    $n      = $idx + 1;
    $bookId = (string)$book['id'];
    $title  = trim($book['title']);

    echo sprintf("[%d/%d] %s", $n, $total, $title);

    if ($dryRun) {
        echo " [dry run]\n";
        continue;
    }

    // Pass 1: exact quoted phrase; Pass 2: unquoted title only
    $results = wikiSearchBulk('"' . $title . '"', $ctx);
    if ($results === 'rate_limited') {
        echo " [RATE LIMITED — stop and retry later with a higher --delay]\n";
        $failed++;
        break;
    }
    if (empty($results)) {
        $results = wikiSearchBulk($title, $ctx);
        if ($results === 'rate_limited') {
            echo " [RATE LIMITED — stop and retry later with a higher --delay]\n";
            $failed++;
            break;
        }
    }

    if (empty($results)) {
        echo " [not found]\n";
        cacheNotFound($cacheFile, $notFoundCache, $bookId, 'not found');
        $notFound++;
        sleep($delay);
        continue;
    }

    $pageTitle = null;
    foreach ($results as $r) {
        if (titleMatches($r['title'], $title)) {
            $pageTitle = $r['title'];
            break;
        }
    }

    if ($pageTitle === null) {
        echo " [no title match]\n";
        cacheNotFound($cacheFile, $notFoundCache, $bookId, 'no title match');
        $notFound++;
        sleep($delay);
        continue;
    }

    // Summary
    $summaryUrl = 'https://en.wikipedia.org/api/rest_v1/page/summary/' . rawurlencode($pageTitle);
    $summaryRes = wikiGet($summaryUrl, $ctx);
    if (isset($summaryRes['rate_limited'])) {
        echo " [RATE LIMITED — stop and retry later with a higher --delay]\n";
        $failed++;
        break;
    }
    if (!isset($summaryRes['body'])) {
        echo " [ERROR: summary request failed]\n";
        $failed++;
        sleep($delay);
        continue;
    }
    $summary = json_decode($summaryRes['body'], true);
    if (empty($summary['extract'])) {
        echo " [no extract]\n";
        cacheNotFound($cacheFile, $notFoundCache, $bookId, 'no extract');
        $notFound++;
        sleep($delay);
        continue;
    }
    if (looksLikePerson($summary)) {
        echo " [person page — skipped]\n";
        cacheNotFound($cacheFile, $notFoundCache, $bookId, 'person page');
        $notFound++;
        sleep($delay);
        continue;
    }
    $author = trim($book['authors'] ?? '');
    if ($author !== '' && !authorInSummary($author, $summary)) {
        echo " [author not found in summary — skipped]\n";
        cacheNotFound($cacheFile, $notFoundCache, $bookId, 'author not in summary');
        $notFound++;
        sleep($delay);
        continue;
    }

    $plot         = null;
    $plotKeywords = ['plot', 'synopsis', 'plot summary', 'story'];

    $secListUrl = 'https://en.wikipedia.org/w/api.php?' . http_build_query([
        'action' => 'parse', 'page' => $pageTitle, 'prop' => 'sections', 'format' => 'json',
    ]);
    $secListRes = wikiGet($secListUrl, $ctx);
    if (isset($secListRes['rate_limited'])) {
        echo " [RATE LIMITED — stop and retry later with a higher --delay]\n";
        $failed++;
        break;
    }
    if (isset($secListRes['body'])) {
        $sectionIndex = null;
        foreach (json_decode($secListRes['body'], true)['parse']['sections'] ?? [] as $sec) {
            if (in_array(strtolower(trim($sec['line'] ?? '')), $plotKeywords)) {
                $sectionIndex = $sec['index'];
                break;
            }
        }
        if ($sectionIndex !== null) {
            $secHtmlUrl = 'https://en.wikipedia.org/w/api.php?' . http_build_query([
                'action' => 'parse', 'page' => $pageTitle, 'prop' => 'text',
                'section' => $sectionIndex, 'disablelimitreport' => '1', 'format' => 'json',
            ]);
            $secHtmlRes = wikiGet($secHtmlUrl, $ctx);
            if (isset($secHtmlRes['rate_limited'])) {
                echo " [RATE LIMITED — stop and retry later with a higher --delay]\n";
                $failed++;
                $rateLimited = true;
            } elseif (isset($secHtmlRes['body'])) {
                $raw  = json_decode($secHtmlRes['body'], true)['parse']['text']['*'] ?? '';
                $plot = cleanWikiHtml($raw);
            }
        }
    }

    if ($rateLimited) break;

    $data = [
        'title'   => $summary['title'] ?? $pageTitle,
        'url'     => $summary['content_urls']['desktop']['page']
                        ?? ('https://en.wikipedia.org/wiki/' . rawurlencode($pageTitle)),
        'extract' => $summary['extract_html']
                        ?? '<p>' . nl2br(htmlspecialchars($summary['extract'])) . '</p>',
        'plot'    => $plot,
    ];

    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    $pdo->prepare("INSERT OR IGNORE INTO $valueTable (value) VALUES (?)")->execute([$json]);
    $valId = $pdo->query("SELECT id FROM $valueTable WHERE value = " . $pdo->quote($json))->fetchColumn();
    $pdo->prepare("DELETE FROM $linkTable WHERE book = ?")->execute([$book['id']]);
    $pdo->prepare("INSERT INTO $linkTable (book, value) VALUES (?, ?)")->execute([$book['id'], $valId]);

    echo " → {$data['title']}\n";
    $done++;

    if ($n < $total) {
        sleep($delay);
    }
}

if (!$dryRun) {
    saveCache($cacheFile, $notFoundCache);
    echo "Cache saved: " . count($notFoundCache) . " not-found entries in $cacheFile\n";
}

echo "\nDone: $done  |  Not found: $notFound  |  Errors: $failed\n";
