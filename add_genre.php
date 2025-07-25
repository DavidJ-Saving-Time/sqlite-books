<?php
header('Content-Type: application/json');
require_once 'db.php';

$genre = trim($_POST['genre'] ?? '');
if ($genre === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid genre']);
    exit;
}

$pdo = getDatabaseConnection();
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS custom_column_2 (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT NOT NULL COLLATE NOCASE, link TEXT NOT NULL DEFAULT '', UNIQUE(value))");
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO custom_column_2 (value) VALUES (:val)');
    $stmt->execute([':val' => $genre]);
    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
