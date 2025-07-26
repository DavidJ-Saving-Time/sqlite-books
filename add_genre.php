<?php
header('Content-Type: application/json');
require_once 'db.php';
requireLogin();

$genre = trim($_POST['genre'] ?? '');
if ($genre === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid genre']);
    exit;
}

$pdo = getDatabaseConnection();
try {
    [, $valueTable, ] = ensureMultivalueColumn($pdo, 'genre');
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO $valueTable (value) VALUES (:val)");
    $stmt->execute([':val' => $genre]);
    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
