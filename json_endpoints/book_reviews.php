<?php
require_once __DIR__ . '/../db.php';
requireLogin();
header('Content-Type: application/json');

$bookId = (int)($_GET['book_id'] ?? 0);
if (!$bookId) { echo json_encode(['error' => 'Missing book_id']); exit; }

$pdo = getDatabaseConnection();

// Check table exists
$exists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='book_reviews'")->fetchColumn();
if (!$exists) {
    echo json_encode(['reviews' => []]);
    exit;
}

$stmt = $pdo->prepare('SELECT reviewer, reviewer_url, rating, review_date, like_count, text
    FROM book_reviews
    WHERE book = ? AND (spoiler = 0 OR spoiler IS NULL)
    ORDER BY like_count DESC
    LIMIT 5');
$stmt->execute([$bookId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['reviews' => $rows]);
