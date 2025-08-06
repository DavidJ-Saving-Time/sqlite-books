<?php
header('Content-Type: application/json');
require_once 'db.php';
require_once 'cache.php';
requireLogin();

$bookId = isset($_POST['book_id']) ? (int)$_POST['book_id'] : 0;
if ($bookId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid book id']);
    exit;
}

$pdo = getDatabaseConnection();
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT author FROM books_authors_link WHERE book = :id');
    $stmt->execute([':id' => $bookId]);
    $authorIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $statusId = ensureMultiValueColumn($pdo, '#status', 'Status');
    $linkTable = "books_custom_column_{$statusId}_link";
    $delStatus = $pdo->prepare("DELETE FROM $linkTable WHERE book = :id");
    $delStatus->execute([':id' => $bookId]);

    $delBook = $pdo->prepare('DELETE FROM books WHERE id = :id');
    $delBook->execute([':id' => $bookId]);

    $check = $pdo->prepare('SELECT COUNT(*) FROM books_authors_link WHERE author = ?');
    $delAuthor = $pdo->prepare('DELETE FROM authors WHERE id = ?');
    foreach ($authorIds as $aid) {
        $check->execute([$aid]);
        if ((int)$check->fetchColumn() === 0) {
            $delAuthor->execute([$aid]);
        }
    }

    $pdo->commit();
    invalidateCache('total_books');
    invalidateCache('shelves');
    invalidateCache('statuses');
    invalidateCache('genres');
    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
