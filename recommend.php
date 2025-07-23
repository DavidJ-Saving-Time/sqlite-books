<?php
header('Content-Type: application/json');

$authors = $_GET['authors'] ?? '';
$title = $_GET['title'] ?? '';

if ($authors === '' && $title === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

$cmd = 'direnv exec ./python python3 '
    . escapeshellarg(__DIR__ . '/python/book_recommend.py') . ' '
    . escapeshellarg($authors) . ' ' . escapeshellarg($title);

$output = shell_exec($cmd);

if ($output === null) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to execute recommendation script']);
    exit;
}

echo json_encode(['output' => $output]);

