<?php
header('Content-Type: application/json');
require_once 'db.php';

$bookId = isset($_POST['book_id']) ? (int)$_POST['book_id'] : 0;
$value = trim($_POST['value'] ?? '');

$pdo = getDatabaseConnection();
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS reading_log (book INTEGER PRIMARY KEY REFERENCES books(id) ON DELETE CASCADE, year INTEGER, read_date TEXT)");
    $cols = $pdo->query("PRAGMA table_info(reading_log)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('read_date', $cols, true)) {
        $pdo->exec("ALTER TABLE reading_log ADD COLUMN read_date TEXT");
    }
    $stmt = $pdo->prepare("SELECT id FROM custom_columns WHERE label = 'status'");
    $stmt->execute();
    $statusId = $stmt->fetchColumn();
    if ($statusId === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Status column not found']);
        exit;
    }

    $base = 'books_custom_column_' . (int)$statusId;
    $direct = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . $base . "'")->fetchColumn();
    $link = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . $base . "_link'")->fetchColumn();

    if ($bookId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input']);
        exit;
    }

    if ($link) {
        // Enumerated column using link table
        $table = $base . '_link';
        $valueTable = 'custom_column_' . (int)$statusId;
        if ($value === '') {
            $stmt = $pdo->prepare("DELETE FROM $table WHERE book = :book");
            $stmt->execute([':book' => $bookId]);
        } else {
            // Ensure value exists
            $stmt = $pdo->prepare("SELECT id FROM $valueTable WHERE value = :val");
            $stmt->execute([':val' => $value]);
            $valId = $stmt->fetchColumn();
            if ($valId === false) {
                $stmt = $pdo->prepare("INSERT INTO $valueTable (value) VALUES (:val)");
                $stmt->execute([':val' => $value]);
                $valId = $pdo->lastInsertId();
            }
        $stmt = $pdo->prepare("REPLACE INTO $table (book, value) VALUES (:book, :val)");
        $stmt->execute([':book' => $bookId, ':val' => $valId]);
        }
    } else {
        // Simple text column
        $table = $base;
        $pdo->exec("CREATE TABLE IF NOT EXISTS $table (book INTEGER PRIMARY KEY REFERENCES books(id) ON DELETE CASCADE, value TEXT)");
        $stmt = $pdo->prepare("REPLACE INTO $table (book, value) VALUES (:book, :value)");
        $stmt->execute([':book' => $bookId, ':value' => $value]);
    }

    $currentYear = (int)date('Y');
    if (strcasecmp($value, 'Read') === 0) {
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
