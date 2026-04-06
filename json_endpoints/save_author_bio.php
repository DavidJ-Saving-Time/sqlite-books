<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

$authorId = isset($_POST['author_id']) ? (int)$_POST['author_id'] : 0;
$bio      = trim($_POST['bio'] ?? '');

if ($authorId <= 0) {
    echo json_encode(['error' => 'Invalid author_id']);
    exit;
}

$pdo = getDatabaseConnection();

// Verify author exists
$exists = $pdo->prepare('SELECT 1 FROM authors WHERE id = ?');
$exists->execute([$authorId]);
if (!$exists->fetchColumn()) {
    echo json_encode(['error' => 'Author not found']);
    exit;
}

if ($bio === '') {
    $pdo->prepare('DELETE FROM author_identifiers WHERE author_id = ? AND type = "bio"')->execute([$authorId]);
} else {
    $pdo->prepare('INSERT OR REPLACE INTO author_identifiers (author_id, type, val) VALUES (?, "bio", ?)')->execute([$authorId, $bio]);
}

echo json_encode(['status' => 'ok']);
