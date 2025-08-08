<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid series']);
    exit;
}

$pdo = getDatabaseConnection();
try {
    $pdo->prepare('DELETE FROM books_series_link WHERE series = :id')->execute([':id' => $id]);
    $pdo->prepare('DELETE FROM series WHERE id = :id')->execute([':id' => $id]);
    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
