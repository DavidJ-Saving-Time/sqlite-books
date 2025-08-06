<?php
header('Content-Type: application/json');
require_once 'db.php';
require_once 'cache.php';
requireLogin();

$status = trim($_POST['status'] ?? '');
if ($status === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid status']);
    exit;
}

$pdo = getDatabaseConnection();
try {
    $stmt = $pdo->prepare("SELECT id FROM custom_columns WHERE label = 'status'");
    $stmt->execute();
    $statusId = $stmt->fetchColumn();
    if ($statusId === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Status column not found']);
        exit;
    }

    $valueTable = 'custom_column_' . (int)$statusId;
    $pdo->prepare("INSERT OR IGNORE INTO $valueTable (value) VALUES (:val)")
        ->execute([':val' => $status]);
    invalidateCache('statuses');
    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
