<?php
/**
 * Look up a single work from the local Open Library PostgreSQL mirror.
 *
 * GET ?olid=OL12345W
 *
 * Returns the same {"books":[...]} shape as openlibrary_search.php so the
 * existing modal rendering logic works unchanged.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

$olid = preg_replace('/[^A-Za-z0-9]/', '', trim($_GET['olid'] ?? ''));
if ($olid === '') {
    echo json_encode(['books' => [], 'error' => 'olid required']);
    exit;
}

$workKey = '/works/' . $olid;

try {
    $pgPdo = new PDO('pgsql:host=/run/postgresql;dbname=openlibrary;user=postgres');
    $pgPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    echo json_encode(['books' => [], 'error' => 'DB unavailable']);
    exit;
}

// ── Work ─────────────────────────────────────────────────────────────────────

$stmt = $pgPdo->prepare("SELECT data FROM works WHERE key = ?");
$stmt->execute([$workKey]);
$workRow = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$workRow) {
    echo json_encode(['books' => []]);
    exit;
}

$workData = json_decode($workRow['data'], true) ?: [];

$description = '';
if (!empty($workData['description'])) {
    $description = is_array($workData['description'])
        ? ($workData['description']['value'] ?? '')
        : (string)$workData['description'];
}

$cover = '';
foreach ((array)($workData['covers'] ?? []) as $cid) {
    if ((int)$cid > 0) {
        $cover = "https://covers.openlibrary.org/b/id/{$cid}-L.jpg";
        break;
    }
}

$subjects = array_slice((array)($workData['subjects'] ?? []), 0, 20);

// ── Authors ───────────────────────────────────────────────────────────────────

$aStmt = $pgPdo->prepare("
    SELECT a.data->>'name' AS name
    FROM author_works aw
    JOIN authors a ON a.key = aw.author_key
    WHERE aw.work_key = ?
    LIMIT 5
");
$aStmt->execute([$workKey]);
$authorNames = array_column($aStmt->fetchAll(PDO::FETCH_ASSOC), 'name');

// ── Publish date from work metadata (editions table has no work-key index) ────

$isbn = $publisher = $year = '';
if (!empty($workData['first_publish_date'])) {
    if (preg_match('/(\d{4})/', $workData['first_publish_date'], $m)) $year = $m[1];
}

echo json_encode(['books' => [[
    'title'       => $workData['title'] ?? '',
    'authors'     => implode(', ', $authorNames),
    'year'        => $year,
    'description' => $description,
    'subjects'    => $subjects,
    'cover'       => $cover,
    'key'         => $workKey,
    'source_id'   => 'openlibrary_local',
    'source_link' => 'https://openlibrary.org' . $workKey,
    'isbn'        => $isbn,
    'publisher'   => $publisher,
    'series'      => '',
]]]);
