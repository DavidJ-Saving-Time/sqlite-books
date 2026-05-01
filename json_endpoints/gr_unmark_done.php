<?php
/**
 * Remove a single book ID from the Goodreads metadata and shelves progress files
 * so it gets re-fetched on the next import run.
 *
 * POST book_id=N
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

$bookId = (int)($_POST['book_id'] ?? 0);
if ($bookId <= 0) {
    echo json_encode(['error' => 'Invalid book ID']);
    exit;
}

$dir = __DIR__ . '/../data';
$files = [
    $dir . '/scrape_gr_progress.json',
    $dir . '/shelves_progress.json',
];

$unmarked = [];
foreach ($files as $path) {
    if (!file_exists($path)) continue;
    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data) || !isset($data['done_ids'])) continue;

    $before = count($data['done_ids']);
    $data['done_ids'] = array_values(array_filter($data['done_ids'], fn($id) => (int)$id !== $bookId));
    if (count($data['done_ids']) < $before) {
        file_put_contents($path, json_encode($data));
        $unmarked[] = basename($path);
    }
}

echo json_encode(['ok' => true, 'unmarked_from' => $unmarked]);
