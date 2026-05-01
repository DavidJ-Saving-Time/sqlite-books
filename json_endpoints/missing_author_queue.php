<?php
/**
 * Appends selected IRC bot lines to missing-books-results.txt.
 * POST lines[] = array of IRC lines to queue for sending.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

$lines = array_filter(array_map('trim', (array)($_POST['lines'] ?? [])), 'strlen');

if (empty($lines)) {
    echo json_encode(['error' => 'No lines provided']);
    exit;
}

$file    = __DIR__ . '/../data/missing-books-results.txt';
$content = implode("\n", $lines) . "\n";

if (file_put_contents($file, $content, FILE_APPEND) === false) {
    echo json_encode(['error' => 'Failed to write to queue file']);
    exit;
}

echo json_encode(['ok' => true, 'added' => count($lines)]);
