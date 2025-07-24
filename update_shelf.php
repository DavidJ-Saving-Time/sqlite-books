<?php
header('Content-Type: application/json');
require_once 'db.php';

$bookId = isset($_POST['book_id']) ? (int)$_POST['book_id'] : 0;
$value = $_POST['value'] ?? '';

$allowed = ['Physical', 'Ebook Calibre', 'PDFs'];
if ($bookId <= 0 || !in_array($value, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input']);
    exit;
}

$pdo = getDatabaseConnection();
$stmt = $pdo->prepare('REPLACE INTO books_custom_column_11 (book, value) VALUES (:book, :value)');
$stmt->execute([':book' => $bookId, ':value' => $value]);

echo json_encode(['status' => 'ok']);

