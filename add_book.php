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

    // Assign default shelf
    $shelfId = ensureSingleValueColumn($pdo, '#shelf', 'Shelf');
    $shelfValTable = "custom_column_{$shelfId}";
    $shelfLinkTable = "books_custom_column_{$shelfId}_link";
    $pdo->prepare("INSERT OR IGNORE INTO $shelfValTable (value) VALUES ('Ebook Calibre')")->execute();
    $shelfValId = $pdo->query("SELECT id FROM $shelfValTable WHERE value = 'Ebook Calibre'")->fetchColumn();
    $pdo->prepare("INSERT INTO $shelfLinkTable (book, value) VALUES (:book, :val)")
        ->execute([':book' => $bookId, ':val' => $shelfValId]);

    // Assign default status
    $statusId = ensureMultiValueColumn($pdo, '#status', 'Status');
    $statusValTable = "custom_column_{$statusId}";
    $statusLinkTable = "books_custom_column_{$statusId}_link";
    $pdo->prepare("INSERT OR IGNORE INTO $statusValTable (value) VALUES ('Want to Read')")->execute();
    $statusValId = $pdo->query("SELECT id FROM $statusValTable WHERE value = 'Want to Read'")->fetchColumn();
    $pdo->prepare("INSERT INTO $statusLinkTable (book, value) VALUES (:book, :val)")->execute([':book' => $bookId, ':val' => $statusValId]);

    $pdo->commit();
    echo json_encode(['status' => 'ok', 'book_id' => $bookId]);
} catch (PDOException $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

