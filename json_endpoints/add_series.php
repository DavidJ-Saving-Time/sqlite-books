<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

$name = trim($_POST['name'] ?? '');
if ($name === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid series']);
    exit;
}

$pdo = getDatabaseConnection();
try {
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO series (name, sort) VALUES (:name, :sort)');
    $stmt->execute([':name' => $name, ':sort' => $name]);
    $id = $pdo->query('SELECT id FROM series WHERE name = ' . $pdo->quote($name))->fetchColumn();
    echo json_encode(['status' => 'ok', 'id' => (int)$id]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
