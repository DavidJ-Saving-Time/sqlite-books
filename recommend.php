<?php
header('Content-Type: application/json');

require_once 'book_recommend.php';
require_once 'db.php';

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
            $check = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='books_custom_column_10'");
            if ($check->fetch()) {
                $exists = true;
            }
        } catch (PDOException $e) {
            $exists = false;
        }
        if (!$exists) {
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS books_custom_column_10 (book INTEGER PRIMARY KEY REFERENCES books(id) ON DELETE CASCADE, value TEXT)");
                $exists = true;
            } catch (PDOException $e) {
                // If creation fails we still continue without saving
                $exists = false;
            }
        }

        if ($exists) {
            $stmt = $pdo->prepare('REPLACE INTO books_custom_column_10 (book, value) VALUES (:book, :value)');
            $stmt->execute([':book' => $bookId, ':value' => $output]);
        }
    }

    echo json_encode(['output' => $output]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
