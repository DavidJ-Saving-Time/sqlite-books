<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../cache.php';
requireLogin();

$genre = trim($_POST['genre'] ?? '');
if ($genre === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid genre']);
    exit;
}

$pdo = getDatabaseConnection();
try {
    $genreId = ensureMultiValueColumn($pdo, '#genre', 'Genre');
    $valueTable = "custom_column_{$genreId}";
    $pdo->prepare("INSERT OR IGNORE INTO $valueTable (value) VALUES (:val)")
        ->execute([':val' => $genre]);
    invalidateCache('genres');
    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
