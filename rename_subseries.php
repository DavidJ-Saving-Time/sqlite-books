<?php
header('Content-Type: application/json');
require_once 'db.php';
requireLogin();

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$new = trim($_POST['new'] ?? '');
if ($id <= 0 || $new === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid subseries']);
    exit;
}

$pdo = getDatabaseConnection();
try {
    $subseriesColumnId = getCustomColumnId($pdo, 'subseries');
    if ($subseriesColumnId) {
        $valueTable = "custom_column_{$subseriesColumnId}";
        $stmt = $pdo->prepare("UPDATE $valueTable SET value = :new WHERE id = :id");
        $stmt->execute([':new' => $new, ':id' => $id]);
    } else {
        $stmt = $pdo->prepare('UPDATE subseries SET name = :new, sort = :new WHERE id = :id');
        $stmt->execute([':new' => $new, ':id' => $id]);
    }
    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
