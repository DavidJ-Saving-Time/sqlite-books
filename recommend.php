<?php
header('Content-Type: application/json');

require_once 'book_recommend.php';
require_once 'db.php';
requireLogin();

$authors = $_GET['authors'] ?? '';
$title = $_GET['title'] ?? '';
$bookId = isset($_GET['book_id']) ? (int)$_GET['book_id'] : 0;

if ($authors === '' && $title === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

$userInput = trim($authors . ' ' . $title);

try {
    $output = get_book_recommendations($userInput);

    if ($bookId > 0) {
        $pdo = getDatabaseConnection();

        // Ensure the recommendation column exists before storing the data
        $exists = false;
        try {
            $stmt = $pdo->prepare("SELECT id, is_multiple FROM custom_columns WHERE label = '#recommendations'");
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                $recId = (int)$pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM custom_columns")->fetchColumn();
                $pdo->prepare("INSERT INTO custom_columns (id, label, name, datatype, mark_for_delete, editable, is_multiple, normalized, display) VALUES (:id, '#recommendations', 'recommendations', 'text', 0, 1, 0, 1, '{}')")
                    ->execute([':id' => $recId]);
                $isMultiple = 0;
            } else {
                $recId = (int)$row['id'];
                $isMultiple = (int)$row['is_multiple'];
            }

            $base = 'custom_column_' . $recId;
            $link = 'books_custom_column_' . $recId . '_link';

            if ($isMultiple) {
                // Enumerated column with link table
                $pdo->exec("CREATE TABLE IF NOT EXISTS $base (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT)");
                $pdo->exec("CREATE TABLE IF NOT EXISTS $link (book INTEGER REFERENCES books(id) ON DELETE CASCADE, value INTEGER REFERENCES $base(id), PRIMARY KEY(book,value))");

                // Ensure value entry exists
                $stmt = $pdo->prepare("SELECT id FROM $base WHERE value = :v");
                $stmt->execute([':v' => $output]);
                $valId = $stmt->fetchColumn();
                if ($valId === false) {
                    $stmt = $pdo->prepare("INSERT INTO $base (value) VALUES (:v)");
                    $stmt->execute([':v' => $output]);
                    $valId = $pdo->lastInsertId();
                }

                $pdo->prepare("DELETE FROM $link WHERE book = :book")->execute([':book' => $bookId]);
                $stmt = $pdo->prepare("INSERT INTO $link (book, value) VALUES (:book, :val)");
                $stmt->execute([':book' => $bookId, ':val' => $valId]);
            } else {
                // Simple text column
                $pdo->exec("CREATE TABLE IF NOT EXISTS $base (book INTEGER PRIMARY KEY REFERENCES books(id) ON DELETE CASCADE, value TEXT)");
                $stmt = $pdo->prepare("REPLACE INTO $base (book, value) VALUES (:book, :value)");
                $stmt->execute([':book' => $bookId, ':value' => $output]);
            }

            $exists = true;
        } catch (PDOException $e) {
            $exists = false;
        }
    }

    echo json_encode(['output' => $output]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
