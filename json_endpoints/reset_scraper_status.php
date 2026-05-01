<?php
/**
 * Reset scraper status for one or more books so they are re-processed on the
 * next import run.
 *
 * POST book_ids[]=N&book_ids[]=M   (one or more IDs)
 *
 * Wipes ALL identifiers for the selected books so every scraper (GR and OL)
 * treats them as completely unseen, then removes them from all three GR
 * progress files (goodreads_progress.json, scrape_gr_progress.json,
 * shelves_progress.json).
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

$bookIds = array_map('intval', (array)($_POST['book_ids'] ?? []));
$bookIds = array_values(array_filter($bookIds, fn($id) => $id > 0));

if (empty($bookIds)) {
    echo json_encode(['error' => 'No valid book IDs']);
    exit;
}

$dir        = __DIR__ . '/../data';
$pdo        = getDatabaseConnection();
$bookIdsSql = implode(',', $bookIds); // safe — already cast to int above

// ── Wipe all identifiers ───────────────────────────────────────────────────────
$stmt = $pdo->exec("DELETE FROM identifiers WHERE book IN ($bookIdsSql)");
$identifiersDeleted = $pdo->query("SELECT changes()")->fetchColumn();

// ── Remove from all GR progress files ─────────────────────────────────────────
$idSet = array_flip($bookIds);

foreach ([
    $dir . '/goodreads_progress.json',
    $dir . '/scrape_gr_progress.json',
    $dir . '/shelves_progress.json',
] as $path) {
    if (!file_exists($path)) continue;
    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data) || !isset($data['done_ids'])) continue;

    $before = count($data['done_ids']);
    $data['done_ids'] = array_values(
        array_filter($data['done_ids'], fn($id) => !isset($idSet[(int)$id]))
    );
    if (count($data['done_ids']) < $before) {
        file_put_contents($path, json_encode($data));
    }
}

echo json_encode([
    'ok'                  => true,
    'books_reset'         => count($bookIds),
    'identifiers_deleted' => (int)$identifiersDeleted,
]);
