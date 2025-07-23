<?php
header('Content-Type: application/json');

require_once 'book_recommend.php';

$authors = $_GET['authors'] ?? '';
$title = $_GET['title'] ?? '';

if ($authors === '' && $title === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

$userInput = trim($authors . ' ' . $title);

try {
    $output = get_book_recommendations($userInput);
    echo json_encode(['output' => $output]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
