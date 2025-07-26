<?php
header('Content-Type: application/json');
require_once 'db.php';
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
    $linkTable = "books_custom_column_{$statusId}_link";
    $pdo->prepare("DELETE FROM $linkTable WHERE value = :val")->execute([':val' => $status]);
    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
