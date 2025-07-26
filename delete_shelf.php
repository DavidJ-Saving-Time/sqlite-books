<?php
header('Content-Type: application/json');
require_once 'db.php';
requireLogin();

$shelf = trim($_POST['shelf'] ?? '');
if ($shelf === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid shelf']);
    exit;
}

$pdo = getDatabaseConnection();
try {
    $stmt = $pdo->prepare('DELETE FROM shelves WHERE name = :name');
    $stmt->execute([':name' => $shelf]);
    $shelfId = ensureSingleValueColumn($pdo, '#shelf', 'Shelf');
    $table = "custom_column_{$shelfId}";
    $update = $pdo->prepare("UPDATE $table SET value = 'Ebook Calibre' WHERE value = :name");
    $update->execute([':name' => $shelf]);
    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
