<?php
/**
 * Sets the status for all books by a specific author and updates the reading log.
 *
 * Expects an HTTP POST request.
 *
 * POST Parameters:
 * - author_id: ID of the author.
 * - value: Status name to apply; empty to clear.
 *
 * Returns:
 * {"status":"ok"} on success
 * or {"error":"message"} on failure.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

$authorId = isset($_POST['author_id']) ? (int)$_POST['author_id'] : 0;
$value = trim($_POST['value'] ?? '');

if ($authorId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input']);
    exit;
}

$pdo = getDatabaseConnection();
try {
    $pdo->beginTransaction();
    $pdo->exec("CREATE TABLE IF NOT EXISTS reading_log (book INTEGER PRIMARY KEY REFERENCES books(id) ON DELETE CASCADE, year INTEGER, read_date TEXT)");
    $cols = $pdo->query("PRAGMA table_info(reading_log)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('read_date', $cols, true)) {
        $pdo->exec("ALTER TABLE reading_log ADD COLUMN read_date TEXT");
    }

    $statusId = ensureMultiValueColumn($pdo, '#status', 'Status');
    $valueTable = "custom_column_{$statusId}";
    $linkTable = "books_custom_column_{$statusId}_link";

    $stmt = $pdo->prepare('SELECT book FROM books_authors_link WHERE author = :author');
    $stmt->execute([':author' => $authorId]);
    $bookIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if ($bookIds) {
        $delStmt = $pdo->prepare("DELETE FROM $linkTable WHERE book = :book");
        foreach ($bookIds as $bookId) {
            $delStmt->execute([':book' => $bookId]);
        }

        if ($value !== '') {
            $pdo->prepare("INSERT OR IGNORE INTO $valueTable (value) VALUES (:val)")
                ->execute([':val' => $value]);
            $valStmt = $pdo->prepare("SELECT id FROM $valueTable WHERE value = :val");
            $valStmt->execute([':val' => $value]);
            $valId = $valStmt->fetchColumn();
            $insStmt = $pdo->prepare("INSERT INTO $linkTable (book, value) VALUES (:book, :val)");
            foreach ($bookIds as $bookId) {
                $insStmt->execute([':book' => $bookId, ':val' => $valId]);
            }
        }

        $currentYear = (int)date('Y');
        $rlInsert = $pdo->prepare('REPLACE INTO reading_log (book, year, read_date) VALUES (:book, :year, :read_date)');
        $rlDelete = $pdo->prepare('DELETE FROM reading_log WHERE book = :book');
        foreach ($bookIds as $bookId) {
            if (strcasecmp($value, 'Read Challenge') === 0) {
                $rlInsert->execute([':book' => $bookId, ':year' => $currentYear, ':read_date' => date('Y-m-d')]);
            } else {
                $rlDelete->execute([':book' => $bookId]);
            }
        }
    }

    $pdo->commit();
    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
