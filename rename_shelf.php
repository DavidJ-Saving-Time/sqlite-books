<?php
header('Content-Type: application/json');
require_once 'db.php';
requireLogin();

$old = trim($_POST['shelf'] ?? '');
$new = trim($_POST['new'] ?? '');
if ($old === '' || $new === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid shelf']);
    exit;
}

$pdo = getDatabaseConnection();
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS shelves (name TEXT PRIMARY KEY)");
    $pdo->beginTransaction();
    $pdo->prepare('INSERT OR IGNORE INTO shelves (name) VALUES (:new)')->execute([':new' => $new]);
    $shelfId = ensureSingleValueColumn($pdo, '#shelf', 'Shelf');
    $valueTable = "custom_column_{$shelfId}";
    $linkTable  = "books_custom_column_{$shelfId}_link";

    $pdo->prepare("INSERT OR IGNORE INTO $valueTable (value) VALUES (:new)")->execute([':new' => $new]);
    $stmtOld = $pdo->prepare("SELECT id FROM $valueTable WHERE value = :old");
    $stmtOld->execute([':old' => $old]);
    $oldId = $stmtOld->fetchColumn();
    $stmtNew = $pdo->prepare("SELECT id FROM $valueTable WHERE value = :new");
    $stmtNew->execute([':new' => $new]);
    $newId = $stmtNew->fetchColumn();
    if ($oldId !== false && $newId !== false) {
        $pdo->prepare("UPDATE $linkTable SET value = :newId WHERE value = :oldId")
            ->execute([':newId' => $newId, ':oldId' => $oldId]);
        $pdo->prepare("DELETE FROM $valueTable WHERE id = :oldId")
            ->execute([':oldId' => $oldId]);
    }
    $pdo->prepare('DELETE FROM shelves WHERE name = :old')->execute([':old' => $old]);
    $pdo->commit();
    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
