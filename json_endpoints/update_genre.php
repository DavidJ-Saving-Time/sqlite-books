<?php
/**
 * Sets the genre for a specific book.
 *
 * Expects an HTTP POST request.
 *
 * POST Parameters:
 * - book_id: ID of the book.
 * - value: Genre name to apply; empty to clear.
 *
 * Returns:
 * {"status":"ok"} on success
 * or {"error":"message"} on failure.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

$bookId = isset($_POST['book_id']) ? (int)$_POST['book_id'] : 0;
$value = isset($_POST['value']) ? trim((string)$_POST['value']) : '';

if ($bookId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input']);
    exit;
}

$pdo = getDatabaseConnection();

try {
    $genreId = ensureMultiValueColumn($pdo, '#genre', 'Genre');
    $valueTable = "custom_column_{$genreId}";
    $linkTable = "books_custom_column_{$genreId}_link";

    $pdo->prepare("DELETE FROM $linkTable WHERE book = :book")->execute([':book' => $bookId]);

    if ($value !== '') {
        $pdo->prepare("INSERT OR IGNORE INTO $valueTable (value) VALUES (:val)")
            ->execute([':val' => $value]);
        $valStmt = $pdo->prepare("SELECT id FROM $valueTable WHERE value = :val");
        $valStmt->execute([':val' => $value]);
        $valId = $valStmt->fetchColumn();
        $stmt = $pdo->prepare("INSERT INTO $linkTable (book, value) VALUES (:book, :val)");
        $stmt->execute([':book' => $bookId, ':val' => $valId]);
    }

    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
