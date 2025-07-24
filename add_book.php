<?php
header('Content-Type: application/json');
require_once 'db.php';

$title = trim($_POST['title'] ?? '');
$authors = trim($_POST['authors'] ?? '');
if ($title === '' || $authors === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

$pdo = getDatabaseConnection();

try {
    $pdo->beginTransaction();

    $primaryAuthor = trim(explode(',', $authors)[0]);
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO authors (name, sort) VALUES (:name, :sort)');
    $stmt->execute([':name' => $primaryAuthor, ':sort' => $primaryAuthor]);

    $stmt = $pdo->prepare('SELECT id FROM authors WHERE name = :name');
    $stmt->execute([':name' => $primaryAuthor]);
    $authorId = (int)$stmt->fetchColumn();

    $path = preg_replace('/[^A-Za-z0-9]+/', '_', $title);
    $insertBook = $pdo->prepare('INSERT INTO books (title, sort, author_sort, timestamp, pubdate, series_index, last_modified, path)
                                 VALUES (:title, :sort, :author_sort, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 1.0, CURRENT_TIMESTAMP, :path)');
    $insertBook->execute([
        ':title' => $title,
        ':sort' => $title,
        ':author_sort' => $primaryAuthor,
        ':path' => $path
    ]);
    $bookId = (int)$pdo->lastInsertId();

    $balStmt = $pdo->prepare('INSERT INTO books_authors_link (book, author) VALUES (:book, :author)');
    $balStmt->execute([':book' => $bookId, ':author' => $authorId]);

    $tableStmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'books_custom_column_%' AND name NOT LIKE '%_link'");
    $tables = $tableStmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $value = null;
        if ($table === 'books_custom_column_11') {
            $value = 'Physical';
        }
        $pdo->prepare("INSERT INTO $table (book, value) VALUES (:book, :value)")->execute([':book' => $bookId, ':value' => $value]);
    }

    // Default status
    $stmt = $pdo->prepare("SELECT id FROM custom_columns WHERE label = 'status'");
    $stmt->execute();
    $statusId = $stmt->fetchColumn();
    if ($statusId !== false) {
        $base = 'books_custom_column_' . (int)$statusId;
        $link = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . $base . "_link'")->fetchColumn();
        if ($link) {
            $valueTable = 'custom_column_' . (int)$statusId;
            $pdo->prepare("INSERT OR IGNORE INTO $valueTable (value) VALUES ('Want to Read')")->execute();
            $defaultId = $pdo->query("SELECT id FROM $valueTable WHERE value = 'Want to Read'")->fetchColumn();
            $pdo->prepare("INSERT INTO {$base}_link (book, value) VALUES (:book, :val)")->execute([':book' => $bookId, ':val' => $defaultId]);
        } else {
            $statusTable = $base;
            $pdo->exec("CREATE TABLE IF NOT EXISTS $statusTable (book INTEGER PRIMARY KEY REFERENCES books(id) ON DELETE CASCADE, value TEXT)");
            $pdo->prepare("INSERT INTO $statusTable (book, value) VALUES (:book, 'Want to Read')")->execute([':book' => $bookId]);
        }
    }

    $pdo->commit();
    echo json_encode(['status' => 'ok', 'book_id' => $bookId]);
} catch (PDOException $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

