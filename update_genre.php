<?php
header('Content-Type: application/json');
require_once 'db.php';
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
    $pdo->exec("CREATE TABLE IF NOT EXISTS custom_column_2 (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT NOT NULL COLLATE NOCASE, link TEXT NOT NULL DEFAULT '', UNIQUE(value))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS books_custom_column_2_link (book INTEGER REFERENCES books(id) ON DELETE CASCADE, value INTEGER REFERENCES custom_column_2(id), PRIMARY KEY(book,value))");

    $pdo->prepare('DELETE FROM books_custom_column_2_link WHERE book = :book')->execute([':book' => $bookId]);

    if ($value !== '') {
        $genreId = (int)$value;
        $stmt = $pdo->prepare('SELECT id FROM custom_column_2 WHERE id = :id');
        $stmt->execute([':id' => $genreId]);
        if ($stmt->fetchColumn() === false) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid genre']);
            exit;
        }
        $stmt = $pdo->prepare('INSERT INTO books_custom_column_2_link (book, value) VALUES (:book, :value)');
        $stmt->execute([':book' => $bookId, ':value' => $genreId]);
    }

    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
