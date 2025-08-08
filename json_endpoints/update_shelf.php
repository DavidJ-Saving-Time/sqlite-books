<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

$bookId = isset($_POST['book_id']) ? (int)$_POST['book_id'] : 0;
$value = $_POST['value'] ?? '';

$pdo = getDatabaseConnection();
$pdo->exec("CREATE TABLE IF NOT EXISTS shelves (name TEXT PRIMARY KEY)");
$defaults = ['Physical', 'Ebook Calibre'];
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
$valueTable = "custom_column_{$shelfId}";
$linkTable  = "books_custom_column_{$shelfId}_link";

$pdo->prepare("INSERT OR IGNORE INTO $valueTable (value) VALUES (:val)")
    ->execute([':val' => $value]);
$valId = $pdo->query("SELECT id FROM $valueTable WHERE value = " . $pdo->quote($value))->fetchColumn();
$pdo->prepare("DELETE FROM $linkTable WHERE book = :book")->execute([':book' => $bookId]);
$stmt = $pdo->prepare("INSERT INTO $linkTable (book, value) VALUES (:book, :val)");
$stmt->execute([':book' => $bookId, ':val' => $valId]);

echo json_encode(['status' => 'ok']);
?>
