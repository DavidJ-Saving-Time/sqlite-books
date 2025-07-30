<?php
header('Content-Type: application/json');
require_once __DIR__.'/../lib/db.php';
requireLogin();

$bookId = isset($_POST['book_id']) ? (int)$_POST['book_id'] : 0;
$value = isset($_POST['value']) ? (int)$_POST['value'] : 0;

if ($bookId <= 0 || $value < 0 || $value > 5) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input']);
    exit;
}

$pdo = getDatabaseConnection();

try {
    $pdo->prepare('DELETE FROM books_ratings_link WHERE book = ?')->execute([$bookId]);
    if ($value > 0) {
        $ratingVal = $value * 2;
        $pdo->prepare('INSERT OR IGNORE INTO ratings (rating) VALUES (?)')->execute([$ratingVal]);
        $stmt = $pdo->prepare('SELECT id FROM ratings WHERE rating = ?');
        $stmt->execute([$ratingVal]);
        $ratingId = $stmt->fetchColumn();
        $pdo->prepare('INSERT INTO books_ratings_link (book, rating) VALUES (?, ?)')->execute([$bookId, $ratingId]);
    }
    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
