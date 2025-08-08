<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../cache.php';
requireLogin();

$authorId = isset($_POST['author_id']) ? (int)$_POST['author_id'] : 0;
$value = isset($_POST['value']) ? trim((string)$_POST['value']) : '';

if ($authorId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input']);
    exit;
}

$pdo = getDatabaseConnection();

try {
    $pdo->beginTransaction();
    $genreId = ensureMultiValueColumn($pdo, '#genre', 'Genre');
    $valueTable = "custom_column_{$genreId}";
    $linkTable = "books_custom_column_{$genreId}_link";

    $stmt = $pdo->prepare('SELECT book FROM books_authors_link WHERE author = :author');
    $stmt->execute([':author' => $authorId]);
    $bookIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if ($bookIds) {
        $delStmt = $pdo->prepare("DELETE FROM $linkTable WHERE book = :book");
        foreach ($bookIds as $bookId) {
            $delStmt->execute([':book' => $bookId]);
        }

        if ($value !== '') {
            $pdo->prepare("INSERT OR IGNORE INTO $valueTable (value) VALUES (:val)")
                ->execute([':val' => $value]);
            $valStmt = $pdo->prepare("SELECT id FROM $valueTable WHERE value = :val");
            $valStmt->execute([':val' => $value]);
            $valId = $valStmt->fetchColumn();
            $insStmt = $pdo->prepare("INSERT INTO $linkTable (book, value) VALUES (:book, :val)");
            foreach ($bookIds as $bookId) {
                $insStmt->execute([':book' => $bookId, ':val' => $valId]);
            }
        }
    }

    $pdo->commit();
    invalidateCache('genres');
    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
