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

    $pdo->prepare("INSERT OR IGNORE INTO $valueTable (value) VALUES ('Ebook Calibre')")->execute();
    $defaultId = $pdo->query("SELECT id FROM $valueTable WHERE value = 'Ebook Calibre'")->fetchColumn();
    $oldStmt = $pdo->prepare("SELECT id FROM $valueTable WHERE value = :old");
    $oldStmt->execute([':old' => $shelf]);
    $oldId = $oldStmt->fetchColumn();
    if ($oldId !== false) {
        $pdo->prepare("UPDATE $linkTable SET value = :def WHERE value = :oldId")
            ->execute([':def' => $defaultId, ':oldId' => $oldId]);
        $pdo->prepare("DELETE FROM $valueTable WHERE id = :oldId")
            ->execute([':oldId' => $oldId]);
    }
    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
