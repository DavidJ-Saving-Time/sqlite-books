<?php
header('Content-Type: application/json');
require_once 'db.php';

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid genre']);
    exit;
}

$pdo = getDatabaseConnection();
try {
    $stmt = $pdo->prepare('DELETE FROM custom_column_2 WHERE id = :id');
    $stmt->execute([':id' => $id]);
    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
