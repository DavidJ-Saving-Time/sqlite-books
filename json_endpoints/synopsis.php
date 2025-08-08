<?php
header('Content-Type: application/json');

require_once __DIR__ . '/book_synopsis.php';
require_once __DIR__ . '/../db.php';
requireLogin();

$title = $_GET['title'] ?? '';
$authors = $_GET['authors'] ?? '';
$bookId = isset($_GET['book_id']) ? (int)$_GET['book_id'] : 0;

if ($title === '' || $bookId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

try {
    $output = get_book_synopsis($title, $authors);

    $pdo = getDatabaseConnection();
    $stmt = $pdo->prepare('INSERT INTO comments (book, text) VALUES (:book, :text) '
        . 'ON CONFLICT(book) DO UPDATE SET text=excluded.text');
    $stmt->execute([':book' => $bookId, ':text' => $output]);

    echo json_encode(['output' => $output]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
