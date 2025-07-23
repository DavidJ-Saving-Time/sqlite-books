<?php
header('Content-Type: application/json');

require_once 'book_recommend.php';
require_once 'db.php';

$authors = $_GET['authors'] ?? '';
$title = $_GET['title'] ?? '';
$bookId = isset($_GET['book_id']) ? (int)$_GET['book_id'] : 0;

if ($authors === '' && $title === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

$userInput = trim($authors . ' ' . $title);

try {
    $output = get_book_recommendations($userInput);

    if ($bookId > 0) {
        $pdo = getDatabaseConnection();
        $stmt = $pdo->prepare('REPLACE INTO custom_column_10 (book, value) VALUES (:book, :value)');
        $stmt->execute([':book' => $bookId, ':value' => $output]);
    }

    echo json_encode(['output' => $output]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
