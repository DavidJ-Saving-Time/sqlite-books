<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

$bookId = isset($_POST['book_id']) ? (int)$_POST['book_id'] : 0;
$value = trim($_POST['value'] ?? '');

$pdo = getDatabaseConnection();
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS reading_log (book INTEGER PRIMARY KEY REFERENCES books(id) ON DELETE CASCADE, year INTEGER, read_date TEXT)");
    $cols = $pdo->query("PRAGMA table_info(reading_log)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('read_date', $cols, true)) {
        $pdo->exec("ALTER TABLE reading_log ADD COLUMN read_date TEXT");
    }
    $statusId = ensureMultiValueColumn($pdo, '#status', 'Status');
    $valueTable = "custom_column_{$statusId}";
    $link = "books_custom_column_{$statusId}_link";

    if ($bookId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input']);
        exit;
    }

    $stmt = $pdo->prepare("DELETE FROM $link WHERE book = :book");
    $stmt->execute([':book' => $bookId]);
    if ($value !== '') {
        $pdo->prepare("INSERT OR IGNORE INTO $valueTable (value) VALUES (:val)")
            ->execute([':val' => $value]);
        $valStmt = $pdo->prepare("SELECT id FROM $valueTable WHERE value = :val");
        $valStmt->execute([':val' => $value]);
        $valId = $valStmt->fetchColumn();
        $stmt = $pdo->prepare("INSERT INTO $link (book, value) VALUES (:book, :val)");
        $stmt->execute([':book' => $bookId, ':val' => $valId]);
    }

    $currentYear = (int)date('Y');
    if (strcasecmp($value, 'Read Challenge') === 0) {
        $stmt = $pdo->prepare('REPLACE INTO reading_log (book, year, read_date) VALUES (:book, :year, :read_date)');
        $stmt->execute([
            ':book' => $bookId,
            ':year' => $currentYear,
            ':read_date' => date('Y-m-d')
        ]);
    } else {
        $stmt = $pdo->prepare('DELETE FROM reading_log WHERE book = :book');
        $stmt->execute([':book' => $bookId]);
    }

    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
