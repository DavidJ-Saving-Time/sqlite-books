<?php
header('Content-Type: application/json');
require_once 'db.php';

$status = trim($_POST['status'] ?? '');
if ($status === '') {
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
        $stmt->execute([':val' => $status]);
        $valId = $stmt->fetchColumn();
        if ($valId !== false) {
            // ensure default exists
            $pdo->prepare("INSERT OR IGNORE INTO $valueTable (value) VALUES ('Want to Read')")->execute();
            $defId = $pdo->query("SELECT id FROM $valueTable WHERE value = 'Want to Read'")->fetchColumn();
            $statusLinkTable = $base . '_link';
            $pdo->prepare("UPDATE $statusLinkTable SET value = :def WHERE value = :old")
                ->execute([':def' => $defId, ':old' => $valId]);
            $pdo->prepare("DELETE FROM $valueTable WHERE id = :id")->execute([':id' => $valId]);
        }
    }
    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
