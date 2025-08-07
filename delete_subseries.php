<?php
header('Content-Type: application/json');
require_once 'db.php';
requireLogin();

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid subseries']);
    exit;
}

$pdo = getDatabaseConnection();
try {
    $subseriesColumnId = getCustomColumnId($pdo, 'subseries');
    if ($subseriesColumnId) {
        $valueTable = "custom_column_{$subseriesColumnId}";
        $linkTable  = "books_custom_column_{$subseriesColumnId}_link";
        $pdo->prepare("DELETE FROM $linkTable WHERE value = :id")->execute([':id' => $id]);
        $pdo->prepare("DELETE FROM $valueTable WHERE id = :id")->execute([':id' => $id]);
    } else {
        $pdo->prepare('DELETE FROM books_subseries_link WHERE subseries = :id')->execute([':id' => $id]);
        $pdo->prepare('DELETE FROM subseries WHERE id = :id')->execute([':id' => $id]);
    }
    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
