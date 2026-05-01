<?php
/**
 * Returns stored wiki_book data for a book without hitting Wikipedia.
 * GET ?book_id=N
 * Returns {"status":"ok","data":{...}} or {"not_found":true}.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

$bookId = isset($_GET['book_id']) ? (int)$_GET['book_id'] : 0;
if ($bookId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid book_id']);
    exit;
}

$pdo   = getDatabaseConnection();
$colId = getCustomColumnId($pdo, 'wiki_book');
if (!$colId) {
    echo json_encode(['not_found' => true]);
    exit;
}

$linkTable  = "books_custom_column_{$colId}_link";
$valueTable = "custom_column_{$colId}";

$stmt = $pdo->prepare(
    "SELECT cv.value FROM $linkTable cl JOIN $valueTable cv ON cl.value = cv.id WHERE cl.book = ?"
);
$stmt->execute([$bookId]);
$json = $stmt->fetchColumn();

if (!$json) {
    echo json_encode(['not_found' => true]);
    exit;
}

$data = json_decode($json, true);
if (!$data) {
    echo json_encode(['not_found' => true]);
    exit;
}

echo json_encode(['status' => 'ok', 'data' => $data]);
