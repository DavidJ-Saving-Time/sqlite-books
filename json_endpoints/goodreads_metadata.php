<?php
/**
 * Fetch series and description for a book from Goodreads.
 *
 * GET ?book_id=N        — look up Goodreads ID from identifiers table, then fetch
 * GET ?goodreads_id=X   — use this Goodreads ID directly
 *
 * Returns JSON:
 *   { title_complete, series, series_index, description }
 *   or { error: "..." }
 */
require_once __DIR__ . '/../db.php';
requireLogin();

header('Content-Type: application/json');

// ── Resolve Goodreads ID ──────────────────────────────────────────────────────

$goodreadsId = '';

if (!empty($_GET['goodreads_id'])) {
    $goodreadsId = trim($_GET['goodreads_id']);
} elseif (!empty($_GET['book_id'])) {
    $pdo = getDatabaseConnection();
    $stmt = $pdo->prepare("SELECT val FROM identifiers WHERE book = ? AND type = 'goodreads' LIMIT 1");
    $stmt->execute([(int)$_GET['book_id']]);
    $goodreadsId = (string)($stmt->fetchColumn() ?: '');
}

if ($goodreadsId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'No Goodreads ID found']);
    exit;
}

// ── Fetch Goodreads page ──────────────────────────────────────────────────────

$url = 'https://www.goodreads.com/book/show/' . urlencode($goodreadsId);

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

$html   = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err    = curl_error($ch);
curl_close($ch);

if ($html === false || $err !== '') {
    http_response_code(502);
    echo json_encode(['error' => 'Fetch failed: ' . $err]);
    exit;
}

if ($status !== 200) {
    http_response_code(502);
    echo json_encode(['error' => "Goodreads returned HTTP $status"]);
    exit;
}

// ── Extract __NEXT_DATA__ JSON ────────────────────────────────────────────────

if (!preg_match('/<script id="__NEXT_DATA__" type="application\/json">(.*?)<\/script>/s', $html, $m)) {
    http_response_code(502);
    echo json_encode(['error' => '__NEXT_DATA__ not found in Goodreads page']);
    exit;
}

$nextData = json_decode($m[1], true);
if (!$nextData) {
    http_response_code(502);
    echo json_encode(['error' => 'Failed to parse __NEXT_DATA__ JSON']);
    exit;
}

$apollo = $nextData['props']['pageProps']['apolloState'] ?? null;
if (!$apollo) {
    http_response_code(502);
    echo json_encode(['error' => 'apolloState not found in __NEXT_DATA__']);
    exit;
}

// ── Navigate Apollo state ─────────────────────────────────────────────────────

// ROOT_QUERY has a key like: getBookByLegacyId({"legacyId":"123456"})
$bookRef = null;
$rootQuery = $apollo['ROOT_QUERY'] ?? [];
foreach ($rootQuery as $key => $val) {
    if (str_starts_with($key, 'getBookByLegacyId(')) {
        $bookRef = $val['__ref'] ?? null;
        break;
    }
}

if (!$bookRef || !isset($apollo[$bookRef])) {
    http_response_code(502);
    echo json_encode(['error' => 'Book data not found in Apollo state']);
    exit;
}

$bookData = $apollo[$bookRef];

// Title
$titleComplete = $bookData['titleComplete'] ?? '';

// Description — key includes the GraphQL argument
$description = '';
foreach ($bookData as $k => $v) {
    if (str_starts_with($k, 'description(') && is_string($v)) {
        // Prefer stripped version; accept any
        if (str_contains($k, '"stripped":true')) {
            $description = $v;
            break;
        }
        $description = $v; // fallback, keep looking
    }
}
// If no stripped version found, fall back to the plain description key
if ($description === '' && isset($bookData['description']) && is_string($bookData['description'])) {
    $description = $bookData['description'];
}

// Series
$seriesName  = '';
$seriesIndex = '';

$bookSeriesArr = $bookData['bookSeries'] ?? [];
if (!empty($bookSeriesArr[0])) {
    $entry = $bookSeriesArr[0];
    $seriesIndex = (string)($entry['userPosition'] ?? '');

    $seriesRef = $entry['series']['__ref'] ?? null;
    if ($seriesRef && isset($apollo[$seriesRef])) {
        $seriesName = (string)($apollo[$seriesRef]['title'] ?? '');
    }
}

// ── Return ────────────────────────────────────────────────────────────────────

echo json_encode([
    'title_complete' => $titleComplete,
    'series'         => $seriesName,
    'series_index'   => $seriesIndex,
    'description'    => $description,
]);
