<?php
header('Content-Type: application/json');
require_once 'db.php';

$old = trim($_POST['status'] ?? '');
$new = trim($_POST['new'] ?? '');
if ($old === '' || $new === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid status']);
    exit;
}

$pdo = getDatabaseConnection();
try {
    $stmt = $pdo->prepare("SELECT id FROM custom_columns WHERE label = 'status'");
    $stmt->execute();
    $statusId = $stmt->fetchColumn();
    if ($statusId === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Status column not found']);
        exit;
    }
    $base = 'books_custom_column_' . (int)$statusId;
    $link = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . $base . "_link'")->fetchColumn();
    if ($link) {
        $valueTable = 'custom_column_' . (int)$statusId;
        $stmt = $pdo->prepare("SELECT id FROM $valueTable WHERE value = :val");
        $stmt->execute([':val' => $old]);
        $oldId = $stmt->fetchColumn();
        if ($oldId === false) {
            http_response_code(400);
            echo json_encode(['error' => 'Status not found']);
            exit;
        }
        $stmt = $pdo->prepare("SELECT id FROM $valueTable WHERE value = :val");
        $stmt->execute([':val' => $new]);
        $newId = $stmt->fetchColumn();
        if ($newId === false) {
            $pdo->prepare("UPDATE $valueTable SET value = :new WHERE id = :id")->execute([':new' => $new, ':id' => $oldId]);
        } else {
            $statusLinkTable = $base . '_link';
            $pdo->prepare("UPDATE $statusLinkTable SET value = :newid WHERE value = :oldid")
                ->execute([':newid' => $newId, ':oldid' => $oldId]);
            $pdo->prepare("DELETE FROM $valueTable WHERE id = :oldid")->execute([':oldid' => $oldId]);
        }
    } else {
        $table = $base;
        $pdo->exec("CREATE TABLE IF NOT EXISTS $table (book INTEGER PRIMARY KEY REFERENCES books(id) ON DELETE CASCADE, value TEXT)");
        $pdo->prepare("UPDATE $table SET value = :new WHERE value = :old")
            ->execute([':new' => $new, ':old' => $old]);
    }
    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
