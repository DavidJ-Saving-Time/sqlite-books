<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
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
    $table = "custom_column_{$shelfId}";
    $pdo->prepare("UPDATE $table SET value = :new WHERE value = :old")->execute([':new' => $new, ':old' => $old]);
    $pdo->prepare('DELETE FROM shelves WHERE name = :old')->execute([':old' => $old]);
    $pdo->commit();
    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
