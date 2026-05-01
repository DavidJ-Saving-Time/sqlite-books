<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

try {
    $pdo = getDatabaseConnection();
    $stmt = $pdo->prepare("DELETE FROM identifiers WHERE type = 'loc_checked'");
    $stmt->execute();
    echo json_encode(['success' => true, 'deleted' => $stmt->rowCount()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
