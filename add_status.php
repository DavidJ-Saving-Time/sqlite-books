<?php
header('Content-Type: application/json');
require_once 'db.php';
requireLogin();

$status = trim($_POST['status'] ?? '');
if ($status === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid status']);
    exit;
}

$pdo = getDatabaseConnection();
try {
    $stmt = $pdo->prepare("SELECT id FROM custom_columns WHERE label = '#status'");
    $stmt->execute();
    $statusId = $stmt->fetchColumn();
    if ($statusId === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Status column not found']);
        exit;
    }
    $base = 'custom_column_' . (int)$statusId;
    $link = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . $base . "_link'")->fetchColumn();
    if ($link) {
        $valueTable = 'custom_column_' . (int)$statusId;
        $pdo->prepare("INSERT OR IGNORE INTO $valueTable (value) VALUES (:val)")->execute([':val' => $status]);
    } else {
        // For non-enumerated columns just insert into the main table for filtering
        $valueTable = $base;
        $pdo->exec("CREATE TABLE IF NOT EXISTS $valueTable (book INTEGER PRIMARY KEY REFERENCES books(id) ON DELETE CASCADE, value TEXT)");
        $pdo->prepare("INSERT OR IGNORE INTO $valueTable (book, value) SELECT id, :val FROM books WHERE 0")->execute([':val' => $status]);
    }
    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
