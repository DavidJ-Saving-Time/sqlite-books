<?php
header('Content-Type: application/json');
require_once 'db.php';
requireLogin();

$bookId = isset($_POST['book_id']) ? (int)$_POST['book_id'] : 0;
$value = $_POST['value'] ?? '';

$pdo = getDatabaseConnection();
$pdo->exec("CREATE TABLE IF NOT EXISTS shelves (name TEXT PRIMARY KEY)");
$defaults = ['Physical', 'Ebook Calibre', 'PDFs'];
foreach ($defaults as $d) {
    $pdo->prepare('INSERT OR IGNORE INTO shelves (name) VALUES (?)')->execute([$d]);
}
$allowed = $pdo->query('SELECT name FROM shelves')->fetchAll(PDO::FETCH_COLUMN);

if ($bookId <= 0 || !in_array($value, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input']);
    exit;
}

$shelfId = ensureSingleValueColumn($pdo, '#shelf', 'Shelf');
$table = "custom_column_{$shelfId}";
$stmt = $pdo->prepare("REPLACE INTO $table (book, value) VALUES (:book, :value)");
$stmt->execute([':book' => $bookId, ':value' => $value]);

echo json_encode(['status' => 'ok']);
?>
