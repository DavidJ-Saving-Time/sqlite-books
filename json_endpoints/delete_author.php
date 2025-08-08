<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../cache.php';
requireLogin();

$authorId = isset($_POST['author_id']) ? (int)$_POST['author_id'] : 0;
if ($authorId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid author id']);
    exit;
}

$pdo = getDatabaseConnection();
try {
    $pdo->beginTransaction();

    $booksStmt = $pdo->prepare('SELECT book FROM books_authors_link WHERE author = :id');
    $booksStmt->execute([':id' => $authorId]);
    $bookIds = $booksStmt->fetchAll(PDO::FETCH_COLUMN);

    $authorIds = [$authorId];
    if ($bookIds) {
        $placeholders = implode(',', array_fill(0, count($bookIds), '?'));
        $aStmt = $pdo->prepare("SELECT DISTINCT author FROM books_authors_link WHERE book IN ($placeholders)");
        $aStmt->execute($bookIds);
        $authorIds = $aStmt->fetchAll(PDO::FETCH_COLUMN);
    }

    $tablesStmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name GLOB 'books_custom_column_*_link'");
    $linkTables = $tablesStmt ? $tablesStmt->fetchAll(PDO::FETCH_COLUMN) : [];
    $delBook = $pdo->prepare('DELETE FROM books WHERE id = :id');

    foreach ($bookIds as $bookId) {
        foreach ($linkTables as $table) {
            $pdo->prepare("DELETE FROM $table WHERE book = :id")->execute([':id' => $bookId]);
        }
        $delBook->execute([':id' => $bookId]);
    }

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
