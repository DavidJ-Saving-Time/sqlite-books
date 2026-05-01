<?php
/**
 * Bulk-fetches Wikipedia summaries for authors and appends them to author_identifiers.
 * Stores two new identifier types: wiki_bio (HTML extract) and wiki_url (page URL).
 * These are displayed alongside the existing OpenLibrary bio in the author modal.
 *
 * Usage:
 *   php scripts/fetch_wikipedia_authors_bulk.php --db /path/to/metadata.db [options]
 *
 * Options:
 *   --db PATH      Path to Calibre metadata.db (required)
 *   --limit N      Max authors to process this run (default: all)
 *   --delay N      Seconds to wait between requests (default: 2)
 *   --refetch      Re-fetch even if wiki_bio already exists (also clears not-found cache)
 *   --dry-run      Show what would be fetched without saving
 *
 * Not-found cache: authors with no usable Wikipedia result are recorded in
 * wiki_author_cache.json (next to metadata.db) and skipped on future runs.
 * Use --refetch to clear the cache and retry everything.
 */

if (PHP_SAPI !== 'cli') exit("CLI only\n");

// ── Parse arguments ───────────────────────────────────────────────────────────
$dbPath  = null;
$limit   = PHP_INT_MAX;
$delay   = 2;
$refetch = false;
$dryRun  = false;

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
    fwrite(STDERR, "Usage: php scripts/fetch_wikipedia_authors_bulk.php --db /path/to/metadata.db [--limit N] [--delay N] [--refetch] [--dry-run]\n");
    exit(1);
}
if (!file_exists($dbPath)) {
    fwrite(STDERR, "Error: database not found: $dbPath\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');

// ── Not-found cache ───────────────────────────────────────────────────────────
$cacheFile = dirname(realpath($dbPath)) . '/wiki_author_cache.json';

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
        echo "Not-found cache loaded: " . count($notFoundCache) . " authors will be skipped.\n";
    }
}

function saveCache(string $cacheFile, array $cache): void
{
    file_put_contents($cacheFile, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function cacheNotFound(string $cacheFile, array &$cache, string $key, string $reason): void
{
    $cache[$key] = $reason;
    saveCache($cacheFile, $cache);
}

// ── Build author list ─────────────────────────────────────────────────────────
if ($refetch) {
    $skipClause = '';
} else {
    $skipClause = "AND a.id NOT IN (SELECT author_id FROM author_identifiers WHERE type = 'wiki_bio')";
}

$authors = $pdo->query("
    SELECT a.id, a.name
    FROM authors a
    JOIN books_authors_link bal ON bal.author = a.id
    $skipClause
    GROUP BY a.id
    ORDER BY a.sort COLLATE NOCASE
")->fetchAll(PDO::FETCH_ASSOC);

// Filter out authors already in the not-found cache
if (!$refetch && $notFoundCache) {
    $authors = array_values(array_filter($authors, fn($a) => !isset($notFoundCache[(string)$a['id']])));
}

$total = min(count($authors), $limit);
echo "Authors to process: $total" . ($dryRun ? " (DRY RUN)" : "") . "\n\n";

if ($total === 0) {
    echo "Nothing to do.\n";
    exit(0);
}

$ctx = stream_context_create(['http' => [
    'timeout' => 10,
    'header'  => "User-Agent: calibre-nilla/1.0 (personal book library); principle3@gmail.com\r\n",
]]);

// ── Helpers ───────────────────────────────────────────────────────────────────
function normaliseAuthor(string $s): string
{
    return trim(preg_replace('/\s+/', ' ', strtolower(preg_replace('/[^a-z0-9\s]/i', '', $s))));
}

/**
 * True if the Wikipedia page title is a plausible match for the author name.
 * Requires the author's surname (and ideally first name) to appear as a whole
 * word in the page title.
 */
function authorTitleMatches(string $pageTitle, string $authorName): bool
{
    $pt   = normaliseAuthor($pageTitle);
    $an   = normaliseAuthor($authorName);
    if ($pt === $an) return true;

    $parts   = array_filter(preg_split('/\s+/', $an), fn($w) => strlen($w) > 1);
    $surname = end($parts);

    // Surname must appear as a whole word in the page title
    if (!preg_match('/\b' . preg_quote($surname, '/') . '\b/u', $pt)) return false;

    // If the page title starts with the full name (e.g. "Anthony Horowitz (author)")
    if (preg_match('/^' . preg_quote($an, '/') . '(\s|\(|$)/u', $pt)) return true;

    // Require at least one other name part to also appear
    $otherParts = array_filter($parts, fn($w) => $w !== $surname && strlen($w) > 1);
    foreach ($otherParts as $w) {
        if (preg_match('/\b' . preg_quote($w, '/') . '\b/u', $pt)) return true;
    }

    return false;
}

/**
 * True if the REST summary looks like a person (author, novelist, writer, etc.).
 * For authors we WANT person pages — the opposite of the book fetch logic.
 */
function isPersonPage(array $summary): bool
{
    if (($summary['type'] ?? '') === 'disambiguation') return false;
    $desc = strtolower($summary['description'] ?? '');
    $personKeywords = [
        'author', 'novelist', 'writer', 'poet', 'playwright', 'screenwriter',
        'journalist', 'biographer', 'historian', 'critic', 'essayist', 'editor',
    ];
    foreach ($personKeywords as $kw) {
        if (str_contains($desc, $kw)) return true;
    }
    // Broader fallback: any person described with birth/death info
    if (preg_match('/\b(born|died|\d{4}–|\d{4}-)\b/', $summary['description'] ?? '')) return true;
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
    $raw = preg_replace('/<div[^>]*class="[^"]*mw-heading[^"]*"[^>]*>.*?<\/div>/s', '', $raw);
    $raw = preg_replace('/<span[^>]*class="[^"]*mw-editsection[^"]*"[^>]*>.*?<\/span>/s', '', $raw);
    $raw = preg_replace('/<sup[^>]*class="[^"]*reference[^"]*"[^>]*>.*?<\/sup>/s', '', $raw);
    $raw = preg_replace('/<div[^>]*class="[^"]*mw-references-wrap[^"]*"[^>]*>.*?<\/div>/s', '', $raw);
    $raw = preg_replace('/<div[^>]*class="[^"]*reflist[^"]*"[^>]*>.*?<\/div>/s', '', $raw);
    $raw = preg_replace('/<ol[^>]*class="[^"]*references[^"]*"[^>]*>.*?<\/ol>/s', '', $raw);
    // Strip Wikipedia maintenance banners (ambox: BLP sources, notability, etc.)
    $raw = preg_replace('/<table[^>]*class="[^"]*ambox[^"]*"[^>]*>.*?<\/table>/s', '', $raw);
    $raw = str_replace('href="/wiki/', 'href="https://en.wikipedia.org/wiki/', $raw);
    return trim($raw);
}

function wikiSearchAuthors(string $query, $ctx): array|string
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

// ── Upsert helper ─────────────────────────────────────────────────────────────
$upsert = $pdo->prepare(
    "INSERT OR REPLACE INTO author_identifiers (author_id, type, val) VALUES (:author_id, :type, :val)"
);

$done        = 0;
$failed      = 0;
$notFound    = 0;
$rateLimited = false;

foreach (array_slice($authors, 0, $limit) as $idx => $author) {
    $n        = $idx + 1;
    $authorId = (int)$author['id'];
    $cacheKey = (string)$authorId;
    $name     = trim($author['name']);

    echo sprintf("[%d/%d] %s", $n, $total, $name);

    if ($dryRun) {
        echo " [dry run]\n";
        continue;
    }

    // Pass 1: exact quoted name; Pass 2: unquoted
    $results = wikiSearchAuthors('"' . $name . '"', $ctx);
    if ($results === 'rate_limited') {
        echo " [RATE LIMITED — stop and retry later with a higher --delay]\n";
        $failed++;
        break;
    }
    if (empty($results)) {
        $results = wikiSearchAuthors($name, $ctx);
        if ($results === 'rate_limited') {
            echo " [RATE LIMITED — stop and retry later with a higher --delay]\n";
            $failed++;
            break;
        }
    }

    if (empty($results)) {
        echo " [not found]\n";
        cacheNotFound($cacheFile, $notFoundCache, $cacheKey, 'not found');
        $notFound++;
        sleep($delay);
        continue;
    }

    $pageTitle = null;
    foreach ($results as $r) {
        if (authorTitleMatches($r['title'], $name)) {
            $pageTitle = $r['title'];
            break;
        }
    }

    if ($pageTitle === null) {
        echo " [no title match]\n";
        cacheNotFound($cacheFile, $notFoundCache, $cacheKey, 'no title match');
        $notFound++;
        sleep($delay);
        continue;
    }

    // Fetch REST summary
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
        cacheNotFound($cacheFile, $notFoundCache, $cacheKey, 'no extract');
        $notFound++;
        sleep($delay);
        continue;
    }

    if (!isPersonPage($summary)) {
        echo " [not a person page — skipped]\n";
        cacheNotFound($cacheFile, $notFoundCache, $cacheKey, 'not a person page');
        $notFound++;
        sleep($delay);
        continue;
    }

    $extract = $summary['extract_html']
        ?? '<p>' . nl2br(htmlspecialchars($summary['extract'])) . '</p>';

    $url = $summary['content_urls']['desktop']['page']
        ?? ('https://en.wikipedia.org/wiki/' . rawurlencode($pageTitle));

    // Fetch life / education sections
    $lifeKeywords = [
        'biography', 'life', 'early life', 'personal life', 'education',
        'early life and education', 'life and education', 'childhood', 'background',
    ];
    $secListUrl = 'https://en.wikipedia.org/w/api.php?' . http_build_query([
        'action' => 'parse', 'page' => $pageTitle, 'prop' => 'sections', 'format' => 'json',
    ]);
    $secListRes = wikiGet($secListUrl, $ctx);
    if (isset($secListRes['rate_limited'])) {
        echo " [RATE LIMITED — stop and retry later with a higher --delay]\n";
        $failed++;
        break;
    }
    $lifeSections = '';
    if (isset($secListRes['body'])) {
        foreach (json_decode($secListRes['body'], true)['parse']['sections'] ?? [] as $sec) {
            // Only top-level sections (toclevel 1) to avoid grabbing subsections
            if ((int)($sec['toclevel'] ?? 0) !== 1) continue;
            $secLine = strtolower(strip_tags(trim($sec['line'] ?? '')));
            if (!in_array($secLine, $lifeKeywords)) continue;

            $secHtmlUrl = 'https://en.wikipedia.org/w/api.php?' . http_build_query([
                'action' => 'parse', 'page' => $pageTitle, 'prop' => 'text',
                'section' => $sec['index'], 'disablelimitreport' => '1', 'format' => 'json',
            ]);
            $secHtmlRes = wikiGet($secHtmlUrl, $ctx);
            if (isset($secHtmlRes['rate_limited'])) {
                echo " [RATE LIMITED — stop and retry later with a higher --delay]\n";
                $failed++;
                $rateLimited = true;
                break;
            }
            if (isset($secHtmlRes['body'])) {
                $raw          = json_decode($secHtmlRes['body'], true)['parse']['text']['*'] ?? '';
                $sectionTitle = htmlspecialchars(strip_tags($sec['line']));
                $lifeSections .= "<h6 class=\"mt-3 mb-2 fw-semibold border-top pt-3\">{$sectionTitle}</h6>" . cleanWikiHtml($raw);
            }
            sleep($delay);
        }
    }
    if ($rateLimited) break;

    $wikiBioVal = $extract . $lifeSections;

    $upsert->execute([':author_id' => $authorId, ':type' => 'wiki_bio', ':val' => $wikiBioVal]);
    $upsert->execute([':author_id' => $authorId, ':type' => 'wiki_url', ':val' => $url]);

    $sectionsFound = $lifeSections ? ' + life sections' : '';
    echo " → {$summary['title']}{$sectionsFound}\n";
    $done++;

    if ($n < $total) {
        sleep($delay);
    }
}

if (!$dryRun) {
    saveCache($cacheFile, $notFoundCache);
    echo "Cache saved: " . count($notFoundCache) . " not-found entries in $cacheFile\n";
}

echo "\nDone: $done  |  Not found / skipped: $notFound  |  Errors: $failed\n";
