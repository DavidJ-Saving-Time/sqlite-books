<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../cache.php';
requireLogin();

$status = trim($_POST['status'] ?? '');
if ($status === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid status']);
    exit;
}

$pdo = getDatabaseConnection();
try {
    $statusId = ensureMultiValueColumn($pdo, '#status', 'Status');
    $valueTable = "custom_column_{$statusId}";
    $linkTable = "books_custom_column_{$statusId}_link";

    $valStmt = $pdo->prepare("SELECT id FROM $valueTable WHERE value = :val");
    $valStmt->execute([':val' => $status]);
    $sid = $valStmt->fetchColumn();
    if ($sid !== false) {
        $pdo->prepare("DELETE FROM $linkTable WHERE value = :id")->execute([':id' => $sid]);
        $pdo->prepare("DELETE FROM $valueTable WHERE id = :id")->execute([':id' => $sid]);
    }
    invalidateCache('statuses');
    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
