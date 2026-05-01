<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

$toRemove = array_flip(array_filter(array_map('trim', (array)($_POST['lines'] ?? [])), 'strlen'));

if (empty($toRemove)) {
    echo json_encode(['error' => 'No lines provided']);
    exit;
}

$inputFile = __DIR__ . '/../data/similar-author-books-results.txt';

$all  = file_exists($inputFile)
    ? array_filter(array_map('trim', file($inputFile, FILE_IGNORE_NEW_LINES)), 'strlen')
    : [];

$kept = array_values(array_filter($all, fn($l) => !isset($toRemove[$l])));

file_put_contents($inputFile, $kept ? implode("\n", $kept) . "\n" : '');

echo json_encode(['ok' => true, 'removed' => count($toRemove), 'remaining' => count($kept)]);
