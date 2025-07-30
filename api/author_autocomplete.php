<?php
require_once __DIR__.'/../lib/db.php';
requireLogin();
header('Content-Type: application/json');

$term = isset($_GET['term']) ? trim((string)$_GET['term']) : '';
if ($term === '') {
    echo json_encode([]);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    $stmt = $pdo->prepare('SELECT name FROM authors WHERE name LIKE :term || "%" ORDER BY name LIMIT 10');
    $stmt->execute([':term' => $term]);
    $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode($names);
} catch (PDOException $e) {
    echo json_encode([]);
}

