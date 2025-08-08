<?php
/**
 * Updates the title of a book.
 *
 * Expects an HTTP POST request.
 *
 * POST Parameters:
 * - book_id: ID of the book.
 * - title: New title.
 *
 * Returns:
 * {"status":"ok"} on success
 * or {"error":"message"} on failure.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

$bookId = isset($_POST['book_id']) ? (int)$_POST['book_id'] : 0;
$title = trim($_POST['title'] ?? '');
if ($bookId <= 0 || $title === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input']);
    exit;
}

$pdo = getDatabaseConnection();
try {
    $stmt = $pdo->prepare('UPDATE books SET title = :title, sort = :sort, last_modified = CURRENT_TIMESTAMP WHERE id = :id');
    $stmt->execute([':title' => $title, ':sort' => $title, ':id' => $bookId]);
    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
