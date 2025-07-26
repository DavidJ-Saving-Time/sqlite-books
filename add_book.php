<?php
header('Content-Type: application/json');
require_once 'db.php';
requireLogin();

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

    $colStmt = $pdo->query("SELECT id, label, is_multiple FROM custom_columns");
    $columns = $colStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $col) {
        $colId = (int)$col['id'];
        $label = $col['label'];
        $isMultiple = (int)$col['is_multiple'];
        $linkTable = 'books_custom_column_' . $colId . '_link';
        $valueTable = 'custom_column_' . $colId;
        $linkExists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . $linkTable . "'")->fetchColumn();
        $directExists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . $valueTable . "'")->fetchColumn();
        if ($linkExists && $directExists && $isMultiple === 0) {
            $defaultId = null;
            if ($label === '#status') {
                $pdo->prepare("INSERT OR IGNORE INTO $valueTable (value) VALUES ('Want to Read')")->execute();
                $defaultId = $pdo->query("SELECT id FROM $valueTable WHERE value = 'Want to Read'")->fetchColumn();
            } else {
                $defaultId = $pdo->query("SELECT id FROM $valueTable ORDER BY id LIMIT 1")->fetchColumn();
            }
            if ($defaultId !== false && $defaultId !== null) {
                $pdo->prepare("INSERT INTO $linkTable (book, value) VALUES (:book, :val)")->execute([':book' => $bookId, ':val' => $defaultId]);
            }
        } elseif ($directExists) {
            $value = null;
            if ($label === '#shelf') {
                $value = 'Ebook Calibre';
            }
            $pdo->prepare("INSERT INTO $valueTable (book, value) VALUES (:book, :value)")->execute([':book' => $bookId, ':value' => $value]);
        }
    }

    $pdo->commit();
    echo json_encode(['status' => 'ok', 'book_id' => $bookId]);
} catch (PDOException $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

