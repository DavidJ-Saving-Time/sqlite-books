<?php
/**
 * Search Goodreads for books matching a title + author query.
 *
 * GET ?book_id=N   — build query from book title + author in the DB;
 *                    also tries ISBN search first when an ISBN is available
 * GET ?q=term      — use this search string directly (no ISBN attempt)
 *
 * Returns JSON: { results: [{id, title, author, rating, rating_count, pub_year, isbn_match}],
 *                 query, isbn_query }
 *           or  { error: "..." }
 */
require_once __DIR__ . '/../db.php';
requireLogin();

header('Content-Type: application/json');

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Perform one curl GET and return [html|false, http_status, error_string, effective_url].
 */
function gr_curl(string $url): array {
    $ch = curl_init($url);
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
    $html        = curl_exec($ch);
    $status      = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err         = curl_error($ch);
    $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    return [$html, $status, $err, $effectiveUrl];
}

const AUDIOBOOK_WORDS = [
    'unabridged', 'abridged', 'audiobook', 'audio cd', 'audio book',
    'mp3 cd', 'audible', 'narrated by',
];

function is_audiobook(string $title): bool {
    $lower = strtolower($title);
    foreach (AUDIOBOOK_WORDS as $w) {
        if (str_contains($lower, $w)) return true;
    }
    return false;
}

/**
 * Parse Goodreads search-results HTML into an array of result rows.
 * Each row: [id, title, author, rating, rating_count, pub_year]
 */
function parse_gr_results(string $html, int $limit = 15, bool $isbnMatch = false): array {
    $dom = new DOMDocument();
    @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($dom);

    $results = [];
    $rows = $xpath->query('//tr[@itemtype="http://schema.org/Book"]');

    foreach ($rows as $row) {
        // Numeric Goodreads ID
        $anchorDiv = $xpath->query('.//div[contains(@class,"u-anchorTarget")]', $row)->item(0);
        $grId = $anchorDiv ? trim($anchorDiv->getAttribute('id')) : null;
        if (!$grId || !ctype_digit($grId)) continue;

        // Title
        $titleLink = $xpath->query('.//a[@class="bookTitle"]', $row)->item(0);
        if (!$titleLink) {
            $titleLink = $xpath->query('.//a[contains(@href,"/book/show/")][@title]', $row)->item(0);
        }
        $title = $titleLink ? trim($titleLink->getAttribute('title') ?: $titleLink->textContent) : '';
        if ($title === '') continue;
        if (is_audiobook($title)) continue;   // skip audiobook editions

        // Author
        $authorSpan = $xpath->query('.//a[contains(@class,"authorName")]//span[@itemprop="name"]', $row)->item(0);
        $author = $authorSpan ? trim($authorSpan->textContent) : '';

        // Rating + rating count  ("3.89 avg rating — 6,177 ratings")
        $rating = '';
        $ratingCount = '';
        $minirating = $xpath->query('.//*[contains(@class,"minirating")]', $row)->item(0);
        if ($minirating) {
            $mt = trim($minirating->textContent);
            if (preg_match('/([\d.]+)\s+avg rating\s*[—–\-]\s*([\d,]+)\s+rating/', $mt, $m)) {
                $rating      = $m[1];
                $ratingCount = str_replace(',', '', $m[2]);
            }
        }

        // Publication year  ("published 2011")
        $pubYear = '';
        $grey = $xpath->query('.//*[contains(@class,"greyText") and contains(@class,"smallText")]', $row)->item(0);
        if ($grey) {
            if (preg_match('/\bpublished\s+(\d{4})\b/', $grey->textContent, $ym)) {
                $pubYear = $ym[1];
            }
        }

        $results[] = [
            'id'           => $grId,
            'title'        => $title,
            'author'       => $author,
            'rating'       => $rating,
            'rating_count' => $ratingCount,
            'pub_year'     => $pubYear,
            'isbn_match'   => $isbnMatch,
            'url'          => 'https://www.goodreads.com/book/show/' . $grId,
        ];

        if (count($results) >= $limit) break;
    }

    return $results;
}

// ── Resolve query ─────────────────────────────────────────────────────────────

$q          = '';
$isbnQuery  = '';

if (!empty($_GET['q'])) {
    $q = trim($_GET['q']);
} elseif (!empty($_GET['book_id'])) {
    $pdo  = getDatabaseConnection();

    $stmt = $pdo->prepare(
        "SELECT b.title, GROUP_CONCAT(a.name, ' ') AS authors
         FROM books b
         LEFT JOIN books_authors_link bal ON bal.book = b.id
         LEFT JOIN authors a ON a.id = bal.author
         WHERE b.id = ?
         GROUP BY b.id"
    );
    $stmt->execute([(int)$_GET['book_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $q = trim(($row['title'] ?? '') . ' ' . ($row['authors'] ?? ''));
    }

    // Prefer isbn13 over isbn; ignore NOT_FOUND placeholder values
    $isbnStmt = $pdo->prepare(
        "SELECT val FROM identifiers
         WHERE book = ? AND type IN ('isbn','isbn13') AND val NOT LIKE 'NOT%'
         ORDER BY CASE type WHEN 'isbn13' THEN 0 ELSE 1 END
         LIMIT 1"
    );
    $isbnStmt->execute([(int)$_GET['book_id']]);
    $isbnVal = $isbnStmt->fetchColumn();
    if ($isbnVal) {
        $isbnQuery = trim($isbnVal);
    }
}

if ($q === '' && $isbnQuery === '') {
    http_response_code(400);
    echo json_encode(['error' => 'No search query']);
    exit;
}

// ── ISBN search (first attempt) ───────────────────────────────────────────────

$isbnResults = [];

if ($isbnQuery !== '') {
    // /book/isbn/{ISBN} is a direct lookup endpoint — more reliable than search
    // for curl requests where the session-cookie redirect doesn't fire.
    // Fall back to the search URL if that returns nothing useful.
    foreach ([
        'https://www.goodreads.com/book/isbn/' . urlencode($isbnQuery),
        'https://www.goodreads.com/search?q='  . urlencode($isbnQuery),
    ] as $isbnUrl) {
        [$html, $status, , $effectiveUrl] = gr_curl($isbnUrl);
        if ($html === false || $status !== 200) continue;

        // Both endpoints redirect to /book/show/ID when they find a match
        if (preg_match('|goodreads\.com/book/show/(\d+)|', $effectiveUrl, $m)) {
            $grId = $m[1];

            // Extract title + author from the book page (__NEXT_DATA__ JSON)
            $title = $author = $rating = $ratingCount = $pubYear = '';
            if (preg_match('/<script[^>]+id="__NEXT_DATA__"[^>]*>(.+?)<\/script>/s', $html, $nd)) {
                $nd = json_decode($nd[1], true);
                $apollo = $nd['props']['pageProps']['apolloState'] ?? [];
                foreach ($apollo as $key => $node) {
                    if (str_starts_with($key, 'Book:') && isset($node['title'])) {
                        $title      = $node['title'] ?? '';
                        $rating     = isset($node['stats']['averageRating'])
                            ? (string)round((float)$node['stats']['averageRating'], 2) : '';
                        $ratingCount = (string)($node['stats']['ratingsCount'] ?? '');
                        break;
                    }
                }
                // Author via Work or Contributor nodes
                foreach ($apollo as $key => $node) {
                    if (str_starts_with($key, 'Contributor:') && isset($node['name'])) {
                        $author = $node['name'];
                        break;
                    }
                }
                // Publication year
                foreach ($apollo as $key => $node) {
                    if (str_starts_with($key, 'BookDetails:') && !empty($node['publicationTime'])) {
                        $pubYear = (string)date('Y', (int)($node['publicationTime'] / 1000));
                        break;
                    }
                }
            }

            // Fallback: scrape <title> tag if JSON parsing got nothing
            if ($title === '' && preg_match('|<title[^>]*>([^<]+)</title>|i', $html, $t)) {
                $title = preg_replace('/\s*[|\-].*$/u', '', trim($t[1]));
            }

            if ($title !== '') {
                $isbnResults[] = [
                    'id'           => $grId,
                    'title'        => $title,
                    'author'       => $author,
                    'rating'       => $rating,
                    'rating_count' => $ratingCount,
                    'pub_year'     => $pubYear,
                    'isbn_match'   => true,
                    'url'          => 'https://www.goodreads.com/book/show/' . $grId,
                ];
                break;  // found via redirect — no need to try second URL
            }
        } else {
            // Got a search results page — parse normally (audiobooks already filtered)
            $isbnResults = parse_gr_results($html, 3, true);
            if (!empty($isbnResults)) break;  // found results — stop trying
        }
    }
}

// ── Title+author fallback search ──────────────────────────────────────────────

$titleResults = [];

if ($q !== '') {
    [$html, $status, $err] = gr_curl('https://www.goodreads.com/search?q=' . urlencode($q));

    if ($html === false || $err !== '') {
        if (empty($isbnResults)) {
            http_response_code(502);
            echo json_encode(['error' => 'Fetch failed: ' . $err]);
            exit;
        }
    } elseif ($status !== 200) {
        if (empty($isbnResults)) {
            http_response_code(502);
            echo json_encode(['error' => "Goodreads returned HTTP $status"]);
            exit;
        }
    } else {
        $titleResults = parse_gr_results($html, 15, false);
    }
}

// ── Merge: ISBN results first, then title results (deduped by ID) ─────────────

$seen    = [];
$results = [];

foreach (array_merge($isbnResults, $titleResults) as $r) {
    if (isset($seen[$r['id']])) continue;
    $seen[$r['id']] = true;
    $results[] = $r;
}

echo json_encode([
    'results'     => $results,
    'query'       => $q,
    'isbn_query'  => $isbnQuery,
]);
