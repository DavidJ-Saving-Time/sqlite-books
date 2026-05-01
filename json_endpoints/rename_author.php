<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

$authorId = (int)($_POST['author_id'] ?? 0);
$newName  = trim($_POST['name'] ?? '');

if ($authorId <= 0 || $newName === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

$pdo = getDatabaseConnection();

try {
    // Check the name isn't already taken by a different author
    $existing = $pdo->prepare('SELECT id FROM authors WHERE LOWER(name) = LOWER(?) AND id != ?');
    $existing->execute([$newName, $authorId]);
    if ($existing->fetch()) {
        echo json_encode(['error' => 'An author with that name already exists']);
        exit;
    }

    // Compute sort value using the same registered SQLite function
    $sortVal = $pdo->query("SELECT author_sort(" . $pdo->quote($newName) . ")")->fetchColumn();

    $stmt = $pdo->prepare('UPDATE authors SET name = ?, sort = ? WHERE id = ?');
    $stmt->execute([$newName, $sortVal, $authorId]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(['error' => 'Author not found']);
        exit;
    }

    echo json_encode(['status' => 'ok', 'name' => $newName, 'sort' => $sortVal]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
