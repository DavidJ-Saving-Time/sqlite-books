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
            $stmt = $pdo->prepare("SELECT id FROM custom_columns WHERE label = '#recommendations'");
            $stmt->execute();
            $recId = $stmt->fetchColumn();
            if ($recId === false) {
                $recId = (int)$pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM custom_columns")->fetchColumn();
                $pdo->prepare("INSERT INTO custom_columns (id, label, name, datatype, mark_for_delete, editable, is_multiple, normalized, display) VALUES (:id, '#recommendations', 'recommendations', 'text', 0, 1, 0, 1, '{}')")
                    ->execute([':id' => $recId]);
            }
            $base = 'custom_column_' . (int)$recId;
            $link = 'books_custom_column_' . (int)$recId . '_link';
            $linkCheck = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . $link . "'");
            if ($linkCheck->fetch()) {
                $recTable = $link;
            } else {
                $recTable = $base;
                $pdo->exec("CREATE TABLE IF NOT EXISTS $recTable (book INTEGER PRIMARY KEY REFERENCES books(id) ON DELETE CASCADE, value TEXT)");
            }
            $exists = true;
        } catch (PDOException $e) {
            $exists = false;
        }

        if ($exists) {
            $stmt = $pdo->prepare('REPLACE INTO ' . $recTable . ' (book, value) VALUES (:book, :value)');
            $stmt->execute([':book' => $bookId, ':value' => $output]);
        }
    }

    echo json_encode(['output' => $output]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
