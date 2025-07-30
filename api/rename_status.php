<?php
header('Content-Type: application/json');
require_once __DIR__.'/../lib/db.php';
requireLogin();

$old = trim($_POST['status'] ?? '');
$new = trim($_POST['new'] ?? '');
if ($old === '' || $new === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid status']);
    exit;
}

$pdo = getDatabaseConnection();
try {
    $statusId = ensureMultiValueColumn($pdo, '#status', 'Status');
    $valueTable = "custom_column_{$statusId}";
    $pdo->prepare("UPDATE $valueTable SET value = :new WHERE value = :old")
        ->execute([':new' => $new, ':old' => $old]);
    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
