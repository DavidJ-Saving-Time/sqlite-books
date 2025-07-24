<?php
header('Content-Type: application/json');
require_once 'db.php';

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
    $update = $pdo->prepare("UPDATE books_custom_column_11 SET value = 'Ebook Calibre' WHERE value = :name");
    $update->execute([':name' => $shelf]);
    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
