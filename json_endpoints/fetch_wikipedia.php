<?php
/**
 * Fetches a book's Wikipedia summary and saves it to the wiki_book custom column.
 *
 * POST book_id  — Calibre book ID
 *
 * Returns {"status":"ok","data":{"title","url","extract"}} on success,
 *         {"not_found":true,"error":"..."} when Wikipedia has no match,
 *      or {"error":"..."} on failure.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

$bookId = isset($_POST['book_id']) ? (int)$_POST['book_id'] : 0;
if ($bookId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid book_id']);
    exit;
}

$pdo = getDatabaseConnection();

// ── Fetch title + authors ─────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT b.title, GROUP_CONCAT(a.name, ' ') AS authors
    FROM books b
    LEFT JOIN books_authors_link bal ON bal.book = b.id
    LEFT JOIN authors a ON a.id = bal.author
    WHERE b.id = ?
    GROUP BY b.id
");
$stmt->execute([$bookId]);
$book = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$book) {
    http_response_code(404);
    echo json_encode(['error' => 'Book not found']);
    exit;
}

$title  = trim($book['title']);
$author = trim($book['authors'] ?? '');

$ctx = stream_context_create(['http' => [
    'timeout' => 10,
    'header'  => "User-Agent: calibre-nilla/1.0 (personal book library; principle3@gmail.com)\r\n",
]]);

/**
 * Returns true if the Wikipedia page title is a plausible match for the book title.
 * Normalises both strings and requires either:
 *   - exact match, OR
 *   - page title starts with book title (e.g. "Dune (novel)" → "Dune"), OR
 *   - every significant word (length > 2) from the book title appears in the page title.
 */
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

function normalise(string $s): string
{
    return trim(preg_replace('/\s+/', ' ', strtolower(preg_replace('/[^a-z0-9\s]/i', '', $s))));
}

/**
 * True if the Wikipedia page title is a plausible match for the book title.
 * Requires a proper word boundary — "Snakeheads" must NOT match "Snakehead".
 */
function titleMatches(string $pageTitle, string $bookTitle): bool
{
    $pt = normalise($pageTitle);
    $bt = normalise($bookTitle);
    if ($pt === $bt) return true;
    // Page title starts with book title then a word boundary (space, bracket, or end)
    if (preg_match('/^' . preg_quote($bt, '/') . '(\s|\(|$)/u', $pt)) return true;
    // All significant words (>2 chars) from book title must appear as whole words in page title
    $words = array_filter(preg_split('/\s+/', $bt), fn($w) => strlen($w) > 2);
    if (empty($words)) return false;
    foreach ($words as $w) {
        if (!preg_match('/\b' . preg_quote($w, '/') . '\b/u', $pt)) return false;
    }
    return true;
}

/**
 * True if the author's surname appears in the summary text.
 * This is the key check that catches non-book pages (e.g. gangs, places, concepts).
 */
function authorInSummary(string $author, array $summary): bool
{
    $parts   = preg_split('/\s+/', trim($author));
    $surname = strtolower(end($parts));
    if (strlen($surname) < 3) return true; // too short to be reliable, skip
    $text = strtolower(strip_tags(
        ($summary['description'] ?? '') . ' ' . ($summary['extract'] ?? '')
    ));
    return str_contains($text, $surname);
}

/**
 * True if the REST summary looks like a person/author page or disambiguation.
 */
function looksLikePerson(array $summary): bool
{
    if (($summary['type'] ?? '') === 'disambiguation') return true;
    $desc = strtolower($summary['description'] ?? '');
    foreach (['author', 'novelist', 'writer', 'poet', 'playwright', 'screenwriter'] as $kw) {
        if (str_contains($desc, $kw)) return true;
    }
    return false;
}

// ── Wikipedia search — title only, quoted for precision ──────────────────────
function wikiSearch(string $query, $ctx): array
{
    $url  = 'https://en.wikipedia.org/w/api.php?' . http_build_query([
        'action'   => 'query',
        'list'     => 'search',
        'srsearch' => $query,
        'format'   => 'json',
        'srlimit'  => 5,
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) return [];
    return json_decode($body, true)['query']['search'] ?? [];
}

// Pass 1: exact quoted phrase
$results = wikiSearch('"' . $title . '"', $ctx);

// Pass 2: unquoted title only (no author — adding author causes author pages to rank higher)
if (empty($results)) {
    $results = wikiSearch($title, $ctx);
}

if (empty($results)) {
    echo json_encode(['not_found' => true, 'error' => 'No Wikipedia results found for this book']);
    exit;
}

// Only accept results whose page title closely matches the book title
$pageTitle = null;
foreach ($results as $r) {
    if (titleMatches($r['title'], $title)) {
        $pageTitle = $r['title'];
        break;
    }
}

if ($pageTitle === null) {
    echo json_encode(['not_found' => true, 'error' => 'Wikipedia results did not match the book title closely enough']);
    exit;
}

// ── Wikipedia REST summary ────────────────────────────────────────────────────
$summaryUrl  = 'https://en.wikipedia.org/api/rest_v1/page/summary/' . rawurlencode($pageTitle);
$summaryBody = @file_get_contents($summaryUrl, false, $ctx);
if ($summaryBody === false) {
    http_response_code(502);
    echo json_encode(['error' => 'Wikipedia summary request failed']);
    exit;
}

$summary = json_decode($summaryBody, true);
if (empty($summary['extract'])) {
    echo json_encode(['not_found' => true, 'error' => 'Wikipedia page found but has no usable extract']);
    exit;
}
if (looksLikePerson($summary)) {
    echo json_encode(['not_found' => true, 'error' => 'Wikipedia result appears to be a person page, not a book page']);
    exit;
}
if ($author !== '' && !authorInSummary($author, $summary)) {
    echo json_encode(['not_found' => true, 'error' => 'Wikipedia result does not mention the author — likely the wrong page']);
    exit;
}

// ── Fetch Plot / Synopsis section via action=parse ───────────────────────────
$plot         = null;
$plotKeywords = ['plot', 'synopsis', 'plot summary', 'story'];

$secListUrl  = 'https://en.wikipedia.org/w/api.php?' . http_build_query([
    'action' => 'parse', 'page' => $pageTitle, 'prop' => 'sections', 'format' => 'json',
]);
$secListBody = @file_get_contents($secListUrl, false, $ctx);
if ($secListBody !== false) {
    $sectionIndex = null;
    foreach (json_decode($secListBody, true)['parse']['sections'] ?? [] as $sec) {
        if (in_array(strtolower(trim($sec['line'] ?? '')), $plotKeywords)) {
            $sectionIndex = $sec['index'];
            break;
        }
    }
    if ($sectionIndex !== null) {
        $secHtmlUrl  = 'https://en.wikipedia.org/w/api.php?' . http_build_query([
            'action' => 'parse', 'page' => $pageTitle, 'prop' => 'text',
            'section' => $sectionIndex, 'disablelimitreport' => '1', 'format' => 'json',
        ]);
        $secHtmlBody = @file_get_contents($secHtmlUrl, false, $ctx);
        if ($secHtmlBody !== false) {
            $raw  = json_decode($secHtmlBody, true)['parse']['text']['*'] ?? '';
            $plot = cleanWikiHtml($raw);
        }
    }
}

$data = [
    'title'   => $summary['title'] ?? $pageTitle,
    'url'     => $summary['content_urls']['desktop']['page']
                    ?? ('https://en.wikipedia.org/wiki/' . rawurlencode($pageTitle)),
    'extract' => $summary['extract_html']
                    ?? '<p>' . nl2br(htmlspecialchars($summary['extract'])) . '</p>',
    'plot'    => $plot,
];

// ── Persist to wiki_book custom column ───────────────────────────────────────
$colId      = ensureSingleValueColumn($pdo, 'wiki_book', 'Wikipedia (Book)');
$valueTable = "custom_column_{$colId}";
$linkTable  = "books_custom_column_{$colId}_link";
$json       = json_encode($data, JSON_UNESCAPED_UNICODE);

$pdo->prepare("INSERT OR IGNORE INTO $valueTable (value) VALUES (?)")->execute([$json]);
$valId = $pdo->query("SELECT id FROM $valueTable WHERE value = " . $pdo->quote($json))->fetchColumn();
$pdo->prepare("DELETE FROM $linkTable WHERE book = ?")->execute([$bookId]);
$pdo->prepare("INSERT INTO $linkTable (book, value) VALUES (?, ?)")->execute([$bookId, $valId]);

echo json_encode(['status' => 'ok', 'data' => $data]);
