<?php
header('Content-Type: application/json');
require_once 'db.php';

$bookId = isset($_POST['book_id']) ? (int)$_POST['book_id'] : 0;
$value = trim($_POST['value'] ?? '');

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
    $table = 'books_custom_column_' . (int)$statusId;
    $pdo->exec("CREATE TABLE IF NOT EXISTS $table (book INTEGER PRIMARY KEY REFERENCES books(id) ON DELETE CASCADE, value TEXT)");

    if ($bookId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input']);
        exit;
    }
    $stmt = $pdo->prepare("REPLACE INTO $table (book, value) VALUES (:book, :value)");
    $stmt->execute([':book' => $bookId, ':value' => $value]);

    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
