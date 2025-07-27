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
    $valueTable = "custom_column_{$shelfId}";
    $linkTable  = "books_custom_column_{$shelfId}_link";

    $valStmt = $pdo->prepare("SELECT id FROM $valueTable WHERE value = :val");
    $valStmt->execute([':val' => $shelf]);
    $oldId = $valStmt->fetchColumn();
    if ($oldId !== false) {
        $pdo->prepare("INSERT OR IGNORE INTO $valueTable (value) VALUES ('Ebook Calibre')")->execute();
        $defId = $pdo->query("SELECT id FROM $valueTable WHERE value = 'Ebook Calibre'")->fetchColumn();
        $pdo->prepare("UPDATE $linkTable SET value = :def WHERE value = :old")->execute([':def' => $defId, ':old' => $oldId]);
        $pdo->prepare("DELETE FROM $valueTable WHERE id = :id")->execute([':id' => $oldId]);
    }
    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
